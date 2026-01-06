<?php

declare(strict_types=1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'error' => 'Method not allowed. Use POST.',
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

try {
    $dialogId = null;
    $pdo = null;

    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput ?: '', true, 512, JSON_THROW_ON_ERROR);

    $telegramUserId = $payload['telegram_user_id'] ?? null;
    $text = $payload['text'] ?? null;

    if (!is_int($telegramUserId)) {
        throw new InvalidArgumentException('telegram_user_id must be an integer.');
    }

    if (!is_string($text) || trim($text) === '') {
        throw new InvalidArgumentException('text must be a non-empty string.');
    }

    require __DIR__ . '/../db.php';
    require __DIR__ . '/../lib/db_functions.php';
    require __DIR__ . '/../lib/schema_bootstrap.php';
    require __DIR__ . '/../lib/openai.php';

    $pdo = getPdo();
    ensureSchema($pdo);

    $dialogId = createDialog($pdo, $telegramUserId);
    addMessage($pdo, $dialogId, 'user', $text);
    $analysisResponse = analyzeTextWithAI($text);

    $rawJson = $analysisResponse['raw_json'] ?? null;
    if (!is_string($rawJson) || trim($rawJson) === '') {
        throw new RuntimeException('OpenAI returned an empty response.');
    }

    addMessage($pdo, $dialogId, 'assistant', $rawJson);

    $analysis = json_decode($rawJson, true);
    if (!is_array($analysis) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('OpenAI returned invalid JSON.');
    }

    $verdict = $analysis['verdict'] ?? null;
    $score = $analysis['score'] ?? null;
    $signals = $analysis['signals'] ?? null;
    $summary = $analysis['summary'] ?? null;

    $validVerdicts = ['скорее ложь', 'скорее правда', 'не удалось проанализировать'];
    $signalsValid = is_array($signals) && array_reduce(
        $signals,
        static fn ($carry, $item) => $carry && is_string($item),
        true
    );

    if (!in_array($verdict, $validVerdicts, true)
        || !is_int($score)
        || $score < 1
        || $score > 100
        || !$signalsValid
        || !is_string($summary)
        || trim($summary) === '') {
        throw new RuntimeException('OpenAI returned an invalid analysis payload.');
    }

    finishDialog($pdo, $dialogId, 'done');

    $formattedAnswer = formatEmojiAnswer($verdict, $score, $signals, $summary);

    echo json_encode([
        'dialog_id' => $dialogId,
        'analysis' => [
            'verdict' => $verdict,
            'score' => $score,
            'signals' => $signals,
            'summary' => $summary,
        ],
        'answer' => $formattedAnswer,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (JsonException | InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode([
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($dialogId !== null && $pdo instanceof PDO) {
        try {
            finishDialog($pdo, $dialogId, 'error');
        } catch (Throwable $finishException) {
        }
    }

    http_response_code(500);
    echo json_encode([
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function formatEmojiAnswer(string $verdict, int $score, array $signals, string $summary): string
{
    $score = max(1, min(100, $score));
    $signals = array_values(array_filter($signals, static fn ($item) => is_string($item) && trim($item) !== ''));
    $signals = array_slice($signals, 0, 6);

    $lines = [];

    if ($verdict === 'не удалось проанализировать') {
        $lines[] = '⚠️ <b>Не удалось проанализировать</b>';
    } else {
        $lines[] = '🧠 <b>Вердикт:</b> ' . htmlspecialchars($verdict, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines[] = '📊 <b>Скор:</b> ' . $score . ' / 100';
    }

    if ($signals !== []) {
        $lines[] = '';
        $lines[] = '❗ <b>Ключевые признаки:</b>';
        foreach ($signals as $signal) {
            $lines[] = '• ' . htmlspecialchars($signal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    $lines[] = '';
    $lines[] = '📝 <b>Пояснение:</b>';
    $lines[] = htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return implode("\n", $lines);
}
