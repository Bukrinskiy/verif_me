CREATE TABLE dialogs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  telegram_user_id BIGINT NOT NULL,
  status ENUM('done', 'error') NOT NULL,
  welcomed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL
);

CREATE TABLE messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dialog_id INT NOT NULL,
  role ENUM('user', 'assistant', 'system') NOT NULL,
  content TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (dialog_id) REFERENCES dialogs(id)
);
