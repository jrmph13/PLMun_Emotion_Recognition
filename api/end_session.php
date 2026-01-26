<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

$session_id = $data['session_id'] ?? 0;
$user_id = $data['user_id'] ?? 0;

if (!$session_id || !$user_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit();
}

try {
    // Verify user is instructor of this session
    $stmt = $pdo->prepare("
        SELECT ls.*, c.instructor_id 
        FROM " . TABLE_LIVE_SESSIONS . " ls
        JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
        WHERE ls.id = ? AND ls.status = 'active'
    ");
    $stmt->execute([$session_id]);
    $session = $stmt->fetch();
    
    if (!$session || $session['instructor_id'] != $user_id) {
        echo json_encode(['success' => false, 'message' => 'You are not authorized to end this session']);
        exit();
    }
    
    // End the session
    $stmt = $pdo->prepare("UPDATE " . TABLE_LIVE_SESSIONS . " SET status = 'ended', end_time = NOW() WHERE id = ?");
    $stmt->execute([$session_id]);
    
    // Update all active participants
    $stmt = $pdo->prepare("UPDATE " . TABLE_LIVE_SESSION_PARTICIPANTS . " SET is_active = 0, leave_time = NOW() WHERE session_id = ? AND is_active = 1");
    $stmt->execute([$session_id]);
    
    // Log audit trail
    $userData = getUserData();
    logAuditTrail(
        $user_id,
        $_SESSION['role'] ?? 'instructor',
        $userData['username'] ?? 'unknown',
        'update',
        "Ended live session",
        TABLE_LIVE_SESSIONS,
        $session_id,
        ['session_id' => $session_id]
    );
    
    echo json_encode(['success' => true]);
    
} catch (PDOException $e) {
    error_log("End session error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error ending session']);
}
?>