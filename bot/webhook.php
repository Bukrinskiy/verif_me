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

$rootConfig = [];
$rootConfigPath = dirname(__DIR__) . '/config.php';
if (file_exists($rootConfigPath)) {
    $rootConfig = require $rootConfigPath;
    if (!is_array($rootConfig)) {
        $rootConfig = [];
    }
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
$voice = $message['voice'] ?? null;
$audio = $message['audio'] ?? null;
$welcomeText = $config['welcome_text'] ?? <<<TEXT
👋 Привет.

Я анализирую речь и показываю,
где слова звучат не так, как должны звучать при правде.

🧪 Я ищу:
— противоречия и логические разрывы
— признаки манипуляции
— нестыковки в подаче
— неуверенность в формулировках
— резкие смены уверенности
— дрожь и напряжение в голосе 🎤

📩 Отправь текст или голосовое сообщение —
и посмотри результат.
TEXT;

if (!is_string($text) && !is_array($voice) && !is_array($audio)) {
    tgApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => 'Пока понимаю только текст',
    ], $config);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/schema_bootstrap.php';

$pdo = null;
try {
    $pdo = getPdo();
    ensureSchema($pdo);
} catch (Throwable $exception) {
    tgApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => 'Ошибка обработки, попробуй ещё раз',
    ], $config);
    exit;
}

$lastWelcomed = fetchLastWelcomed($pdo, $telegramUserId);
$hasWelcomed = $lastWelcomed === 1;

if (is_string($text)) {
    $trimmedText = trim($text);
    if ($trimmedText === '') {
        tgApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => 'Пришлите аудио или текст для разбора',
        ], $config);
        exit;
    }

    if (preg_match('/^\\/start(\\s|$)/', $trimmedText) === 1) {
        $textToSend = $hasWelcomed ? 'Пришлите аудио или текст для разбора' : $welcomeText;
        tgApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => $textToSend,
        ], $config);
        exit;
    }

    if (!$hasWelcomed) {
        tgApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => $welcomeText,
        ], $config);
        $hasWelcomed = true;
    }

    try {
        $analysis = callAnalyze($telegramUserId, $text, $config);

        if ($analysis === null) {
            throw new RuntimeException('Analyze failed.');
        }

        $responseText = renderTelegramAnswer($analysis);

        tgApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => $responseText,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $config);
    } catch (Throwable $exception) {
        tgApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => 'Ошибка обработки, попробуй ещё раз',
        ], $config);
    }

    exit;
}

$audioPayload = is_array($voice) ? $voice : (is_array($audio) ? $audio : null);
$isVoice = is_array($voice);
$fileId = is_array($audioPayload) ? ($audioPayload['file_id'] ?? null) : null;
$fileSize = is_array($audioPayload) ? ($audioPayload['file_size'] ?? null) : null;
$maxVoiceBytes = $config['max_voice_bytes'] ?? 15000000;
if (!is_int($maxVoiceBytes) || $maxVoiceBytes <= 0) {
    $maxVoiceBytes = 15000000;
}

if (!is_string($fileId) || $fileId === '') {
    tgApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => 'Ошибка обработки, попробуй ещё раз',
    ], $config);
    exit;
}

if (is_int($fileSize) && $fileSize > $maxVoiceBytes) {
    tgApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => 'Слишком длинное аудио для MVP',
    ], $config);
    exit;
}

if (!$hasWelcomed) {
    tgApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => $welcomeText,
    ], $config);
    $hasWelcomed = true;
}

tgApi('sendMessage', [
    'chat_id' => $chatId,
    'text' => 'Распознаю аудио...',
], $config);

$tempFile = null;
try {
    $filePath = tgGetFilePath($fileId, $config);
    if ($filePath === null) {
        throw new RuntimeException('Missing file path.');
    }

    $tempFile = downloadTelegramFile($filePath, $isVoice, $config);
    if ($tempFile === null) {
        throw new RuntimeException('Download failed.');
    }

    $transcription = transcribeAudio($tempFile, $config, $rootConfig);
    if ($transcription === null) {
        throw new RuntimeException('Transcription failed.');
    }

    $transcription = trim($transcription);
    if ($transcription === '') {
        tgApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => 'Не смог распознать речь, попробуй ещё раз',
        ], $config);
        exit;
    }

    $analysis = callAnalyze($telegramUserId, $transcription, $config);
    if ($analysis === null) {
        throw new RuntimeException('Analyze failed.');
    }

    $responseText = renderTelegramAnswer($analysis, $transcription);

    tgApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => $responseText,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ], $config);
} catch (Throwable $exception) {
    tgApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => 'Ошибка обработки, попробуй ещё раз',
    ], $config);
} finally {
    if (is_string($tempFile) && $tempFile !== '' && file_exists($tempFile)) {
        unlink($tempFile);
    }
}

