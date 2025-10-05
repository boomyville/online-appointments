<?php
session_start();
require_once 'config.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set JSON response header
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// CSRF protection - use the function from config.php
function validateCSRF() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateCSRFToken();
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!validateCSRFToken($token)) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF token mismatch']);
            exit;
        }
    }
}

// Check if user is admin
function isAdmin() {
    return isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin';
}

// Use the $pdo connection from config.php instead of creating a new one
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_timeline':
            getTimeline();
            break;
        case 'get_appointments':
            getAppointments();
            break;
        case 'book_appointment':
            validateCSRF();
            bookAppointment();
            break;
        case 'cancel_appointment':
            validateCSRF();
            cancelAppointment();
            break;
        case 'get_blocked_times':
            getBlockedTimes();
            break;
        case 'block_time':
            if (!isAdmin()) {
                throw new Exception('Admin access required');
            }
            validateCSRF();
            blockTime();
            break;
        case 'unblock_time':
            if (!isAdmin()) {
                throw new Exception('Admin access required');
            }
            validateCSRF();
            unblockTime();
            break;
        case 'get_csrf_token':
            echo json_encode(['token' => generateCSRFToken()]);
            break;
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

function getTimeline() {
    global $pdo;
    
    $date = $_GET['date'] ?? date('Y-m-d');
    
    // Use validation function from config.php
    if (!validateDate($date)) {
        throw new Exception('Invalid date format');
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            TIME_FORMAT(appointment_time, '%H:%i') as time,
            'booked' as status,
            student_name,
            student_email,
            appointment_id
        FROM appointments 
        WHERE appointment_date = ? AND status = 'confirmed'
        
        UNION ALL
        
        SELECT 
            TIME_FORMAT(start_time, '%H:%i') as time,
            'blocked' as status,
            reason as student_name,
            '' as student_email,
            block_id as appointment_id
        FROM blocked_times 
        WHERE block_date = ?
        
        ORDER BY time
    ");
    
    $stmt->execute([$date, $date]);
    $timeline = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['timeline' => $timeline]);
}

function getAppointments() {
    global $pdo;
    
    $date = $_GET['date'] ?? date('Y-m-d');
    
    if (!validateDate($date)) {
        throw new Exception('Invalid date format');
    }
    
    $stmt = $pdo->prepare("
        SELECT * FROM appointments 
        WHERE appointment_date = ? 
        ORDER BY appointment_time
    ");
    
    $stmt->execute([$date]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['appointments' => $appointments]);
}

function bookAppointment() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        throw new Exception('Invalid JSON data');
    }
    
    // Validate required fields
    $required = ['student_name', 'student_email', 'appointment_date', 'appointment_time'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    // Sanitize and validate input
    $student_name = sanitizeInput($data['student_name']);
    $student_email = sanitizeInput($data['student_email']);
    $appointment_date = $data['appointment_date'];
    $appointment_time = $data['appointment_time'];
    
    if (!validateEmail($student_email)) {
        throw new Exception('Invalid email format');
    }
    
    if (!validateDate($appointment_date)) {
        throw new Exception('Invalid date format');
    }
    
    if (!validateTime($appointment_time)) {
        throw new Exception('Invalid time format');
    }
    
    // Check if time slot is available
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM appointments 
        WHERE appointment_date = ? AND appointment_time = ? AND status = 'confirmed'
    ");
    $stmt->execute([$appointment_date, $appointment_time]);
    
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('Time slot already booked');
    }
    
    // Check if time is blocked
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM blocked_times 
        WHERE block_date = ? AND start_time <= ? AND end_time > ?
    ");
    $stmt->execute([$appointment_date, $appointment_time, $appointment_time]);
    
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('Time slot is blocked');
    }
    
    // Insert appointment
    $stmt = $pdo->prepare("
        INSERT INTO appointments (student_name, student_email, appointment_date, appointment_time, status, created_at)
        VALUES (?, ?, ?, ?, 'confirmed', NOW())
    ");
    
    $stmt->execute([
        $student_name,
        $student_email,
        $appointment_date,
        $appointment_time
    ]);
    
    echo json_encode(['success' => true, 'appointment_id' => $pdo->lastInsertId()]);
}

function cancelAppointment() {
    global $pdo;
    
    $appointment_id = $_POST['appointment_id'] ?? '';
    
    if (empty($appointment_id) || !is_numeric($appointment_id)) {
        throw new Exception('Missing or invalid appointment ID');
    }
    
    $stmt = $pdo->prepare("UPDATE appointments SET status = 'cancelled' WHERE appointment_id = ?");
    $stmt->execute([$appointment_id]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Appointment not found');
    }
    
    echo json_encode(['success' => true]);
}

function getBlockedTimes() {
    global $pdo;
    
    $date = $_GET['date'] ?? date('Y-m-d');
    
    if (!validateDate($date)) {
        throw new Exception('Invalid date format');
    }
    
    $stmt = $pdo->prepare("SELECT * FROM blocked_times WHERE block_date = ? ORDER BY start_time");
    $stmt->execute([$date]);
    $blocked = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['blocked_times' => $blocked]);
}

function blockTime() {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        throw new Exception('Invalid JSON data');
    }
    
    $required = ['block_date', 'start_time', 'end_time'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    if (!validateDate($data['block_date'])) {
        throw new Exception('Invalid date format');
    }
    
    if (!validateTime($data['start_time']) || !validateTime($data['end_time'])) {
        throw new Exception('Invalid time format');
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO blocked_times (block_date, start_time, end_time, reason, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $data['block_date'],
        $data['start_time'],
        $data['end_time'],
        sanitizeInput($data['reason'] ?? 'Admin blocked')
    ]);
    
    echo json_encode(['success' => true]);
}

function unblockTime() {
    global $pdo;
    
    $block_id = $_POST['block_id'] ?? '';
    
    if (empty($block_id) || !is_numeric($block_id)) {
        throw new Exception('Missing or invalid block ID');
    }
    
    $stmt = $pdo->prepare("DELETE FROM blocked_times WHERE block_id = ?");
    $stmt->execute([$block_id]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Blocked time not found');
    }
    
    echo json_encode(['success' => true]);
}
?>