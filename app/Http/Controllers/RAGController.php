<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Cloudstudio\Ollama\Facades\Ollama;
use Illuminate\Support\Str;
use App\Services\QdrantService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RAGController extends Controller
{
    public function __construct(protected QdrantService $qdrant) {}

    public function ask(Request $request)
    {
        $originalQuestion = $request->input('question');

        if (!$originalQuestion) {
            return response()->json(['error' => 'Pertanyaan wajib diisi.'], 400);
        }

        try {
            Log::info("Pertanyaan awal: '{$originalQuestion}'");

            $translationPrompt = "You are an expert translator. Translate the following text to English. Return only the translated text, nothing else.  
            text: '{$originalQuestion}'";

            $translationResponse = Ollama::model('llama3.2:latest')->chat([
                ['role' => 'system', 'content' => $translationPrompt],
                ['role' => 'user', 'content' => $originalQuestion]
            ]);

            $translatedQuestion = $translationResponse['message']['content'] ?? $originalQuestion;
            // $translatedQuestion = trim($translatedQuestion, " \n\r\t\v\0\"'");

            Log::info("terjemahan: '{$translatedQuestion}'");

            $embeddingIndoResponse = Ollama::model('nomic-embed-text')->embeddings($originalQuestion);
            $embeddingIndo = $embeddingIndoResponse['embedding'] ?? [];

            $embeddingEngResponse = Ollama::model('nomic-embed-text')->embeddings($translatedQuestion);
            $embeddingEng = $embeddingEngResponse['embedding'] ?? [];

            if (empty($embeddingIndo) && empty($embeddingEng)) {
                return response()->json([
                   'success' => false,
                    'message' => 'Maaf, sistem tidak dapat memproses pertanyaan Anda. Silakan coba dengan kalimat yang berbeda.'
                ], 422);
            }

            Log::info('Embedding Indo: ' . count($embeddingIndo));
            Log::info('Embedding English: ' . count($embeddingEng));

            $contexts = [];
            $contextText = "Tidak ada konteks yang relevan ditemukan.";

            $jurusanContext = $this->qdrant->search($embeddingIndo, 'jurusan', 5);
            if (!empty($jurusanContext)) {
                foreach ($jurusanContext as $item) {
                    $contexts[] = "Jurusan Relevan: {$item['payload']['nama_jurusan']} ({$item['payload']['jenjang']}) di fakultas {$item['payload']['fakultas']}";
                }
            }

            $occupationContext = $this->qdrant->search($embeddingEng, 'occupations', 3);
            if (!empty($occupationContext)) {
                foreach ($occupationContext as $item) {
                    $contexts[] = "Pekerjaan: {$item['payload']['title']}\nDeskripsi: {$item['payload']['description']}";
                }
            }

            $taskContext = $this->qdrant->search($embeddingEng, 'tasks', 3);
            if (!empty($taskContext)) {
                foreach ($taskContext as $item) {
                    $contexts[] = "Untuk pekerjaan '{$item['payload']['occupation_title']}', ada tugas relevan: {$item['payload']['task_description']}";
                }
            }

            $technologiesContext = $this->qdrant->search($embeddingEng, 'technologies', 3);
            if (!empty($technologiesContext)) {
                foreach ($technologiesContext as $item) {
                    $contexts[] = "Pekerjaan '{$item['payload']['occupation_title']}' menggunakan teknologi: {$item['payload']['technology_name']}";
                }
            }

            $skillsContext = $this->qdrant->search($embeddingEng, 'skills', 3);
            if (!empty($skillsContext)) {
                foreach ($skillsContext as $item) {
                    $contexts[] = "Pekerjaan '{$item['payload']['occupation_title']}' membutuhkan skill '{$item['payload']['attribute_name']}'";
                }
            }



            if (!empty($contexts)) {
                $contextText = implode("\n---\n", array_unique($contexts));
            }
            Log::info("Retrieval: " . $contextText);
            Log::info('Search result:', ['result' => $contexts]);

            $finalPrompt = <<<EOT
Anda adalah asisten AI ahli karir yang membantu dan informatif. 
Tugas utama Anda adalah merangkai jawaban yang koheren dan bermanfaat berdasarkan informasi yang disediakan dalam "Konteks". 
Hubungkan titik-titik antara berbagai potongan informasi untuk menjawab "Pertanyaan" pengguna sebaik mungkin.
---
$contextText
---

Pertanyaan:
$originalQuestion 
EOT;
            $response = Ollama::model('llama3.2:latest')
                ->options([
                    'temperature' => 0.1,
                ])
                ->chat([
                    ['role' => 'user', 'content' => $finalPrompt]
                ]);

            $reply = $response['message']['content'] ?? '[No response]';

            return response()->json([
                'question' => $originalQuestion,
                'translated_question (for debugging)' => $translatedQuestion,
                'reply' => $reply,
                'context' => $contexts,
            ]);
        } catch (\Exception $e) {
            Log::error('Ask error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            return response()->json(['error' => 'Gagal memproses pertanyaan.'], 500);
        }
    }

    public function showChat()
    {
        return view('chat');
    }
}
