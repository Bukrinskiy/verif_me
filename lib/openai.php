<?php

declare(strict_types=1);

function analyzeTextWithAI(string $text): array
{
    $config = require __DIR__ . '/../config.php';

    $apiKey = $config['openai_api_key'] ?? '';
    if (!is_string($apiKey) || $apiKey === '') {
        throw new RuntimeException('OpenAI API key is not set in config.php');
    }

    $systemPrompt = <<<'PROMPT'
"Ты — AI-детектор лжи.
Твоя задача — анализировать текст пользователя и возвращать ТОЛЬКО JSON строго следующего формата:

{
  \"verdict\": \"скорее ложь\" | \"скорее правда\",
  \"score\": число от 1 до 100,
  \"signals\": массив строк с выявленными признаками,
  \"summary\": краткое объяснение вывода
}

Интерпретация score:
- 1–20: высокая вероятность правды
- 21–40: скорее правда
- 41–60: сомнительно
- 61–80: скорее ложь
- 81–100: высокая вероятность лжи

Правила:
- Никакого текста вне JSON
- Никакого markdown
- Никаких пояснений
- Если данных недостаточно — score = 50 и verdict = \"скорее правда\""
PROMPT;

    $timeout = (int) ($config['openai_timeout_sec'] ?? 30);

    $payload = [
        'model' => 'gpt-4o-mini',
        'input' => [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => $text,
            ],
        ],
        'temperature' => 0.2,
        'text' => [
            'format' => [
                'type' => 'json_object',
            ],
        ],
    ];

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException($error);
    }

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        $snippet = mb_substr((string) $response, 0, 500);
        throw new RuntimeException(sprintf('OpenAI HTTP %d: %s', $httpCode, $snippet));
    }

    $data = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);

    if (isset($data['output_text']) && is_string($data['output_text'])) {
        return ['raw_json' => $data['output_text']];
    }

    $fallbackText = $data['output'][0]['content'][0]['text'] ?? null;
    if (is_string($fallbackText)) {
        return ['raw_json' => $fallbackText];
    }

    throw new RuntimeException('OpenAI response has no text output');
}
