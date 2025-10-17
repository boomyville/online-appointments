<?php
    require_once 'config.php';
    header('Content-Type: application/json');

    // Get all appointments for a specific day (YYYY-MM-DD)
    if (isset($_GET['action']) && $_GET['action'] === 'get_appointments' && isset($_GET['date'])) {
        $date = $_GET['date'];
        $stmt = $pdo->prepare('
            SELECT a.*, u.first_name, u.last_name, u.phone_number, u.email as user_email 
            FROM appointments a 
            LEFT JOIN Users u ON a.user_id = u.id 
            WHERE a.appointment_date = ?
        ');
        $stmt->execute([$date]);
        $appointments = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['full_name'] = ($row['first_name'] && $row['last_name']) ? $row['first_name'] . ' ' . $row['last_name'] : null;
            $row['phone_number'] = $row['phone_number'] ?? '';
            $row['time'] = $row['appointment_time'];
            $row['id'] = $row['appointment_id'];
            $row['duration'] = 30; // Default duration since not stored in schema
            $row['customer_name'] = $row['full_name'];
            $row['customer_email'] = $row['user_email'];
            $appointments[] = $row;
        }
        echo json_encode(['appointments' => $appointments]);
        exit;
    }

    // Get unavailable days in the next 120 days (no appointments)
    if (isset($_GET['action']) && $_GET['action'] === 'get_unavailable_days') {
        $today = get_server_date();
        $dates = [];
        // Include past 30 days and future 120 days for complete view
        for ($i = -30; $i < 120; $i++) {
            $d = date('Y-m-d', strtotime("$today +$i day"));
            $dates[] = $d;
        }
        
        // Debug: Check date range
        error_log("Date range: " . min($dates) . " to " . max($dates) . " (total: " . count($dates) . " dates)");
        
        $result = [];
        foreach ($dates as $date) {
            $stmt = $pdo->prepare('SELECT status FROM appointments WHERE appointment_date = ? ORDER BY appointment_time ASC');
            $stmt->execute([$date]);
            $appts = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Debug: log what we found for past days
            if ($date < $today && count($appts) > 0) {
                error_log("Past date $date has " . count($appts) . " appointments: " . implode(', ', $appts));
            }
            
            if (count($appts) === 0) {
                $result[$date] = [
                    'status' => 'none',
                    'appointments' => []
                ]; // No appointments at all
            } else {
                // Only count bookable appointments (confirmed + available), ignore blocked
                $bookableAppts = array_filter($appts, function($status) {
                    return $status === 'confirmed' || $status === 'available';
                });
                
                $confirmedCount = count(array_filter($appts, function($status){return $status === 'confirmed';}));
                $bookableCount = count($bookableAppts);
                
                $status = 'none';
                if ($bookableCount === 0) {
                    // No bookable appointments (all blocked or other statuses)
                    $status = 'none';
                } else if ($confirmedCount === 0) {
                    $status = 'all_available'; // All bookable appointments are available
                } else if ($confirmedCount === $bookableCount) {
                    $status = 'all_booked'; // All bookable appointments are booked
                } else {
                    $status = 'some_booked'; // Some bookable appointments are booked
                }
                
                $result[$date] = [
                    'status' => $status,
                    'appointments' => $appts
                ];
            }
        }
        echo json_encode(['days' => $result]);
        exit;
    }

    // Get current server date endpoint
    if (isset($_GET['action']) && $_GET['action'] === 'get_server_date') {
        echo json_encode(['date' => get_server_date(), 'time' => get_server_time()]);
        exit;
    }

    // Get settings endpoint
    if (isset($_GET['action']) && $_GET['action'] === 'get_settings') {
        $stmt = $pdo->prepare('SELECT key_name, value FROM Settings');
        $stmt->execute();
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['key_name']] = $row['value'];
        }
        echo json_encode(['settings' => $settings]);
        exit;
    }

    // Get specific setting endpoint
    if (isset($_GET['action']) && $_GET['action'] === 'get_setting' && isset($_GET['key'])) {
        $key = $_GET['key'];
        $stmt = $pdo->prepare('SELECT value FROM Settings WHERE key_name = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $value = $row ? $row['value'] : null;
        echo json_encode(['key' => $key, 'value' => $value]);
        exit;
    }

    // Add a new appointment
    if (isset($_GET['action']) && $_GET['action'] === 'add_appointment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $date = $_POST['date'] ?? '';
        $time = $_POST['time'] ?? '';
        $duration = intval($_POST['duration'] ?? 30);
        // Validate date and time
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
            exit;
        }
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            echo json_encode(['success' => false, 'message' => 'Invalid time format.']);
            exit;
        }
        if ($duration < 1 || $duration > 120) {
            echo json_encode(['success' => false, 'message' => 'Duration must be between 1 and 120 minutes.']);
            exit;
        }
        
        // Check for overlaps with existing appointments
        $stmt = $pdo->prepare('SELECT * FROM appointments WHERE appointment_date = ? AND appointment_time = ?');
        $stmt->execute([$date, $time]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Time slot already exists.']);
            exit;
        }
        
        // Add appointment (create as available slot)
        $stmt = $pdo->prepare('INSERT INTO appointments (user_id, appointment_date, appointment_time, status, notes) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([null, $date, $time, 'available', "Duration: {$duration} minutes"]);
        echo json_encode(['success' => true]);
        exit;
    }

    // Delete an appointment
    if (isset($_GET['action']) && $_GET['action'] === 'delete_appointment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['appointment_id'] ?? $_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM appointments WHERE appointment_id = ?');
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid appointment ID.']);
        }
        exit;
    }

    // Delete all appointments for a specific date
    if (isset($_GET['action']) && $_GET['action'] === 'delete_all_appointments' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $date = $_POST['date'] ?? '';
        
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare('DELETE FROM appointments WHERE appointment_date = ?');
            $stmt->execute([$date]);
            $deletedCount = $stmt->rowCount();
            
            echo json_encode([
                'success' => true, 
                'message' => "Deleted $deletedCount appointments",
                'deleted_count' => $deletedCount
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error deleting appointments: ' . $e->getMessage()]);
        }
        exit;
    }

    // Remove user from an appointment (make it available again)
    if (isset($_GET['action']) && $_GET['action'] === 'remove_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE appointments SET user_id = NULL, status = ?, notes = ? WHERE appointment_id = ?');
            $stmt->execute(['available', 'Slot made available', $id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid appointment ID.']);
        }
        exit;
    }

    // Cancel a user from an appointment
    if (isset($_GET['action']) && $_GET['action'] === 'cancel_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $id = intval($_POST['id'] ?? 0);
            if ($id > 0) {
                // Set appointment status to available and remove user association
                $stmt = $pdo->prepare('UPDATE appointments SET status = ?, user_id = NULL WHERE appointment_id = ?');
                $stmt->execute(['available', $id]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid appointment ID.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Search for a user
    if (isset($_GET['action']) && $_GET['action'] === 'search_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $mobile = $_POST['mobile'] ?? '';
        $email = $_POST['email'] ?? '';
        $query = [];
        $params = [];
        if ($mobile) {
            $query[] = 'phone_number = ?';
            $params[] = $mobile;
        }
        if ($email) {
            $query[] = 'email = ?';
            $params[] = $email;
        }
        if ($query) {
            $sql = 'SELECT id, first_name, last_name, phone_number, email FROM Users WHERE ' . implode(' OR ', $query);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll();
            echo json_encode(['users' => $users]);
        } else {
            echo json_encode(['users' => []]);
        }
        exit;
    }

    // Book an appointment with an existing user
    if (isset($_GET['action']) && $_GET['action'] === 'book_appointment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        $user_id = intval($_POST['user_id'] ?? 0);
        if ($id > 0 && $user_id > 0) {
            try {
                $stmt = $pdo->prepare('UPDATE appointments SET user_id = ?, status = ? WHERE appointment_id = ?');
                $stmt->execute([$user_id, 'confirmed', $id]);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error booking appointment: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid appointment or user ID.']);
        }
        exit;
    }

    // Create a new user and book an appointment
    if (isset($_GET['action']) && $_GET['action'] === 'create_user_and_book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $mobile = $_POST['mobile'] ?? '';
        $email = $_POST['email'] ?? '';
        
        if (!$mobile && !$email) {
            echo json_encode(['success' => false, 'message' => 'Mobile or email required.']);
            exit;
        }
        
        // Require at least first name if creating new user
        if (empty($first_name)) {
            echo json_encode(['success' => false, 'message' => 'First name is required when creating a new user.']);
            exit;
        }
        
        try {
            // Check for existing users with same email or mobile
            $existingUsers = [];
            
            if ($email) {
                $stmt = $pdo->prepare('SELECT id, first_name, last_name, phone_number, email FROM Users WHERE email = ?');
                $stmt->execute([$email]);
                $emailUsers = $stmt->fetchAll();
                foreach ($emailUsers as $user) {
                    $existingUsers[] = $user;
                }
            }
            
            if ($mobile) {
                $stmt = $pdo->prepare('SELECT id, first_name, last_name, phone_number, email FROM Users WHERE phone_number = ?');
                $stmt->execute([$mobile]);
                $phoneUsers = $stmt->fetchAll();
                foreach ($phoneUsers as $user) {
                    // Avoid duplicates if user has same email and phone
                    $alreadyAdded = false;
                    foreach ($existingUsers as $existing) {
                        if ($existing['id'] == $user['id']) {
                            $alreadyAdded = true;
                            break;
                        }
                    }
                    if (!$alreadyAdded) {
                        $existingUsers[] = $user;
                    }
                }
            }
            
            // If users exist, check if we can create a new one (different name) or need to show options
            if (!empty($existingUsers)) {
                $sameNameExists = false;
                foreach ($existingUsers as $user) {
                    if (strtolower(trim($user['first_name'])) === strtolower($first_name) && 
                        strtolower(trim($user['last_name'])) === strtolower($last_name)) {
                        $sameNameExists = true;
                        break;
                    }
                }
                
                // If same name exists, we cannot create a new user - show existing users
                if ($sameNameExists) {
                    echo json_encode([
                        'success' => false,
                        'conflict' => true,
                        'existing_users' => $existingUsers,
                        'message' => 'A user with the same contact details and name already exists.'
                    ]);
                    exit;
                }
                
                // Different name exists - give option to create new or use existing
                echo json_encode([
                    'success' => false,
                    'conflict' => true,
                    'existing_users' => $existingUsers,
                    'can_create_new' => true,
                    'new_user_details' => [
                        'first_name' => $first_name,
                        'last_name' => $last_name,
                        'mobile' => $mobile,
                        'email' => $email
                    ],
                    'message' => 'Users with this contact information already exist, but with different names.'
                ]);
                exit;
            }
            
            // No conflicts - create new user and book appointment
            $stmt = $pdo->prepare('INSERT INTO Users (first_name, last_name, phone_number, email) VALUES (?, ?, ?, ?)');
            $stmt->execute([$first_name, $last_name, $mobile, $email]);
            $user_id = $pdo->lastInsertId();
            
            // Book appointment using new structure
            if ($id > 0 && $user_id > 0) {
                $stmt = $pdo->prepare('UPDATE appointments SET user_id = ?, status = ? WHERE appointment_id = ?');
                $stmt->execute([$user_id, 'confirmed', $id]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Could not book appointment.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error creating user: ' . $e->getMessage()]);
        }
        exit;
    }

    // Force create new user (when user confirms despite conflicts)
    if (isset($_GET['action']) && $_GET['action'] === 'force_create_user_and_book' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $mobile = $_POST['mobile'] ?? '';
        $email = $_POST['email'] ?? '';
        
        try {
            // Force create new user even if contact details exist (but name is different)
            $stmt = $pdo->prepare('INSERT INTO Users (first_name, last_name, phone_number, email) VALUES (?, ?, ?, ?)');
            $stmt->execute([$first_name, $last_name, $mobile, $email]);
            $user_id = $pdo->lastInsertId();
            
            // Book appointment
            if ($id > 0 && $user_id > 0) {
                $stmt = $pdo->prepare('UPDATE appointments SET user_id = ?, status = ? WHERE appointment_id = ?');
                $stmt->execute([$user_id, 'confirmed', $id]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Could not book appointment.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error creating user: ' . $e->getMessage()]);
        }
        exit;
    }

    // Auto-create empty appointment slots for a day
    if (isset($_GET['action']) && $_GET['action'] === 'auto_create_slots' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $date = $_POST['date'] ?? '';
        
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
            exit;
        }
        
        try {
            // Get ALL settings to ensure we have the right values
            $stmt = $pdo->prepare('SELECT key_name, value FROM Settings WHERE key_name IN (?, ?, ?, ?, ?)');
            $stmt->execute(['daily_start_time', 'daily_end_time', 'lunch_start_time', 'lunch_end_time', 'appointment_duration']);
            $settings = [];
            foreach ($stmt->fetchAll() as $row) {
                $settings[$row['key_name']] = $row['value'];
            }
            
            // Use database values with proper fallbacks and validation
            $startTime = $settings['daily_start_time'] ?? '09:00';
            $endTime = $settings['daily_end_time'] ?? '17:00';
            $lunchStart = $settings['lunch_start_time'] ?? '12:00';
            $lunchEnd = $settings['lunch_end_time'] ?? '13:00';
            $duration = intval($settings['appointment_duration'] ?? 30);
            
            // Validate time format (HH:MM)
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $startTime) ||
                !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $endTime) ||
                !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $lunchStart) ||
                !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $lunchEnd)) {
                echo json_encode(['success' => false, 'message' => 'Invalid time format in settings. Please check your database settings.']);
                exit;
            }
            
            // Parse time strings (HH:MM format)
            list($startHour, $startMin) = explode(':', $startTime);
            list($endHour, $endMin) = explode(':', $endTime);
            list($lunchStartHour, $lunchStartMin) = explode(':', $lunchStart);
            list($lunchEndHour, $lunchEndMin) = explode(':', $lunchEnd);
            
            // Get existing appointments for the day
            $stmt = $pdo->prepare('SELECT appointment_time FROM appointments WHERE appointment_date = ?');
            $stmt->execute([$date]);
            $existingTimes = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Generate all possible time slots
            $slots = [];
            $currentTime = new DateTime("$date {$startTime}:00");
            $endDateTime = new DateTime("$date {$endTime}:00");
            $lunchStartTime = new DateTime("$date {$lunchStart}:00");
            $lunchEndTime = new DateTime("$date {$lunchEnd}:00");
            
            while ($currentTime < $endDateTime) {
                $slotEnd = clone $currentTime;
                $slotEnd->add(new DateInterval("PT{$duration}M"));
                
                // Skip if slot would go past end time
                if ($slotEnd > $endDateTime) {
                    break;
                }
                
                // Skip lunch time slots
                if ($currentTime >= $lunchStartTime && $currentTime < $lunchEndTime) {
                    $currentTime->add(new DateInterval("PT{$duration}M"));
                    continue;
                }
                
                $timeSlot = $currentTime->format('H:i:s');
                
                // Check if time slot already exists
                if (!in_array($timeSlot, $existingTimes)) {
                    $slots[] = $timeSlot;
                }
                
                $currentTime->add(new DateInterval("PT{$duration}M"));
            }
            
            // Insert the new slots
            $created = 0;
            foreach ($slots as $timeSlot) {
                $stmt = $pdo->prepare('INSERT INTO appointments (user_id, appointment_date, appointment_time, status, notes) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([null, $date, $timeSlot, 'available', "Duration: {$duration} minutes"]);
                $created++;
            }
            
            // Include settings info in response for debugging
            echo json_encode([
                'success' => true, 
                'message' => "Created $created appointment slots from {$startTime} to {$endTime} (lunch {$lunchStart}-{$lunchEnd})",
                'slots_created' => $created,
                'settings_used' => [
                    'start' => $startTime,
                    'end' => $endTime,
                    'lunch_start' => $lunchStart,
                    'lunch_end' => $lunchEnd,
                    'duration' => $duration
                ]
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error creating slots: ' . $e->getMessage()]);
        }
        exit;
    }

    // Block an appointment slot
    if (isset($_GET['action']) && $_GET['action'] === 'block_appointment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare('UPDATE appointments SET status = ?, user_id = NULL WHERE appointment_id = ?');
                $stmt->execute(['blocked', $id]);
                echo json_encode(['success' => true, 'message' => 'Appointment slot blocked']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error blocking appointment: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid appointment ID.']);
        }
        exit;
    }

    // Unblock an appointment slot
    if (isset($_GET['action']) && $_GET['action'] === 'unblock_appointment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ?');
                $stmt->execute(['available', $id]);
                echo json_encode(['success' => true, 'message' => 'Appointment slot unblocked']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error unblocking appointment: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid appointment ID.']);
        }
        exit;
    }

    echo json_encode(['error' => 'Invalid action']);
    exit;