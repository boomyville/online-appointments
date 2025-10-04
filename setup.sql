CREATE TABLE Users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    phone_number VARCHAR(15) CHECK (phone_number REGEXP '^04[0-9]{8}$'),
    email VARCHAR(100) UNIQUE NOT NULL CHECK (email REGEXP '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$')
);

CREATE TABLE Appointments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    start_time DATETIME NOT NULL,
    duration INT NOT NULL CHECK (duration % 5 = 0),
    user_id INT,
    FOREIGN KEY (user_id) REFERENCES Users(id)
);

CREATE TABLE Nonces (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    nonce VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES Users(id)
);

CREATE TABLE Admin (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    hash VARCHAR(255) NOT NULL,
    salt VARCHAR(255) NOT NULL
);

CREATE TABLE Logins (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ip VARCHAR(45) NOT NULL,
    time DATETIME NOT NULL,
    username VARCHAR(50),
    status ENUM('success', 'fail') NOT NULL
);