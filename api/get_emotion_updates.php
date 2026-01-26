<?php
require_once '../config.php';

header('Content-Type: application/json');

$session_id = $_GET['session_id'] ?? 0;
$last_id = $_GET['last_id'] ?? 0;

try {
    $stmt = $pdo->prepare("
        SELECT ed.*, s.student_number, u.full_name, u.id as user_id
        FROM emotion_data ed
        JOIN students s ON ed.student_id = s.id
        JOIN users u ON s.user_id = u.id
        WHERE ed.session_id = ? AND ed.id > ?
        ORDER BY ed.captured_at DESC
        LIMIT 20
    ");
    $stmt->execute([$session_id, $last_id]);
    $data = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>