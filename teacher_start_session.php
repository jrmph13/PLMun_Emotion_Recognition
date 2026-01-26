<?php
// ==================== TEACHER START SESSION PAGE ====================
// Start session and load configuration
require_once 'config.php';

// Require instructor or admin role
requireInstructor();

// Get current user data
$userData = getUserData();
// Ensure $userData is always an array and has default values
$userData = is_array($userData) ? $userData : [];
$userData['full_name'] = $userData['full_name'] ?? 'Instructor';

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// Get session and class info from URL
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

if (!$session_id || !$class_id) {
    setFlash('error', "Invalid session or class ID.");
    header("Location: teacher_live_classes.php");
    exit();
}

// Generate or get room ID for WebRTC
try {
    // Check if room ID already exists
    $stmt = $pdo->prepare("SELECT room_id FROM live_sessions WHERE id = ?");
    $stmt->execute([$session_id]);
    $sessionData = $stmt->fetch();
    
    $room_id = $sessionData['room_id'] ?? null;
    
    // Generate new room ID if not exists
    if (!$room_id) {
        $room_id = 'room_' . $session_id . '_' . uniqid();
        $stmt = $pdo->prepare("UPDATE live_sessions SET room_id = ? WHERE id = ?");
        $stmt->execute([$room_id, $session_id]);
    }
} catch (PDOException $e) {
    error_log("Error generating room ID: " . $e->getMessage());
    // Generate fallback room ID
    $room_id = 'room_' . $session_id . '_' . time();
}

