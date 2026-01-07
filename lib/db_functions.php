<?php

declare(strict_types=1);

function createDialog(PDO $pdo, int $telegramUserId, int $welcomed = 0): int
{
    $welcomedValue = $welcomed === 1 ? 1 : 0;
    $stmt = $pdo->prepare(
        'INSERT INTO dialogs (telegram_user_id, status, welcomed) VALUES (:telegram_user_id, :status, :welcomed)'
    );
    $stmt->execute([
        'telegram_user_id' => $telegramUserId,
        'status' => 'error',
        'welcomed' => $welcomedValue,
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

function ensureSchema(PDO $pdo): void
{
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($database === '') {
        throw new RuntimeException('Database is not selected.');
    }

    $requiredTables = ['dialogs', 'messages'];
    $missingTables = [];

    $checkStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
    );

    foreach ($requiredTables as $table) {
        $checkStmt->execute([
            'schema' => $database,
            'table' => $table,
        ]);
        $exists = (int) $checkStmt->fetchColumn();
        if ($exists === 0) {
            $missingTables[] = $table;
        }
    }

    if ($missingTables !== []) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS dialogs (
              id INT AUTO_INCREMENT PRIMARY KEY,
              telegram_user_id BIGINT NOT NULL,
              status ENUM('done', 'error') NOT NULL,
              welcomed TINYINT(1) NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              finished_at DATETIME NULL
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS messages (
              id INT AUTO_INCREMENT PRIMARY KEY,
              dialog_id INT NOT NULL,
              role ENUM('user', 'assistant', 'system') NOT NULL,
              content TEXT NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (dialog_id) REFERENCES dialogs(id)
            )"
        );
    }

    $columnStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $columnStmt->execute([
        'schema' => $database,
        'table' => 'dialogs',
        'column' => 'welcomed',
    ]);
    $hasColumn = (int) $columnStmt->fetchColumn();
    if ($hasColumn === 0) {
        $pdo->exec('ALTER TABLE dialogs ADD COLUMN welcomed TINYINT(1) NOT NULL DEFAULT 0');
    }
}
