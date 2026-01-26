<?php
// get_chat_messages.php
session_start();
require_once '../config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
$last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

if ($session_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid session ID']);
    exit();
}

try {
    // Check if user is part of this session
    $stmt = $pdo->prepare("
        SELECT 1 FROM live_session_participants 
        WHERE session_id = ? AND user_id = ? AND is_active = 1
    ");
    $stmt->execute([$session_id, $_SESSION['user_id']]);
    
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Not authorized to view messages']);
        exit();
    }
    
    // Get new messages
    if ($last_id > 0) {
        $stmt = $pdo->prepare("
            SELECT cm.*, u.full_name, u.role as sender_role
            FROM chat_messages cm
            LEFT JOIN users u ON cm.sender_id = u.id
            WHERE cm.session_id = ? AND cm.id > ?
            ORDER BY cm.created_at ASC
        ");
        $stmt->execute([$session_id, $last_id]);
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
        $stmt->execute([$session_id]);
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
    error_log("Get chat messages error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>