-- Task Tracker schema + sample data (MySQL 5.7+ / MariaDB 10.3+)
-- Import:  mysql -u root -p < database.sql
-- It creates the database `task_tracker`, so no manual CREATE DATABASE is needed.

CREATE DATABASE IF NOT EXISTS task_tracker
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE task_tracker;

DROP TABLE IF EXISTS tasks;
DROP TABLE IF EXISTS users;

-- Users: one row per account. Passwords are stored ONLY as password_hash() output.
CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(100)  NOT NULL,
  email         VARCHAR(190)  NOT NULL,
  password_hash VARCHAR(255)  NOT NULL,            -- bcrypt hash is 60 chars; 255 leaves room for future algorithms
  role          ENUM('user','admin') NOT NULL DEFAULT 'user',
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)                -- one account per email; also speeds up login lookup
) ENGINE=InnoDB;

-- Tasks: every task belongs to exactly one user (ownership = authorization basis).
CREATE TABLE tasks (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  title       VARCHAR(150) NOT NULL,
  description TEXT         NOT NULL,
  status      ENUM('Pending','In Progress','Completed') NOT NULL DEFAULT 'Pending',
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tasks_user_status (user_id, status),     -- dashboard: "my tasks" + status filter
  KEY idx_tasks_user_created (user_id, created_at),-- dashboard ordering
  CONSTRAINT fk_tasks_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Sample users (DEMO ONLY - change or delete before any real use)
--   admin@example.com / Admin@1234
--   alice@example.com / Alice@1234
--   bob@example.com   / Bob@12345
INSERT INTO users (name, email, password_hash, role) VALUES
  ('Admin', 'admin@example.com', '$2y$10$1v9LzgjOQooas.8Tuqfhb.Ls6sme8v3HQt.NYCty6gfybcaCd.Hv.', 'admin'),
  ('Alice', 'alice@example.com', '$2y$10$pn6LE5aC5lnYqDHSCj3viuB4K0bx6URWaINUIxGdCZ2jMbuF44nri', 'user'),
  ('Bob', 'bob@example.com', '$2y$10$sFGNkTN.2IPZ40xobCPXY.jnL6U1mb0OowECVamGL7qMU11.zHq3a', 'user');

INSERT INTO tasks (user_id, title, description, status) VALUES
  (2, 'Write project README',        'Setup steps, test logins and a short note on roles.', 'In Progress'),
  (2, 'Review pull request #12',     'Check validation and prepared statements.',           'Pending'),
  (2, 'Back up the database',        'Run mysqldump and test the restore.',                 'Completed'),
  (3, 'Plan sprint demo',            'Outline the demo flow and sample data.',              'Pending'),
  (3, 'Fix login redirect bug',      '',                                                    'Completed'),
  (1, 'Review user accounts',        'Admin task: check for unused accounts.',              'Pending');
