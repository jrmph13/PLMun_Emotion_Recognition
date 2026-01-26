<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

$session_id = $data['session_id'] ?? 0;
$message = $data['message'] ?? '';
$sender_type = $data['sender_type'] ?? '';
$sender_id = $data['sender_id'] ?? 0;
$sender_name = $data['sender_name'] ?? '';

if (!$session_id || empty($message) || !$sender_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit();
}

try {
    // Verify session is active
    $stmt = $pdo->prepare("SELECT status FROM " . TABLE_LIVE_SESSIONS . " WHERE id = ?");
    $stmt->execute([$session_id]);
    $session = $stmt->fetch();
    
    if (!$session || $session['status'] !== 'active') {
        echo json_encode(['success' => false, 'message' => 'Session is not active']);
        exit();
    }
    
    // Insert chat message
    $stmt = $pdo->prepare("
        INSERT INTO " . TABLE_CHAT_MESSAGES . " 
        (session_id, sender_id, sender_role, message) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$session_id, $sender_id, $sender_type, $message]);
    $message_id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message_id' => $message_id
    ]);
    
} catch (PDOException $e) {
    error_log("Send chat message error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error sending message']);
}
?>