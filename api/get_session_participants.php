<?php
require_once '../config.php';

header('Content-Type: application/json');

$session_id = $_GET['session_id'] ?? 0;

try {
    $stmt = $pdo->prepare("
        SELECT lsp.*, u.full_name, u.username, s.student_number
        FROM live_session_participants lsp
        JOIN users u ON lsp.user_id = u.id
        LEFT JOIN students s ON u.id = s.user_id AND u.role = 'student'
        WHERE lsp.session_id = ? AND lsp.is_active = 1
    ");
    $stmt->execute([$session_id]);
    $participants = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'participants' => $participants]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>