<?php
// class_details.php
require_once 'config.php';
requireInstructor();

$class_id = $_GET['id'] ?? 0;
$user_id = $_SESSION['user_id'];

// Fetch class details with enrolled students
$stmt = $pdo->prepare("
    SELECT c.*, u.full_name as instructor_name,
           COUNT(DISTINCT ce.student_id) as student_count,
           COUNT(DISTINCT ls.id) as session_count
    FROM " . TABLE_CLASSES . " c
    JOIN " . TABLE_USERS . " u ON c.instructor_id = u.id
    LEFT JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
    LEFT JOIN " . TABLE_LIVE_SESSIONS . " ls ON c.id = ls.class_id
    WHERE c.id = ? AND c.instructor_id = ?
    GROUP BY c.id
");
$stmt->execute([$class_id, $user_id]);
$class = $stmt->fetch();

if (!$class) {
    header('Location: instructor_classes.php');
    exit();
}

// Fetch enrolled students
$stmt = $pdo->prepare("
    SELECT s.*, u.full_name, u.email
    FROM " . TABLE_STUDENTS . " s
    JOIN " . TABLE_USERS . " u ON s.user_id = u.id
    JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON s.id = ce.student_id
    WHERE ce.class_id = ?
    ORDER BY u.full_name
");
$stmt->execute([$class_id]);
$students = $stmt->fetchAll();

// ... (create detailed view page similar to dashboard)
?>