<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\QdrantService;
use Cloudstudio\Ollama\Facades\Ollama;
use League\Csv\Reader;
use Exception;
use Illuminate\Support\Facades\http;

class ProcessOnetDataNew extends Command
{
    protected $signature = 'onet:process
                            {--type=all : Tipe data (all|occupations|tasks|technologies|skills|knowledge|relations|zones)}
                            {--fresh : Hapus koleksi yang ada sebelum memproses atau membuat baru}';


    protected $description = 'Proses data';

    protected QdrantService $qdrant;

    private array $occupationMasterDictionary = [];
    private const COLLECTION_OCCUPATIONS = 'occupations';
    private const COLLECTION_TASKS = 'tasks';
    private const COLLECTION_TECHNOLOGIES = 'technologies';
    private const COLLECTION_SKILLS = 'skills';
    private const COLLECTION_KNOWLEDGE = 'knowledge';
    private const COLLECTION_RELATIONS = 'relations';
    private const COLLECTION_JURUSAN = 'jurusan';

    public function __construct(QdrantService $qdrant)
    {
        parent::__construct();
        $this->qdrant = $qdrant;
    }

    public function handle(): int
    {
        $type = $this->option('type');
        $this->info("Memulai memasukan data untuk tipe: '{$type}'...");
        $apiToken = env('JURUSAN_API_TOKEN', 'Bearer 24|Yk4J7Colv7CltN6KGLBcnt81Mk9HDDSiz8EgqJSk');

        try {
            $dataPath = storage_path('app/onet_data/');

            if ($this->option('fresh')) {
                $this->recreateCollections();
            }

            $this->line('Memuat pekerjaan utama');
            $this->loadOccupationMasterDictionary($dataPath . 'Occupation Data.txt');
            if (empty($this->occupationMasterDictionary)) {
                $this->error('pekerjaan gagal dimuat');
                return Command::FAILURE;
            }
            $this->info("Pekerjaan berhasil dimuat: " . count($this->occupationMasterDictionary) .  " data.");

            $processMap = [
                'occupations' => fn() => $this->processOccupations($dataPath . 'Occupation Data.txt'),
                'tasks' => fn() => $this->processTasks($dataPath . 'Task Statements.txt'),
                'technologies' => fn() => $this->processTechnologies($dataPath . 'Technology Skills.txt'),
                // 'skills' => fn() => $this->processAttributes($dataPath . 'Skills.txt', 'Skill', self::COLLECTION_SKILLS),
                'knowledge' => fn() => $this->processAttributes($dataPath . 'Knowledge.txt', 'Pengetahuan', self::COLLECTION_KNOWLEDGE),
                'relations' => fn() => $this->processRelatedOccupations($dataPath . 'Related Occupations.txt'),
                'zones' => fn() => $this->updateOccupationsWithJobZones($dataPath . 'Job Zones.txt'),
                'jurusan' => fn() => $this->processJurusan(
                    'https://e-form.upi.edu/api/ref_pst',
                    $apiToken,
                ),
            ];

            if ($type == 'all') {
                foreach ($processMap as $key => $processFuntion) {
                    $processFuntion();
                }
            } elseif (isset($processMap[$type])) {
                $processMap[$type]();
            } else {
                $this->error("Tipe '{$type}' tidak valid.");
                return Command::FAILURE;
            }

            $this->info("Proses memasukan data untuk tipe '{$type}' berhasil!");
            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error('Terjadi error: ' . $e->getMessage());
            Log::error('Failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return Command::FAILURE;
        }
    }

    private function processOccupations(string $path): void
    {
        $this->line('Memproses occupation..');
        foreach ($this->getRecordsWithProgress($path) as $record) {
            $textToEmbed = $record['Description'];
            $payload = [
                'soc_code' => $record['O*NET-SOC Code'],
                'title' => $record['Title'],
                'description' => $record['Description'],
                'source' => 'Occupation Data',
            ];
            $this->embedAndUpsert($textToEmbed, $payload, self::COLLECTION_OCCUPATIONS);
        }
        $this->info('Occupation selesai diproses.');
    }

    private function processTasks(string $path): void
    {
        $this->line('Memproses Tasks...');
        foreach ($this->getRecordsWithProgress($path) as $record) {
            $occupation = $this->getOccupation($record['O*NET-SOC Code']);
            if (!$occupation) continue;

            $textToEmbed = $record['Task'];

            $payload = [
                'task_id' => $record['Task ID'],
                'task_description' => $record['Task'],
                'soc_code' => $record['O*NET-SOC Code'],
                'occupation_title' => $occupation['title'],
                'source' => 'Task Statements',
            ];

            $this->embedAndUpsert($textToEmbed, $payload, self::COLLECTION_TASKS);
        }
        $this->info('Task selesai diproses.');
    }

    private function processTechnologies(string $path): void
    {
        $this->line('Memproses Technologies...');
        foreach ($this->getRecordsWithProgress($path) as $record) {
            $occupation = $this->getOccupation($record['O*NET-SOC Code']);
            if (!$occupation) continue;

            $textToEmbed = $record['Example'];

            $payload = [
                'technology_name' => $record['Example'],
                'category' => $record['Commodity Title'],
                'soc_code' => $record['O*NET-SOC Code'],
                'occupation_title' => $occupation['title'],
                'source' => 'Technology Skills',
            ];

            $this->embedAndUpsert($textToEmbed, $payload, self::COLLECTION_TECHNOLOGIES);
        }
        $this->info('Task selesai diproses.');
    }

    private function processAttributes(string $path, string $attributeType, string $collectionName): void
    {
        $this->line('Memproses Skills...');
        foreach ($this->getRecordsWithProgress($path) as $record) {
            if (!in_array($record['Scale ID'], ['IM', 'LV'])) {
                continue;
            }
            $occupation = $this->getOccupation($record['O*NET-SOC Code']);
            if (!$occupation) continue;

            $textToEmbed = $record['Element Name'];

            $payload = [
                'attribute_name' => $record['Element Name'],
                'attribute_type' => $attributeType,
                'scale' => $record['Scale ID'] === 'IM' ? 'Importance' : 'Level',
                'value' => (float) $record['Data Value'],
                'soc_code' => $record['O*NET-SOC Code'],
                'occupation_title' => $occupation['title'],
                'source' => basename($path),
            ];

            $this->embedAndUpsert($textToEmbed, $payload, $collectionName);
        }
        $this->info('Skills selesai diproses.');
    }

    private function processRelatedOccupations(string $path): void
    {
        $this->line('Memproses Related Occupation...');
        foreach ($this->getRecordsWithProgress($path) as $record) {

            $sourceOccupation = $this->getOccupation($record['O*NET-SOC Code']);
            $relatedOccupation = $this->getOccupation($record['Related O*NET-SOC Code']);

            if (!$sourceOccupation || !$relatedOccupation) {
                continue;
            }

            $textToEmbed = "Pekerjaan yang terkait dengan '{$sourceOccupation['title']}' adalah '{$relatedOccupation['title']}'.";

            $payload = [
                'text' => $textToEmbed,
                'source_soc_code' => $record['O*NET-SOC Code'],
                'source_title' => $sourceOccupation['title'],
                'related_soc_code' => $record['Related O*NET-SOC Code'],
                'related_title' => $relatedOccupation['title'],
                'relation_tier' => $record['Relatedness Tier'],
                'source' => 'Related Occupations',
            ];

            $this->embedAndUpsert($textToEmbed, $payload, self::COLLECTION_RELATIONS);
        }
        $this->info('Related Occupation selesai diproses.');
    }

    public function processJurusan(string $apiUrl, string $apiToken): void
    {
        $this->line('Memproses jurusan...');
        try {
            $response = Http::withToken($apiToken)->get($apiUrl);
            if (!$response->successful() || !$response->json()['success']) {
                $this->error('Gagal mengambil data dari API Jurusan. Cek URL dan Token Anda.');
                Log::error('API Jurusan Gagal', ['status' => $response->status(), 'body' => $response->body()]);
                return;
            }
            $jurusanData = $response->json()['data'];
            if (empty($jurusanData)) {
                $this->warn('Tidak mengembalikan data.');
            }
            $bar = $this->output->createProgressBar(count($jurusanData));
            $bar->start();
            foreach ($jurusanData as $jurusan) {
                $namaJurusanAwal = $jurusan['namapst'];
                $parts = explode(' - ', $namaJurusanAwal);
                $namaJurusan = trim($parts[0]);

                $textToEmbed = $namaJurusan;

                if (empty(trim($textToEmbed))) {
                    $bar->advance();
                    continue;
                }
                $payload = [
                    'nama_jurusan' => $namaJurusan,
                    'fakultas' => $jurusan['fakultas'],
                    'jenjang' => $jurusan['jenjang'],
                    'kode_prodi' => $jurusan['kodepst'],
                    'source' => 'API Jurusan Kampus UPI'
                ];
                $this->embedAndUpsert($textToEmbed, $payload, self::COLLECTION_JURUSAN);
                $bar->advance();
            }
            $bar->finish();
            $this->output->writeln('');
            $this->info('Jurusan selesai.');
        } catch (Exception $e) {
            $this->error('Error saat memproses API Jurusan: ' . $e->getMessage());
        }
    }

    private function embedAndUpsert(string $text, array $payload, string $collectionName): void
    {
        try {
            if (empty(trim($text))) {
                return;
            }
            $response = Ollama::model('nomic-embed-text')->embeddings($text);

            if (empty($response['embedding'])) {
                $this->warn("Gagal membuat embedding untuk: " . substr($text, 0, 70) . "...");
                return;
            }

            $this->qdrant->upsertChunk(
                (string) Str::uuid(),
                $response['embedding'],
                $payload,
                $collectionName
            );
            Log::info('Qdrant Upsert Response', [
                'embedding_length' => count($response['embedding']),
                'sample' => array_slice($response['embedding'], 0, 5),
            ]);

            usleep(50000);
        } catch (Exception $e) {
            $logMessage = "Gagal menyimpan ke Qdrant koleksi '{$collectionName}'. Error: " . $e->getMessage();
            $this->error($logMessage);
            Log::warning($logMessage, ['text' => $text]);
        }
    }

    private function loadOccupationMasterDictionary(string $path): void
    {
        $records = $this->createCsvReader($path)->getRecords();
        foreach ($records as $record) {
            $this->occupationMasterDictionary[$record['O*NET-SOC Code']] = [
                'title' => $record['Title'],
                'description' => $record['Description'],
            ];
        }
    }

    private function getOccupation(string $socCode): ?array
    {
        return $this->occupationMasterDictionary[$socCode] ?? null;
    }

    private function recreateCollections(): void
    {
        $this->warn('Menghapus dan membuat ulang koleksi di Qdrant...');
        $collections = [
            self::COLLECTION_OCCUPATIONS,
            // self::COLLECTION_TASKS,
            // self::COLLECTION_TECHNOLOGIES,
            // self::COLLECTION_SKILLS,
            // self::COLLECTION_KNOWLEDGE,
            // self::COLLECTION_RELATIONS,
            // self::COLLECTION_JURUSAN
        ];
        foreach ($collections as $collection) {
            $this->qdrant->recreateCollection($collection);
            $this->line("Koleksi '{$collection}' telah dibuat ulang.");
        }
    }

    private function getRecordsWithProgress(string $path): \Generator
    {
        if (!file_exists($path)) {
            $this->warn("File tidak ditemukan, melewati: {$path}");
            return;
        }
        $csv = $this->createCsvReader($path);
        $records = iterator_to_array($csv->getRecords());
        $bar = $this->output->createProgressBar(count($records));
        $bar->setFormat('%current%/%max% [%bar%] %percent:3s%% -- %message%');
        $bar->setMessage(basename($path));
        $bar->start();

        foreach ($records as $record) {
            yield $record;
            $bar->advance();
        }

        $bar->finish();
        $this->output->writeln('');
    }

    private function createCsvReader(string $path): Reader
    {
        $csv = Reader::createFromPath($path, 'r');
        $csv->setDelimiter("\t");
        $csv->setHeaderOffset(0);
        return $csv;
    }
}
