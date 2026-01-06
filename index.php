<?php

declare(strict_types=1);

header('Content-Type: application/json');

try {
    require __DIR__ . '/db.php';
    require __DIR__ . '/lib/db_functions.php';

    $pdo = getPdo();

    $telegramUserId = 123456;
    $userText = 'test message';

    $dialogId = createDialog($pdo, $telegramUserId);
    addMessage($pdo, $dialogId, 'user', $userText);
    addMessage($pdo, $dialogId, 'assistant', 'ok');
    finishDialog($pdo, $dialogId, 'done');

    echo json_encode([
        'dialog_id' => $dialogId,
        'status' => 'done',
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
