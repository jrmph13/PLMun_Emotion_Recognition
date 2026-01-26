<?php
session_start();
require_once '../config.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['session_id']) || !isset($input['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        UPDATE live_session_participants 
        SET leave_time = NOW(), is_active = 0 
        WHERE session_id = ? AND user_id = ?
    ");
    $stmt->execute([$input['session_id'], $input['user_id']]);
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>