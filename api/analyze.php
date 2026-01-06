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

    $pdo = getPdo();
    ensureSchema($pdo);

    $dialogId = createDialog($pdo, $telegramUserId);
    addMessage($pdo, $dialogId, 'user', $text);
    addMessage($pdo, $dialogId, 'assistant', 'ok');
    finishDialog($pdo, $dialogId, 'done');

    echo json_encode([
        'dialog_id' => $dialogId,
        'status' => 'done',
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (JsonException | InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode([
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
