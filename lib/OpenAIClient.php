<?php

class OpenAIClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $embeddingModel;
    private int $timeout;

    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.openai.com/v1',
        string $embeddingModel = 'text-embedding-3-small',
        int $timeout = 30
    ) {
        $this->apiKey = trim($apiKey);
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->embeddingModel = $embeddingModel;
        $this->timeout = $timeout;

        if ($this->apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is missing');
        }
    }

    /**
     * @return float[]
     */
    public function embed(string $text): array
    {
        $payload = json_encode([
            'model' => $this->embeddingModel,
            'input' => $text,
        ]);

        $ch = curl_init($this->baseUrl.'/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: ' . 'Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('cURL error: '.$err);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($status >= 400) {
            $msg = $data['error']['message'] ?? 'API error '.$status;
            throw new RuntimeException($msg);
        }

        $embedding = $data['data'][0]['embedding'] ?? null;
        if (!is_array($embedding)) {
            throw new RuntimeException('Invalid embedding response');
        }

        return array_map('floatval', $embedding);
    }
}
