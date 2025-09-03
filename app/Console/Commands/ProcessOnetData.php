<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\QdrantService;
use Cloudstudio\Ollama\Facades\Ollama;
use League\Csv\Reader;
use Exception;

class ProcessOnetData extends Command
{
    protected $signature = 'career:process {--type=all : Tipe data (all|occupations|tasks|skills|knowledge|tech|zones|related|work_activities|work_context|alternate_titles)}';

    protected $description = 'Memproses 7 file inti O*NET dan memasukkannya ke Qdrant untuk RAG';

    protected QdrantService $qdrant;
    private array $occupationDictionary = [];

    public function __construct(QdrantService $qdrant)
    {
        parent::__construct();
        $this->qdrant = $qdrant;
    }

    public function handle()
    {
        $type = $this->option('type');
        $this->info("Memulai proses data karir untuk tipe: '{$type}'...");

        try {
            $dataPath = storage_path('app/onet_data/');

            $this->line('Memuat kamus pekerjaan (Occupation Data)...');
            $this->occupationDictionary = $this->loadOccupationDictionary($dataPath . 'Occupation Data.txt');
            if (empty($this->occupationDictionary)) {
                $this->error('Occupation gagal dimuat atau kosong.');
                return Command::FAILURE;
            }
            $this->info("Kamus Pekerjaan berhasil dimuat: " . count($this->occupationDictionary) . " Data.");
            
            $skillToOccupationMap = [];
            if (in_array($type, ['all', 'work_activities', 'work_context'])) {
                $skillToOccupationMap = $this->loadSkillToOccupationMap($dataPath . 'Skills.txt');
            }

            if ($type === 'all' || $type === 'occupations') {
                $this->processOccupations($dataPath . 'Occupation Data.txt');
            }
            if ($type === 'all' || $type === 'tasks') {
                $this->processTasks($dataPath . 'Task Statements.txt');
            }
            if ($type === 'all' || $type === 'skills') {
                $this->processGenericFact($dataPath . 'Skills.txt', 'Skill');
            }
            if ($type === 'all' || $type === 'knowledge') {
                $this->processGenericFact($dataPath . 'Knowledge.txt', 'Pengetahuan');
            }
            if ($type === 'all' || $type === 'tech') {
                $this->processTechnologySkills($dataPath . 'Technology Skills.txt');
            }
            if ($type === 'all' || $type === 'zones') {
                $this->processJobZones($dataPath . 'Job Zones.txt');
            }
            if ($type === 'all' || $type === 'related') {
                $this->processRelatedOccupations($dataPath . 'Related Occupations.txt');
            }
            if ($type === 'all' || $type === 'work_activities') {
                $this->processSkillsToWorkActivities($dataPath . 'Skills to Work Activities.txt', $skillToOccupationMap);
            }
            if ($type === 'all' || $type === 'work_context') {
                $this->processSkillsToWorkContext($dataPath . 'Skills to Work Context.txt', $skillToOccupationMap);
            }

            $this->info("Proses untuk tipe '{$type}' berhasil diselesaikan!");
            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error('Terjadi error: ' . $e->getMessage());
            Log::error('Career Data Processing Failed: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            return Command::FAILURE;
        }
    }

    private function processOccupations(string $path): void
    {
        $this->line('Memproses Occupation');
        foreach ($this->getRecordsWithProgress($path) as $record) {
            $sentence = "Pekerjaan '{$record['Title']}' memiliki deskripsi sebagai berikut: {$record['Description']}. Kode O*NET-SOC untuk pekerjaan ini adalah {$record['O*NET-SOC Code']}.";
            $this->embedAndUpsert($sentence, ['source' => 'Occupation Data', 'soc_code' => $record['O*NET-SOC Code']]);
        }
        $this->info('Occupation selesai diproses.');
    }

    private function processTasks(string $path): void
    {
        $this->line('Memproses Task');
        foreach ($this->getRecordsWithProgress($path) as $record) {
            $occupationName = $this->getOccupationName($record['O*NET-SOC Code']);
            if (!$occupationName) continue;

            $sentence = "Untuk pekerjaan '{$occupationName}', salah satu tugas utamanya adalah '{$record['Task']}'.";
            $this->embedAndUpsert($sentence, ['source' => 'Task Statements', 'soc_code' => $record['O*NET-SOC Code']]);
        }
        $this->info('Task selesai diproses.');
    }

    private function processGenericFact(string $path, string $factType): void
    {
        $this->line("Memproses {$factType}...");
        foreach ($this->getRecordsWithProgress($path) as $record) {
            $occupationName = $this->getOccupationName($record['O*NET-SOC Code']);
            if (!$occupationName) continue;

            $scale = ($record['Scale Name'] ?? '') === 'Importance' ? 'tingkat kepentingan' : 'level yang dibutuhkan';
            $sentence = "Dalam pekerjaan '{$occupationName}', {$factType} '{$record['Element Name']}' dinilai memiliki {$scale} sebesar {$record['Data Value']} dari 5.";
            $this->embedAndUpsert($sentence, ['source' => $factType, 'soc_code' => $record['O*NET-SOC Code']]);
        }
        $this->info(" {$factType} selesai diproses.");
    }

    private function processTechnologySkills(string $path): void
    {
        $this->line('Memproses Technology Skills...');
        foreach ($this->getRecordsWithProgress($path) as $record) {
            $occupationName = $this->getOccupationName($record['O*NET-SOC Code']);
            if (!$occupationName) continue;

            $sentence = "Orang dalam pekerjaan '{$occupationName}' sering menggunakan teknologi atau software '{$record['Example']}', yang termasuk dalam kategori '{$record['Commodity Title']}'.";
            $this->embedAndUpsert($sentence, ['source' => 'Technology Skills', 'soc_code' => $record['O*NET-SOC Code']]);
        }
        $this->info(' Technology Skills selesai diproses.');
    }

    private function processJobZones(string $path): void
    {
        $this->line('Memproses Job Zones...');
        foreach ($this->getRecordsWithProgress($path) as $record) {
            $occupationName = $this->getOccupationName($record['O*NET-SOC Code']);
            if (!$occupationName) continue;

            $sentence = "Pekerjaan '{$occupationName}' berada di Job Zone {$record['Job Zone']}. Ini mengindikasikan tingkat persiapan, pengalaman, dan pendidikan yang dibutuhkan untuk karir tersebut.";
            $this->embedAndUpsert($sentence, ['source' => 'Job Zones', 'soc_code' => $record['O*NET-SOC Code']]);
        }
        $this->info('Job Zones selesai diproses.');
    }

    private function processRelatedOccupations(string $path): void
    {
        $this->line('Memproses Related Occupation...');
        foreach ($this->getRecordsWithProgress($path) as $record) {
            $sourceOccupation = $this->getOccupationName($record['O*NET-SOC Code']);
            $relatedOccupation = $this->getOccupationName($record['Related O*NET-SOC Code']);
            if (!$sourceOccupation || !$relatedOccupation) continue;

            $relationType = ($record['Related Index'] ?? '0') == '1' ? 'memiliki banyak kesamaan tugas' : 'merupakan langkah karir selanjutnya';
            $sentence = "Karir yang terkait dengan '{$sourceOccupation}' adalah '{$relatedOccupation}'. Hubungan ini ada karena keduanya {$relationType}.";
            $this->embedAndUpsert($sentence, ['source' => 'Related Occupations', 'soc_code' => $record['O*NET-SOC Code']]);
        }
        $this->info('Related Occupation selesai diproses.');
    }

    private function processSkillsToWorkActivities(string $path, array $skillToOccMap): void
    {
        $this->line('Memproses Keterampilan -> Aktivitas Kerja...');
        foreach ($this->getRecordsWithProgress($path) as $record) {
            $skillId = $record['Skills Element ID'];
            $skillName = $record['Skills Element Name'];
            $activityName = $record['Work Activities Element Name'];

            if (isset($skillToOccMap[$skillId])) {
                foreach ($skillToOccMap[$skillId] as $socCode) {
                    $occupationName = $this->getOccupationName($socCode);
                    if (!$occupationName) continue;

                    $sentence = "Untuk pekerjaan '{$occupationName}', skill '{$skillName}' relevan dengan aktivitas kerja '{$activityName}'.";
                    $this->embedAndUpsert($sentence, [
                        'source' => 'Skills to Work Activities',
                        'soc_code' => $socCode,
                        'skill_id' => $skillId
                    ]);
                }
            }
        }
        $this->info('Keterampilan -> Aktivitas Kerja selesai diproses.');
    }

    private function processSkillsToWorkContext(string $path, array $skillToOccMap): void
    {
        $this->line('Memproses Keterampilan -> Konteks Kerja...');
        foreach ($this->getRecordsWithProgress($path) as $record) {
            $skillId = $record['Skills Element ID'];
            $skillName = $record['Skills Element Name'];
            $contextName = $record['Work Context Element Name'];

            if (isset($skillToOccMap[$skillId])) {
                foreach ($skillToOccMap[$skillId] as $socCode) {
                    $occupationName = $this->getOccupationName($socCode);
                    if (!$occupationName) continue;

                    $sentence = "Dalam pekerjaan '{$occupationName}', skill '{$skillName}' diterapkan dalam konteks kerja '{$contextName}'.";
                    $this->embedAndUpsert($sentence, [
                        'source' => 'Skills to Work Context',
                        'soc_code' => $socCode,
                        'skill_id' => $skillId
                    ]);
                }
            }
        }
        $this->info('Keterampilan -> Konteks Kerja selesai diproses.');
    }

    private function loadSkillToOccupationMap(string $path): array
    {
        $this->line('Membuat pemetaan Skill ke Pekerjaan...');
        $map = [];
        $records = $this->createCsvReader($path)->getRecords();
        foreach ($records as $record) {
            $skillId = $record['Element ID'];
            $socCode = $record['O*NET-SOC Code'];
            if (!isset($map[$skillId])) {
                $map[$skillId] = [];
            }
            // Avoid duplicates
            if (!in_array($socCode, $map[$skillId])) {
                $map[$skillId][] = $socCode;
            }
        }
        $this->info('Pemetaan Skill ke Pekerjaan selesai.');
        return $map;
    }

    private function loadOccupationDictionary(string $path): array
    {
        $dictionary = [];
        $records = $this->createCsvReader($path)->getRecords();
        foreach ($records as $record) {
            $dictionary[$record['O*NET-SOC Code']] = $record['Title'];
        }
        return $dictionary;
    }

    private function getOccupationName(string $socCode): ?string
    {
        return $this->occupationDictionary[$socCode] ?? null;
    }

    private function getRecordsWithProgress(string $path): \Generator
    {
        $csv = $this->createCsvReader($path);
        $records = iterator_to_array($csv->getRecords());
        $totalRecords = count($records);
        $spinner = ['-', '\\', '|', '/'];
        $i = 0;

        foreach ($records as $index => $record) {
            $spinnerFrame = $spinner[$i % count($spinner)];
            $this->output->write("     [{$spinnerFrame}] Memproses baris " . ($index + 1) . "/{$totalRecords}...\r");
            $i++;
            yield $record;
        }
        $this->output->write("\r");
        $this->output->write(str_repeat(' ', 60) . "\r");
    }

    private function createCsvReader(string $path): Reader
    {
        if (!file_exists($path)) throw new Exception("File tidak ditemukan di: {$path}");
        $csv = Reader::createFromPath($path, 'r');
        $csv->setDelimiter("\t");
        $csv->setHeaderOffset(0);
        return $csv;
    }

    private function embedAndUpsert(string $sentence, array $payload = []): void
    {
        try {
            $response = Ollama::model('nomic-embed-text')->embeddings($sentence);

            if (empty($response['embedding'])) {
                $this->warn("Gagal membuat embedding untuk: " . substr($sentence, 0, 70) . "...");
                return;
            }

            $payload['text'] = $sentence;
            // $this->qdrant->upsertChunk((string) Str::uuid(), $response['embedding'], $payload);

            usleep(50000); // 50 milidetik

        } catch (Exception $e) {
            $this->error("Gagal menyimpan ke Qdrant: " . substr($e->getMessage(), 0, 100));
        }
    }
}
