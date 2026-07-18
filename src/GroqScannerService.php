<?php

declare(strict_types=1);

namespace MyAds\Plugins\LinkChecker;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqScannerService
{
    private string $apiKey;
    private string $model = 'llama3-8b-8192';

    public function __construct()
    {
        $this->apiKey = \App\Models\Option::where('name', 'lc_groq_api_key')->value('o_valuer') ?: '';
    }

    /**
     * Use Groq LLM to intelligently analyze if a URL is broken, parked, or malicious.
     *
     * @param string $url The URL to analyze
     * @return array{is_bad: bool, reason: string|null}
     */
    public function analyzeUrl(string $url): array
    {
        if (empty($this->apiKey)) {
            Log::warning('LinkChecker: GROQ_API_KEY is not set in settings');
            return ['is_bad' => false, 'reason' => null];
        }

        try {
            // First, fetch a small snippet of the page to give to the LLM
            $snippet = $this->fetchPageSnippet($url);
            
            if ($snippet === false) {
                return ['is_bad' => true, 'reason' => 'تعذر الاتصال بالرابط (مغلق أو لا يستجيب)'];
            }

            // Prepare prompt for Groq
            $prompt = "Analyze the following URL and its HTML snippet to determine if it is a broken link, parked domain, error page (like 404/500), or suspended account page.
URL: {$url}
HTML Snippet:
```html
{$snippet}
```

Answer ONLY with a JSON object in this format:
{\"is_bad\": true/false, \"reason\": \"Brief explanation in Arabic why it's bad, or null if it's good\"}";

            $response = Http::withToken($this->apiKey)
                ->timeout(15)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a smart link analyzer. You return strictly JSON output without any markdown wrapping or extra text.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.1,
                ]);

            if ($response->successful()) {
                $content = $response->json('choices.0.message.content');
                $result = json_decode($content, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($result['is_bad'])) {
                    return [
                        'is_bad' => (bool)$result['is_bad'],
                        'reason' => $result['is_bad'] ? ($result['reason'] ?? 'رابط معطوب أو مهجور بناءً على الفحص الذكي') : null,
                    ];
                }
            } else {
                Log::error('LinkChecker: Groq API Error', ['status' => $response->status(), 'body' => $response->body()]);
            }
        } catch (\Exception $e) {
            Log::error('LinkChecker: Groq Scanner Exception: ' . $e->getMessage());
        }

        return ['is_bad' => false, 'reason' => null];
    }

    /**
     * Fetch a small snippet of the HTML page (e.g., first 4KB) to avoid downloading huge files.
     */
    private function fetchPageSnippet(string $url): string|false
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'MYADS-SmartScanner/1.0',
            CURLOPT_RANGE => '0-4096', // Fetch only the first 4KB
        ]);

        $snippet = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Treat 4xx and 5xx as immediately bad without needing LLM
        if ($httpCode >= 400) {
            return false;
        }

        return $snippet !== false ? mb_substr(strip_tags($snippet, '<title><h1><h2><h3><p><div><span>'), 0, 1000) : false;
    }
}