// Verify instructor owns this class
try {
   $stmt = $pdo->prepare("
    SELECT 
        c.*, 
        c.class_code as session_code, 
        ls.id as session_id, 
        ls.start_time, 
        ls.status as session_status,
        COALESCE(ls.emotion_tracking, 0) as emotion_tracking
    FROM classes c
    JOIN live_sessions ls ON c.id = ls.class_id
    WHERE c.id = ? AND c.instructor_id = ? AND ls.id = ?
");
    $stmt->execute([$class_id, $userId, $session_id]);
    $class = $stmt->fetch();
    
    if (!$class) {
        setFlash('error', "Class not found or you don't have permission to access this session.");
        header("Location: teacher_live_classes.php");
        exit();
    }
    
    // Check if session is active
    if ($class['session_status'] !== 'active') {
        // Start the session if not active
        $stmt = $pdo->prepare("UPDATE live_sessions SET status = 'active', start_time = NOW() WHERE id = ?");
        $stmt->execute([$session_id]);
        $class['session_status'] = 'active';
    }
    
    // Add session_name to class array
    $class['session_name'] = $class['class_name'] . " - Live Session";
    $class['emotion_detection_status'] = $class['emotion_tracking'] == 1 ? 'active' : 'stopped';
    
} catch (PDOException $e) {
    error_log("Session verification error: " . $e->getMessage());
    setFlash('error', "Error verifying session access: " . $e->getMessage());
    header("Location: teacher_live_classes.php");
    exit();
}

// Add/update instructor in session participants
try {
    // First check if instructor already exists in participants
    $stmt = $pdo->prepare("
        SELECT id FROM live_session_participants 
        WHERE session_id = ? AND user_id = ? AND user_role = 'instructor'
    ");
    $stmt->execute([$session_id, $userId]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Update existing record
        $stmt = $pdo->prepare("
            UPDATE live_session_participants 
            SET leave_time = NULL, 
                is_active = 1, 
                camera_active = 1,
                mic_active = 1,
                join_time = NOW()
            WHERE session_id = ? AND user_id = ?
        ");
        $stmt->execute([$session_id, $userId]);
    } else {
        // Insert new record
        $stmt = $pdo->prepare("
            INSERT INTO live_session_participants 
            (session_id, user_id, user_role, join_time, camera_active, mic_active, is_active) 
            VALUES (?, ?, 'instructor', NOW(), 1, 1, 1)
        ");
        $stmt->execute([$session_id, $userId]);
    }
} catch (PDOException $e) {
    error_log("Error adding instructor to participants: " . $e->getMessage());
    // Continue anyway - don't fail the whole page
}

// ==================== HANDLE ALL ACTIONS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    $session_id_post = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
    
    switch ($action) {
        case 'send_chat':
            // Handle chat message sending
            $message = trim($_POST['message'] ?? '');
            $sender_id = isset($_POST['sender_id']) ? intval($_POST['sender_id']) : $userId;
            
            if (empty($message)) {
                echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
                exit();
            }
            
            if ($session_id_post <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid session']);
                exit();
            }
            
            try {
                // Insert chat message
                $stmt = $pdo->prepare("
                    INSERT INTO chat_messages (session_id, sender_id, sender_role, message, created_at) 
                    VALUES (?, ?, 'instructor', ?, NOW())
                ");
                
                $result = $stmt->execute([$session_id_post, $sender_id, $message]);
                
                if ($result) {
                    $message_id = $pdo->lastInsertId();
                    
                    // Get the inserted message
                    $stmt = $pdo->prepare("
                        SELECT cm.*, u.full_name 
                        FROM chat_messages cm
                        LEFT JOIN users u ON cm.sender_id = u.id
                        WHERE cm.id = ?
                    ");
                    $stmt->execute([$message_id]);
                    $message_data = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    echo json_encode([
                        'success' => true,
                        'message_id' => $message_id,
                        'data' => $message_data
                    ]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to save message']);
                }
            } catch (PDOException $e) {
                error_log("Chat message error: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            }
            exit();
            
        case 'send_announcement':
            // Handle announcement sending
            $message = trim($_POST['message'] ?? '');
            $teacher_id = isset($_POST['teacher_id']) ? intval($_POST['teacher_id']) : $userId;
            $class_id_post = isset($_POST['class_id']) ? intval($_POST['class_id']) : $class_id;
            
            if (empty($message)) {
                echo json_encode(['success' => false, 'error' => 'Announcement message cannot be empty']);
                exit();
            }
            
            if ($session_id_post <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid session']);
                exit();
            }
            
            try {
                // Insert announcement as a chat message with special type
                $stmt = $pdo->prepare("
                    INSERT INTO chat_messages (session_id, sender_id, sender_role, message, is_announcement, created_at) 
                    VALUES (?, ?, 'instructor', ?, 1, NOW())
                ");
                
                $result = $stmt->execute([$session_id_post, $teacher_id, "📢 ANNOUNCEMENT: " . $message]);
                
                if ($result) {
                    $message_id = $pdo->lastInsertId();
                    
                    // Log announcement activity
                    logAuditTrail(
                        $teacher_id,
                        'instructor',
                        $_SESSION['username'] ?? 'unknown',
                        'send_announcement',
                        'Sent announcement in session: ' . $session_id_post,
                        'chat_messages',
                        $message_id,
                        ['session_id' => $session_id_post, 'message_length' => strlen($message)]
                    );
                    
                    echo json_encode([
                        'success' => true,
                        'message_id' => $message_id,
                        'message' => 'Announcement sent successfully'
                    ]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to save announcement']);
                }
            } catch (PDOException $e) {
                error_log("Announcement error: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            }
            exit();
            
        case 'end_session':
            try {
                // Update session status
                $stmt = $pdo->prepare("UPDATE live_sessions SET status = 'ended', end_time = NOW(), emotion_tracking = 0 WHERE id = ?");
                $stmt->execute([$session_id_post]);
                
                // Update all participants leave time
                $stmt = $pdo->prepare("
                    UPDATE live_session_participants 
                    SET leave_time = NOW(), is_active = 0 
                    WHERE session_id = ?
                ");
                $stmt->execute([$session_id_post]);
                
                echo json_encode(['success' => true, 'message' => 'Session ended successfully']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            }
            exit();
            
        case 'update_device_status':
            $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : $userId;
            $camera_active = isset($_POST['camera_active']) ? intval($_POST['camera_active']) : 0;
            $mic_active = isset($_POST['mic_active']) ? intval($_POST['mic_active']) : 0;
            
            try {
                $stmt = $pdo->prepare("
                    UPDATE live_session_participants 
                    SET camera_active = ?, mic_active = ?, last_activity = NOW()
                    WHERE session_id = ? AND user_id = ?
                ");
                $stmt->execute([$camera_active, $mic_active, $session_id_post, $user_id]);
                
                echo json_encode(['success' => true]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit();
            
        case 'mute_student':
            $student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
            
            try {
                $stmt = $pdo->prepare("
                    UPDATE live_session_participants 
                    SET mic_active = 0 
                    WHERE session_id = ? AND user_id = ?
                ");
                $stmt->execute([$session_id_post, $student_id]);
                
                echo json_encode(['success' => true, 'message' => 'Student muted']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit();
            
        case 'remove_student':
            $student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
            
            try {
                $stmt = $pdo->prepare("
                    UPDATE live_session_participants 
                    SET is_active = 0, leave_time = NOW() 
                    WHERE session_id = ? AND user_id = ?
                ");
                $stmt->execute([$session_id_post, $student_id]);
                
                echo json_encode(['success' => true, 'message' => 'Student removed']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit();
            
        case 'webrtc_signal':
            // Handle WebRTC signaling between teacher and students
            $signalData = $_POST['signal_data'] ?? '';
            $targetUserId = isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : 0;
            $senderUserId = isset($_POST['sender_user_id']) ? intval($_POST['sender_user_id']) : $userId;
            $signalType = $_POST['signal_type'] ?? 'offer';
            
            if (empty($signalData) || $session_id_post <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid signaling data']);
                exit();
            }
            
            try {
                // Create webrtc_signals table if not exists
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS webrtc_signals (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        session_id INT NOT NULL,
                        sender_id INT NOT NULL,
                        receiver_id INT NOT NULL,
                        signal_type VARCHAR(50) NOT NULL,
                        signal_data TEXT NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_session_receiver (session_id, receiver_id),
                        INDEX idx_created_at (created_at)
                    )
                ");
                
                // Store the signaling data
                $stmt = $pdo->prepare("
                    INSERT INTO webrtc_signals 
                    (session_id, sender_id, receiver_id, signal_type, signal_data, created_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                
                $result = $stmt->execute([
                    $session_id_post, 
                    $senderUserId, 
                    $targetUserId, 
                    $signalType, 
                    $signalData
                ]);
                
                if ($result) {
                    echo json_encode(['success' => true, 'signal_id' => $pdo->lastInsertId()]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to store signal']);
                }
            } catch (PDOException $e) {
                error_log("WebRTC signaling error: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'Database error']);
            }
            exit();
            
        case 'get_webrtc_signals':
            // Get pending WebRTC signals for this user
            $last_signal_id = isset($_POST['last_signal_id']) ? intval($_POST['last_signal_id']) : 0;
            
            try {
                // Ensure table exists
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS webrtc_signals (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        session_id INT NOT NULL,
                        sender_id INT NOT NULL,
                        receiver_id INT NOT NULL,
                        signal_type VARCHAR(50) NOT NULL,
                        signal_data TEXT NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_session_receiver (session_id, receiver_id),
                        INDEX idx_created_at (created_at)
                    )
                ");
                
                $stmt = $pdo->prepare("
                    SELECT * FROM webrtc_signals 
                    WHERE session_id = ? AND receiver_id = ? AND id > ?
                    ORDER BY created_at ASC
                    LIMIT 10
                ");
                $stmt->execute([$session_id_post, $userId, $last_signal_id]);
                $signals = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Clean up old signals
                $stmt = $pdo->prepare("DELETE FROM webrtc_signals WHERE created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
                $stmt->execute();
                
                echo json_encode([
                    'success' => true,
                    'signals' => $signals,
                    'count' => count($signals)
                ]);
            } catch (PDOException $e) {
                error_log("Error getting WebRTC signals: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'Database error']);
            }
            exit();
            
        case 'update_student_stream':
            // Update student's stream information
            $student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
            $stream_active = isset($_POST['stream_active']) ? intval($_POST['stream_active']) : 0;
            
            try {
                $stmt = $pdo->prepare("
                    UPDATE live_session_participants 
                    SET stream_active = ?, last_activity = NOW()
                    WHERE session_id = ? AND user_id = ?
                ");
                $stmt->execute([$stream_active, $session_id_post, $student_id]);
                
                echo json_encode(['success' => true]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit();
            
        case 'update_emotion_tracking':
            // Update emotion tracking status
            $status = isset($_POST['status']) ? intval($_POST['status']) : 0;
            
            try {
                $stmt = $pdo->prepare("UPDATE live_sessions SET emotion_tracking = ? WHERE id = ?");
                $stmt->execute([$status, $session_id_post]);
                
                $status_text = $status == 1 ? 'active' : ($status == 2 ? 'paused' : 'stopped');
                echo json_encode(['success' => true, 'status' => $status_text]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit();
            
        case 'save_emotion_data':
            // Save emotion data from students
            $student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
            $emotion = isset($_POST['emotion']) ? trim($_POST['emotion']) : 'neutral';
            $confidence = isset($_POST['confidence']) ? floatval($_POST['confidence']) : 0;
            $timestamp = isset($_POST['timestamp']) ? trim($_POST['timestamp']) : date('Y-m-d H:i:s');
            
            // Validate emotion type
            $valid_emotions = ['happy', 'angry', 'sad', 'neutral', 'confused'];
            if (!in_array($emotion, $valid_emotions)) {
                $emotion = 'neutral';
            }
            
            try {
                // Create emotion_data table if not exists
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS emotion_data (
                        id INT PRIMARY KEY AUTO_INCREMENT,
                        session_id INT NOT NULL,
                        student_id INT NOT NULL,
                        facial_emotion VARCHAR(20) NOT NULL,
                        confidence_score DECIMAL(5,2) DEFAULT 0,
                        captured_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_session_student (session_id, student_id),
                        INDEX idx_captured_at (captured_at)
                    )
                ");
                
                // Insert emotion data
                $stmt = $pdo->prepare("
                    INSERT INTO emotion_data 
                    (session_id, student_id, facial_emotion, confidence_score, captured_at) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                $result = $stmt->execute([
                    $session_id_post, 
                    $student_id, 
                    $emotion, 
                    $confidence, 
                    $timestamp
                ]);
                
                if ($result) {
                    echo json_encode([
                        'success' => true, 
                        'emotion_id' => $pdo->lastInsertId(),
                        'emotion' => $emotion
                    ]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to save emotion data']);
                }
            } catch (PDOException $e) {
                error_log("Error saving emotion data: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'Database error']);
            }
            exit();
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
            exit();
    }
}

// ==================== HANDLE GET ACTIONS ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    $action = $_GET['action'];
    $session_id_get = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
    
    switch ($action) {
        case 'get_chat':
            $last_id = intval($_GET['last_id'] ?? 0);
            
            try {
                if ($last_id > 0) {
                    $stmt = $pdo->prepare("
                        SELECT cm.*, u.full_name, u.role as sender_role
                        FROM chat_messages cm
                        LEFT JOIN users u ON cm.sender_id = u.id
                        WHERE cm.session_id = ? AND cm.id > ?
                        ORDER BY cm.created_at ASC
                    ");
                    $stmt->execute([$session_id_get, $last_id]);
                } else {
                    // Get last 50 messages
                    $stmt = $pdo->prepare("
                        SELECT cm.*, u.full_name, u.role as sender_role
                        FROM chat_messages cm
                        LEFT JOIN users u ON cm.sender_id = u.id
                        WHERE cm.session_id = ?
                        ORDER BY cm.created_at DESC
                        LIMIT 50
                    ");
                    $stmt->execute([$session_id_get]);
                }
                
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Reverse order for last 50 messages
                if ($last_id === 0) {
                    $messages = array_reverse($messages);
                }
                
                echo json_encode([
                    'success' => true,
                    'messages' => $messages,
                    'count' => count($messages)
                ]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => 'Database error']);
            }
            exit();
            
        case 'get_emotion_updates':
            $last_id = intval($_GET['last_id'] ?? 0);
            $student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
            
            try {
                $query = "
                    SELECT ed.*, u.full_name, s.student_number
                    FROM emotion_data ed
                    JOIN students s ON ed.student_id = s.id
                    JOIN users u ON s.user_id = u.id
                    WHERE ed.session_id = ? 
                ";
                
                $params = [$session_id_get];
                
                if ($student_id > 0) {
                    $query .= " AND ed.student_id = ?";
                    $params[] = $student_id;
                }
                
                if ($last_id > 0) {
                    $query .= " AND ed.id > ?";
                    $params[] = $last_id;
                }
                
                $query .= " ORDER BY ed.captured_at DESC LIMIT 20";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $emotions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'data' => $emotions,
                    'count' => count($emotions)
                ]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => 'Database error']);
            }
            exit();
            
        case 'get_session_participants':
            try {
                $stmt = $pdo->prepare("
                    SELECT lsp.*, u.full_name, u.username, s.student_number
                    FROM live_session_participants lsp
                    JOIN users u ON lsp.user_id = u.id
                    LEFT JOIN students s ON u.id = s.user_id AND u.role = 'student'
                    WHERE lsp.session_id = ? AND lsp.is_active = 1
                    ORDER BY lsp.join_time
                ");
                $stmt->execute([$session_id_get]);
                $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'participants' => $participants,
                    'count' => count($participants)
                ]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => 'Database error']);
            }
            exit();
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
            exit();
    }
}

// Get enrolled students count
$enrolled_students = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM class_enrollments 
        WHERE class_id = ?
    ");
    $stmt->execute([$class_id]);
    $result = $stmt->fetch();
    $enrolled_students = $result['count'] ?? 0;
} catch (PDOException $e) {
    error_log("Error counting enrolled students: " . $e->getMessage());
}

// Get active participants (excluding instructor)
$active_participants = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT user_id) as count 
        FROM live_session_participants 
        WHERE session_id = ? AND is_active = 1 AND user_role = 'student'
    ");
    $stmt->execute([$session_id]);
    $result = $stmt->fetch();
    $active_participants = $result['count'] ?? 0;
} catch (PDOException $e) {
    error_log("Error counting active participants: " . $e->getMessage());
}

// Get recent emotion data for dashboard
$recent_emotions = [];
try {
    $stmt = $pdo->prepare("
        SELECT ed.*, s.student_number, u.full_name, u.id as user_id
        FROM emotion_data ed
        JOIN students s ON ed.student_id = s.id
        JOIN users u ON s.user_id = u.id
        WHERE ed.session_id = ?
        ORDER BY ed.captured_at DESC
        LIMIT 10
    ");
    $stmt->execute([$session_id]);
    $recent_emotions = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching recent emotions: " . $e->getMessage());
}

// Get participants list
$participants = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            lsp.*, 
            u.full_name, 
            u.username, 
            s.student_number,
            'participant' as role_display
        FROM live_session_participants lsp
        JOIN users u ON lsp.user_id = u.id
        LEFT JOIN students s ON u.id = s.user_id AND u.role = 'student'
        WHERE lsp.session_id = ? AND lsp.is_active = 1
        ORDER BY 
            CASE WHEN lsp.user_role = 'instructor' THEN 1 ELSE 2 END,
            lsp.join_time
    ");
    $stmt->execute([$session_id]);
    $participants = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching participants: " . $e->getMessage());
}

// Get emotion statistics
$emotion_stats = [
    'happy' => 0,
    'neutral' => 0,
    'sad' => 0,
    'angry' => 0,
    'confused' => 0
];

try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN facial_emotion = 'happy' THEN 1 END) as happy,
            COUNT(CASE WHEN facial_emotion = 'neutral' THEN 1 END) as neutral,
            COUNT(CASE WHEN facial_emotion = 'sad' THEN 1 END) as sad,
            COUNT(CASE WHEN facial_emotion = 'angry' THEN 1 END) as angry,
            COUNT(CASE WHEN facial_emotion = 'confused' THEN 1 END) as confused,
            COUNT(*) as total
        FROM emotion_data 
        WHERE session_id = ?
    ");
    $stmt->execute([$session_id]);
    $stats = $stmt->fetch();
    
    if ($stats && $stats['total'] > 0) {
        $emotion_stats = [
            'happy' => round(($stats['happy'] / $stats['total']) * 100, 1),
            'neutral' => round(($stats['neutral'] / $stats['total']) * 100, 1),
            'sad' => round(($stats['sad'] / $stats['total']) * 100, 1),
            'angry' => round(($stats['angry'] / $stats['total']) * 100, 1),
            'confused' => round(($stats['confused'] / $stats['total']) * 100, 1)
        ];
    }
} catch (PDOException $e) {
    error_log("Error fetching emotion stats: " . $e->getMessage());
}

// Get total emotion records count
$total_emotion_records = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM emotion_data WHERE session_id = ?");
    $stmt->execute([$session_id]);
    $result = $stmt->fetch();
    $total_emotion_records = $result['count'] ?? 0;
} catch (PDOException $e) {
    error_log("Error counting emotion records: " . $e->getMessage());
}

// Get chat messages for initial load
$chat_messages = [];
try {
    $stmt = $pdo->prepare("
        SELECT cm.*, 
               COALESCE(u.full_name, 'Unknown User') as full_name, 
               COALESCE(u.role, 'student') as sender_role
        FROM chat_messages cm
        LEFT JOIN users u ON cm.sender_id = u.id
        WHERE cm.session_id = ?
        ORDER BY cm.created_at ASC
        LIMIT 50
    ");
    $stmt->execute([$session_id]);
    $chat_messages = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching chat messages: " . $e->getMessage());
}

// Get latest emotion for each student
$student_emotions = [];
try {
    $stmt = $pdo->prepare("
        SELECT ed1.*, u.full_name, s.student_number
        FROM emotion_data ed1
        JOIN students s ON ed1.student_id = s.id
        JOIN users u ON s.user_id = u.id
        WHERE ed1.session_id = ? AND ed1.captured_at = (
            SELECT MAX(ed2.captured_at)
            FROM emotion_data ed2
            WHERE ed2.session_id = ed1.session_id AND ed2.student_id = ed1.student_id
        )
        ORDER BY ed1.captured_at DESC
    ");
    $stmt->execute([$session_id]);
    $student_emotions = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching student emotions: " . $e->getMessage());
}

// Log page access for audit trail
logAuditTrail(
    $_SESSION['user_id'],
    $_SESSION['role'],
    $_SESSION['username'],
    'view',
    'Joined live session: ' . $class['class_name'],
    'live_sessions',
    $session_id,
    ['class_id' => $class_id, 'session_id' => $session_id]
);

// Set page title
$page_title = "Live Session - " . htmlspecialchars($class['class_name']) . " - Emotion AI System";

// Helper functions
function getInitials($name) {
    if (empty($name) || $name === null) return '??';
    $parts = explode(' ', $name);
    $initials = '';
    foreach ($parts as $part) {
        if (!empty($part)) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }
    return substr($initials, 0, 2);
}

function formatDate($date, $format = 'h:i A') {
    if (empty($date) || $date === null) return '';
    $timestamp = strtotime($date);
    return $timestamp ? date($format, $timestamp) : '';
}

// Calculate engagement score
$engagement_score = round(($emotion_stats['happy'] * 0.8 + $emotion_stats['neutral'] * 0.6 + 
    (100 - $emotion_stats['confused'] - $emotion_stats['sad'] - $emotion_stats['angry']) * 0.4) / 100, 1);

// Count active students in participants
$active_students = 0;
foreach ($participants as $participant) {
    if ($participant['user_role'] === 'student' && $participant['is_active'] == 1) {
        $active_students++;
    }
}

// Empty slots for UI
$empty_slots = max(0, $enrolled_students - $active_students);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title><?php echo $page_title; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-purple: #8b5cf6;
            --secondary-purple: #7c3aed;
            --dark-purple: #6d28d9;
            --light-purple: #ede9fe;
            --accent-blue: #3b82f6;
            --primary-white: #ffffff;
            --secondary-white: #f5f5f5;
            --off-white: #f9f9f9;
            --light-gray: #e0e0e0;
            --medium-gray: #cccccc;
            --dark-gray: #333333;
            --card-bg: #f8fafc;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            --gradient-secondary: linear-gradient(135deg, var(--secondary-purple) 0%, var(--primary-purple) 100%);
            --gradient-card: linear-gradient(135deg, var(--card-bg) 0%, #e5e7eb 100%);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: var(--dark-gray);
            line-height: 1.6;
            overflow: hidden;
            height: 100vh;
            touch-action: manipulation;
        }
        
        .live-session-container {
            display: grid;
            grid-template-rows: 70px 1fr;
            height: 100vh;
            gap: 0;
            overflow: hidden;
        }
        
        /* Header Styles */
        .session-header {
            background: rgba(255, 255, 255, 0.98);
            padding: 0 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            z-index: 100;
            backdrop-filter: blur(10px);
            height: 70px;
            min-height: 70px;
            border-bottom: 1px solid rgba(139, 92, 246, 0.2);
        }
        
        .session-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .session-title {
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 250px;
        }
        
        .session-code {
            background: rgba(139, 92, 246, 0.1);
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            color: var(--primary-purple);
            border: 1px solid rgba(139, 92, 246, 0.3);
            white-space: nowrap;
        }
        
        .session-timer {
            font-size: 14px;
            font-weight: 600;
            background: rgba(139, 92, 246, 0.1);
            padding: 8px 15px;
            border-radius: 20px;
            color: var(--primary-purple);
            border: 1px solid rgba(139, 92, 246, 0.3);
            white-space: nowrap;
        }
        
        /* Main Content Grid */
        .session-main {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 0;
            height: calc(100vh - 70px);
            overflow: hidden;
            position: relative;
        }
        
        /* Video Monitoring Section */
        .video-section {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
            height: 100%;
            overflow: hidden;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 10px;
            flex-shrink: 0;
        }
        
        .stat-card-teacher {
            background: rgba(255, 255, 255, 0.95);
            padding: 15px;
            border-radius: 12px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.6);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            min-height: 80px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border-top: 4px solid var(--primary-purple);
        }
        
        .stat-card-teacher:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.15);
        }
        
        .stat-value-teacher {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary-purple);
            margin-bottom: 5px;
            line-height: 1.2;
        }
        
        .stat-label-teacher {
            font-size: 12px;
            color: #6b7280;
            font-weight: 600;
            line-height: 1.2;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .video-grid-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            flex: 1;
            display: flex;
            flex-direction: column;
            border: 2px solid rgba(139, 92, 246, 0.1);
            overflow: hidden;
            backdrop-filter: blur(5px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
            min-height: 0;
        }
        
        .video-grid-header {
            padding: 15px 20px;
            background: rgba(139, 92, 246, 0.05);
            border-bottom: 2px solid rgba(139, 92, 246, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        
        .video-grid-title {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            white-space: nowrap;
        }
        
        .participant-count {
            font-size: 13px;
            color: var(--primary-purple);
            font-weight: 600;
            white-space: nowrap;
        }
        
        .video-grid {
            flex: 1;
            padding: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            overflow-y: auto;
            align-content: start;
            min-height: 0;
        }
        
        .video-item {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid rgba(139, 92, 246, 0.2);
            transition: all 0.3s ease;
            position: relative;
            aspect-ratio: 16/9;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            min-height: 160px;
        }
        
        .video-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(139, 92, 246, 0.2);
            border-color: var(--primary-purple);
        }
        
        .video-item.teacher {
            border: 2px solid var(--primary-purple);
            box-shadow: 0 4px 20px rgba(139, 92, 246, 0.3);
        }
        
        .video-placeholder {
            width: 100%;
            height: 100%;
            background: rgba(139, 92, 246, 0.05);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #4b5563;
            padding: 15px;
            text-align: center;
        }
        
        .video-icon {
            font-size: 36px;
            opacity: 0.8;
            color: var(--primary-purple);
        }
        
        video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 12px;
            background: #000;
        }
        
        /* WebRTC specific video styles */
        .student-video-element {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 12px;
            background: #000;
            display: none;
        }
        
        .video-item.active-stream .video-placeholder {
            display: none;
        }
        
        .video-item.active-stream .student-video-element {
            display: block;
        }
        
        .video-info {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 12px;
            background: linear-gradient(transparent, rgba(15, 23, 42, 0.9));
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 0 0 12px 12px;
        }
        
        .video-user-name {
            font-weight: 600;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: white;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .video-user-role {
            font-size: 10px;
            color: white;
            background: rgba(139, 92, 246, 0.8);
            padding: 3px 8px;
            border-radius: 10px;
            flex-shrink: 0;
            font-weight: 600;
        }
        
        .video-status {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            color: #e0e7ff;
            flex-shrink: 0;
        }
        
        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
            animation: pulse 2s infinite;
        }
        
        /* ==================== EMOTION LABEL STYLES ==================== */
        .emotion-label {
            position: absolute;
            top: 10px;
            left: 10px;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 6px;
            z-index: 10;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            min-width: 80px;
            text-align: center;
        }
        
        .emotion-label.happy {
            background: rgba(16, 185, 129, 0.85);
            color: white;
            border-color: rgba(16, 185, 129, 0.5);
        }
        
        .emotion-label.angry {
            background: rgba(239, 68, 68, 0.85);
            color: white;
            border-color: rgba(239, 68, 68, 0.5);
        }
        
        .emotion-label.sad {
            background: rgba(59, 130, 246, 0.85);
            color: white;
            border-color: rgba(59, 130, 246, 0.5);
        }
        
        .emotion-label.neutral {
            background: rgba(156, 163, 175, 0.85);
            color: white;
            border-color: rgba(156, 163, 175, 0.5);
        }
        
        .emotion-label.confused {
            background: rgba(245, 158, 11, 0.85);
            color: white;
            border-color: rgba(245, 158, 11, 0.5);
        }
        
        .emotion-label.no-face {
            background: rgba(107, 114, 128, 0.85);
            color: white;
            border-color: rgba(107, 114, 128, 0.5);
        }
        
        /* Controls Section */
        .controls-section {
            padding: 20px;
            background: rgba(255, 255, 255, 0.95);
            border-top: 1px solid rgba(139, 92, 246, 0.1);
            backdrop-filter: blur(5px);
            border-radius: 0 0 15px 15px;
            flex-shrink: 0;
            margin-top: auto;
        }
        
        .control-buttons {
            display: flex;
            justify-content: center;
            gap: 20px;
        }
        
        .control-btn {
            width: 55px;
            height: 55px;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            font-size: 20px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            touch-action: manipulation;
        }
        
        .control-btn-primary {
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            color: var(--primary-white);
        }
        
        .control-btn-secondary {
            background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%);
            color: var(--primary-white);
        }
        
        .control-btn-danger {
            background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%);
            color: var(--primary-white);
        }
        
        .control-btn:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.3);
        }
        
        .control-btn.active {
            transform: scale(1.1);
            box-shadow: 0 0 20px currentColor;
        }
        
        /* Sidebar Styles */
        .session-sidebar {
            background: rgba(255, 255, 255, 0.98);
            border-left: 1px solid rgba(139, 92, 246, 0.1);
            display: flex;
            flex-direction: column;
            height: 100%;
            backdrop-filter: blur(5px);
            position: relative;
            overflow: hidden;
            width: 100%;
        }
        
        .sidebar-tabs {
            display: flex;
            background: rgba(255, 255, 255, 0.98);
            flex-shrink: 0;
            z-index: 1000;
            position: relative;
            border-bottom: 2px solid rgba(139, 92, 246, 0.2);
            height: 55px;
            min-height: 55px;
        }
        
        .tab-btn {
            flex: 1;
            padding: 0;
            background: none;
            border: none;
            color: #4b5563;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 14px;
            position: relative;
            touch-action: manipulation;
        }
        
        .tab-btn:hover {
            background: rgba(139, 92, 246, 0.05);
            color: var(--primary-purple);
        }
        
        .tab-btn.active {
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            color: var(--primary-white);
            box-shadow: inset 0 -3px 0 var(--dark-purple);
        }
        
        .tab-btn i {
            font-size: 16px;
        }
        
        .tab-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
            position: relative;
            overflow: hidden;
            height: calc(100% - 55px);
        }
        
        .tab-pane {
            flex: 1;
            display: none;
            background: rgba(255, 255, 255, 0.9);
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
            height: 100%;
        }
        
        .tab-pane.active {
            display: flex;
        }
        
        /* Participants List */
        .participants-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex: 1;
            overflow-y: auto;
            padding: 15px;
            min-height: 0;
        }
        
        .participant-item {
            background: rgba(255, 255, 255, 0.95);
            padding: 12px 15px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s ease;
            border: 1px solid rgba(139, 92, 246, 0.1);
            min-height: 60px;
            position: relative;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .participant-item:hover {
            border-color: var(--primary-purple);
            transform: translateX(3px);
        }
        
        .participant-item:hover .participant-actions {
            display: flex;
        }
        
        .participant-item.active {
            border-left: 4px solid var(--primary-purple);
            background: rgba(139, 92, 246, 0.05);
        }
        
        .participant-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            color: white;
            flex-shrink: 0;
            box-shadow: 0 3px 10px rgba(139, 92, 246, 0.3);
        }
        
        .participant-info {
            flex: 1;
            min-width: 0;
        }
        
        .participant-name {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 3px;
            color: #1f2937;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .participant-meta {
            font-size: 11px;
            color: var(--primary-purple);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .participant-status {
            font-size: 10px;
            padding: 4px 8px;
            border-radius: 10px;
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid var(--success);
            white-space: nowrap;
            flex-shrink: 0;
            font-weight: 600;
        }
        
        .participant-status.offline {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid var(--danger);
        }
        
        .participant-actions {
            display: none;
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
            border: 1px solid rgba(139, 92, 246, 0.1);
        }
        
        .participant-action-btn {
            padding: 8px 12px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 12px;
            color: #4b5563;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            border-right: 1px solid rgba(139, 92, 246, 0.1);
        }
        
        .participant-action-btn:last-child {
            border-right: none;
        }
        
        .participant-action-btn:hover {
            background: rgba(139, 92, 246, 0.05);
            color: var(--primary-purple);
        }
        
        .participant-action-btn.remove {
            color: var(--danger);
        }
        
        .participant-action-btn.remove:hover {
            background: rgba(239, 68, 68, 0.1);
        }
        
        /* ========== ANALYTICS TAB - FIXED VERSION ========== */
        .analytics-tab-content {
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow: hidden;
        }
        
        .analytics-grid {
            display: flex;
            flex-direction: column;
            gap: 15px;
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 15px;
            min-height: 0;
            max-height: 100%;
        }
        
        .analytics-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 15px;
            border-radius: 12px;
            border: 1px solid rgba(139, 92, 246, 0.1);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            min-height: 0;
            flex-shrink: 0;
            overflow: hidden;
        }
        
        .analytics-card.chart-card {
            flex: 0 0 auto;
            height: 220px;
            min-height: 220px;
            max-height: 220px;
        }
        
        .analytics-card.stats-card {
            flex: 0 0 auto;
            height: 160px;
            min-height: 160px;
            max-height: 160px;
        }
        
        .analytics-card.recent-card {
            flex: 1;
            min-height: 200px;
            max-height: none;
            overflow: hidden;
        }
        
        .analytics-title {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 12px;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .chart-container {
            height: 160px;
            position: relative;
            width: 100%;
            overflow: hidden;
        }
        
        .chart-container canvas {
            max-width: 100%;
            max-height: 100%;
        }
        
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            width: 100%;
        }
        
        .metric-card {
            text-align: center;
            padding: 10px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 8px;
            border: 1px solid rgba(139, 92, 246, 0.1);
            min-height: 70px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .metric-card:hover {
            transform: translateY(-2px);
            border-color: var(--primary-purple);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.15);
        }
        
        .metric-value {
            font-size: 18px;
            font-weight: 800;
            color: var(--primary-purple);
            margin-bottom: 3px;
            line-height: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .metric-label {
            font-size: 11px;
            color: #6b7280;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .recent-emotions-container {
            display: flex;
            flex-direction: column;
            height: 140px;
            overflow-y: auto;
            overflow-x: hidden;
            gap: 8px;
            padding-right: 5px;
        }
        
        .recent-emotion-item {
            display: flex;
            align-items: center;
            padding: 8px 10px;
            border-bottom: 1px solid rgba(139, 92, 246, 0.1);
            background: rgba(255, 255, 255, 0.7);
            border-radius: 6px;
            min-height: 50px;
            transition: all 0.2s;
        }
        
        .recent-emotion-item:hover {
            background: rgba(139, 92, 246, 0.05);
            transform: translateX(3px);
        }
        
        .recent-emotion-item:last-child {
            border-bottom: none;
        }
        
        .recent-emotion-info {
            flex: 1;
            min-width: 0;
        }
        
        .recent-emotion-name {
            font-weight: 600;
            font-size: 12px;
            color: #1f2937;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 2px;
        }
        
        .recent-emotion-time {
            font-size: 10px;
            color: var(--primary-purple);
            font-weight: 500;
        }
        
        .recent-emotion-confidence {
            font-size: 11px;
            font-weight: 600;
            color: var(--primary-purple);
            background: rgba(139, 92, 246, 0.1);
            padding: 3px 8px;
            border-radius: 10px;
            flex-shrink: 0;
            white-space: nowrap;
        }
        
        .no-data-message {
            text-align: center;
            padding: 20px;
            color: var(--primary-purple);
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .no-data-icon {
            font-size: 24px;
            margin-bottom: 10px;
            opacity: 0.7;
        }
        
        .no-data-text {
            font-size: 12px;
            color: #6b7280;
        }
        
        /* Chat Tab */
        .chat-messages-container {
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 0;
            position: relative;
            overflow: hidden;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
            min-height: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
            background: rgba(255, 255, 255, 0.7);
            max-height: calc(100% - 70px);
        }

        .chat-message {
            padding: 10px 12px;
            border-radius: 10px;
            max-width: 85%;
            word-wrap: break-word;
            animation: fadeIn 0.3s ease;
            background: white;
            border: 1px solid rgba(139, 92, 246, 0.1);
            font-size: 13px;
            min-width: 120px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .teacher-message {
            align-self: flex-start;
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            color: white;
            border-color: var(--dark-purple);
        }

        .student-message {
            align-self: flex-end;
            background: rgba(139, 92, 246, 0.05);
            border-left: 4px solid var(--accent-blue);
        }

        .own-message {
            align-self: flex-end;
            background: linear-gradient(135deg, var(--accent-blue) 0%, #2563eb 100%);
            color: white;
            border-color: var(--dark-purple);
        }

        .system-message {
            align-self: center;
            background: rgba(139, 92, 246, 0.1);
            border-left: 4px solid var(--primary-purple);
            text-align: center;
            max-width: 90%;
            font-size: 12px;
        }

        .announcement-message {
            align-self: center;
            background: linear-gradient(135deg, #ffd700 0%, #ffb800 100%);
            border-left: 4px solid #ff9500;
            text-align: center;
            max-width: 90%;
            font-size: 12px;
            font-weight: 600;
            color: #5a4200;
        }

        .chat-input-area {
            padding: 15px;
            border-top: 1px solid rgba(139, 92, 246, 0.1);
            background: rgba(255, 255, 255, 0.95);
            flex-shrink: 0;
            height: 70px;
            min-height: 70px;
            display: flex;
            align-items: center;
            position: relative;
            z-index: 100;
        }

        .chat-input-wrapper {
            display: flex;
            gap: 10px;
            align-items: center;
            width: 100%;
            height: 100%;
        }

        .chat-input {
            flex: 1;
            padding: 10px 12px;
            background: white;
            border: 2px solid rgba(139, 92, 246, 0.2);
            border-radius: 10px;
            color: #1f2937;
            font-size: 13px;
            resize: none;
            min-height: 40px;
            max-height: 100px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.4;
            transition: all 0.3s;
        }

        .chat-input:focus {
            outline: none;
            border-color: var(--primary-purple);
            background: white;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.15);
        }

        .chat-send-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: none;
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            touch-action: manipulation;
            box-shadow: 0 3px 10px rgba(139, 92, 246, 0.3);
        }

        .chat-send-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(139, 92, 246, 0.4);
        }

        /* Chat message info */
        .chat-message-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
        }

        .chat-sender {
            font-weight: 600;
            color: #1f2937;
            font-size: 12px;
        }

        .teacher-message .chat-sender {
            color: white;
        }

        .own-message .chat-sender {
            color: white;
        }

        .chat-time {
            font-size: 11px;
            color: #6b7280;
            opacity: 0.8;
        }

        .teacher-message .chat-time {
            color: rgba(255, 255, 255, 0.8);
        }

        .own-message .chat-time {
            color: rgba(255, 255, 255, 0.8);
        }

        /* Individual Student Modal */
        .student-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .student-modal.active {
            display: flex;
        }

        .student-modal-content {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
        }

        .student-modal-header {
            padding: 20px 25px;
            border-bottom: 2px solid rgba(139, 92, 246, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            color: white;
            border-radius: 20px 20px 0 0;
        }

        .student-modal-header h3 {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .student-modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: white;
            cursor: pointer;
            padding: 0;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            transition: all 0.3s;
        }

        .student-modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .student-modal-body {
            padding: 25px;
        }

        .student-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }

        .student-info-item {
            background: rgba(139, 92, 246, 0.05);
            padding: 15px;
            border-radius: 12px;
            border: 1px solid rgba(139, 92, 246, 0.2);
            transition: all 0.3s;
        }

        .student-info-item:hover {
            transform: translateY(-2px);
            border-color: var(--primary-purple);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.15);
        }

        .student-info-label {
            font-size: 12px;
            color: var(--primary-purple);
            font-weight: 700;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .student-info-value {
            font-size: 18px;
            font-weight: 800;
            color: #1f2937;
        }

        .emotion-timeline {
            background: white;
            border-radius: 12px;
            padding: 15px;
            border: 1px solid rgba(139, 92, 246, 0.1);
            margin-bottom: 20px;
        }

        .emotion-timeline-title {
            font-size: 15px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .timeline-item {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid rgba(139, 92, 246, 0.1);
        }

        .timeline-item:last-child {
            border-bottom: none;
        }

        .timeline-time {
            font-size: 12px;
            color: var(--primary-purple);
            width: 70px;
            flex-shrink: 0;
            font-weight: 600;
        }

        .timeline-emotion {
            font-size: 20px;
            margin: 0 15px;
            width: 30px;
            text-align: center;
        }

        .timeline-bar {
            flex: 1;
            height: 8px;
            background: rgba(139, 92, 246, 0.1);
            border-radius: 4px;
            overflow: hidden;
        }

        .timeline-bar-fill {
            height: 100%;
            border-radius: 4px;
        }

        .timeline-bar-fill.happy { background: linear-gradient(90deg, #10b981 0%, #059669 100%); }
        .timeline-bar-fill.neutral { background: linear-gradient(90deg, #3b82f6 0%, #1d4ed8 100%); }
        .timeline-bar-fill.sad { background: linear-gradient(90deg, #ef4444 0%, #dc2626 100%); }
        .timeline-bar-fill.angry { background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%); }
        .timeline-bar-fill.confused { background: linear-gradient(90deg, #8b5cf6 0%, #7c3aed 100%); }

        /* Emotion Detection Controls */
        .emotion-controls {
            position: fixed;
            bottom: 100px;
            right: 30px;
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            display: none;
            flex-direction: column;
            gap: 12px;
            min-width: 220px;
            border: 1px solid rgba(139, 92, 246, 0.2);
        }

        .emotion-controls.active {
            display: flex;
        }

        .emotion-control-title {
            font-size: 15px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 5px;
        }

        .emotion-control-buttons {
            display: flex;
            gap: 10px;
        }

        .emotion-control-btn {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
        }

        .emotion-control-btn.start {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
            color: white;
        }

        .emotion-control-btn.pause {
            background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%);
            color: white;
        }

        .emotion-control-btn.stop {
            background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%);
            color: white;
        }

        .emotion-control-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.2);
        }

        /* Announcement Modal */
        .announcement-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .announcement-modal.active {
            display: flex;
        }

        .announcement-modal-content {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
        }

        .announcement-modal-header {
            padding: 20px 25px;
            border-bottom: 2px solid rgba(139, 92, 246, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #ffd700 0%, #ffb800 100%);
            color: #5a4200;
            border-radius: 20px 20px 0 0;
        }

        .announcement-modal-body {
            padding: 25px;
        }

        .announcement-input {
            width: 100%;
            padding: 15px;
            border: 2px solid rgba(139, 92, 246, 0.2);
            border-radius: 12px;
            font-size: 14px;
            color: #1f2937;
            background: white;
            min-height: 120px;
            resize: vertical;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin-bottom: 20px;
            transition: all 0.3s;
        }

        .announcement-input:focus {
            outline: none;
            border-color: #ffb800;
            box-shadow: 0 0 0 3px rgba(255, 184, 0, 0.1);
        }

        .announcement-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }

        .announcement-send-btn {
            padding: 12px 30px;
            background: linear-gradient(135deg, #ffd700 0%, #ffb800 100%);
            border: none;
            border-radius: 10px;
            color: #5a4200;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(255, 184, 0, 0.3);
        }

        .announcement-send-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(255, 184, 0, 0.4);
        }

        /* Screen Sharing */
        .screen-sharing-active {
            position: fixed;
            top: 80px;
            left: 30px;
            background: rgba(15, 23, 42, 0.9);
            color: white;
            padding: 12px 18px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 1000;
            animation: slideInLeft 0.3s ease;
            border: 1px solid rgba(139, 92, 246, 0.3);
            backdrop-filter: blur(10px);
        }

        @keyframes slideInLeft {
            from { transform: translateX(-100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(139, 92, 246, 0.05);
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, var(--secondary-purple) 0%, var(--primary-purple) 100%);
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Notification */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            color: white;
            padding: 12px 16px;
            border-radius: 10px;
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.3);
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 8px;
            transform: translateX(120%);
            transition: transform 0.3s ease;
            border-left: 4px solid var(--dark-purple);
            font-size: 14px;
            max-width: 300px;
        }
        
        .notification.show {
            transform: translateX(0);
        }

        /* User Info in Header */
        .user-info-header {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(139, 92, 246, 0.1);
            padding: 6px 12px;
            border-radius: 25px;
            border: 1px solid rgba(139, 92, 246, 0.3);
            min-width: 200px;
        }
        
        .user-avatar-header {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            font-size: 14px;
            flex-shrink: 0;
            box-shadow: 0 3px 10px rgba(139, 92, 246, 0.3);
        }
        
        .user-details-header {
            display: flex;
            flex-direction: column;
            min-width: 0;
            flex: 1;
        }
        
        .user-name-header {
            font-weight: 700;
            font-size: 13px;
            color: #1f2937;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .user-role-header {
            font-size: 11px;
            color: var(--primary-purple);
            font-weight: 600;
        }
        
        .logout-btn-header {
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            border: none;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 3px 10px rgba(139, 92, 246, 0.3);
            font-size: 13px;
            white-space: nowrap;
            touch-action: manipulation;
        }
        
        .logout-btn-header:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(139, 92, 246, 0.4);
        }

        /* Muted/Removed Indicators */
        .muted-indicator {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(239, 68, 68, 0.9);
            color: white;
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 6px;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }

        .removed-indicator {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            color: white;
            font-weight: 700;
            font-size: 13px;
            flex-direction: column;
            gap: 8px;
        }

        /* Mobile Responsive Styles */
        @media (max-width: 1200px) {
            .session-main {
                grid-template-columns: 1fr 300px;
            }
            
            .video-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            }
            
            .stats-grid {
                gap: 8px;
            }
            
            .stat-card-teacher {
                padding: 12px;
                min-height: 70px;
            }
            
            .stat-value-teacher {
                font-size: 22px;
            }
        }
        
        @media (max-width: 992px) {
            .session-main {
                grid-template-columns: 1fr;
                grid-template-rows: 1fr auto;
                height: calc(100vh - 70px);
            }
            
            .session-sidebar {
                grid-row: 2;
                height: 350px;
                min-height: 350px;
                border-left: none;
                border-top: 1px solid rgba(139, 92, 246, 0.1);
            }
            
            .video-section {
                height: 100%;
                overflow: auto;
            }
            
            .tab-content {
                height: 300px;
            }
            
            .chat-input-area {
                height: 70px;
                min-height: 70px;
            }
            
            .chat-messages {
                max-height: calc(100% - 70px);
            }
        }
        
        @media (max-width: 768px) {
            .session-header {
                padding: 0 15px;
            }
            
            .session-info {
                gap: 10px;
            }
            
            .session-title {
                font-size: 18px;
                max-width: 180px;
            }
            
            .session-code, .session-timer {
                font-size: 12px;
                padding: 6px 12px;
            }
            
            .user-info-header {
                min-width: 170px;
            }
            
            .video-section {
                padding: 15px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 8px;
            }
            
            .stat-card-teacher {
                padding: 10px;
                min-height: 65px;
            }
            
            .stat-value-teacher {
                font-size: 20px;
            }
            
            .stat-label-teacher {
                font-size: 11px;
            }
            
            .video-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 15px;
                padding: 15px;
            }
            
            .video-item {
                min-height: 140px;
            }
            
            .video-info {
                padding: 10px;
            }
            
            .video-user-name {
                font-size: 12px;
            }
            
            .controls-section {
                padding: 15px;
            }
            
            .control-btn {
                width: 50px;
                height: 50px;
                font-size: 18px;
            }
            
            .notification {
                max-width: 250px;
                font-size: 13px;
            }
            
            /* Analytics mobile fixes */
            .analytics-card.chart-card {
                height: 200px;
                min-height: 200px;
                max-height: 200px;
            }
            
            .analytics-card.stats-card {
                height: 140px;
                min-height: 140px;
                max-height: 140px;
            }
            
            .chart-container {
                height: 140px;
            }
            
            .metric-value {
                font-size: 16px;
            }
        }
        
        @media (max-width: 480px) {
            .analytics-grid {
                padding: 10px;
                gap: 10px;
            }
            
            .analytics-card {
                padding: 12px;
            }
            
            .analytics-card.chart-card {
                height: 180px;
                min-height: 180px;
                max-height: 180px;
            }
            
            .analytics-card.stats-card {
                height: 120px;
                min-height: 120px;
                max-height: 120px;
            }
            
            .chart-container {
                height: 120px;
            }
            
            .metric-card {
                padding: 8px;
                min-height: 60px;
            }
            
            .metric-value {
                font-size: 14px;
            }
            
            .metric-label {
                font-size: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="live-session-container">
        <!-- Header -->
        <div class="session-header">
            <div class="session-info">
                <div class="session-title">
                    <i class="fas fa-video" style="color: var(--primary-purple);"></i> <?php echo htmlspecialchars($class['class_name']); ?>
                </div>
                <div class="session-code">Room: <?php echo htmlspecialchars($room_id); ?></div>
                <div class="session-timer">
                    <i class="fas fa-clock" style="color: var(--primary-purple);"></i> <span id="sessionTimer">00:00:00</span>
                </div>
            </div>
            <div class="user-info-header">
                <div class="user-avatar-header"><?php echo getInitials($userData['full_name']); ?></div>
                <div class="user-details-header">
                    <div class="user-name-header"><?php echo htmlspecialchars($userData['full_name']); ?></div>
                    <div class="user-role-header">Instructor</div>
                </div>
                <button class="logout-btn-header" onclick="endSession()" title="Exit Session">
                    <i class="fas fa-sign-out-alt"></i> <span>Exit</span>
                </button>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="session-main">
            <!-- Video Grid Section -->
            <div class="video-section">
                <!-- Teacher Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card-teacher" onclick="showAllStudents()">
                        <div class="stat-value-teacher" id="enrolledCount"><?php echo $enrolled_students; ?></div>
                        <div class="stat-label-teacher">Enrolled Students</div>
                    </div>
                    <div class="stat-card-teacher" onclick="showActiveParticipants()">
                        <div class="stat-value-teacher" id="activeCount"><?php echo $active_participants; ?></div>
                        <div class="stat-label-teacher">Active Participants</div>
                    </div>
                    <div class="stat-card-teacher" onclick="toggleEmotionControls()">
                        <div class="stat-value-teacher" id="emotionCount"><?php echo $total_emotion_records; ?></div>
                        <div class="stat-label-teacher">Emotion Records</div>
                        <div style="font-size: 10px; color: <?php echo $class['emotion_detection_status'] == 'active' ? 'var(--success)' : ($class['emotion_detection_status'] == 'paused' ? 'var(--warning)' : 'var(--danger)'); ?>; font-weight: 600;">
                            <?php echo ucfirst($class['emotion_detection_status']); ?>
                        </div>
                    </div>
                </div>
                
                <div class="video-grid-container">
                    <div class="video-grid-header">
                        <div class="video-grid-title">
                            <i class="fas fa-users" style="color: var(--primary-purple);"></i> Live Classroom Monitoring
                        </div>
                        <div class="participant-count">
                            <span id="participantCount"><?php echo $active_participants; ?></span> connected
                        </div>
                    </div>
                    <div class="video-grid" id="videoGrid">
                        <!-- Teacher's video -->
                        <div class="video-item teacher">
                            <div class="video-placeholder" id="teacherVideoPlaceholder">
                                <div class="video-icon">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                </div>
                                <div><?php echo htmlspecialchars($userData['full_name']); ?></div>
                                <small>Teacher</small>
                            </div>
                            <video id="teacherVideo" autoplay playsinline style="display: none;" muted></video>
                            <div class="video-info">
                                <div class="video-user-name">
                                    <?php echo htmlspecialchars($userData['full_name']); ?>
                                    <span class="video-user-role">Teacher</span>
                                </div>
                                <div class="video-status">
                                    <span class="status-indicator"></span>
                                    <span>Live</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Student videos will be added here dynamically -->
                        <?php foreach ($participants as $participant): 
                            if ($participant['user_role'] === 'student'): 
                                $studentName = $participant['full_name'] ?? 'Unknown Student';
                                ?>
                                <div class="video-item student" data-user-id="<?php echo $participant['user_id']; ?>" 
                                     id="videoContainer_<?php echo $participant['user_id']; ?>"
                                     onclick="showStudentDetails(<?php echo $participant['user_id']; ?>, '<?php echo htmlspecialchars($studentName); ?>')">
                                    <div class="video-placeholder" id="placeholder_<?php echo $participant['user_id']; ?>">
                                        <div class="video-icon">
                                            <i class="fas fa-user-graduate"></i>
                                        </div>
                                        <div><?php echo htmlspecialchars($studentName); ?></div>
                                        <small>Student</small>
                                    </div>
                                    <video id="remoteVideo_<?php echo $participant['user_id']; ?>" 
                                           class="student-video-element" 
                                           autoplay playsinline></video>
                                    <div class="video-info">
                                        <div class="video-user-name">
                                            <?php echo htmlspecialchars($studentName); ?>
                                            <span class="video-user-role">Student</span>
                                        </div>
                                        <div class="video-status">
                                            <span class="status-indicator"></span>
                                            <span>Live</span>
                                        </div>
                                    </div>
                                    <!-- Emotion label will be added here dynamically -->
                                </div>
                            <?php endif;
                        endforeach; ?>
                        
                        <!-- Empty slots for remaining students -->
                        <?php for ($i = 0; $i < min(6, $empty_slots); $i++): ?>
                            <div class="video-item student">
                                <div class="video-placeholder">
                                    <div class="video-icon">
                                        <i class="fas fa-user-clock"></i>
                                    </div>
                                    <div>Waiting for student...</div>
                                    <small>Not Connected</small>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                
                <!-- Controls -->
                <div class="controls-section">
                    <div class="control-buttons">
                        <button class="control-btn control-btn-primary" id="cameraBtnMain" onclick="toggleCamera()" title="Toggle Camera">
                            <i class="fas fa-video"></i>
                        </button>
                        <button class="control-btn control-btn-primary" id="micBtnMain" onclick="toggleMicrophone()" title="Toggle Microphone">
                            <i class="fas fa-microphone"></i>
                        </button>
                        <button class="control-btn control-btn-secondary" onclick="toggleScreenShare()" id="screenShareBtnMain" title="Share Screen">
                            <i class="fas fa-desktop"></i>
                        </button>
                        <button class="control-btn control-btn-secondary" onclick="toggleEmotionControls()" id="emotionControlBtn" title="Emotion Detection Controls">
                            <i class="fas fa-brain"></i>
                        </button>
                        <button class="control-btn control-btn-danger" onclick="confirmEndSession()" id="endSessionBtn" title="End Session">
                            <i class="fas fa-stop"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Sidebar -->
            <div class="session-sidebar">
                <div class="sidebar-tabs">
                    <button class="tab-btn active" onclick="switchTab('participants')" title="Participants">
                        <i class="fas fa-users"></i> <span class="tab-text">Participants</span>
                    </button>
                    <button class="tab-btn" onclick="switchTab('analytics')" title="Analytics">
                        <i class="fas fa-chart-bar"></i> <span class="tab-text">Analytics</span>
                    </button>
                    <button class="tab-btn" onclick="switchTab('chat')" title="Chat">
                        <i class="fas fa-comments"></i> <span class="tab-text">Chat</span>
                    </button>
                </div>
                
                <div class="tab-content">
                    <!-- Participants Tab -->
                    <div class="tab-pane active" id="participantsTab">
                        <div class="participants-list" id="participantsList">
                            <!-- Teacher -->
                            <div class="participant-item active">
                                <div class="participant-avatar">
                                    <?php echo getInitials($userData['full_name']); ?>
                                </div>
                                <div class="participant-info">
                                    <div class="participant-name"><?php echo htmlspecialchars($userData['full_name']); ?> (You)</div>
                                    <div class="participant-meta">
                                        <span>Instructor</span>
                                        <span>•</span>
                                        <span>Host</span>
                                    </div>
                                </div>
                                <div class="participant-status">Online</div>
                            </div>
                            
                            <!-- Students -->
                            <?php foreach ($participants as $participant): 
                                if ($participant['user_role'] === 'student'): 
                                    $studentName = $participant['full_name'] ?? 'Unknown Student';
                                    $studentNumber = $participant['student_number'] ?? 'N/A';
                                    ?>
                                    <div class="participant-item" data-user-id="<?php echo $participant['user_id']; ?>">
                                        <div class="participant-avatar">
                                            <?php echo getInitials($studentName); ?>
                                        </div>
                                        <div class="participant-info">
                                            <div class="participant-name"><?php echo htmlspecialchars($studentName); ?></div>
                                            <div class="participant-meta">
                                                <span>Student</span>
                                                <span>•</span>
                                                <span><?php echo htmlspecialchars($studentNumber); ?></span>
                                            </div>
                                        </div>
                                        <div class="participant-status">Online</div>
                                        <div class="participant-actions">
                                            <button class="participant-action-btn" onclick="muteStudent(<?php echo $participant['user_id']; ?>, '<?php echo htmlspecialchars($studentName); ?>')">
                                                <i class="fas fa-volume-mute"></i> Mute
                                            </button>
                                            <button class="participant-action-btn remove" onclick="removeStudent(<?php echo $participant['user_id']; ?>, '<?php echo htmlspecialchars($studentName); ?>')">
                                                <i class="fas fa-user-slash"></i> Remove
                                            </button>
                                        </div>
                                    </div>
                                <?php endif;
                            endforeach; ?>
                            
                            <!-- Not connected students -->
                            <?php if ($empty_slots > 0): ?>
                                <div class="participant-item inactive">
                                    <div class="participant-avatar">
                                        <i class="fas fa-user-slash"></i>
                                    </div>
                                    <div class="participant-info">
                                        <div class="participant-name"><?php echo $empty_slots; ?> students not connected</div>
                                        <div class="participant-meta">
                                            <span>Waiting to join</span>
                                        </div>
                                    </div>
                                    <div class="participant-status offline">Offline</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Analytics Tab - FIXED VERSION -->
                    <div class="tab-pane" id="analyticsTab">
                        <div class="analytics-tab-content">
                            <div class="analytics-grid">
                                <!-- Emotion Distribution Chart -->
                                <div class="analytics-card chart-card">
                                    <div class="analytics-title">
                                        <i class="fas fa-chart-pie" style="color: var(--primary-purple);"></i>
                                        Real-time Emotion Distribution
                                    </div>
                                    <div class="chart-container">
                                        <canvas id="emotionChart"></canvas>
                                    </div>
                                </div>
                                
                                <!-- Quick Stats -->
                                <div class="analytics-card stats-card">
                                    <div class="analytics-title">
                                        <i class="fas fa-tachometer-alt" style="color: var(--primary-purple);"></i>
                                        Session Stats
                                    </div>
                                    <div class="metrics-grid">
                                        <div class="metric-card">
                                            <div class="metric-value" id="happyPercent"><?php echo $emotion_stats['happy']; ?>%</div>
                                            <div class="metric-label">Happy</div>
                                        </div>
                                        <div class="metric-card">
                                            <div class="metric-value" id="neutralPercent"><?php echo $emotion_stats['neutral']; ?>%</div>
                                            <div class="metric-label">Neutral</div>
                                        </div>
                                        <div class="metric-card">
                                            <div class="metric-value" id="confusedPercent"><?php echo $emotion_stats['confused']; ?>%</div>
                                            <div class="metric-label">Confused</div>
                                        </div>
                                        <div class="metric-card">
                                            <div class="metric-value" id="engagementScore"><?php echo $engagement_score; ?>%</div>
                                            <div class="metric-label">Engagement</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Recent Emotions -->
                                <div class="analytics-card recent-card">
                                    <div class="analytics-title">
                                        <i class="fas fa-history" style="color: var(--primary-purple);"></i>
                                        Recent Emotions
                                    </div>
                                    <div class="recent-emotions-container">
                                        <?php if (!empty($recent_emotions)): ?>
                                            <?php foreach ($recent_emotions as $emotion): 
                                                $emotionName = $emotion['full_name'] ?? 'Unknown Student';
                                                $emotionType = $emotion['facial_emotion'] ?? 'neutral';
                                                $emotionTime = $emotion['captured_at'] ?? '';
                                                $confidence = $emotion['confidence_score'] ?? 0;
                                                ?>
                                                <div class="recent-emotion-item">
                                                    <div class="recent-emotion-info">
                                                        <div class="recent-emotion-name"><?php echo htmlspecialchars($emotionName); ?></div>
                                                        <div class="recent-emotion-time"><?php echo formatDate($emotionTime, 'h:i A'); ?></div>
                                                    </div>
                                                    <div class="recent-emotion-confidence <?php echo $emotionType; ?>">
                                                        <?php echo ucfirst($emotionType); ?> (<?php echo round($confidence, 1); ?>%)
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="no-data-message">
                                                <div class="no-data-icon">
                                                    <i class="fas fa-smile"></i>
                                                </div>
                                                <div class="no-data-text">No emotion data recorded yet</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Chat Tab -->
                    <div class="tab-pane" id="chatTab">
                        <div class="chat-messages-container">
                            <div class="chat-messages" id="chatMessages">
                                <div class="chat-message system-message">
                                    <strong>System:</strong> Welcome to the live class chat! Messages are logged for record keeping.
                                </div>
                                <?php foreach ($chat_messages as $chat): 
                                    $senderId = $chat['sender_id'] ?? 0;
                                    $senderRole = $chat['sender_role'] ?? 'student';
                                    $senderName = $chat['full_name'] ?? 'Unknown User';
                                    $messageText = $chat['message'] ?? '[Empty message]';
                                    $messageTime = $chat['created_at'] ?? '';
                                    
                                    $messageClass = 'student-message';
                                    if ($senderId == $userId) {
                                        $messageClass = 'teacher-message';
                                    } elseif ($senderRole == 'instructor') {
                                        $messageClass = 'teacher-message';
                                    }
                                    ?>
                                    <div class="chat-message <?php echo $messageClass; ?>">
                                        <div class="chat-message-info">
                                            <div class="chat-sender"><?php echo htmlspecialchars($senderName); ?></div>
                                            <div class="chat-time"><?php echo formatDate($messageTime); ?></div>
                                        </div>
                                        <div class="chat-message-text"><?php echo htmlspecialchars($messageText); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="chat-input-area">
                                <div class="chat-input-wrapper">
                                    <textarea class="chat-input" id="chatInput" placeholder="Type a message to the class..." rows="1"></textarea>
                                    <button class="chat-send-btn" onclick="sendChatMessage()" title="Send Message">
                                        <i class="fas fa-paper-plane"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals and Controls -->
    <!-- Emotion Detection Controls -->
    <div class="emotion-controls" id="emotionControls">
        <div class="emotion-control-title">Emotion Detection</div>
        <div class="emotion-control-buttons">
            <button class="emotion-control-btn start" onclick="startEmotionDetection()">
                <i class="fas fa-play"></i> Start
            </button>
            <button class="emotion-control-btn pause" onclick="pauseEmotionDetection()">
                <i class="fas fa-pause"></i> Pause
            </button>
            <button class="emotion-control-btn stop" onclick="stopEmotionDetection()">
                <i class="fas fa-stop"></i> Stop
            </button>
        </div>
        <div style="font-size: 11px; color: var(--dark-gray); margin-top: 5px;">
            Status: <span id="emotionDetectionStatus"><?php echo ucfirst($class['emotion_detection_status']); ?></span>
        </div>
    </div>

    <!-- Individual Student Details Modal -->
    <div class="student-modal" id="studentModal">
        <div class="student-modal-content">
            <div class="student-modal-header">
                <h3 id="studentModalTitle">
                    <i class="fas fa-user-graduate"></i> Student Details
                </h3>
                <button class="student-modal-close" onclick="closeStudentModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="student-modal-body">
                <div class="student-info-grid">
                    <div class="student-info-item">
                        <div class="student-info-label">Current Emotion</div>
                        <div class="student-info-value" id="studentCurrentEmotion">Neutral</div>
                    </div>
                    <div class="student-info-item">
                        <div class="student-info-label">Confidence</div>
                        <div class="student-info-value" id="studentConfidence">85%</div>
                    </div>
                    <div class="student-info-item">
                        <div class="student-info-label">Time in Session</div>
                        <div class="student-info-value" id="studentTimeInSession">45 mins</div>
                    </div>
                    <div class="student-info-item">
                        <div class="student-info-label">Engagement Score</div>
                        <div class="student-info-value" id="studentEngagementScore">78%</div>
                    </div>
                </div>
                
                <div class="emotion-timeline">
                    <div class="emotion-timeline-title">
                        <i class="fas fa-history"></i> Emotion Timeline (Last 30 mins)
                    </div>
                    <div id="studentEmotionTimeline">
                        <!-- Timeline items will be added here -->
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: center;">
                    <button class="control-btn control-btn-secondary" onclick="muteCurrentStudent()">
                        <i class="fas fa-volume-mute"></i> Mute Audio
                    </button>
                    <button class="control-btn control-btn-danger" onclick="removeCurrentStudent()">
                        <i class="fas fa-user-slash"></i> Remove
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Announcement Modal -->
    <div class="announcement-modal" id="announcementModal">
        <div class="announcement-modal-content">
            <div class="announcement-modal-header">
                <h3>
                    <i class="fas fa-bullhorn"></i> Send Announcement
                </h3>
                <button class="student-modal-close" onclick="closeAnnouncementModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="announcement-modal-body">
                <textarea class="announcement-input" id="announcementInput" placeholder="Type your announcement here..."></textarea>
                <div class="announcement-actions">
                    <button class="announcement-send-btn" onclick="sendAnnouncement()">
                        <i class="fas fa-paper-plane"></i> Send Announcement
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Screen Sharing Indicator -->
    <div class="screen-sharing-active" id="screenSharingIndicator" style="display: none;">
        <i class="fas fa-desktop"></i> Screen Sharing Active
        <button onclick="stopScreenShare()" style="background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%); border: none; color: white; padding: 4px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; margin-left: 10px; font-weight: 600;">
            Stop
        </button>
    </div>

    <!-- Notification Container -->
    <div id="notificationContainer"></div>

   <script>
    // ==================== GLOBAL VARIABLES ====================
    let sessionStartTime = new Date("<?php echo $class['start_time']; ?>");
    let sessionId = <?php echo $session_id; ?>;
    let classId = <?php echo $class_id; ?>;
    let userId = <?php echo $userId; ?>;
    let userName = '<?php echo addslashes($userData['full_name']); ?>';
    let roomId = '<?php echo $room_id; ?>';
    let currentEmotionStatus = '<?php echo $class['emotion_detection_status']; ?>';
    let currentStudentId = null;
    let currentStudentName = null;
    let emotionChart = null;
    let emotionPollingInterval = null;
    let chatPollingInterval = null;
    let participantPollingInterval = null;
    
    // WebRTC Variables
    let localStream = null;
    let screenStream = null;
    let cameraActive = true;
    let microphoneActive = true;
    let isSharingScreen = false;
    let peerConnections = new Map();
    let connectedStudents = new Set(); // Track connected students
    
    const configuration = {
        iceServers: [
            { urls: 'stun:stun.l.google.com:19302' },
            { urls: 'stun:stun1.l.google.com:19302' },
            { urls: 'stun:stun2.l.google.com:19302' },
            { urls: 'stun:stun3.l.google.com:19302' },
            { urls: 'stun:stun4.l.google.com:19302' }
        ],
        iceCandidatePoolSize: 10
    };
    
    let signalingPollingInterval = null;
    
    // ==================== REAL-TIME STUDENT MONITORING ====================
    
    // Poll for new participants
    function startParticipantPolling() {
        if (participantPollingInterval) clearInterval(participantPollingInterval);
        
        participantPollingInterval = setInterval(async () => {
            try {
                const response = await fetch(`?action=get_session_participants&session_id=${sessionId}&t=${Date.now()}`);
                const result = await response.json();
                
                if (result.success) {
                    updateParticipantList(result.participants);
                    updateVideoGrid(result.participants);
                    updateParticipantCounts(result.participants);
                }
            } catch (error) {
                console.error('Error polling participants:', error);
            }
        }, 3000); // Poll every 3 seconds
    }
    
    // Update participant list in sidebar
    function updateParticipantList(participants) {
        const participantsList = document.getElementById('participantsList');
        if (!participantsList) return;
        
        // Get current list of student IDs
        const currentStudentIds = new Set();
        document.querySelectorAll('.participant-item[data-user-id]').forEach(item => {
            const id = item.getAttribute('data-user-id');
            if (id && id != userId) currentStudentIds.add(parseInt(id));
        });
        
        // Filter only students
        const studentParticipants = participants.filter(p => p.user_role === 'student');
        
        // Add new students
        studentParticipants.forEach(student => {
            const studentId = student.user_id;
            if (!currentStudentIds.has(studentId)) {
                addParticipantToList(student);
            }
        });
        
        // Remove disconnected students
        currentStudentIds.forEach(studentId => {
            const stillConnected = studentParticipants.some(s => s.user_id === studentId);
            if (!stillConnected) {
                removeParticipantFromList(studentId);
            }
        });
    }
    
    // Add a participant to the list
    function addParticipantToList(student) {
        const participantsList = document.getElementById('participantsList');
        if (!participantsList) return;
        
        const studentName = student.full_name || 'Unknown Student';
        const studentNumber = student.student_number || 'N/A';
        
        const participantItem = document.createElement('div');
        participantItem.className = 'participant-item';
        participantItem.setAttribute('data-user-id', student.user_id);
        
        participantItem.innerHTML = `
            <div class="participant-avatar">
                ${getInitialsFromName(studentName)}
            </div>
            <div class="participant-info">
                <div class="participant-name">${escapeHtml(studentName)}</div>
                <div class="participant-meta">
                    <span>Student</span>
                    <span>•</span>
                    <span>${escapeHtml(studentNumber)}</span>
                </div>
            </div>
            <div class="participant-status">Online</div>
            <div class="participant-actions">
                <button class="participant-action-btn" onclick="muteStudent(${student.user_id}, '${escapeHtml(studentName)}')">
                    <i class="fas fa-volume-mute"></i> Mute
                </button>
                <button class="participant-action-btn remove" onclick="removeStudent(${student.user_id}, '${escapeHtml(studentName)}')">
                    <i class="fas fa-user-slash"></i> Remove
                </button>
            </div>
        `;
        
        // Add after teacher but before "not connected" message
        const teacherItem = participantsList.querySelector('.participant-item.active');
        if (teacherItem) {
            teacherItem.after(participantItem);
        } else {
            participantsList.appendChild(participantItem);
        }
        
        // Show notification
        showNotification(`${studentName} joined the class`, 'success');
        
        // Initialize WebRTC connection with new student
        setTimeout(() => {
            initWebRTCForStudent(student.user_id);
        }, 1000);
    }
    
    // Remove participant from list
    function removeParticipantFromList(studentId) {
        const participantItem = document.querySelector(`.participant-item[data-user-id="${studentId}"]`);
        if (participantItem) {
            participantItem.remove();
        }
    }
    
    // Update video grid with new participants
    function updateVideoGrid(participants) {
        const videoGrid = document.getElementById('videoGrid');
        if (!videoGrid) return;
        
        // Get current student video containers
        const currentStudentIds = new Set();
        document.querySelectorAll('.video-item.student[data-user-id]').forEach(item => {
            const id = item.getAttribute('data-user-id');
            if (id) currentStudentIds.add(parseInt(id));
        });
        
        // Filter only students
        const studentParticipants = participants.filter(p => p.user_role === 'student');
        
        // Add new student video items
        studentParticipants.forEach(student => {
            const studentId = student.user_id;
            if (!currentStudentIds.has(studentId)) {
                addStudentVideoItem(student);
                connectedStudents.add(studentId);
            }
        });
        
        // Remove disconnected student video items
        currentStudentIds.forEach(studentId => {
            const stillConnected = studentParticipants.some(s => s.user_id === studentId);
            if (!stillConnected) {
                removeStudentVideoItem(studentId);
                connectedStudents.delete(studentId);
            }
        });
    }
    
    // Add student video item to grid
    function addStudentVideoItem(student) {
        const videoGrid = document.getElementById('videoGrid');
        if (!videoGrid) return;
        
        const studentName = student.full_name || 'Unknown Student';
        const studentId = student.user_id;
        
        const videoItem = document.createElement('div');
        videoItem.className = 'video-item student';
        videoItem.setAttribute('data-user-id', studentId);
        videoItem.id = `videoContainer_${studentId}`;
        videoItem.onclick = () => showStudentDetails(studentId, studentName);
        
        videoItem.innerHTML = `
            <div class="video-placeholder" id="placeholder_${studentId}">
                <div class="video-icon">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div>${escapeHtml(studentName)}</div>
                <small>Student</small>
            </div>
            <video id="remoteVideo_${studentId}" 
                   class="student-video-element" 
                   autoplay playsinline></video>
            <div class="video-info">
                <div class="video-user-name">
                    ${escapeHtml(studentName)}
                    <span class="video-user-role">Student</span>
                </div>
                <div class="video-status">
                    <span class="status-indicator"></span>
                    <span>Connecting...</span>
                </div>
            </div>
        `;
        
        // Add to video grid (after teacher video)
        const teacherVideo = videoGrid.querySelector('.video-item.teacher');
        if (teacherVideo) {
            teacherVideo.after(videoItem);
        } else {
            videoGrid.appendChild(videoItem);
        }
        
        // Update counts
        updateParticipantCounts();
    }
    
    // Remove student video item
    function removeStudentVideoItem(studentId) {
        const videoItem = document.querySelector(`.video-item[data-user-id="${studentId}"]`);
        if (videoItem) {
            videoItem.remove();
        }
        
        // Close WebRTC connection
        if (peerConnections.has(studentId)) {
            peerConnections.get(studentId).close();
            peerConnections.delete(studentId);
        }
        
        // Update counts
        updateParticipantCounts();
    }
    
    // Update participant counts
    function updateParticipantCounts(participants = null) {
        let activeStudents = 0;
        
        if (participants) {
            activeStudents = participants.filter(p => p.user_role === 'student').length;
        } else {
            // Count from DOM
            activeStudents = document.querySelectorAll('.video-item.student[data-user-id]').length;
        }
        
        // Update display
        const activeCountElement = document.getElementById('activeCount');
        const participantCountElement = document.getElementById('participantCount');
        
        if (activeCountElement) {
            activeCountElement.textContent = activeStudents;
        }
        if (participantCountElement) {
            participantCountElement.textContent = activeStudents;
        }
    }
    
    // ==================== EMOTION DETECTION SYSTEM ====================
    
    // Update emotion label for a student
    function updateEmotionLabel(studentId, emotion, confidence) {
        const videoContainer = document.getElementById(`videoContainer_${studentId}`);
        if (!videoContainer) return;
        
        // Remove existing emotion label
        const existingLabel = videoContainer.querySelector('.emotion-label');
        if (existingLabel) existingLabel.remove();
        
        // Don't show emotion for teacher (user should not see their own emotion)
        if (studentId == userId) return;
        
        // Determine what to display
        let displayEmotion = emotion;
        let displayText = '';
        let cssClass = 'emotion-label ';
        
        if (emotion === 'no_face' || confidence === 0 || confidence < 30) {
            // No face detected or very low confidence
            displayEmotion = 'no-face';
            displayText = 'No face detected';
            cssClass += 'no-face';
        } else {
            // Valid emotion detected
            displayText = `${emotion.toUpperCase()} (${Math.round(confidence)}%)`;
            cssClass += emotion;
        }
        
        // Create new emotion label
        const emotionLabel = document.createElement('div');
        emotionLabel.className = cssClass;
        emotionLabel.textContent = displayText;
        emotionLabel.title = displayText;
        
        // Position at upper-left corner
        emotionLabel.style.position = 'absolute';
        emotionLabel.style.top = '10px';
        emotionLabel.style.left = '10px';
        emotionLabel.style.zIndex = '10';
        
        videoContainer.appendChild(emotionLabel);
        
        // Update in analytics if this student is currently viewed
        if (currentStudentId === studentId) {
            if (displayEmotion === 'no-face') {
                document.getElementById('studentCurrentEmotion').textContent = 'No face detected';
                document.getElementById('studentConfidence').textContent = '0%';
            } else {
                document.getElementById('studentCurrentEmotion').textContent = 
                    `${capitalizeFirst(emotion)} (${Math.round(confidence)}%)`;
                document.getElementById('studentConfidence').textContent = `${Math.round(confidence)}%`;
            }
        }
    }
    
    // Start emotion detection polling
    function startEmotionPolling() {
        if (emotionPollingInterval) clearInterval(emotionPollingInterval);
        
        emotionPollingInterval = setInterval(async () => {
            if (currentEmotionStatus !== 'active') return;
            
            try {
                // Get latest emotion updates for all students
                const response = await fetch(`?action=get_emotion_updates&session_id=${sessionId}&last_id=${window.lastEmotionId || 0}`);
                const result = await response.json();
                
                if (result.success && result.data.length > 0) {
                    result.data.forEach(emotionData => {
                        const studentId = emotionData.student_id;
                        const emotion = emotionData.facial_emotion || 'neutral';
                        const confidence = emotionData.confidence_score || 0;
                        
                        // Update emotion label on video feed
                        updateEmotionLabel(studentId, emotion, confidence);
                        
                        // Update analytics if chart exists
                        if (emotionChart) {
                            updateEmotionChart(emotionData);
                        }
                        
                        window.lastEmotionId = Math.max(window.lastEmotionId || 0, emotionData.id);
                    });
                } else {
                    // If no emotion data, check if we should show "No face detected" for connected students
                    // Only do this if we haven't received any data for a while
                    const now = Date.now();
                    if (!window.lastEmotionCheck || now - window.lastEmotionCheck > 10000) {
                        window.lastEmotionCheck = now;
                        
                        // For each connected student, check if we need to show "No face detected"
                        connectedStudents.forEach(studentId => {
                            // Check if this student has recent emotion data
                            // If not, show "No face detected"
                            const videoContainer = document.getElementById(`videoContainer_${studentId}`);
                            if (videoContainer) {
                                const existingLabel = videoContainer.querySelector('.emotion-label');
                                if (!existingLabel) {
                                    // No emotion label exists, show "No face detected"
                                    updateEmotionLabel(studentId, 'no_face', 0);
                                }
                            }
                        });
                    }
                }
            } catch (error) {
                console.error('Error polling emotions:', error);
            }
        }, 2000); // Poll every 2 seconds
    }
    
    // Update emotion chart with new data
    function updateEmotionChart(emotionData) {
        const emotion = emotionData.facial_emotion || 'neutral';
        const index = ['happy', 'neutral', 'sad', 'angry', 'confused'].indexOf(emotion);
        
        if (index !== -1) {
            // Increment count for this emotion
            emotionChart.data.datasets[0].data[index]++;
            emotionChart.update('none');
            
            // Update percentages in stats cards
            const total = emotionChart.data.datasets[0].data.reduce((a, b) => a + b, 0);
            if (total > 0) {
                const percentages = emotionChart.data.datasets[0].data.map(count => 
                    Math.round((count / total) * 100 * 10) / 10
                );
                
                // Update displayed percentages
                const emotionLabels = ['happyPercent', 'neutralPercent', 'sadPercent', 'angryPercent', 'confusedPercent'];
                percentages.forEach((percent, i) => {
                    const element = document.getElementById(emotionLabels[i]);
                    if (element) element.textContent = `${percent}%`;
                });
                
                // Update engagement score
                const engagementScore = Math.round(
                    (percentages[0] * 0.8 + percentages[1] * 0.6 + 
                     (100 - percentages[2] - percentages[3] - percentages[4]) * 0.4) / 100 * 100
                ) / 100;
                const engagementElement = document.getElementById('engagementScore');
                if (engagementElement) engagementElement.textContent = `${engagementScore}%`;
            }
        }
    }
    
    // Emotion detection controls
    async function startEmotionDetection() {
        try {
            const formData = new FormData();
            formData.append('action', 'update_emotion_tracking');
            formData.append('session_id', sessionId);
            formData.append('status', '1'); // 1 = active
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            if (result.success) {
                currentEmotionStatus = 'active';
                document.getElementById('emotionDetectionStatus').textContent = 'Active';
                showNotification('Emotion detection started', 'success');
                
                // Start polling for emotion updates
                startEmotionPolling();
                
                // Initialize emotion labels for existing students
                <?php foreach ($student_emotions as $emotion): ?>
                    updateEmotionLabel(
                        <?php echo $emotion['student_id']; ?>, 
                        '<?php echo $emotion['facial_emotion']; ?>', 
                        <?php echo $emotion['confidence_score']; ?>
                    );
                <?php endforeach; ?>
            }
        } catch (error) {
            console.error('Error starting emotion detection:', error);
            showNotification('Failed to start emotion detection', 'error');
        }
    }
    
    async function pauseEmotionDetection() {
        try {
            const formData = new FormData();
            formData.append('action', 'update_emotion_tracking');
            formData.append('session_id', sessionId);
            formData.append('status', '2'); // 2 = paused
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            if (result.success) {
                currentEmotionStatus = 'paused';
                document.getElementById('emotionDetectionStatus').textContent = 'Paused';
                showNotification('Emotion detection paused', 'warning');
                
                // Stop emotion polling
                if (emotionPollingInterval) {
                    clearInterval(emotionPollingInterval);
                    emotionPollingInterval = null;
                }
            }
        } catch (error) {
            console.error('Error pausing emotion detection:', error);
            showNotification('Failed to pause emotion detection', 'error');
        }
    }
    
    async function stopEmotionDetection() {
        try {
            const formData = new FormData();
            formData.append('action', 'update_emotion_tracking');
            formData.append('session_id', sessionId);
            formData.append('status', '0'); // 0 = stopped
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            if (result.success) {
                currentEmotionStatus = 'stopped';
                document.getElementById('emotionDetectionStatus').textContent = 'Stopped';
                showNotification('Emotion detection stopped', 'info');
                
                // Stop emotion polling
                if (emotionPollingInterval) {
                    clearInterval(emotionPollingInterval);
                    emotionPollingInterval = null;
                }
                
                // Remove all emotion labels
                document.querySelectorAll('.emotion-label').forEach(label => label.remove());
            }
        } catch (error) {
            console.error('Error stopping emotion detection:', error);
            showNotification('Failed to stop emotion detection', 'error');
        }
    }
    
    function toggleEmotionControls() {
        const controls = document.getElementById('emotionControls');
        controls.classList.toggle('active');
    }
    
    // ==================== WEBRTC IMPLEMENTATION ====================
    
    function updateTimer() {
        const now = new Date();
        const diff = now - sessionStartTime;
        const hours = Math.floor(diff / 3600000);
        const minutes = Math.floor((diff % 3600000) / 60000);
        const seconds = Math.floor((diff % 60000) / 1000);
        
        document.getElementById('sessionTimer').textContent = 
            `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    }

    async function initTeacherMedia() {
        try {
            localStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    width: { ideal: 640 },
                    height: { ideal: 480 },
                    facingMode: 'user'
                },
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true
                }
            });

            const teacherVideo = document.getElementById('teacherVideo');
            teacherVideo.srcObject = localStream;
            
            document.getElementById('teacherVideoPlaceholder').style.display = 'none';
            teacherVideo.style.display = 'block';
            
            showNotification('Camera and microphone enabled', 'success');
            
            startSignalingPolling();
            
        } catch (error) {
            console.error('Error accessing media devices:', error);
            showNotification('Could not access camera/microphone. Using audio only.', 'warning');
        }
    }

    function createPeerConnection(studentId) {
        console.log('Creating peer connection for student:', studentId);
        
        if (peerConnections.has(studentId)) {
            console.log('Peer connection already exists for student:', studentId);
            return peerConnections.get(studentId);
        }
        
        const peerConnection = new RTCPeerConnection(configuration);
        
        if (localStream) {
            localStream.getTracks().forEach(track => {
                peerConnection.addTrack(track, localStream);
            });
        }
        
        peerConnection.onicecandidate = (event) => {
            if (event.candidate) {
                sendSignal(studentId, {
                    type: 'candidate',
                    candidate: event.candidate
                });
            }
        };
        
        peerConnection.ontrack = (event) => {
            console.log('Received remote track from student:', studentId);
            const remoteVideo = document.getElementById(`remoteVideo_${studentId}`);
            if (remoteVideo) {
                remoteVideo.srcObject = event.streams[0];
                
                const videoContainer = document.getElementById(`videoContainer_${studentId}`);
                if (videoContainer) {
                    videoContainer.classList.add('active-stream');
                }
                
                // Update status to "Live"
                const statusElement = videoContainer.querySelector('.video-status span:last-child');
                if (statusElement) {
                    statusElement.textContent = 'Live';
                }
                
                updateStudentStreamStatus(studentId, true);
                
                // Initialize emotion label for this student
                if (currentEmotionStatus === 'active') {
                    // Initially show "No face detected" until we get emotion data
                    setTimeout(() => {
                        updateEmotionLabel(studentId, 'no_face', 0);
                    }, 1000);
                }
            }
        };
        
        peerConnection.onconnectionstatechange = () => {
            console.log(`Connection state for student ${studentId}:`, peerConnection.connectionState);
            
            const videoContainer = document.getElementById(`videoContainer_${studentId}`);
            const statusElement = videoContainer ? videoContainer.querySelector('.video-status span:last-child') : null;
            
            if (peerConnection.connectionState === 'connected') {
                if (statusElement) statusElement.textContent = 'Live';
                showNotification(`Connected to student ${studentId}`, 'success');
            } else if (peerConnection.connectionState === 'failed' || 
                      peerConnection.connectionState === 'disconnected' ||
                      peerConnection.connectionState === 'closed') {
                console.warn(`Connection lost with student ${studentId}`);
                
                if (statusElement) statusElement.textContent = 'Disconnected';
                
                const remoteVideo = document.getElementById(`remoteVideo_${studentId}`);
                if (remoteVideo) {
                    remoteVideo.srcObject = null;
                    
                    if (videoContainer) {
                        videoContainer.classList.remove('active-stream');
                    }
                }
                
                updateStudentStreamStatus(studentId, false);
                peerConnections.delete(studentId);
                
                // Try to reconnect after 5 seconds
                setTimeout(() => {
                    if (!peerConnections.has(studentId) && connectedStudents.has(studentId)) {
                        console.log(`Attempting to reconnect to student ${studentId}`);
                        initWebRTCForStudent(studentId);
                    }
                }, 5000);
            }
        };
        
        peerConnections.set(studentId, peerConnection);
        
        return peerConnection;
    }

    async function sendSignal(studentId, signal) {
        try {
            const formData = new FormData();
            formData.append('action', 'webrtc_signal');
            formData.append('session_id', sessionId);
            formData.append('target_user_id', studentId);
            formData.append('sender_user_id', userId);
            formData.append('signal_type', signal.type);
            formData.append('signal_data', JSON.stringify(signal));
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            if (!result.success) {
                console.error('Failed to send signal:', result.error);
            }
        } catch (error) {
            console.error('Error sending signal:', error);
        }
    }

    async function pollSignals() {
        try {
            const formData = new FormData();
            formData.append('action', 'get_webrtc_signals');
            formData.append('session_id', sessionId);
            formData.append('last_signal_id', window.lastSignalId || 0);
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            if (result.success && result.signals.length > 0) {
                result.signals.forEach(signal => {
                    handleIncomingSignal(signal);
                    window.lastSignalId = Math.max(window.lastSignalId || 0, signal.id);
                });
            }
        } catch (error) {
            console.error('Error polling signals:', error);
        }
    }

    async function handleIncomingSignal(signal) {
        const signalData = JSON.parse(signal.signal_data);
        const studentId = signal.sender_id;
        
        console.log('Received signal from student:', studentId, 'Type:', signalData.type);
        
        let peerConnection = peerConnections.get(studentId);
        if (!peerConnection) {
            peerConnection = createPeerConnection(studentId);
        }
        
        try {
            switch (signalData.type) {
                case 'offer':
                    await peerConnection.setRemoteDescription(new RTCSessionDescription(signalData));
                    const answer = await peerConnection.createAnswer();
                    await peerConnection.setLocalDescription(answer);
                    await sendSignal(studentId, answer);
                    break;
                    
                case 'answer':
                    await peerConnection.setRemoteDescription(new RTCSessionDescription(signalData));
                    break;
                    
                case 'candidate':
                    await peerConnection.addIceCandidate(new RTCIceCandidate(signalData.candidate));
                    break;
            }
        } catch (error) {
            console.error('Error handling signal:', error);
        }
    }

    function startSignalingPolling() {
        signalingPollingInterval = setInterval(pollSignals, 2000);
    }

    async function updateStudentStreamStatus(studentId, isActive) {
        try {
            const formData = new FormData();
            formData.append('action', 'update_student_stream');
            formData.append('session_id', sessionId);
            formData.append('student_id', studentId);
            formData.append('stream_active', isActive ? '1' : '0');
            
            await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
        } catch (error) {
            console.error('Error updating stream status:', error);
        }
    }

    async function initWebRTCForStudent(studentId) {
        console.log('Initializing WebRTC for student:', studentId);
        
        const peerConnection = createPeerConnection(studentId);
        
        try {
            const offer = await peerConnection.createOffer({
                offerToReceiveAudio: true,
                offerToReceiveVideo: true
            });
            
            await peerConnection.setLocalDescription(offer);
            await sendSignal(studentId, offer);
            
        } catch (error) {
            console.error('Error creating offer:', error);
        }
    }

    async function updateDeviceStatus() {
        try {
            const formData = new FormData();
            formData.append('action', 'update_device_status');
            formData.append('session_id', sessionId);
            formData.append('user_id', userId);
            formData.append('camera_active', cameraActive ? '1' : '0');
            formData.append('mic_active', microphoneActive ? '1' : '0');
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            if (!result.success) {
                console.error('Failed to update device status:', result.error);
            }
            
            peerConnections.forEach((connection, studentId) => {
                const senders = connection.getSenders();
                senders.forEach(sender => {
                    if (sender.track) {
                        sender.track.enabled = (sender.track.kind === 'video') ? cameraActive : microphoneActive;
                    }
                });
            });
            
        } catch (error) {
            console.error('Error updating device status:', error);
        }
    }

    async function toggleCamera() {
        if (localStream) {
            const videoTrack = localStream.getVideoTracks()[0];
            if (videoTrack) {
                videoTrack.enabled = !videoTrack.enabled;
                cameraActive = videoTrack.enabled;
                
                const btn = document.getElementById('cameraBtnMain');
                const icon = cameraActive ? '<i class="fas fa-video"></i>' : '<i class="fas fa-video-slash"></i>';
                
                btn.innerHTML = icon;
                btn.classList.toggle('active', !cameraActive);
                
                const placeholder = document.getElementById('teacherVideoPlaceholder');
                const video = document.getElementById('teacherVideo');
                
                if (cameraActive) {
                    placeholder.style.display = 'none';
                    video.style.display = 'block';
                } else {
                    placeholder.style.display = 'flex';
                    video.style.display = 'none';
                }
                
                updateDeviceStatus();
            }
        }
    }

    async function toggleMicrophone() {
        if (localStream) {
            const audioTrack = localStream.getAudioTracks()[0];
            if (audioTrack) {
                audioTrack.enabled = !audioTrack.enabled;
                microphoneActive = audioTrack.enabled;
                
                const btn = document.getElementById('micBtnMain');
                const icon = microphoneActive ? '<i class="fas fa-microphone"></i>' : '<i class="fas fa-microphone-slash"></i>';
                
                btn.innerHTML = icon;
                btn.classList.toggle('active', !microphoneActive);
                
                updateDeviceStatus();
            }
        }
    }

    async function startScreenShare() {
        try {
            screenStream = await navigator.mediaDevices.getDisplayMedia({
                video: {
                    cursor: "always",
                    displaySurface: "monitor"
                },
                audio: false
            });

            const teacherVideo = document.getElementById('teacherVideo');
            teacherVideo.srcObject = screenStream;
            
            const screenVideoTrack = screenStream.getVideoTracks()[0];
            peerConnections.forEach((connection, studentId) => {
                const sender = connection.getSenders().find(s => s.track && s.track.kind === 'video');
                if (sender) {
                    sender.replaceTrack(screenVideoTrack);
                }
            });
            
            const btn = document.getElementById('screenShareBtnMain');
            btn.innerHTML = '<i class="fas fa-stop"></i>';
            btn.classList.add('active');
            
            document.getElementById('screenSharingIndicator').style.display = 'flex';
            
            isSharingScreen = true;
            showNotification('Screen sharing started', 'success');
            
            screenStream.getVideoTracks()[0].addEventListener('ended', () => {
                stopScreenShare();
            });
            
        } catch (error) {
            console.error('Error starting screen share:', error);
            if (error.name !== 'NotAllowedError') {
                showNotification('Failed to start screen sharing', 'error');
            }
        }
    }

    async function stopScreenShare() {
        if (screenStream) {
            screenStream.getTracks().forEach(track => track.stop());
            screenStream = null;
        }
        
        const teacherVideo = document.getElementById('teacherVideo');
        if (localStream) {
            teacherVideo.srcObject = localStream;
            
            const cameraVideoTrack = localStream.getVideoTracks()[0];
            peerConnections.forEach((connection, studentId) => {
                const sender = connection.getSenders().find(s => s.track && s.track.kind === 'video');
                if (sender) {
                    sender.replaceTrack(cameraVideoTrack);
                }
            });
        }
        
        const btn = document.getElementById('screenShareBtnMain');
        btn.innerHTML = '<i class="fas fa-desktop"></i>';
        btn.classList.remove('active');
        
        document.getElementById('screenSharingIndicator').style.display = 'none';
        
        isSharingScreen = false;
        showNotification('Screen sharing stopped', 'info');
    }

    function cleanup() {
        if (signalingPollingInterval) clearInterval(signalingPollingInterval);
        if (emotionPollingInterval) clearInterval(emotionPollingInterval);
        if (chatPollingInterval) clearInterval(chatPollingInterval);
        if (participantPollingInterval) clearInterval(participantPollingInterval);
        
        peerConnections.forEach((connection, studentId) => {
            connection.close();
        });
        peerConnections.clear();
        
        if (localStream) localStream.getTracks().forEach(track => track.stop());
        if (screenStream) screenStream.getTracks().forEach(track => track.stop());
        
        if (emotionChart) emotionChart.destroy();
    }

    // ==================== EXISTING FUNCTIONS (UPDATED) ====================
    
    function switchTab(tabName) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        event.target.classList.add('active');
        
        document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
        document.getElementById(tabName + 'Tab').classList.add('active');
        
        if (tabName === 'chat') {
            setTimeout(() => {
                const chatMessages = document.getElementById('chatMessages');
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }, 100);
        } else if (tabName === 'analytics' && !emotionChart) {
            initAnalytics();
        }
        
        event.preventDefault();
    }

    async function showStudentDetails(studentId, studentName) {
        try {
            currentStudentId = studentId;
            currentStudentName = studentName;
            
            document.getElementById('studentModalTitle').innerHTML = 
                `<i class="fas fa-user-graduate"></i> ${studentName}`;
            
            // Load student's emotion history
            const response = await fetch(`?action=get_emotion_updates&session_id=${sessionId}&student_id=${studentId}`);
            const result = await response.json();
            
            if (result.success && result.data.length > 0) {
                const latestEmotion = result.data[0];
                if (latestEmotion.confidence_score > 0) {
                    document.getElementById('studentCurrentEmotion').textContent = 
                        `${capitalizeFirst(latestEmotion.facial_emotion)} (${Math.round(latestEmotion.confidence_score)}%)`;
                    document.getElementById('studentConfidence').textContent = 
                        `${Math.round(latestEmotion.confidence_score)}%`;
                } else {
                    document.getElementById('studentCurrentEmotion').textContent = 'No face detected';
                    document.getElementById('studentConfidence').textContent = '0%';
                }
                updateEmotionTimeline(result.data.slice(0, 8));
            } else {
                document.getElementById('studentCurrentEmotion').textContent = 'No face detected';
                document.getElementById('studentConfidence').textContent = '0%';
                updateEmotionTimeline([]);
            }
            
            document.getElementById('studentModal').classList.add('active');
            
        } catch (error) {
            console.error('Error loading student details:', error);
            showNotification('Failed to load student details', 'error');
        }
    }

    function closeStudentModal() {
        document.getElementById('studentModal').classList.remove('active');
        currentStudentId = null;
        currentStudentName = null;
    }

    async function muteStudent(studentId, studentName) {
        if (confirm(`Mute audio for ${studentName}?`)) {
            try {
                const formData = new FormData();
                formData.append('action', 'mute_student');
                formData.append('session_id', sessionId);
                formData.append('student_id', studentId);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    showNotification(`${studentName} has been muted`, 'success');
                    
                    const participantItem = document.querySelector(`.participant-item[data-user-id="${studentId}"]`);
                    if (participantItem) {
                        const statusDiv = participantItem.querySelector('.participant-status');
                        statusDiv.textContent = 'Muted';
                        statusDiv.classList.remove('online');
                        statusDiv.classList.add('offline');
                        
                        const videoItem = document.querySelector(`.video-item[data-user-id="${studentId}"]`);
                        if (videoItem) {
                            const existingMuted = videoItem.querySelector('.muted-indicator');
                            if (!existingMuted) {
                                const mutedIndicator = document.createElement('div');
                                mutedIndicator.className = 'muted-indicator';
                                mutedIndicator.textContent = 'Muted';
                                videoItem.appendChild(mutedIndicator);
                            }
                        }
                    }
                }
            } catch (error) {
                console.error('Error muting student:', error);
                showNotification('Failed to mute student', 'error');
            }
        }
    }

    async function removeStudent(studentId, studentName) {
        if (confirm(`Remove ${studentName} from the session?`)) {
            try {
                const formData = new FormData();
                formData.append('action', 'remove_student');
                formData.append('session_id', sessionId);
                formData.append('student_id', studentId);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    showNotification(`${studentName} has been removed`, 'success');
                    
                    removeParticipantFromList(studentId);
                    removeStudentVideoItem(studentId);
                    connectedStudents.delete(studentId);
                }
            } catch (error) {
                console.error('Error removing student:', error);
                showNotification('Failed to remove student', 'error');
            }
        }
    }

    function muteCurrentStudent() {
        if (currentStudentId && currentStudentName) {
            muteStudent(currentStudentId, currentStudentName);
        }
    }

    function removeCurrentStudent() {
        if (currentStudentId && currentStudentName) {
            removeStudent(currentStudentId, currentStudentName);
            closeStudentModal();
        }
    }

    function openAnnouncementModal() {
        document.getElementById('announcementModal').classList.add('active');
    }

    function closeAnnouncementModal() {
        document.getElementById('announcementModal').classList.remove('active');
        document.getElementById('announcementInput').value = '';
    }

    async function sendAnnouncement() {
        const announcement = document.getElementById('announcementInput').value.trim();
        if (!announcement) {
            showNotification('Please enter an announcement message', 'warning');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('action', 'send_announcement');
            formData.append('session_id', sessionId);
            formData.append('class_id', classId);
            formData.append('teacher_id', userId);
            formData.append('message', announcement);
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            if (result.success) {
                showNotification('Announcement sent successfully!', 'success');
                closeAnnouncementModal();
                addAnnouncementToChat(announcement);
            } else {
                showNotification('Failed to send announcement: ' + result.error, 'error');
            }
        } catch (error) {
            console.error('Error sending announcement:', error);
            showNotification('Failed to send announcement: ' + error.message, 'error');
        }
    }

    function addAnnouncementToChat(announcement) {
        const chatMessages = document.getElementById('chatMessages');
        const messageElement = document.createElement('div');
        messageElement.className = 'chat-message announcement-message';
        
        const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        messageElement.innerHTML = `
            <div class="chat-message-info">
                <div class="chat-sender"><i class="fas fa-bullhorn"></i> Announcement from ${userName}</div>
                <div class="chat-time">${time}</div>
            </div>
            <div class="chat-message-text">📢 ${escapeHtml(announcement)}</div>
        `;
        
        chatMessages.appendChild(messageElement);
        
        setTimeout(() => {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }, 100);
    }

    async function sendChatMessage() {
        const chatInput = document.getElementById('chatInput');
        const message = chatInput.value.trim();
        
        if (!message) {
            showNotification('Please enter a message', 'warning');
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'send_chat');
        formData.append('session_id', sessionId);
        formData.append('message', message);
        
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            console.log('Chat response:', result);
            
            if (result.success) {
                chatInput.value = '';
                chatInput.style.height = 'auto';
                
                addChatMessage(userName + ' (You)', message, 'teacher', result.message_id);
                
                showNotification('Message sent successfully', 'success');
            } else {
                showNotification('Failed to send message: ' + result.error, 'error');
            }
        } catch (error) {
            console.error('Error sending chat message:', error);
            showNotification('Failed to send message: ' + error.message, 'error');
        }
    }

    function addChatMessage(sender, text, type, messageId = null) {
        const chatMessages = document.getElementById('chatMessages');
        const messageElement = document.createElement('div');
        let className = 'chat-message ';
        
        if (type === 'teacher') {
            className += 'teacher-message';
        } else if (type === 'student') {
            className += 'student-message';
        } else {
            className += 'system-message';
        }
        
        messageElement.className = className;
        
        if (messageId) {
            messageElement.setAttribute('data-message-id', messageId);
        }
        
        const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        messageElement.innerHTML = `
            <div class="chat-message-info">
                <div class="chat-sender">${sender}</div>
                <div class="chat-time">${time}</div>
            </div>
            <div class="chat-message-text">${escapeHtml(text)}</div>
        `;
        
        chatMessages.appendChild(messageElement);
        
        setTimeout(() => {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }, 100);
    }

    async function pollChatMessages() {
        try {
            const url = `${window.location.pathname}?action=get_chat&session_id=${sessionId}&last_id=0&t=${Date.now()}`;
            const response = await fetch(url);
            const result = await response.json();
            
            console.log('Poll chat result:', result);
            
            if (result.success && result.messages && result.messages.length > 0) {
                result.messages.forEach(msg => {
                    const existing = document.querySelector(`[data-message-id="${msg.id}"]`);
                    if (!existing) {
                        const type = msg.sender_id == userId ? 'teacher' : 'student';
                        addChatMessage(msg.full_name || 'Unknown', msg.message, type, msg.id);
                    }
                });
            }
        } catch (error) {
            console.error('Error polling chat messages:', error);
        }
    }

    function capitalizeFirst(string) {
        return string.charAt(0).toUpperCase() + string.slice(1);
    }

    function formatTime(minutes) {
        const hours = Math.floor(minutes / 60);
        const mins = minutes % 60;
        return hours > 0 ? `${hours}h ${mins}m` : `${mins}m`;
    }

    function updateEmotionTimeline(timeline) {
        const container = document.getElementById('studentEmotionTimeline');
        container.innerHTML = '';
        
        if (timeline.length === 0) {
            container.innerHTML = '<div style="text-align: center; padding: 20px; color: var(--primary-purple);">No emotion data available</div>';
            return;
        }
        
        timeline.forEach(item => {
            const timelineItem = document.createElement('div');
            timelineItem.className = 'timeline-item';
            
            const time = new Date(item.captured_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            const percentage = Math.min(100, Math.max(0, item.confidence_score || 50));
            
            timelineItem.innerHTML = `
                <div class="timeline-time">${time}</div>
                <div class="timeline-bar">
                    <div class="timeline-bar-fill ${item.facial_emotion}" style="width: ${percentage}%"></div>
                </div>
                <div style="margin-left: 10px; font-size: 11px; font-weight: 600; color: var(--primary-purple);">
                    ${capitalizeFirst(item.facial_emotion)}
                </div>
            `;
            
            container.appendChild(timelineItem);
        });
    }

    function showAllStudents() {
        const enrolled = document.getElementById('enrolledCount').textContent;
        const active = document.getElementById('activeCount').textContent;
        alert(`Total enrolled students: ${enrolled}\nActive participants: ${active}\nMissing: ${enrolled - active}`);
    }

    function showActiveParticipants() {
        const active = document.getElementById('activeCount').textContent;
        alert(`Active participants: ${active}`);
    }

    function initAnalytics() {
        const ctx = document.getElementById('emotionChart').getContext('2d');
        
        if (emotionChart) {
            emotionChart.destroy();
        }
        
        emotionChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Happy', 'Neutral', 'Sad', 'Angry', 'Confused'],
                datasets: [{
                    data: [
                        <?php echo $emotion_stats['happy']; ?>,
                        <?php echo $emotion_stats['neutral']; ?>,
                        <?php echo $emotion_stats['sad']; ?>,
                        <?php echo $emotion_stats['angry']; ?>,
                        <?php echo $emotion_stats['confused']; ?>
                    ],
                    backgroundColor: [
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(239, 68, 68, 0.8)',
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(139, 92, 246, 0.8)'
                    ],
                    borderColor: [
                        'rgba(16, 185, 129, 1)',
                        'rgba(59, 130, 246, 1)',
                        'rgba(239, 68, 68, 1)',
                        'rgba(245, 158, 11, 1)',
                        'rgba(139, 92, 246, 1)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 10,
                            usePointStyle: true,
                            font: {
                                size: 10
                            },
                            boxWidth: 10
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.label}: ${context.raw}%`;
                            }
                        }
                    }
                },
                cutout: '65%',
                animation: {
                    animateScale: true,
                    animateRotate: true
                }
            }
        });
    }

    // Helper function to get initials from name
    function getInitialsFromName(name) {
        if (!name) return '??';
        const parts = name.split(' ');
        const initials = parts.map(part => part.charAt(0).toUpperCase()).join('');
        return initials.substring(0, 2);
    }

    // Initialize when page loads
    document.addEventListener('DOMContentLoaded', function() {
        setInterval(updateTimer, 1000);
        
        initTeacherMedia();
        
        // Start polling for chat messages
        chatPollingInterval = setInterval(pollChatMessages, 5000);
        
        // Start polling for new participants
        startParticipantPolling();
        
        // Set up chat input
        const chatInput = document.getElementById('chatInput');
        chatInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
        
        chatInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendChatMessage();
            }
        });
        
        const announcementInput = document.getElementById('announcementInput');
        if (announcementInput) {
            announcementInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && e.ctrlKey) {
                    e.preventDefault();
                    sendAnnouncement();
                }
            });
        }
        
        window.addEventListener('beforeunload', function(e) {
            cleanup();
        });
        
        setTimeout(() => {
            const chatMessages = document.getElementById('chatMessages');
            if (chatMessages) {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
        }, 500);
        
        if (document.getElementById('analyticsTab').classList.contains('active')) {
            setTimeout(() => {
                initAnalytics();
            }, 100);
        }
        
        // Initialize emotion labels for existing students
        <?php foreach ($student_emotions as $emotion): ?>
            updateEmotionLabel(
                <?php echo $emotion['student_id']; ?>, 
                '<?php echo $emotion['facial_emotion']; ?>', 
                <?php echo $emotion['confidence_score']; ?>
            );
        <?php endforeach; ?>
        
        // Start emotion polling if detection is active
        if (currentEmotionStatus === 'active') {
            startEmotionPolling();
        }
        
        // Initialize "No face detected" for students without emotion data
        setTimeout(() => {
            <?php 
            // Get all student IDs from participants
            $studentIds = [];
            foreach ($participants as $participant) {
                if ($participant['user_role'] === 'student') {
                    $studentIds[] = $participant['user_id'];
                }
            }
            
            // Get student IDs that already have emotion data
            $emotionStudentIds = [];
            foreach ($student_emotions as $emotion) {
                $emotionStudentIds[] = $emotion['student_id'];
            }
            
            // For students without emotion data, initialize with "No face detected"
            foreach ($studentIds as $studentId) {
                if (!in_array($studentId, $emotionStudentIds)) {
                    echo "updateEmotionLabel($studentId, 'no_face', 0);\n";
                }
            }
            ?>
        }, 1000);
    });

    function confirmEndSession() {
        if (confirm('Are you sure you want to end this live session?')) {
            endSession();
        }
    }

    async function endSession() {
        try {
            const formData = new FormData();
            formData.append('action', 'end_session');
            formData.append('session_id', sessionId);
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            if (result.success) {
                showNotification('Session ended successfully', 'success');
                setTimeout(() => {
                    cleanup();
                    window.location.href = 'teacher_live_classes.php';
                }, 1000);
            } else {
                showNotification('Failed to end session: ' + result.error, 'error');
            }
        } catch (error) {
            console.error('Error ending session:', error);
            showNotification('Failed to end session', 'error');
        }
    }

    function showNotification(message, type = 'info') {
        const container = document.getElementById('notificationContainer');
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        
        let icon = 'info-circle';
        if (type === 'success') icon = 'check-circle';
        if (type === 'error') icon = 'exclamation-circle';
        if (type === 'warning') icon = 'exclamation-triangle';
        
        notification.innerHTML = `
            <i class="fas fa-${icon}"></i>
            <span>${message}</span>
        `;
        
        container.appendChild(notification);
        
        setTimeout(() => notification.classList.add('show'), 10);
        
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function toggleScreenShare() {
        if (isSharingScreen) {
            stopScreenShare();
        } else {
            startScreenShare();
        }
    }
</script>