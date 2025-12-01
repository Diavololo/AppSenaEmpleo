<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIService
{
    private string $apiKey;
    private string $baseUrl;
    private string $embeddingModel;
    private string $chatModel;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = (string) config('services.openai.key');
        $this->baseUrl = rtrim((string) config('services.openai.base', 'https://api.openai.com/v1'), '/');
        $this->embeddingModel = (string) config('services.openai.embedding_model', 'text-embedding-3-small');
        $this->chatModel = (string) config('services.openai.chat_model', 'gpt-4o-mini');
        $this->timeout = 30;
    }

    private function ensureKey(): void
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is missing');
        }
    }

    /**
     * @return float[]
     */
    public function embed(string $text): array
    {
        $this->ensureKey();

        $payload = [
            'model' => $this->embeddingModel,
            'input' => $text,
        ];

        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout($this->timeout)
            ->post($this->baseUrl.'/embeddings', $payload)
            ->throw()
            ->json();

        $data = $response['data'][0]['embedding'] ?? null;
        if (!is_array($data)) {
            throw new RuntimeException('Embedding response invalid');
        }

        return array_map('floatval', $data);
    }

    public function chat(string $prompt): string
    {
        $this->ensureKey();

        $payload = [
            'model' => $this->chatModel,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.5,
            'max_tokens' => 140,
        ];

        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout($this->timeout)
            ->post($this->baseUrl.'/chat/completions', $payload)
            ->throw()
            ->json();

        $content = $response['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new RuntimeException('Chat response invalid');
        }

        return trim($content);
    }

    public static function norm(array $vector): float
    {
        $sum = 0.0;
        foreach ($vector as $v) {
            $sum += $v * $v;
        }

        return sqrt($sum);
    }

    public static function cosine(array $a, float $normA, array $b, float $normB): float
    {
        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        $len = min(count($a), count($b));
        $dot = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
        }

        return $dot / ($normA * $normB);
    }
}
