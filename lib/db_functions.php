<?php

declare(strict_types=1);

function createDialog(PDO $pdo, int $telegramUserId): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO dialogs (telegram_user_id, status) VALUES (:telegram_user_id, :status)'
    );
    $stmt->execute([
        'telegram_user_id' => $telegramUserId,
        'status' => 'error',
    ]);

    return (int) $pdo->lastInsertId();
}

function addMessage(PDO $pdo, int $dialogId, string $role, string $content): int
{
    $allowedRoles = ['user', 'assistant', 'system'];
    if (!in_array($role, $allowedRoles, true)) {
        throw new InvalidArgumentException('Invalid role.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO messages (dialog_id, role, content) VALUES (:dialog_id, :role, :content)'
    );
    $stmt->execute([
        'dialog_id' => $dialogId,
        'role' => $role,
        'content' => $content,
    ]);

    return (int) $pdo->lastInsertId();
}

function finishDialog(PDO $pdo, int $dialogId, string $status): void
{
    $allowedStatuses = ['done', 'error'];
    if (!in_array($status, $allowedStatuses, true)) {
        throw new InvalidArgumentException('Invalid status.');
    }

    $stmt = $pdo->prepare(
        'UPDATE dialogs SET status = :status, finished_at = NOW() WHERE id = :id'
    );
    $stmt->execute([
        'status' => $status,
        'id' => $dialogId,
    ]);
}
