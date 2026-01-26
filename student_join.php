<?php
// student_join.php - Student joining interface
require_once 'config.php';
requireStudent();

$classCode = $_GET['code'] ?? '';
$sessionId = $_GET['session'] ?? 0;

if (!$classCode && !$sessionId) {
    setFlash('error', 'Invalid join link');
    header('Location: student_dashboard.php');
    exit();
}

try {
    $userId = $_SESSION['user_id'];
    $studentId = getStudentId();
    
    if ($sessionId) {
        // Join by session ID
        $stmt = $pdo->prepare("
            SELECT ls.*, c.class_name, c.class_code, c.instructor_id
            FROM " . TABLE_LIVE_SESSIONS . " ls
            JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
            WHERE ls.id = ? AND ls.status = 'active'
        ");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();
    } else {
        // Join by class code (find active session)
        $stmt = $pdo->prepare("
            SELECT ls.*, c.class_name, c.class_code, c.instructor_id
            FROM " . TABLE_LIVE_SESSIONS . " ls
            JOIN " . TABLE_CLASSES . " c ON ls.class_id = c.id
            WHERE c.class_code = ? AND ls.status = 'active'
            ORDER BY ls.start_time DESC
            LIMIT 1
        ");
        $stmt->execute([$classCode]);
        $session = $stmt->fetch();
        $sessionId = $session['id'] ?? 0;
    }
    
    if (!$session) {
        setFlash('error', 'No active session found');
        header('Location: student_dashboard.php');
        exit();
    }
    
    // Check if student is enrolled
    $stmt = $pdo->prepare("
        SELECT ce.id 
        FROM " . TABLE_CLASS_ENROLLMENTS . " ce
        JOIN " . TABLE_CLASSES . " c ON ce.class_id = c.id
        WHERE ce.student_id = ? AND c.id = ?
    ");
    $stmt->execute([$studentId, $session['class_id']]);
    $enrollment = $stmt->fetch();
    
    if (!$enrollment) {
        setFlash('error', 'You are not enrolled in this class');
        header('Location: student_dashboard.php');
        exit();
    }
    
    // Record attendance
    $stmt = $pdo->prepare("
        INSERT INTO " . TABLE_SESSION_ATTENDANCE . " 
        (session_id, student_id, join_time)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE join_time = NOW(), leave_time = NULL
    ");
    $stmt->execute([$sessionId, $studentId]);
    
    // Add to session participants
    $stmt = $pdo->prepare("
        INSERT INTO " . TABLE_LIVE_SESSION_PARTICIPANTS . " 
        (session_id, user_id, user_role, join_time, camera_active, mic_active, is_active)
        VALUES (?, ?, 'student', NOW(), 1, 1, 1)
        ON DUPLICATE KEY UPDATE 
            is_active = 1, 
            leave_time = NULL,
            camera_active = 1,
            mic_active = 1
    ");
    $stmt->execute([$sessionId, $userId]);
    
    // Log student join
    logAuditTrail(
        $userId,
        $_SESSION['role'],
        $_SESSION['username'],
        'join',
        "Joined live session: {$session['session_name']}",
        TABLE_LIVE_SESSIONS,
        $sessionId,
        ['class_name' => $session['class_name']]
    );
    
    // Redirect to student interface
    header('Location: student_live.php?session_id=' . $sessionId);
    
} catch (PDOException $e) {
    error_log("Student Join Error: " . $e->getMessage());
    setFlash('error', 'Error joining session');
    header('Location: student_dashboard.php');
}
?>