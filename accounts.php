<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
header('Content-Type: application/json');

try {
    // DEBUG: Testing database connection
    $pdo->query('SELECT 1');
    // DEBUG: Database connection successful
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection error: ' . $e->getMessage()]);
    exit;
}

function admin_exists($pdo) {
    $stmt = $pdo->query('SELECT COUNT(*) FROM Admin');
    $count = $stmt->fetchColumn();
    return $count > 0;
}

// Get setting value from database
function get_setting($pdo, $key, $default = null) {
    $stmt = $pdo->prepare('SELECT value FROM Settings WHERE key_name = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : $default;
}

function validate_nonce($pdo, $nonce) {
    $stmt = $pdo->prepare('SELECT id FROM Nonces WHERE nonce = ? AND created_at >= (NOW() - INTERVAL 1 MINUTE)');
    $stmt->execute([$nonce]);
    $row = $stmt->fetch();
    if ($row) {
        // Delete nonce after use
        $del = $pdo->prepare('DELETE FROM Nonces WHERE id = ?');
        $del->execute([$row['id']]);
        return true;
    }
    return false;
}

function register_admin($pdo) {
    $nonce = $_POST['nonce'] ?? '';
    if (!validate_nonce($pdo, $nonce)) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired nonce.']);
        exit;
    }
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
if ($action === 'get_nonce') {
    // Generate a random nonce
    $nonce = bin2hex(random_bytes(16));
    // Store nonce in DB (user_id is NULL for registration)
    $stmt = $pdo->prepare('INSERT INTO Nonces (user_id, nonce, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))');
    $stmt->execute([null, $nonce]);
    echo json_encode(['nonce' => $nonce]);
    exit;
}
if ($action === 'login_admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare('SELECT username, email, hash, salt FROM Admin WHERE username = ?');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();
    $ip = get_client_ip();
    $status = 'fail';
    if ($admin && password_verify($password . $admin['salt'], $admin['hash'])) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION['admin'] = $admin['username'];
        $status = 'success';
        // Log successful login
        $log = $pdo->prepare('INSERT INTO Logins (ip, time, username, status) VALUES (?, NOW(), ?, ?)');
        $log->execute([$ip, $username, $status]);
        echo json_encode(['success' => true, 'name' => $admin['username']]);
    } else {
        // Log failed login
        $log = $pdo->prepare('INSERT INTO Logins (ip, time, username, status) VALUES (?, NOW(), ?, ?)');
        $log->execute([$ip, $username, $status]);
        // Count failed logins in past hour
        $failStmt = $pdo->prepare('SELECT COUNT(*) FROM Logins WHERE ip = ? AND status = "fail" AND time >= (NOW() - INTERVAL 1 HOUR)');
        $failStmt->execute([$ip]);
        $failCount = $failStmt->fetchColumn();
        $entriesLeft = max(0, $login_fails - $failCount);
        $msg = 'Invalid username or password.';
        if ($failCount > 4) {
            if ($entriesLeft === 0) {
                $msg .= ' You have 0 login attempts remaining.';
            } else {
                $msg .= ' You have ' . $entriesLeft . ' login attempts left.';
            }
        }
        echo json_encode(['success' => false, 'message' => $msg]);
    }
    exit;
}
if ($action === 'next_login_time') {
    $ip = get_client_ip();
    // Get timestamps of failed logins in the past hour
    $stmt = $pdo->prepare('SELECT time FROM Logins WHERE ip = ? AND status = "fail" AND time >= (NOW() - INTERVAL 1 HOUR) ORDER BY time ASC');
    $stmt->execute([$ip]);
    $failed = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($failed) >= $login_fails) {
        // Blocked: next login is 1 hour after the 7th most recent failed attempt
        $block_time = date('Y-m-d H:i:s', strtotime($failed[6]) + 3600);
        echo json_encode(['next_login' => $block_time]);
    } else {
        echo json_encode(['next_login' => null]);
    }
    exit;
}
if ($action === 'login_blocked') {
    $ip = get_client_ip();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM Logins WHERE ip = ? AND status = "fail" AND time >= (NOW() - INTERVAL 1 HOUR)');
    $stmt->execute([$ip]);
    $failCount = $stmt->fetchColumn();
    echo json_encode(['blocked' => ($failCount >= $login_fails)]);
    exit;
}
if ($action === 'session_active') {
    session_start();
    echo json_encode(['active' => isset($_SESSION['admin'])]);
    exit;
}
if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}
echo json_encode(['success' => false, 'message' => 'Invalid action.']);
exit;