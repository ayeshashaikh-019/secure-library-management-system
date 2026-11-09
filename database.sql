-- Secure Library Management System
-- Import this file in phpMyAdmin or run it from the MySQL command line.

CREATE DATABASE IF NOT EXISTS secure_library
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE secure_library;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS borrowings;
DROP TABLE IF EXISTS books;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('student','librarian','admin') NOT NULL DEFAULT 'student',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role_status (role, status)
) ENGINE=InnoDB;

CREATE TABLE categories (
    category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE books (
    book_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    author VARCHAR(140) NOT NULL,
    ISBN VARCHAR(30) NOT NULL UNIQUE,
    category_id INT UNSIGNED NOT NULL,
    total_copies INT UNSIGNED NOT NULL DEFAULT 1,
    available_copies INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_books_category FOREIGN KEY (category_id)
        REFERENCES categories(category_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_book_copies CHECK (available_copies <= total_copies),
    INDEX idx_books_title (title),
    INDEX idx_books_author (author),
    INDEX idx_books_category (category_id)
) ENGINE=InnoDB;

CREATE TABLE borrowings (
    borrowing_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    book_id INT UNSIGNED NOT NULL,
    issue_date DATE NULL,
    due_date DATE NULL,
    return_date DATE NULL,
    status ENUM('pending','borrowed','return_requested','returned','rejected') NOT NULL DEFAULT 'pending',
    processed_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_borrowings_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_borrowings_book FOREIGN KEY (book_id)
        REFERENCES books(book_id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_borrowings_processed_by FOREIGN KEY (processed_by)
        REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_borrowings_user_status (user_id, status),
    INDEX idx_borrowings_book_status (book_id, status),
    INDEX idx_borrowings_due_date (due_date),
    INDEX idx_borrowings_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
    log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NOT NULL DEFAULT 'unknown',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_activity_created (created_at),
    INDEX idx_activity_action (action)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    attempt_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 1,
    last_attempt DATETIME NOT NULL,
    UNIQUE KEY uq_login_email_ip (email, ip_address),
    INDEX idx_login_last_attempt (last_attempt)
) ENGINE=InnoDB;

CREATE TABLE system_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    description VARCHAR(255) NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by)
        REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- Starter categories.
INSERT INTO categories (category_name) VALUES
('Computer Science'),
('Information Security'),
('Fiction'),
('Science'),
('Mathematics'),
('History'),
('Business'),
('Literature'),
('Self Development'),
('Reference');

-- Demo accounts. Change these passwords after your first sign-in.
-- admin@library.local     / Admin@123
-- librarian@library.local / Librarian@123
-- student@library.local   / Student@123
INSERT INTO users (name, email, password, role, status) VALUES
('System Administrator', 'admin@library.local', '$2y$12$jAvkw.jD2/WbTYQ/sItXeuCVzqhzrIrb.Zpx3CGgCsL1Uo.r1RUJu', 'admin', 'active'),
('Library Librarian', 'librarian@library.local', '$2y$12$KU9oESQvsvq1dQBUAWq36e0Sa2xzpLHsRpkl9pu7pAkttFNhg8Cd2', 'librarian', 'active'),
('Demo Student', 'student@library.local', '$2y$12$n6giASypHSJxKl7g5HVcieAKm.tvSbyu9BX20RLMTV/HLFOmM3n9i', 'student', 'active');

-- Sample catalogue records; you can edit or delete them after setup.
INSERT INTO books (title, author, ISBN, category_id, total_copies, available_copies) VALUES
('Clean Code', 'Robert C. Martin', '9780132350884', 1, 4, 4),
('The Web Application Hacker''s Handbook', 'Dafydd Stuttard', '9781118026472', 2, 3, 3),
('Introduction to Algorithms', 'Thomas H. Cormen', '9780262046305', 1, 5, 5),
('The Pragmatic Programmer', 'David Thomas and Andrew Hunt', '9780135957059', 1, 3, 3),
('The Alchemist', 'Paulo Coelho', '9780061122415', 3, 4, 4),
('A Brief History of Time', 'Stephen Hawking', '9780553380163', 4, 2, 2),
('Atomic Habits', 'James Clear', '9780735211292', 9, 4, 4),
('The Lean Startup', 'Eric Ries', '9780307887894', 7, 2, 2),
('Discrete Mathematics and Its Applications', 'Kenneth H. Rosen', '9780073383095', 5, 3, 3),
('The Great Gatsby', 'F. Scott Fitzgerald', '9780743273565', 8, 3, 3),
('Pakistan: A Hard Country', 'Anatol Lieven', '9780141040757', 6, 2, 2),
('Oxford English Dictionary: Concise', 'Oxford Languages', '9780198614385', 10, 1, 1);

INSERT INTO system_settings (setting_key, setting_value, description, updated_by) VALUES
('max_active_loans', '3', 'Maximum pending or active loans allowed for one student.', 1),
('loan_period_days', '14', 'Number of days allowed for an issued book before its due date.', 1);

-- Initial administrator activity entry.
INSERT INTO activity_logs (user_id, action, details, ip_address)
VALUES (1, 'SYSTEM_INITIALIZED', 'Secure Library database created and demo records loaded.', '127.0.0.1');
