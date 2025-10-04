<?php
require_once 'config.php';
header('Content-Type: application/json');

function admin_exists($pdo) {
    $stmt = $pdo->query('SELECT COUNT(*) FROM Admin');
    $count = $stmt->fetchColumn();
    return $count > 0;
}

function register_admin($pdo) {
    if (admin_exists($pdo)) {
        echo json_encode(['success' => false, 'message' => 'Admin already exists.']);
        exit;
    }
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? null;
    $password = $_POST['password'] ?? '';
    $repeat = $_POST['repeatPassword'] ?? '';
    if ($password !== $repeat) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        exit;
    }
    // Password requirements
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/', $password)) {
        echo json_encode(['success' => false, 'message' => 'Password does not meet requirements.']);
        exit;
    }
    // Generate random salt
    $salt = bin2hex(random_bytes(16));
    // Hash password with salt using bcrypt
    $hash = password_hash($password . $salt, PASSWORD_DEFAULT);
    // Save to DB
    $stmt = $pdo->prepare('INSERT INTO Admin (username, email, hash, salt) VALUES (?, ?, ?, ?)');
    $stmt->execute([$username, $email, $hash, $salt]);
    echo json_encode(['success' => true, 'message' => 'Admin registered successfully.']);
    exit;
}

$action = $_GET['action'] ?? '';
if ($action === 'admin_exists') {
    echo json_encode(['exists' => admin_exists($pdo)]);
    exit;
}
if ($action === 'register_admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    register_admin($pdo);
    exit;
}
echo json_encode(['success' => false, 'message' => 'Invalid action.']);
exit;