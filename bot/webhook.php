<?php

declare(strict_types=1);

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo 'Missing bot config.';
    exit;
}

$config = require $configPath;
if (!is_array($config)) {
    http_response_code(500);
    echo 'Invalid bot config.';
    exit;
}

$webhookSecret = $config['webhook_secret'] ?? '';
if (is_string($webhookSecret) && $webhookSecret !== '') {
    $incomingSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!is_string($incomingSecret) || !hash_equals($webhookSecret, $incomingSecret)) {
        http_response_code(403);
        echo 'Forbidden.';
        exit;
    }
}

$rawInput = file_get_contents('php://input');
$update = json_decode($rawInput ?: '', true);

if (!is_array($update)) {
    http_response_code(400);
    echo 'Invalid update.';
    exit;
}

$message = $update['message'] ?? null;
if (!is_array($message)) {
    http_response_code(200);
    echo 'No message.';
    exit;
}

$chatId = $message['chat']['id'] ?? null;
$telegramUserId = $message['from']['id'] ?? null;

if (!is_int($chatId) && !is_string($chatId)) {
    http_response_code(200);
    echo 'No chat.';
    exit;
}

if (!is_int($telegramUserId)) {
    tgApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => 'Ошибка обработки, попробуй ещё раз',
    ], $config);
    exit;
}

$text = $message['text'] ?? null;
if (!is_string($text)) {
    tgApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => 'Пока понимаю только текст',
    ], $config);
    exit;
}

try {
    $analysis = callAnalyze($telegramUserId, $text, $config);

    if ($analysis === null) {
        throw new RuntimeException('Analyze failed.');
    }

    $lines = [
        'Вердикт: ' . $analysis['verdict'],
        'Скор: ' . $analysis['score'] . '/100',
        'Коротко: ' . $analysis['summary'],
    ];

    if (!empty($analysis['signals'])) {
        $lines[] = 'Сигналы: ' . implode(', ', $analysis['signals']);
    }

    tgApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => implode("\n", $lines),
    ], $config);
} catch (Throwable $exception) {
    tgApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => 'Ошибка обработки, попробуй ещё раз',
    ], $config);
}

function tgApi(string $method, array $payload, array $config): ?array
{
    $token = $config['bot_token'] ?? '';
    if (!is_string($token) || $token === '') {
        return null;
    }

    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if ($body === false) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return null;
    }

    return $decoded;
}

function callAnalyze(int $telegramUserId, string $text, array $config): ?array
{
    $apiBaseUrl = $config['api_base_url'] ?? '';
    if (!is_string($apiBaseUrl) || trim($apiBaseUrl) === '') {
        return null;
    }

    $endpoint = rtrim($apiBaseUrl, '/') . '/api/analyze.php';
    $payload = json_encode([
        'telegram_user_id' => $telegramUserId,
        'text' => $text,
    ], JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        return null;
    }

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return null;
    }

    $analysis = $decoded['analysis'] ?? $decoded;
    if (!is_array($analysis)) {
        return null;
    }

    $verdict = $analysis['verdict'] ?? null;
    $score = $analysis['score'] ?? null;
    $signals = $analysis['signals'] ?? [];
    $summary = $analysis['summary'] ?? null;

    if (!is_string($verdict) || !is_int($score) || !is_string($summary)) {
        return null;
    }

    if (!is_array($signals)) {
        return null;
    }

    $signals = array_values(array_filter($signals, static fn ($item) => is_string($item) && trim($item) !== ''));

    return [
        'verdict' => $verdict,
        'score' => $score,
        'signals' => $signals,
        'summary' => $summary,
    ];
}
