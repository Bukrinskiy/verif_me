<?php

declare(strict_types=1);

if (!function_exists('ensureSchema')) {
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

        if ($missingTables === []) {
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS dialogs (
              id INT AUTO_INCREMENT PRIMARY KEY,
              telegram_user_id BIGINT NOT NULL,
              status ENUM('done', 'error') NOT NULL,
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
}