function renderTelegramAnswer(array $analysis, ?string $transcribedText = null): string
{
    $verdict = escapeTelegramHtml((string) ($analysis['verdict'] ?? ''));
    $summary = escapeTelegramHtml((string) ($analysis['summary'] ?? ''));
    $score = (int) ($analysis['score'] ?? 0);

    if ($score < 1) {
        $score = 1;
    } elseif ($score > 100) {
        $score = 100;
    }

    $signals = $analysis['signals'] ?? [];
    if (!is_array($signals)) {
        $signals = [];
    }

    $signals = array_values(array_filter($signals, static fn ($item) => is_string($item) && trim($item) !== ''));
    $signals = array_slice($signals, 0, 6);
    $escapedSignals = array_map(
        static fn (string $item) => escapeTelegramHtml($item),
        $signals
    );

    $lines = [];

    if (is_string($transcribedText)) {
        $snippet = makeSnippet($transcribedText, 200);
        $snippet = escapeTelegramHtml($snippet);
        $lines[] = '🎧 <i>Распознал:</i>';
        $lines[] = '“' . $snippet . '”';
        $lines[] = '';
    }

    if ($verdict === 'не удалось проанализировать') {
        $lines[] = '⚠️ <b>Не удалось проанализировать</b>';
    } else {
        $lines[] = '🧠 <b>Вердикт:</b> ' . $verdict;
        $lines[] = '📊 <b>Скор:</b> ' . $score . ' / 100';
    }

    if ($escapedSignals !== []) {
        $lines[] = '';
        $lines[] = '❗ <b>Ключевые признаки:</b>';
        foreach ($escapedSignals as $signal) {
            $lines[] = '• ' . $signal;
        }
    }

    $lines[] = '';
    $lines[] = '📝 <b>Пояснение:</b>';
    $lines[] = $summary;

    return implode("\n", $lines);
}

function escapeTelegramHtml(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

function tgGetFilePath(string $fileId, array $config): ?string
{
    $response = tgApi('getFile', ['file_id' => $fileId], $config);
    if (!is_array($response)) {
        return null;
    }

    $result = $response['result'] ?? null;
    if (!is_array($result)) {
        return null;
    }

    $filePath = $result['file_path'] ?? null;
    if (!is_string($filePath) || $filePath === '') {
        return null;
    }

    return $filePath;
}

function downloadTelegramFile(string $filePath, bool $isVoice, array $config): ?string
{
    $token = $config['bot_token'] ?? '';
    if (!is_string($token) || $token === '') {
        return null;
    }

    $extension = $isVoice ? 'ogg' : (pathinfo($filePath, PATHINFO_EXTENSION) ?: 'ogg');
    $tempBase = tempnam(sys_get_temp_dir(), 'tg_voice_');
    if ($tempBase === false) {
        return null;
    }

    $tempFile = $tempBase . '.' . $extension;
    rename($tempBase, $tempFile);

    $url = 'https://api.telegram.org/file/bot' . $token . '/' . ltrim($filePath, '/');
    $fileHandle = fopen($tempFile, 'wb');
    if ($fileHandle === false) {
        unlink($tempFile);
        return null;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FILE, $fileHandle);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $success = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fileHandle);

    if ($success === false || $httpCode < 200 || $httpCode >= 300) {
        unlink($tempFile);
        return null;
    }

    return $tempFile;
}

function transcribeAudio(string $filePath, array $config, array $rootConfig): ?string
{
    $openaiApiKey = $config['openai_api_key'] ?? ($rootConfig['openai_api_key'] ?? '');
    if (!is_string($openaiApiKey) || $openaiApiKey === '') {
        return null;
    }

    $model = $config['openai_transcribe_model'] ?? 'gpt-4o-mini-transcribe';
    $language = $config['openai_transcribe_language'] ?? 'ru';

    $fields = [
        'file' => curl_file_create($filePath),
        'model' => $model,
    ];

    if (is_string($language) && $language !== '') {
        $fields['language'] = $language;
    }

    $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $openaiApiKey,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_TIMEOUT, 40);

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

    $text = $decoded['text'] ?? null;
    if (!is_string($text)) {
        return null;
    }

    return $text;
}

function makeSnippet(string $text, int $limit): string
{
    $text = trim($text);
    if ($text === '' || $limit <= 0) {
        return '';
    }

    $length = mb_strlen($text, 'UTF-8');
    if ($length <= $limit) {
        return $text;
    }

    return mb_substr($text, 0, $limit, 'UTF-8') . '…';
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

function fetchLastWelcomed(PDO $pdo, int $telegramUserId): ?int
{
    $stmt = $pdo->prepare(
        'SELECT welcomed FROM dialogs WHERE telegram_user_id = :telegram_user_id ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([
        'telegram_user_id' => $telegramUserId,
    ]);

    $value = $stmt->fetchColumn();
    if ($value === false) {
        return null;
    }

    return (int) $value;
}
