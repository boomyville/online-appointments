<?php
require_once 'config.php';
header('Content-Type: application/json');

// Get all appointments for a specific day (YYYY-MM-DD)
if (isset($_GET['action']) && $_GET['action'] === 'get_appointments' && isset($_GET['date'])) {
    $date = $_GET['date'];
    $stmt = $pdo->prepare('SELECT * FROM Appointments WHERE DATE(start_time) = ?');
    $stmt->execute([$date]);
    $appointments = $stmt->fetchAll();
    echo json_encode(['appointments' => $appointments]);
    exit;
}

// Get unavailable days in the next 120 days (no appointments)
if (isset($_GET['action']) && $_GET['action'] === 'get_unavailable_days') {
    $today = date('Y-m-d');
    $dates = [];
    for ($i = 0; $i < 120; $i++) {
        $d = date('Y-m-d', strtotime("$today +$i day"));
        $dates[] = $d;
    }
    $placeholders = implode(',', array_fill(0, count($dates), '?'));
    $stmt = $pdo->prepare("SELECT DATE(start_time) as day FROM Appointments WHERE DATE(start_time) IN ($placeholders)");
    $stmt->execute($dates);
    $available = array_column($stmt->fetchAll(), 'day');
    $unavailable = array_diff($dates, $available);
    echo json_encode(['unavailable' => array_values($unavailable)]);
    exit;
}

echo json_encode(['error' => 'Invalid action']);
exit;