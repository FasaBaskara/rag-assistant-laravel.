<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class QdrantService
{
    protected string $baseUrl;
    protected ?string $apiKey;
    protected int $vectorSize;

    public function __construct()
    {
        $this->baseUrl = env('QDRANT_URL', 'http://localhost:6333');
        $this->apiKey = env('QDRANT_API_KEY', null);
        $this->vectorSize = 768;
    }

    public function recreateCollection(string $collectionName): bool
    {
        try {
            Http::withHeaders($this->getAuthHeaders())->delete("{$this->baseUrl}/collections/{$collectionName}");

            $response = Http::withHeaders($this->getAuthHeaders())->put("{$this->baseUrl}/collections/{$collectionName}", [
                'vectors' => [
                    'size' => $this->vectorSize,
                    'distance' => 'Cosine',
                ],
            ]);

            if (!$response->successful()) {
                Log::error("Qdrant: Gagal membuat koleksi '{$collectionName}'.", ['response' => $response->body()]);
                return false;
            }

            return true;
        } catch (Exception $e) {
            Log::error("Qdrant recreateCollection exception: " . $e->getMessage());
            return false;
        }
    }

    public function upsertChunk(string $id, array $embedding, array $payload, string $collectionName): bool
    {
        Log::info("UPDATING QDRANT: Menyiapkan data untuk dikirim.", [
        'collection' => $collectionName,
        'point_id' => $id,
        'vector_preview' => array_slice($embedding, 0, 5), 
        'payload' => $payload]);
        try {
            $response = Http::withHeaders($this->getAuthHeaders())->put("{$this->baseUrl}/collections/{$collectionName}/points?wait=true", [
                'points' => [[
                    'id' => $id,
                    'vector' => $embedding,
                    'payload' => $payload
                ]]
            ]);
            Log::info('Qdrant Upsert Response', [

'status' => $response->status(),

 'body' => $response->body() ]);

            if (!$response->successful()) {
                Log::warning("Qdrant: Gagal upsert ke koleksi '{$collectionName}'.", [
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                    'payload' => $payload,
                ]);
                return false;
            }
            return true;
        } catch (Exception $e) {
            Log::error("Qdrant upsertChunk exception: " . $e->getMessage());
            return false;
        }
    }

    public function search(array $embedding, string $collectionName, int $topK = 5): ?array
    {
        try {
            $response = Http::withHeaders($this->getAuthHeaders())->post("{$this->baseUrl}/collections/{$collectionName}/points/search", [
                'vector' => $embedding,
                'limit' => $topK,
                'with_payload' => true
            ]);

            return $response->json()['result'] ?? null;
        } catch (Exception $e) {
            Log::error("Qdrant search exception: " . $e->getMessage());
            return null;
        }
    }
    

    private function getAuthHeaders(): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($this->apiKey) {
            $headers['api-key'] = $this->apiKey;
        }
        return $headers;
    }
}