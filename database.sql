CREATE DATABASE IF NOT EXISTS plmun_emotion_ai;
USE plmun_emotion_ai;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  password VARCHAR(255) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(100),
  personal_email VARCHAR(100),
  contact_number VARCHAR(20),
  department VARCHAR(100),
  role ENUM('admin','instructor','student') NOT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNIQUE NOT NULL,
  student_number VARCHAR(20) UNIQUE NOT NULL,
  course VARCHAR(100),
  year_level VARCHAR(20),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE classes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  instructor_id INT NOT NULL,
  class_name VARCHAR(100) NOT NULL,
  class_code VARCHAR(20) UNIQUE NOT NULL,
  description TEXT,
  schedule DATETIME,
  max_students INT DEFAULT 30,
  emotion_tracking TINYINT(1) DEFAULT 1,
  auto_attendance TINYINT(1) DEFAULT 1,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE class_enrollments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  class_id INT NOT NULL,
  student_id INT NOT NULL,
  enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (class_id, student_id),
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE live_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  class_id INT NOT NULL,
  session_name VARCHAR(150),
  start_time DATETIME DEFAULT CURRENT_TIMESTAMP,
  end_time DATETIME,
  status ENUM('active','ended') DEFAULT 'active',
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);

CREATE TABLE live_session_participants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  user_id INT NOT NULL,
  user_role ENUM('instructor','student') NOT NULL,
  join_time DATETIME DEFAULT CURRENT_TIMESTAMP,
  leave_time DATETIME,
  camera_active TINYINT(1) DEFAULT 1,
  mic_active TINYINT(1) DEFAULT 1,
  is_active TINYINT(1) DEFAULT 1,
  UNIQUE (session_id, user_id),
  FOREIGN KEY (session_id) REFERENCES live_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE session_attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  student_id INT NOT NULL,
  join_time DATETIME DEFAULT CURRENT_TIMESTAMP,
  leave_time DATETIME,
  FOREIGN KEY (session_id) REFERENCES live_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE emotion_data (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  student_id INT NOT NULL,
  captured_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  facial_emotion ENUM('happy','sad','angry','bored','neutral') NOT NULL,
  confidence_score DECIMAL(5,2),
  engagement_level INT,
  FOREIGN KEY (session_id) REFERENCES live_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE session_engagement_summary (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  student_id INT NOT NULL,
  average_emotion VARCHAR(50),
  engagement_score INT,
  bored_percent DECIMAL(5,2),
  happy_percent DECIMAL(5,2),
  neutral_percent DECIMAL(5,2),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (session_id, student_id),
  FOREIGN KEY (session_id) REFERENCES live_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE chat_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  sender_id INT NOT NULL,
  sender_role ENUM('instructor','student') NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (session_id) REFERENCES live_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  class_id INT NOT NULL,
  teacher_id INT NOT NULL,
  title VARCHAR(150),
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE user_consent (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  consent_type ENUM('camera','microphone','emotion_detection') NOT NULL,
  consent_given TINYINT(1) DEFAULT 0,
  consent_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `user_role` enum('admin','instructor','student','system') DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `action_type` enum('login','logout','create','update','delete','view','settings_change','system_event','security_event') NOT NULL,
  `action_description` varchar(255) NOT NULL,
  `table_affected` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `additional_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`additional_data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action_type` (`action_type`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
);

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
);

--User name: admin---
--Password: password--
INSERT INTO users (username, password, full_name, email, role, is_active)
VALUES (
    'admin', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- example hashed password
    'System Administrator', 
    'admin@plmun.edu.ph', 
    'admin', 
    1
);

-- Ensure these columns exist in your database
ALTER TABLE live_sessions 
ADD COLUMN IF NOT EXISTS session_name VARCHAR(150) AFTER class_id,
ADD COLUMN IF NOT EXISTS class_code VARCHAR(20) AFTER class_id;

-- Add end_time to live_sessions if not exists
ALTER TABLE live_sessions 
ADD COLUMN IF NOT EXISTS end_time DATETIME AFTER start_time;

-- Add status to live_sessions if not exists
ALTER TABLE live_sessions 
ADD COLUMN IF NOT EXISTS status ENUM('active','ended') DEFAULT 'active' AFTER end_time;

-- Add update_at in announcement table
ALTER TABLE announcements
ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

-- Add this table to track which announcements students have read
CREATE TABLE IF NOT EXISTS announcement_reads (
    id INT(11) NOT NULL AUTO_INCREMENT,
    announcement_id INT(11) NOT NULL,
    student_id INT(11) NOT NULL,
    read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_announcement_student (announcement_id, student_id),
    KEY announcement_id (announcement_id),
    KEY student_id (student_id),
    CONSTRAINT announcement_reads_ibfk_1 FOREIGN KEY (announcement_id) REFERENCES announcements (id) ON DELETE CASCADE,
    CONSTRAINT announcement_reads_ibfk_2 FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


--------------------------
---SYSTEM SETTINGS NEW TABLE
-- System Settings Table
CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type VARCHAR(50) DEFAULT 'system',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_setting_key (setting_key),
    INDEX idx_setting_type (setting_type)
);

-- System Settings History Table (for audit purposes)
CREATE TABLE system_settings_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    old_value TEXT,
    new_value TEXT,
    changed_by INT NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reason VARCHAR(255),
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_setting_key (setting_key),
    INDEX idx_changed_at (changed_at),
    INDEX idx_changed_by (changed_by)
);

-- System Settings Categories Table (for organization)
CREATE TABLE system_settings_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL,
    category_description TEXT,
    display_order INT DEFAULT 0,
    icon_class VARCHAR(50),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_category_name (category_name),
    INDEX idx_display_order (display_order)
);

-- Add category reference to system_settings
ALTER TABLE system_settings 
ADD COLUMN category_id INT DEFAULT NULL,
ADD FOREIGN KEY (category_id) REFERENCES system_settings_categories(id) ON DELETE SET NULL;

-- User-specific Settings Table
CREATE TABLE user_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    setting_type VARCHAR(50) DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_setting (user_id, setting_key),
    INDEX idx_user_setting (user_id, setting_key)
);

-- System Settings Validation Rules
CREATE TABLE system_settings_validation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    validation_type VARCHAR(50) NOT NULL, -- 'boolean', 'integer', 'string', 'select', 'range'
    min_value INT,
    max_value INT,
    allowed_values TEXT, -- JSON array for select options
    default_value TEXT,
    is_required BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (setting_key) REFERENCES system_settings(setting_key) ON DELETE CASCADE,
    INDEX idx_setting_key (setting_key)
);

-- System Settings Dependencies
CREATE TABLE system_settings_dependencies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    depends_on VARCHAR(100) NOT NULL,
    dependency_type VARCHAR(50) NOT NULL, -- 'required', 'recommended', 'conflicts'
    condition_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (setting_key) REFERENCES system_settings(setting_key) ON DELETE CASCADE,
    FOREIGN KEY (depends_on) REFERENCES system_settings(setting_key) ON DELETE CASCADE,
    INDEX idx_setting_dependency (setting_key, depends_on)
);