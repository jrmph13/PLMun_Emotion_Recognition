<?php
// ==================== AJAX: GET CLASS STUDENTS ====================
require_once 'config.php';

// Check if it's an AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_students') {
    
    // Require instructor login
    requireInstructor();
    
    $classId = $_POST['class_id'] ?? 0;
    $userId = $_SESSION['user_id'];
    
    if ($classId) {
        try {
            // Verify the class belongs to the instructor
            $stmt = $pdo->prepare("SELECT id FROM " . TABLE_CLASSES . " WHERE id = ? AND instructor_id = ?");
            $stmt->execute([$classId, $userId]);
            $class = $stmt->fetch();
            
            if (!$class) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Class not found or access denied'
                ]);
                exit;
            }
            
            // Get enrolled students
            $stmt = $pdo->prepare("
                SELECT 
                    s.id as student_id,
                    s.student_number,
                    s.course,
                    s.year_level,
                    u.full_name,
                    u.email,
                    u.contact_number,
                    ce.enrolled_at
                FROM " . TABLE_STUDENTS . " s
                JOIN " . TABLE_USERS . " u ON s.user_id = u.id
                JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON s.id = ce.student_id
                WHERE ce.class_id = ?
                ORDER BY u.full_name ASC
            ");
            $stmt->execute([$classId]);
            $students = $stmt->fetchAll();
            
            // Get student count
            $stmt = $pdo->prepare("SELECT COUNT(*) as student_count FROM " . TABLE_CLASS_ENROLLMENTS . " WHERE class_id = ?");
            $stmt->execute([$classId]);
            $count = $stmt->fetch();
            
            // Format students data
            $formattedStudents = [];
            foreach ($students as $student) {
                $formattedStudents[] = [
                    'id' => $student['student_id'],
                    'full_name' => htmlspecialchars($student['full_name']),
                    'initials' => getInitials($student['full_name']),
                    'student_number' => htmlspecialchars($student['student_number']),
                    'course' => htmlspecialchars($student['course'] ?? 'Not specified'),
                    'year_level' => htmlspecialchars($student['year_level'] ?? 'Not specified'),
                    'email' => htmlspecialchars($student['email'] ?? ''),
                    'contact_number' => htmlspecialchars($student['contact_number'] ?? ''),
                    'enrolled_at' => formatDate($student['enrolled_at'], 'M d, Y')
                ];
            }
            
            echo json_encode([
                'success' => true,
                'student_count' => $count['student_count'],
                'students' => $formattedStudents
            ]);
            
        } catch (PDOException $e) {
            error_log("Get Class Students Error: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Database error occurred'
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid class ID'
        ]);
    }
    exit;
}

// If not an AJAX request, redirect
header('Location: teacher_live_classes.php');
exit;
?>