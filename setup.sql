-- Database schema for Online Appointments System
-- This creates a unified appointment booking system

-- Admin users table (moved up to resolve foreign key dependency)
CREATE TABLE Admin (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    hash VARCHAR(255) NOT NULL,
    salt VARCHAR(255) NOT NULL,
    role ENUM('admin', 'super_admin') DEFAULT 'admin',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Users table for registered users
CREATE TABLE Users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    phone_number VARCHAR(15) CHECK (phone_number REGEXP '^04[0-9]{8}$'),
    email VARCHAR(100) UNIQUE NOT NULL CHECK (email REGEXP '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Main appointments table (simplified - removed student_name and student_email)
CREATE TABLE appointments (
    appointment_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL, -- Reference to Users table, NULL for available slots
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    status ENUM('available', 'confirmed', 'cancelled', 'blocked') DEFAULT 'available',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES Users(id) ON DELETE SET NULL,
    INDEX idx_date_time (appointment_date, appointment_time),
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    UNIQUE KEY unique_time_slot (appointment_date, appointment_time)
);

-- Security nonces for CSRF protection
CREATE TABLE Nonces (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT DEFAULT NULL,
    nonce VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (user_id) REFERENCES Users(id) ON DELETE CASCADE,
    INDEX idx_nonce (nonce),
    INDEX idx_expires (expires_at)
);

-- Login attempts tracking for security
CREATE TABLE Logins (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip VARCHAR(45) NOT NULL,
    time DATETIME NOT NULL,
    username VARCHAR(50),
    user_agent TEXT,
    status ENUM('success', 'fail', 'blocked') NOT NULL,
    INDEX idx_ip_time (ip, time),
    INDEX idx_username (username)
);

-- System settings
CREATE TABLE Settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    key_name VARCHAR(50) UNIQUE NOT NULL,
    value TEXT NOT NULL,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default settings
INSERT INTO Settings (key_name, value, description) VALUES 
('appointment_duration', '30', 'Default appointment duration in minutes'),
('login_duration', '86400', 'Login session duration in seconds'),
('daily_start_time', '09:00', 'Daily appointment start time'),
('daily_end_time', '17:00', 'Daily appointment end time'),
('lunch_start_time', '12:00', 'Lunch break start time'),
('lunch_end_time', '13:00', 'Lunch break end time'),
('max_appointments_per_day', '20', 'Maximum appointments per day'),
('booking_advance_days', '30', 'How many days in advance can appointments be booked'),
('cancellation_hours', '24', 'Minimum hours before appointment for cancellation');


