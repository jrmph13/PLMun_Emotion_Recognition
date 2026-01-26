<?php
// send_chat_message.php
session_start();
require_once '../config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

// Only instructors or admins can send messages in teacher sessions
if ($_SESSION['role'] !== 'instructor' && $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['session_id']) || !isset($input['sender_id']) || !isset($input['message'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

$session_id = intval($input['session_id']);
$sender_id = intval($input['sender_id']);
$message = trim($input['message']);
$sender_role = isset($input['sender_role']) ? $input['sender_role'] : 'instructor';

// Validate message
if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
    exit();
}

// Check if session exists and user is part of it
try {
    // Verify user is in this session
    $stmt = $pdo->prepare("
        SELECT 1 FROM live_session_participants 
        WHERE session_id = ? AND user_id = ? AND is_active = 1
    ");
    $stmt->execute([$session_id, $sender_id]);
    
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'User not in session']);
        exit();
    }
    
    // Insert chat message
    $stmt = $pdo->prepare("
        INSERT INTO chat_messages (session_id, sender_id, sender_role, message, created_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    
    $result = $stmt->execute([$session_id, $sender_id, $sender_role, $message]);
    
    if ($result) {
        // Get the inserted message with sender info
        $message_id = $pdo->lastInsertId();
        $stmt = $pdo->prepare("
            SELECT cm.*, u.full_name 
            FROM chat_messages cm
            LEFT JOIN users u ON cm.sender_id = u.id
            WHERE cm.id = ?
        ");
        $stmt->execute([$message_id]);
        $message_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Log chat activity
        logAuditTrail(
            $sender_id,
            $_SESSION['role'],
            $_SESSION['username'],
            'send_chat',
            'Sent chat message in session: ' . $session_id,
            'chat_messages',
            $message_id,
            ['session_id' => $session_id, 'message' => substr($message, 0, 50)]
        );
        
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
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>