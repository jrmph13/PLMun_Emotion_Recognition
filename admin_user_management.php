<?php
include 'config.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Get admin info
$admin_full_name = $_SESSION['full_name'];
$admin_initials = getInitials($admin_full_name);

// Function to generate the next ID number for instructors
function generateNextID($pdo) {
    // Get the highest existing ID number from users table where role is instructor or admin
    $sql = "SELECT username FROM users WHERE role IN ('instructor', 'admin') AND username REGEXP '^[0-9]+$' ORDER BY CAST(username AS UNSIGNED) DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && !empty($result['username'])) {
        // Increment the highest ID
        $next_id = intval($result['username']) + 1;
    } else {
        // Start from 12345678 if no instructor/admin IDs exist
        $next_id = 12345678;
    }
    
    return $next_id;
}

// Function to generate the next student number
function generateStudentNumber($pdo) {
    // Get the highest student number from the database
    $sql = "SELECT student_number FROM students ORDER BY student_number DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && !empty($result['student_number'])) {
        // Check if it's in format 20000001
        if (is_numeric($result['student_number'])) {
            $last_id = intval($result['student_number']);
            $new_id = $last_id + 1;
        } else {
            // If not numeric, start from 20000001
            $new_id = 20000001;
        }
    } else {
        // Start with 20000001 if no students exist
        $new_id = 20000001;
    }
    
    return strval($new_id);
}

// Function to generate random password for instructors
function generateRandomPassword() {
    $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $numbers = '0123456789';
    
    // Generate pattern: AA1B22 (2 letters, 1 number, 1 letter, 2 numbers)
    $password = '';
    
    // First two letters
    for ($i = 0; $i < 2; $i++) {
        $password .= $letters[rand(0, strlen($letters) - 1)];
    }
    
    // One number
    $password .= $numbers[rand(0, strlen($numbers) - 1)];
    
    // One letter
    $password .= $letters[rand(0, strlen($letters) - 1)];
    
    // Two numbers
    for ($i = 0; $i < 2; $i++) {
        $password .= $numbers[rand(0, strlen($numbers) - 1)];
    }
    
    return $password;
}

// Function to generate random password for students (e.g., A5G3H3, B2J3H5, C1H41J)
function generateStudentPassword($length = 6) {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $numbers = '0123456789';
    $password = '';
    
    // Alternate between letters and numbers
    for ($i = 0; $i < $length; $i++) {
        if ($i % 2 == 0) {
            // Even position: letter
            $password .= $characters[rand(0, strlen($characters) - 1)];
        } else {
            // Odd position: number
            $password .= $numbers[rand(0, strlen($numbers) - 1)];
        }
    }
    
    return $password;
}

// Function to generate email from name and course for students
function generateStudentEmail($full_name, $course) {
    // Remove special characters and convert to lowercase
    $clean_name = preg_replace('/[^a-zA-Z\s]/', '', $full_name);
    $clean_name = strtolower($clean_name);
    
    // Replace spaces with nothing (no underscores between first and last name)
    $username = str_replace(' ', '', $clean_name);
    
    // Get course abbreviation in lowercase
    $course_abbr = strtolower(getCourseAbbreviation($course));
    
    // Generate email in the format: firstnamelastname_bsit@plmun.edu.ph
    $email = $username . '_' . $course_abbr . '@plmun.edu.ph';
    
    return $email;
}

// Function to generate email from full name for instructors
function generateInstructorEmail($full_name) {
    // Remove extra spaces and split name
    $name_parts = explode(' ', trim($full_name));
    
    // Get first and last name
    $first_name = $name_parts[0];
    $last_name = end($name_parts);
    
    // Remove special characters and convert to lowercase
    $first_name_clean = preg_replace('/[^a-zA-Z]/', '', $first_name);
    $last_name_clean = preg_replace('/[^a-zA-Z]/', '', $last_name);
    
    // Generate email
    $email = strtolower($first_name_clean . $last_name_clean) . '@plmun.edu.ph';
    
    return $email;
}

// Function to get course abbreviation
function getCourseAbbreviation($course) {
    $abbreviations = [
        'Bachelor of Science in Business Administration' => 'BSBA',
        'Bachelor of Science in Accountancy' => 'BSA',
        'Bachelor of Science in Management Accounting' => 'BSMA',
        'Bachelor of Arts in Communication' => 'BAC',
        'Bachelor of Science in Psychology' => 'BSP',
        'Bachelor of Science in Criminology' => 'BSC',
        'Bachelor of Science in Industrial Security Management' => 'BSISM',
        'Bachelor of Science in Computer Science' => 'BSCS',
        'Bachelor of Science in Information Technology' => 'BSIT',
        'Associate in Computer Technology' => 'ACT',
        'Bachelor of Public Administration' => 'BPA',
        'Bachelor of Arts in Political Science' => 'BAPS',
        'Bachelor of Science in Social Work' => 'BSSW',
        'Doctor of Medicine' => 'MD',
        'Bachelor of Elementary Education (BEEd)' => 'BEED',
        'Bachelor of Secondary Education (BSEd)' => 'BSED'
    ];
    
    return isset($abbreviations[$course]) ? $abbreviations[$course] : 'GEN';
}

// Define available courses and year levels for students
$available_courses = [
    'Bachelor of Science in Business Administration',
    'Bachelor of Science in Accountancy',
    'Bachelor of Science in Management Accounting',
    'Bachelor of Arts in Communication',
    'Bachelor of Science in Psychology',
    'Bachelor of Science in Criminology',
    'Bachelor of Science in Industrial Security Management',
    'Bachelor of Science in Computer Science',
    'Bachelor of Science in Information Technology',
    'Associate in Computer Technology',
    'Bachelor of Public Administration',
    'Bachelor of Arts in Political Science',
    'Bachelor of Science in Social Work',
    'Doctor of Medicine',
    'Bachelor of Elementary Education (BEEd)',
    'Bachelor of Secondary Education (BSEd)'
];

$year_levels = [
    '1st Year',
    '2nd Year',
    '3rd Year',
    '4th Year',
    '5th Year'
];

// Initialize variables
$error = '';
$success = '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'instructors'; // Default to instructors tab

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // INSTRUCTOR MANAGEMENT
    if (isset($_POST['add_user'])) {
        $full_name = trim($_POST['full_name']);
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $personal_email = isset($_POST['personal_email']) ? trim($_POST['personal_email']) : '';
        $contact_number = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
        $password = $_POST['password'];
        $role = $_POST['role'];
        $department = isset($_POST['department']) ? $_POST['department'] : '';
        
        if (empty($full_name) || empty($username) || empty($email) || empty($password)) {
            $error = 'Please fill in all required fields.';
        } else {
            // Check if username (ID number) already exists
            $sql = "SELECT id FROM users WHERE username = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$username]);
            
            if ($stmt->fetch()) {
                $error = 'ID Number already exists. Please use a different ID Number.';
            } else {
                // Check if email already exists
                $sql = "SELECT id FROM users WHERE email = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$email]);
                
                if ($stmt->fetch()) {
                    $error = 'Email already exists. Please use a different email.';
                } else {
                    // Check if personal email already exists
                    if (!empty($personal_email)) {
                        $sql = "SELECT id FROM users WHERE personal_email = ?";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$personal_email]);
                        
                        if ($stmt->fetch()) {
                            $error = 'Personal email already exists. Please use a different personal email.';
                        }
                    }
                    
                    if (empty($error)) {
                        try {
                            $pdo->beginTransaction();
                            
                            // Create user in users table
                            $sql = "INSERT INTO users (username, password, full_name, email, personal_email, contact_number, role, department, created_at) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                            $stmt = $pdo->prepare($sql);
                            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                            
                            $result = $stmt->execute([$username, $hashed_password, $full_name, $email, $personal_email, $contact_number, $role, $department]);
                            
                            if ($result) {
                                $pdo->commit();
                                $success = 'Instructor created successfully!';
                            } else {
                                $pdo->rollBack();
                                $error = 'Failed to create instructor. Please try again.';
                            }
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $error = 'Failed to create instructor: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
    
    if (isset($_POST['update_user'])) {
        $user_id = $_POST['user_id'];
        $full_name = trim($_POST['full_name']);
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $personal_email = isset($_POST['personal_email']) ? trim($_POST['personal_email']) : '';
        $contact_number = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
        $role = $_POST['role'];
        $department = isset($_POST['department']) ? $_POST['department'] : '';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($full_name) || empty($username) || empty($email)) {
            $error = 'Please fill in all required fields.';
        } else {
            // Check if username (ID number) already exists (excluding current user)
            $sql = "SELECT id FROM users WHERE username = ? AND id != ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$username, $user_id]);
            
            if ($stmt->fetch()) {
                $error = 'ID Number already exists. Please use a different ID Number.';
            } else {
                // Check if email already exists (excluding current user)
                $sql = "SELECT id FROM users WHERE email = ? AND id != ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$email, $user_id]);
                
                if ($stmt->fetch()) {
                    $error = 'Email already exists. Please use a different email.';
                } else {
                    // Check if personal email already exists (excluding current user)
                    if (!empty($personal_email)) {
                        $sql = "SELECT id FROM users WHERE personal_email = ? AND id != ?";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$personal_email, $user_id]);
                        
                        if ($stmt->fetch()) {
                            $error = 'Personal email already exists. Please use a different personal email.';
                        }
                    }
                    
                    if (empty($error)) {
                        $sql = "UPDATE users SET full_name = ?, username = ?, email = ?, personal_email = ?, contact_number = ?, role = ?, department = ?, is_active = ? WHERE id = ?";
                        $stmt = $pdo->prepare($sql);
                        $result = $stmt->execute([$full_name, $username, $email, $personal_email, $contact_number, $role, $department, $is_active, $user_id]);
                        
                        if ($result) {
                            $success = 'Instructor updated successfully!';
                        } else {
                            $error = 'Failed to update instructor. Please try again.';
                        }
                    }
                }
            }
        }
    }
    
    if (isset($_POST['delete_user'])) {
        $user_id = $_POST['user_id'];
        
        // Prevent admin from deleting themselves
        if ($user_id == $_SESSION['user_id']) {
            $error = 'You cannot delete your own account.';
        } else {
            $sql = "DELETE FROM users WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            
            if ($stmt->execute([$user_id])) {
                $success = 'Instructor deleted successfully!';
            } else {
                $error = 'Failed to delete instructor. Please try again.';
            }
        }
    }
    
    // INSTRUCTOR PASSWORD RESET
    if (isset($_POST['reset_password'])) {
        $user_id = $_POST['user_id'];
        $new_password = $_POST['new_password'];
        
        if (empty($new_password)) {
            $error = 'Please enter a new password.';
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $sql = "UPDATE users SET password = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            
            if ($stmt->execute([$hashed_password, $user_id])) {
                $success = 'Password reset successfully!';
            } else {
                $error = 'Failed to reset password. Please try again.';
            }
        }
    }
    
    // STUDENT PASSWORD RESET
    if (isset($_POST['reset_student_password'])) {
        $student_id = $_POST['student_id'];
        $new_password = $_POST['new_password'];
        
        if (empty($new_password)) {
            $error = 'Please enter a new password.';
        } else {
            try {
                // Get user_id from students table
                $sql = "SELECT user_id FROM students WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$student_id]);
                $student_data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$student_data) {
                    $error = 'Student not found.';
                } else {
                    $user_id = $student_data['user_id'];
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    // Update password in users table
                    $sql = "UPDATE users SET password = ? WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    
                    if ($stmt->execute([$hashed_password, $user_id])) {
                        $success = 'Student password reset successfully!';
                    } else {
                        $error = 'Failed to reset student password. Please try again.';
                    }
                }
            } catch (Exception $e) {
                $error = 'Failed to reset student password: ' . $e->getMessage();
            }
        }
    }
    
    // STUDENT MANAGEMENT
    if (isset($_POST['add_student'])) {
        $student_number = trim($_POST['student_number']);
        $full_name = trim($_POST['full_name']);
        $course = trim($_POST['course']);
        $year_level = trim($_POST['year_level']);
        $email = trim($_POST['email']);
        $personal_email = trim($_POST['personal_email']);
        $contact_number = trim($_POST['contact_number']);
        $password = $_POST['password'];
        
        if (empty($student_number) || empty($full_name) || empty($password) || empty($course) || empty($year_level)) {
            $error = 'Student Number, Full Name, Course, Year Level, and Password are required.';
        } else {
            // Check if student number already exists in students table
            $sql = "SELECT id FROM students WHERE student_number = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$student_number]);
            
            if ($stmt->fetch()) {
                $error = 'Student Number already exists. Please choose a different number.';
            } else {
                // If email is empty, generate one automatically
                if (empty($email)) {
                    $email = generateStudentEmail($full_name, $course);
                }
                
                // Check if email already exists in users table
                $sql = "SELECT id FROM users WHERE email = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$email]);
                
                if ($stmt->fetch()) {
                    // If email exists, append student number to make it unique
                    $base_email = generateStudentEmail($full_name, $course);
                    $email = str_replace('@plmun.edu.ph', $student_number . '@plmun.edu.ph', $base_email);
                }
                
                // Check if student number already exists as username
                $sql = "SELECT id FROM users WHERE username = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$student_number]);
                
                if ($stmt->fetch()) {
                    $error = 'Student number already exists as a username. Please choose a different number.';
                } else {
                    try {
                        $pdo->beginTransaction();
                        
                        // Create user in users table
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $sql = "INSERT INTO users (username, password, full_name, email, personal_email, contact_number, role, is_active, created_at) 
                                VALUES (?, ?, ?, ?, ?, ?, 'student', 1, NOW())";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$student_number, $hashed_password, $full_name, $email, $personal_email, $contact_number]);
                        
                        $user_id = $pdo->lastInsertId();
                        
                        // Create student in students table
                        $sql = "INSERT INTO students (user_id, student_number, course, year_level, created_at) 
                                VALUES (?, ?, ?, ?, NOW())";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([$user_id, $student_number, $course, $year_level]);
                        
                        $pdo->commit();
                        $success = 'Student account created successfully!<br>Auto-generated email: ' . $email . '<br>Auto-generated password: ' . $password;
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = 'Failed to create student account: ' . $e->getMessage();
                    }
                }
            }
        }
    }
    
    elseif (isset($_POST['update_student'])) {
        // Update student information
        $student_id = $_POST['update_student_id'];
        $full_name = trim($_POST['update_full_name']);
        $course = trim($_POST['update_course']);
        $year_level = trim($_POST['update_year_level']);
        $email = trim($_POST['update_email']);
        $personal_email = trim($_POST['update_personal_email']);
        $contact_number = trim($_POST['update_contact_number']);
        $is_active = isset($_POST['update_is_active']) ? 1 : 0;
        
        if (empty($full_name) || empty($course) || empty($year_level)) {
            $error = 'Full Name, Course, and Year Level are required.';
        } else {
            try {
                // Get user_id from students table
                $sql = "SELECT user_id FROM students WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$student_id]);
                $student_data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$student_data) {
                    $error = 'Student not found.';
                } else {
                    $user_id = $student_data['user_id'];
                    
                    // Update user information including is_active status
                    $sql = "UPDATE users SET full_name = ?, email = ?, personal_email = ?, contact_number = ?, is_active = ? WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$full_name, $email, $personal_email, $contact_number, $is_active, $user_id]);
                    
                    // Update student information
                    $sql = "UPDATE students SET course = ?, year_level = ? WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$course, $year_level, $student_id]);
                    
                    $success = 'Student information updated successfully!';
                }
            } catch (Exception $e) {
                $error = 'Failed to update student information: ' . $e->getMessage();
            }
        }
    }
    
    elseif (isset($_POST['delete_student'])) {
        // Delete student account
        $student_id = $_POST['delete_student_id'];
        
        try {
            // Get user_id from students table
            $sql = "SELECT user_id FROM students WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$student_id]);
            $student_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$student_data) {
                $error = 'Student not found.';
            } else {
                $user_id = $student_data['user_id'];
                
                // Delete from users table (cascade will delete from students table)
                $sql = "DELETE FROM users WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                
                if ($stmt->execute([$user_id])) {
                    $success = 'Student account deleted successfully!';
                } else {
                    $error = 'Failed to delete student account. Please try again.';
                }
            }
        } catch (Exception $e) {
            $error = 'Failed to delete student account: ' . $e->getMessage();
        }
    }
    
    // Handle bulk delete for students
    elseif (isset($_POST['bulk_delete'])) {
        $selected_students = isset($_POST['selected_students']) ? $_POST['selected_students'] : [];
        
        if (empty($selected_students)) {
            $error = 'Please select at least one student to delete.';
        } else {
            try {
                $pdo->beginTransaction();
                $deleted_count = 0;
                
                foreach ($selected_students as $student_id) {
                    // Get user_id from students table
                    $sql = "SELECT user_id FROM students WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$student_id]);
                    $student_data = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($student_data) {
                        $user_id = $student_data['user_id'];
                        
                        // Delete from users table (cascade will delete from students table)
                        $sql = "DELETE FROM users WHERE id = ?";
                        $stmt = $pdo->prepare($sql);
                        
                        if ($stmt->execute([$user_id])) {
                            $deleted_count++;
                        }
                    }
                }
                
                $pdo->commit();
                $success = 'Successfully deleted ' . $deleted_count . ' student account(s)!';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Failed to delete student accounts: ' . $e->getMessage();
            }
        }
    }
}

// Generate next available IDs
$next_instructor_id = generateNextID($pdo);
$new_student_number = generateStudentNumber($pdo);

// Generate initial random passwords
$initial_instructor_password = generateRandomPassword();
$auto_student_password = generateStudentPassword();

// Get all instructors (users with role 'instructor' or 'admin')
$sql = "SELECT u.* FROM users u WHERE u.role IN ('instructor', 'admin') ORDER BY u.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$instructors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all students with filters
$selected_course = isset($_GET['course']) ? $_GET['course'] : 'all';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';

// Build query based on filters
$query_params = [];
$query_conditions = [];

if ($selected_course !== 'all') {
    $query_conditions[] = "s.course = ?";
    $query_params[] = $selected_course;
}

if (!empty($search_query)) {
    $query_conditions[] = "(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.personal_email LIKE ? OR u.contact_number LIKE ?)";
    $search_param = "%$search_query%";
    $query_params[] = $search_param;
    $query_params[] = $search_param;
    $query_params[] = $search_param;
    $query_params[] = $search_param;
    $query_params[] = $search_param;
}

// Build final query for students (join users and students tables)
if (count($query_conditions) > 0) {
    $sql = "SELECT s.*, u.full_name, u.email, u.personal_email, u.contact_number, u.username, u.is_active 
            FROM students s 
            JOIN users u ON s.user_id = u.id 
            WHERE " . implode(" AND ", $query_conditions) . " 
            ORDER BY s.course, u.full_name";
} else {
    $sql = "SELECT s.*, u.full_name, u.email, u.personal_email, u.contact_number, u.username, u.is_active 
            FROM students s 
            JOIN users u ON s.user_id = u.id 
            ORDER BY s.course, u.full_name";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($query_params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user statistics
$sql = "SELECT 
        COUNT(*) as total_instructors,
        COUNT(CASE WHEN role = 'admin' THEN 1 END) as admin_count,
        COUNT(CASE WHEN role = 'instructor' THEN 1 END) as instructor_count,
        COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_instructors
        FROM users 
        WHERE role IN ('instructor', 'admin')";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$instructor_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get student statistics - UPDATED
$sql_total = "SELECT COUNT(*) as total_students FROM students s JOIN users u ON s.user_id = u.id";
$stmt_total = $pdo->prepare($sql_total);
$stmt_total->execute();
$total_students_result = $stmt_total->fetch(PDO::FETCH_ASSOC);
$total_students = $total_students_result ? $total_students_result['total_students'] : 0;

// Get active students count
$sql_active = "SELECT COUNT(*) as active_students FROM students s JOIN users u ON s.user_id = u.id WHERE u.is_active = 1";
$stmt_active = $pdo->prepare($sql_active);
$stmt_active->execute();
$active_students_result = $stmt_active->fetch(PDO::FETCH_ASSOC);
$active_students = $active_students_result ? $active_students_result['active_students'] : 0;

// Calculate inactive students
$inactive_students = $total_students - $active_students;

// Get course counts
$sql_student_counts = "SELECT course, COUNT(*) as count FROM students GROUP BY course ORDER BY course";
$stmt_student_counts = $pdo->prepare($sql_student_counts);
$stmt_student_counts->execute();
$course_counts_result = $stmt_student_counts->fetchAll(PDO::FETCH_ASSOC);

// Create course count mapping
$course_count_map = [];
if ($course_counts_result) {
    foreach ($course_counts_result as $course_count) {
        $course_count_map[$course_count['course']] = $course_count['count'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Emotion System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f5f5f5;
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 4px 0 15px rgba(0,0,0,0.2);
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .sidebar:hover {
            width: 280px;
        }

        .sidebar-header {
            padding: 25px 20px;
            background: rgba(15, 23, 42, 0.9);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }

        .sidebar-header h1 {
            font-size: 24px;
            margin-bottom: 5px;
            color: white;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 700;
        }

        .sidebar-header p {
            font-size: 12px;
            opacity: 0.8;
            letter-spacing: 1px;
        }

        .user-profile {
            padding: 25px 20px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: rgba(30, 41, 59, 0.5);
            margin: 10px;
            border-radius: 10px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
            margin-right: 15px;
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3);
        }

        .user-info h3 {
            font-size: 16px;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .user-info p {
            font-size: 12px;
            opacity: 0.8;
            background: rgba(139, 92, 246, 0.1);
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .menu-title {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 2px;
            padding: 0 25px 12px;
            color: rgba(255,255,255,0.5);
            font-weight: 600;
            margin-top: 15px;
        }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 14px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 4px solid transparent;
            margin: 3px 10px;
            border-radius: 0 8px 8px 0;
            position: relative;
        }

        .menu-item:hover {
            background: linear-gradient(90deg, rgba(139, 92, 246, 0.1) 0%, transparent 100%);
            color: white;
            border-left-color: #8b5cf6;
            transform: translateX(5px);
        }

        .menu-item.active {
            background: linear-gradient(90deg, rgba(139, 92, 246, 0.2) 0%, transparent 100%);
            color: white;
            border-left-color: #8b5cf6;
            font-weight: 500;
        }

        .menu-item.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: #8b5cf6;
            border-radius: 0 2px 2px 0;
        }

        .menu-icon {
            margin-right: 15px;
            font-size: 18px;
            width: 24px;
            text-align: center;
        }

        .menu-badge {
            margin-left: auto;
            background: #ef4444;
            color: white;
            font-size: 10px;
            padding: 3px 8px;
            border-radius: 10px;
            font-weight: 600;
        }

/* Logo Styles - ADDED THIS */
.sidebar-logo {
    display: flex;
    justify-content: center;
    align-items: center;
    margin-bottom: 15px;
}

.sidebar-logo img {
    width: 70px;
    height: 70px;
    object-fit: cover; /* Changed from 'contain' to 'cover' for better circle fill */
    border-radius: 50%; /* Changed from '10px' to '50%' for perfect circle */
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    transition: all 0.3s ease;
    background: white;
    padding: 5px;
}

.sidebar-logo img:hover {
    transform: scale(1.05);
    box-shadow: 0 6px 20px rgba(139, 92, 246, 0.3);
}

        /* Main Content Styles */
        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        .topbar {
            background: white;
            padding: 0 30px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            position: fixed;
            top: 0;
            left: 280px;
            right: 0;
            z-index: 100;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
            flex-shrink: 0;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .menu-toggle {
            background: none;
            border: none;
            font-size: 20px;
            color: #4b5563;
            cursor: pointer;
            display: none;
        }

        .topbar-left h2 {
            color: #1f2937;
            font-size: 24px;
            font-weight: 700;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .notification-btn {
            position: relative;
            background: none;
            border: none;
            font-size: 20px;
            color: #6b7280;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: all 0.3s;
        }

        .notification-btn:hover {
            background: #f3f4f6;
            color: #8b5cf6;
        }

        .notification-badge {
            position: absolute;
            top: 0;
            right: 0;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            font-size: 10px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
        }

        .user-menu {
            position: relative;
        }

        .user-menu-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            background: none;
            border: none;
            padding: 8px 16px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .user-menu-btn:hover {
            background: #f3f4f6;
        }

        .user-avatar-small {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
        }

        .user-menu-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            min-width: 200px;
            display: none;
            z-index: 1000;
            overflow: hidden;
        }

        .user-menu-dropdown.show {
            display: block;
        }

        .user-menu-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            color: #4b5563;
            text-decoration: none;
            transition: all 0.3s;
        }

        .user-menu-item:hover {
            background: #f3f4f6;
            color: #8b5cf6;
        }

        .user-menu-divider {
            height: 1px;
            background: #e5e7eb;
            margin: 5px 0;
        }

        .content-wrapper {
            padding: 110px 30px 30px 30px;
            flex: 1;
            overflow-y: auto;
            min-height: 0;
        }

        /* Management Header */
        .management-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .header-title h1 {
            color: #1f2937;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .header-title p {
            color: #6b7280;
            font-size: 14px;
        }

        /* Alert Styles */
        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert-warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
            border-left: 4px solid #f59e0b;
        }

        .alert-danger {
            background: linear-gradient(135deg, #fecaca 0%, #fca5a5 100%);
            color: #7f1d1d;
            border-left: 4px solid #ef4444;
        }

        .alert-close {
            background: none;
            border: none;
            color: inherit;
            font-size: 20px;
            cursor: pointer;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Tab Navigation */
        .tab-navigation {
            display: flex;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 12px;
            padding: 5px;
            margin-bottom: 25px;
            border: 1px solid #e5e7eb;
        }

        .tab-btn {
            flex: 1;
            padding: 15px 20px;
            border: none;
            background: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
            color: #4b5563;
            border-radius: 8px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .tab-btn:hover {
            background: rgba(139, 92, 246, 0.1);
        }

        .tab-btn.active {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s;
            border-top: 4px solid;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--card-color), transparent);
        }

        .stat-card[data-color="purple"] {
            --card-color: #8b5cf6;
            border-color: #8b5cf6;
        }

        .stat-card[data-color="blue"] {
            --card-color: #3b82f6;
            border-color: #3b82f6;
        }

        .stat-card[data-color="green"] {
            --card-color: #10b981;
            border-color: #10b981;
        }

        .stat-card[data-color="red"] {
            --card-color: #ef4444;
            border-color: #ef4444;
        }

        .stat-card[data-color="orange"] {
            --card-color: #f59e0b;
            border-color: #f59e0b;
        }

        .stat-card[data-color="gray"] {
            --card-color: #6b7280;
            border-color: #6b7280;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }

        .stat-card.pulse {
            animation: pulse 2s infinite;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            background: var(--card-color);
        }

        .stat-content {
            flex: 1;
        }

        .stat-value {
            font-size: 36px;
            font-weight: 800;
            color: #1f2937;
            margin-bottom: 5px;
            line-height: 1;
        }

        .stat-label {
            font-size: 14px;
            color: #6b7280;
            font-weight: 500;
        }

        /* Content Grid - CHANGED: Changed from grid to flex column */
        .content-grid {
            display: flex;
            flex-direction: column;
            gap: 25px;
            margin-bottom: 30px;
        }

        /* Form Card - CHANGED: Made full width */
        .form-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border-top: 4px solid #8b5cf6;
            position: relative;
            overflow: hidden;
            width: 100%;
        }

        .form-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #8b5cf6, transparent);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f3f4f6;
        }

        .card-title {
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #374151;
            font-weight: 600;
            font-size: 14px;
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px 15px;
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            color: #4b5563;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .form-input:read-only {
            background: #f8fafc;
            color: #6b7280;
            cursor: not-allowed;
            border-color: #e5e7eb;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox {
            width: 18px;
            height: 18px;
            accent-color: #8b5cf6;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #f3f4f6;
        }

        /* Button Styles */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.4);
        }

        .btn-secondary {
            background: #f3f4f6;
            color: #4b5563;
            border: 1px solid #e5e7eb;
        }

        .btn-secondary:hover {
            background: #e5e7eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
        }

        /* Users/Students List - FIXED: Added scrollable container */
        .users-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border-top: 4px solid #3b82f6;
            position: relative;
            overflow: hidden;
            width: 100%;
        }

        .users-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3b82f6, transparent);
        }

        /* Users/Students List - Fixed height with scroll */
        .users-list, .students-list {
            max-height: 500px;
            overflow-y: auto;
            padding-right: 5px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #f8fafc;
            scrollbar-width: thin;
            scrollbar-color: #8b5cf6 #e5e7eb;
        }

        /* Custom scrollbar for WebKit browsers (Chrome, Safari, Edge) */
        .users-list::-webkit-scrollbar,
        .students-list::-webkit-scrollbar {
            width: 8px;
        }

        .users-list::-webkit-scrollbar-track,
        .students-list::-webkit-scrollbar-track {
            background: #e5e7eb;
            border-radius: 4px;
        }

        .users-list::-webkit-scrollbar-thumb,
        .students-list::-webkit-scrollbar-thumb {
            background: #8b5cf6;
            border-radius: 4px;
        }

        .users-list::-webkit-scrollbar-thumb:hover,
        .students-list::-webkit-scrollbar-thumb:hover {
            background: #7c3aed;
        }

        .user-item, .student-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px;
            background: #f8fafc;
            border-radius: 10px;
            margin-bottom: 15px;
            border-left: 4px solid #8b5cf6;
            transition: all 0.3s;
            margin: 10px;
        }

        .user-item:last-child, .student-item:last-child {
            margin-bottom: 10px;
        }

        .user-item:hover, .student-item:hover {
            transform: translateX(5px);
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .user-info-compact, .student-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
        }

        .user-avatar-small, .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            color: white;
        }

        .user-details-compact, .student-details {
            flex: 1;
        }

        .user-name-compact, .student-name {
            font-weight: 600;
            margin-bottom: 5px;
            font-size: 16px;
            color: #1f2937;
        }

        .user-meta, .student-meta {
            font-size: 12px;
            color: #6b7280;
        }

        /* Badge Styles */
        .user-role-badge, .student-course-badge, .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 5px;
        }

        .role-admin {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
        }

        .role-instructor {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
        }

        .course-badge {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .year-level-badge {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            font-size: 10px;
        }

        .status-active-badge {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .status-inactive-badge {
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
            color: white;
        }

        /* Action Buttons */
        .user-actions, .student-actions {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .edit-btn {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
        }

        .edit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(139, 92, 246, 0.3);
        }

        .delete-btn {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }

        .delete-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(239, 68, 68, 0.3);
        }

        .reset-btn {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }

        .reset-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(245, 158, 11, 0.3);
        }

        /* Search and Filter */
        .search-container {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .search-input {
            flex: 1;
            padding: 12px 20px;
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            color: #4b5563;
            font-size: 14px;
            transition: all 0.3s;
        }

        .search-input:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .search-btn {
            padding: 12px 25px;
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            border: none;
            border-radius: 10px;
            color: white;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3);
        }

        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.4);
        }

        /* Course Filter */
        .course-filter {
            background: white;
            border-radius: 15px;
            padding: 25px;
            border: 2px solid #f3f4f6;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .course-buttons-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }

        .course-btn {
            padding: 15px 20px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
            color: #4b5563;
            border: 1px solid #e5e7eb;
            text-decoration: none;
            text-align: left;
        }

        .course-btn:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-color: #8b5cf6;
        }

        .course-btn.active {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
            border-color: transparent;
        }

        .course-btn-content {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .course-name {
            font-size: 14px;
            font-weight: 600;
        }

        .course-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            min-width: 35px;
            text-align: center;
        }

        .course-btn.active .course-count {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Selected Course Info */
        .selected-course-info {
            background: linear-gradient(135deg, #e0e7ff 0%, #dbeafe 100%);
            border: 1px solid #c7d2fe;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .selected-course-info i {
            color: #4f46e5;
            font-size: 20px;
        }

        .clear-filters {
            background: white;
            border: 1px solid #8b5cf6;
            color: #8b5cf6;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 12px;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .clear-filters:hover {
            background: #8b5cf6;
            color: white;
        }

        /* Bulk Actions */
        .bulk-actions {
            background: white;
            border-radius: 15px;
            padding: 20px;
            border: 2px solid #f3f4f6;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .bulk-actions-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .bulk-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #8b5cf6;
        }

        .bulk-select-info {
            color: #374151;
            font-size: 14px;
            font-weight: 600;
        }

        .bulk-actions-right {
            display: flex;
            gap: 10px;
        }

        .bulk-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }

        .bulk-select-all {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            box-shadow: 0 2px 6px rgba(59, 130, 246, 0.3);
        }

        .bulk-select-all:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(59, 130, 246, 0.4);
        }

        .bulk-deselect-all {
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
            color: white;
            box-shadow: 0 2px 6px rgba(107, 114, 128, 0.3);
        }

        .bulk-deselect-all:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(107, 114, 128, 0.4);
        }

        .bulk-delete {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            box-shadow: 0 2px 6px rgba(239, 68, 68, 0.3);
        }

        .bulk-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(239, 68, 68, 0.4);
        }

        .student-checkbox {
            width: 16px;
            height: 16px;
            cursor: pointer;
            margin-right: 10px;
            accent-color: #8b5cf6;
        }

        .student-item.selected {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-left-color: #ef4444;
        }

        /* Password Container */
        .password-container {
            position: relative;
            display: flex;
            align-items: center;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            background: none;
            border: none;
            color: #6b7280;
            cursor: pointer;
            padding: 5px;
            border-radius: 5px;
            transition: all 0.3s;
        }

        .password-toggle:hover {
            color: #8b5cf6;
            background: #f3f4f6;
        }

        .password-input {
            padding-right: 50px !important;
        }

        .password-generator-btn {
            position: absolute;
            right: 45px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6b7280;
            cursor: pointer;
            padding: 5px;
            border-radius: 4px;
            transition: all 0.3s;
        }

        .password-generator-btn:hover {
            color: #8b5cf6;
            background: #f3f4f6;
        }

        .optional-field {
            color: #6b7280;
            font-style: italic;
            font-size: 12px;
        }

        .email-preview {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 10px 15px;
            margin-top: 8px;
            font-size: 12px;
            color: #0369a1;
        }

        .email-preview i {
            margin-right: 5px;
            color: #0ea5e9;
        }

        .password-preview {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 10px 15px;
            margin-top: 8px;
            font-size: 12px;
            color: #16a34a;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .password-preview i {
            margin-right: 5px;
            color: #22c55e;
        }

        .copy-password-btn {
            background: white;
            border: 1px solid #bbf7d0;
            color: #16a34a;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .copy-password-btn:hover {
            background: #f0fdf4;
            border-color: #86efac;
        }

        /* Modal Styles */
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            border: 2px solid #8b5cf6;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
            animation: modalSlideIn 0.3s ease-out;
            display: flex;
            flex-direction: column;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 25px 30px 0 30px;
            border-bottom: 2px solid #f3f4f6;
            margin-bottom: 20px;
            flex-shrink: 0;
        }

        .modal-header h3 {
            color: #1f2937;
            font-size: 24px;
            font-weight: 700;
        }

        .close {
            color: #6b7280;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .close:hover {
            color: #ef4444;
            background: #fef2f2;
        }

        .modal-body {
            padding: 0 30px 30px 30px;
            overflow-y: auto;
            flex: 1;
        }

        /* Confirmation Modal */
        .confirmation-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1001;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }

        .confirmation-content {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 500px;
            border: 2px solid #ef4444;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }

        .confirmation-header {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            padding: 25px 30px;
            text-align: center;
        }

        .confirmation-icon {
            font-size: 48px;
            margin-bottom: 15px;
            color: rgba(255,255,255,0.9);
        }

        .confirmation-title {
            color: white;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .confirmation-subtitle {
            color: rgba(255,255,255,0.8);
            font-size: 16px;
        }

        .confirmation-body {
            padding: 30px;
            text-align: center;
        }

        .confirmation-message {
            color: #374151;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 25px;
        }

        .confirmation-details {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .confirmation-count {
            font-size: 32px;
            font-weight: 800;
            color: #ef4444;
            margin-bottom: 10px;
        }

        .confirmation-text {
            color: #7f1d1d;
            font-size: 14px;
        }

        .confirmation-warning {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border: 1px solid #fde68a;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 25px;
        }

        .confirmation-warning i {
            color: #f59e0b;
            margin-right: 8px;
        }

        .confirmation-warning-text {
            color: #92400e;
            font-size: 14px;
            font-weight: 600;
        }

        .confirmation-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
        }

        .btn-cancel {
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #e5e7eb;
            padding: 12px 30px;
        }

        .btn-cancel:hover {
            background: #e5e7eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .btn-confirm-delete {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            padding: 12px 30px;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .btn-confirm-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
        }

        /* Toast Notification */
        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 12px 20px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 1000;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
            background: white;
            border-radius: 10px;
            margin: 10px;
        }

        .empty-state i {
            font-size: 64px;
            color: #e5e7eb;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 20px;
            color: #4b5563;
            margin-bottom: 10px;
        }

        .empty-state p {
            font-size: 14px;
            max-width: 400px;
            margin: 0 auto 20px;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .course-buttons-grid {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            }
        }

        @media (max-width: 992px) {
            /* Update topbar for mobile */
            .topbar {
                left: 0;
            }
            
            /* Adjust content wrapper padding for mobile */
            .content-wrapper {
                padding: 100px 30px 30px 30px;
            }
        }

        @media (max-width: 768px) {
            .content-wrapper {
                padding: 90px 20px 20px 20px;
            }
            
            .topbar {
                height: 70px;
                padding: 0 20px;
            }
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 80px;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .menu-toggle {
                display: block;
            }
            
            .sidebar-header h1,
            .user-info,
            .menu-title,
            .menu-item span:not(.menu-icon) {
                display: none;
            }
            
            .menu-item {
                justify-content: center;
                padding: 15px;
                margin: 5px;
                border-radius: 10px;
            }
            
            .menu-icon {
                margin-right: 0;
                font-size: 20px;
            }
            
            .sidebar:hover .sidebar-header h1,
            .sidebar:hover .user-info,
            .sidebar:hover .menu-title,
            .sidebar:hover .menu-item span:not(.menu-icon) {
                display: block;
            }
            
            .sidebar:hover .menu-item {
                justify-content: flex-start;
                padding: 14px 25px;
            }
            
            .sidebar:hover .menu-icon {
                margin-right: 15px;
            }
        }

        @media (max-width: 768px) {
            .content-wrapper {
                padding: 20px;
            }
            
            .topbar {
                padding: 0 20px;
                height: 70px;
            }
            
            .topbar-left h2 {
                font-size: 20px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .user-item, .student-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .user-actions, .student-actions {
                width: 100%;
                justify-content: flex-end;
            }
            
            .search-container {
                flex-direction: column;
            }
            
            .course-buttons-grid {
                grid-template-columns: 1fr;
            }
            
            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }
            
            .bulk-actions-left, .bulk-actions-right {
                width: 100%;
                justify-content: space-between;
            }
            
            .confirmation-actions {
                flex-direction: column;
            }
            
            .modal-content {
                width: 95%;
                max-width: 95%;
            }
            
            .modal-header, .modal-body {
                padding: 20px;
            }
            
            .content-grid {
                gap: 15px;
            }
        }

        @media (max-width: 480px) {
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
.sidebar-logo {
        margin: 10px auto;
    }
    
    .sidebar-logo img {
        width: 40px;
        height: 40px;
    }
    
    /* Hide logo text on mobile collapsed sidebar */
    .sidebar-header h1,
    .sidebar-header p,
    .menu-title,
    .menu-item span:not(.menu-icon) {
        display: none;
    }
    
    /* Show logo image when hovering on collapsed sidebar */
    .sidebar:hover .sidebar-logo {
        display: flex;
    }
    
    .sidebar:hover .sidebar-logo img {
        display: block;
    }
}

        @keyframes pulse {
            0% { transform: scale(1); box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
            50% { transform: scale(1.05); box-shadow: 0 8px 25px rgba(139, 92, 246, 0.2); }
            100% { transform: scale(1); box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <img src="image/logo1.png" alt="Emotion AI Logo">
            </div>

            <h1>PLMUN Emotion Monitoring</h1>
            <p>Administration Panel</p>
        </div>
        
        <div class="sidebar-menu">
            <div class="menu-title">Main Navigation</div>
            <a href="admin_dashboard.php" class="menu-item">
                <i class="menu-icon fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            
            <div class="menu-title">User Management</div>
            <a href="admin_user_management.php" class="menu-item active">
                <i class="menu-icon fas fa-users-cog"></i>
                <span>User Management</span>
            </a>

            <div class="menu-title">Analytics & Reports</div>
            <a href="admin_analytics_reports.php" class="menu-item">
                <i class="menu-icon fas fa-chart-line"></i>
                <span>Analytics & Reports</span>
            </a>
            
            <div class="menu-title">System Settings</div>
            <a href="admin_system_settings.php" class="menu-item">
                <i class="menu-icon fas fa-cogs"></i>
                <span>System Settings</span>
            </a>
            <a href="admin_audit_logs.php" class="menu-item">
                <i class="menu-icon fas fa-clipboard-list"></i>
                <span>Audit Logs</span>
            </a>
            
            <div class="menu-title">Account</div>
            <a href="admin_profile.php" class="menu-item">
                <i class="menu-icon fas fa-user-circle"></i>
                <span>Profile</span>
            </a>
            <a href="logout.php" class="menu-item">
                <i class="menu-icon fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Topbar -->
        <div class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>User Management</h2>
            </div>
            <div class="topbar-right">
                <div class="user-menu">
                    <button class="user-menu-btn" id="userMenuBtn">
                        <div class="user-avatar-small">
                            <?php echo $admin_initials; ?>
                        </div>
                        <span><?php echo htmlspecialchars($admin_full_name); ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="user-menu-dropdown" id="userMenuDropdown">
                        <a href="admin_profile.php" class="user-menu-item">
                            <i class="fas fa-user-circle"></i>
                            <span>My Profile</span>
                        </a>
                        <a href="admin_system_settings.php" class="user-menu-item">
                            <i class="fas fa-cog"></i>
                            <span>Settings</span>
                        </a>
                        <div class="user-menu-divider"></div>
                        <a href="logout.php" class="user-menu-item">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Content -->
        <div class="content-wrapper">
            <!-- Management Header -->
            <div class="management-header">
                <div class="header-title">
                    <h1>User Management System</h1>
                    <p>Manage instructors, students, and administrators</p>
                </div>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo $error; ?>
                    <button class="alert-close" onclick="this.parentElement.style.display='none'">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success; ?>
                    <button class="alert-close" onclick="this.parentElement.style.display='none'">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>
            
            <!-- Tab Navigation -->
            <div class="tab-navigation">
                <button class="tab-btn <?php echo $active_tab === 'instructors' ? 'active' : ''; ?>" data-tab="instructors">
                    <i class="fas fa-chalkboard-teacher"></i> Instructors
                </button>
                <button class="tab-btn <?php echo $active_tab === 'students' ? 'active' : ''; ?>" data-tab="students">
                    <i class="fas fa-user-graduate"></i> Students
                </button>
            </div>
            
            <!-- INSTRUCTORS TAB -->
            <div class="tab-content <?php echo $active_tab === 'instructors' ? 'active' : ''; ?>" id="instructors-tab">
                <!-- Instructor Statistics -->
                <div class="stats-grid">
                    <div class="stat-card pulse" data-color="purple">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $instructor_stats['total_instructors']; ?></div>
                            <div class="stat-label">Total Instructors</div>
                        </div>
                    </div>
                    <div class="stat-card" data-color="red">
                        <div class="stat-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $instructor_stats['admin_count']; ?></div>
                            <div class="stat-label">Administrators</div>
                        </div>
                    </div>
                    <div class="stat-card" data-color="blue">
                        <div class="stat-icon">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $instructor_stats['instructor_count']; ?></div>
                            <div class="stat-label">Instructors</div>
                        </div>
                    </div>
                    <div class="stat-card" data-color="green">
                        <div class="stat-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $instructor_stats['active_instructors']; ?></div>
                            <div class="stat-label">Active Users</div>
                        </div>
                    </div>
                </div>

                <!-- Content Grid -->
                <div class="content-grid">
                    <!-- Add Instructor Form -->
                    <div class="form-card">
                        <div class="card-header">
                            <i class="fas fa-user-plus" style="color: #8b5cf6; font-size: 20px;"></i>
                            <h3 class="card-title">Add New Instructor</h3>
                        </div>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="add_user" value="1">
                            
                            <div class="form-group">
                                <label for="full_name" class="form-label">Full Name *</label>
                                <input type="text" id="full_name" name="full_name" class="form-input" required onchange="generateInstructorEmail()">
                            </div>
                            
                            <div class="form-group">
                                <label for="username" class="form-label">ID Number *</label>
                                <input type="text" id="username" name="username" class="form-input" required value="<?php echo $next_instructor_id; ?>" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label for="email" class="form-label">Email *</label>
                                <input type="email" id="email" name="email" class="form-input" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="personal_email" class="form-label">Personal Email <span class="optional-field">(optional)</span></label>
                                <input type="email" id="personal_email" name="personal_email" class="form-input" placeholder="Enter personal email address">
                            </div>
                            
                            <div class="form-group">
                                <label for="contact_number" class="form-label">Contact Number <span class="optional-field">(optional)</span></label>
                                <input type="tel" id="contact_number" name="contact_number" class="form-input" placeholder="Enter contact number">
                            </div>
                            
                            <div class="form-group">
                                <label for="password" class="form-label">Password *</label>
                                <div class="password-container">
                                    <input type="text" id="instructor_password" name="password" class="form-input password-input" required value="<?php echo $initial_instructor_password; ?>">
                                    <button type="button" class="password-toggle" onclick="togglePasswordVisibility('instructor_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="password-preview">
                                    <span>Auto-generated password: <strong><?php echo $initial_instructor_password; ?></strong></span>
                                    <button type="button" class="copy-password-btn" onclick="copyPassword('instructor_password')">
                                        <i class="fas fa-copy"></i> Copy
                                    </button>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="role" class="form-label">Role *</label>
                                <select id="role" name="role" class="form-select" required>
                                    <option value="instructor">Instructor</option>
                                    <option value="admin">Administrator</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="department" class="form-label">Department</label>
                                <select id="department" name="department" class="form-select">
                                    <option value="">Select Department</option>
                                    <option value="College of Arts and Sciences (CAS)">College of Arts and Sciences (CAS)</option>
                                    <option value="College of Business Administration (CBA)">College of Business Administration (CBA)</option>
                                    <option value="College of Accountancy">College of Accountancy</option>
                                    <option value="College of Criminal Justice (CCJ)">College of Criminal Justice (CCJ)</option>
                                    <option value="College of Information, Technology and Computer Studies (CITCS)">College of Information, Technology and Computer Studies (CITCS)</option>
                                    <option value="College of Medicine (COM)">College of Medicine (COM)</option>
                                    <option value="College of Teacher Education">College of Teacher Education</option>
                                    <option value="Institute of Public Policy and Governance">Institute of Public Policy and Governance</option>
                                    <option value="Institute of Social Work">Institute of Social Work</option>
                                </select>
                            </div>
                            
                            <div class="form-actions">
                                <button type="reset" class="btn btn-secondary" onclick="resetInstructorForm()">
                                    <i class="fas fa-undo"></i> Reset
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Create Instructor
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Instructors List -->
                    <div class="users-card">
                        <div class="card-header">
                            <i class="fas fa-users" style="color: #3b82f6; font-size: 20px;"></i>
                            <h3 class="card-title">Manage Instructors</h3>
                        </div>
                        
                        <!-- Instructor Count -->
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <small style="color: #6b7280; font-weight: 600;">
                                Showing <?php echo count($instructors); ?> instructor(s)
                            </small>
                            <small style="color: #6b7280;">
                                <i class="fas fa-info-circle"></i> Scroll to see more
                            </small>
                        </div>
                        
                        <div class="search-container">
                            <input type="text" class="search-input" placeholder="Search instructors by name, ID, or email..." id="instructorSearch">
                            <button class="search-btn" onclick="searchInstructors()">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </div>
                        
                        <div class="users-list">
                            <?php if (count($instructors) > 0): ?>
                                <?php foreach ($instructors as $user): ?>
                                    <div class="user-item">
                                        <div class="user-info-compact">
                                            <div class="user-avatar-small">
                                                <?php echo getInitials($user['full_name']); ?>
                                            </div>
                                            <div class="user-details-compact">
                                                <div class="user-name-compact">
                                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                                    <span class="user-role-badge role-<?php echo $user['role']; ?>">
                                                        <?php echo ucfirst($user['role']); ?>
                                                    </span>
                                                    <span class="status-badge <?php echo $user['is_active'] ? 'status-active-badge' : 'status-inactive-badge'; ?>">
                                                        <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                </div>
                                                <div class="user-meta">
                                                    <strong>ID Number:</strong> <?php echo htmlspecialchars($user['username']); ?> • 
                                                    <strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?>
                                                    <?php if (!empty($user['personal_email'])): ?>
                                                        • <strong>Personal Email:</strong> <?php echo htmlspecialchars($user['personal_email']); ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($user['contact_number'])): ?>
                                                        • <strong>Contact:</strong> <?php echo htmlspecialchars($user['contact_number']); ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($user['department'])): ?>
                                                        • <strong>Department:</strong> <?php echo htmlspecialchars($user['department']); ?>
                                                    <?php endif; ?>
                                                    <br>
                                                    <small>Created: <?php echo date('M j, Y', strtotime($user['created_at'])); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="user-actions">
                                            <button type="button" class="action-btn edit-btn" onclick="editUser(
                                                <?php echo $user['id']; ?>, 
                                                '<?php echo htmlspecialchars($user['full_name'], ENT_QUOTES); ?>', 
                                                '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>', 
                                                '<?php echo htmlspecialchars($user['email'], ENT_QUOTES); ?>', 
                                                '<?php echo htmlspecialchars($user['personal_email'] ?? '', ENT_QUOTES); ?>', 
                                                '<?php echo htmlspecialchars($user['contact_number'] ?? '', ENT_QUOTES); ?>', 
                                                '<?php echo $user['role']; ?>', 
                                                '<?php echo htmlspecialchars($user['department'] ?? '', ENT_QUOTES); ?>', 
                                                <?php echo $user['is_active'] ? 'true' : 'false'; ?>
                                            )">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button type="button" class="action-btn reset-btn" onclick="resetPassword(<?php echo $user['id']; ?>)">
                                                <i class="fas fa-key"></i> Reset
                                            </button>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <button type="submit" name="delete_user" class="action-btn delete-btn" onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-users-slash"></i>
                                    <h3>No Instructors Found</h3>
                                    <p>Use the form on the left to add new instructors.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- STUDENTS TAB -->
            <div class="tab-content <?php echo $active_tab === 'students' ? 'active' : ''; ?>" id="students-tab">
                <!-- Student Statistics - UPDATED -->
                <div class="stats-grid">
                    <div class="stat-card pulse" data-color="purple">
                        <div class="stat-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $total_students; ?></div>
                            <div class="stat-label">Total Students</div>
                        </div>
                    </div>
                    <div class="stat-card" data-color="green">
                        <div class="stat-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $active_students; ?></div>
                            <div class="stat-label">Active Students</div>
                        </div>
                    </div>
                    <div class="stat-card" data-color="gray">
                        <div class="stat-icon">
                            <i class="fas fa-user-times"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $inactive_students; ?></div>
                            <div class="stat-label">Inactive Students</div>
                        </div>
                    </div>
                </div>

                <!-- Content Grid -->
                <div class="content-grid">
                    <!-- Add Student Form -->
                    <div class="form-card">
                        <div class="card-header">
                            <i class="fas fa-user-plus" style="color: #8b5cf6; font-size: 20px;"></i>
                            <h3 class="card-title">Add New Student</h3>
                        </div>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="add_student" value="1">
                            
                            <div class="form-group">
                                <label for="student_number" class="form-label">Student Number</label>
                                <input type="text" id="student_number" name="student_number" class="form-input" 
                                       value="<?php echo $new_student_number; ?>" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label for="student_full_name" class="form-label">Full Name</label>
                                <input type="text" id="student_full_name" name="full_name" class="form-input" 
                                       placeholder="Enter full name (FirstName LastName)" required 
                                       oninput="updateStudentEmailPreview()">
                            </div>
                            
                            <div class="form-group">
                                <label for="student_course" class="form-label">Course</label>
                                <select id="student_course" name="course" class="form-select" required onchange="updateStudentEmailPreview()">
                                    <option value="">Select Course</option>
                                    <?php foreach ($available_courses as $course): ?>
                                        <option value="<?php echo $course; ?>"><?php echo $course; ?></option>
                                    <?php endforeach; ?>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="year_level" class="form-label">Year Level</label>
                                <select id="year_level" name="year_level" class="form-select" required>
                                    <option value="">Select Year Level</option>
                                    <?php foreach ($year_levels as $level): ?>
                                        <option value="<?php echo $level; ?>"><?php echo $level; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="student_email" class="form-label">PLMUN Email Address</label>
                                <input type="email" id="student_email" name="email" class="form-input" 
                                       placeholder="Leave empty for auto-generation">
                                <div id="studentEmailPreview" class="email-preview" style="display: none;">
                                    <span id="studentPreviewText">Auto-generated email will appear here</span>
                                </div>
                                <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 5px;">
                                    Leave empty to auto-generate PLMUN email
                                </small>
                            </div>
                            
                            <!-- Personal Email and Contact Number Section -->
                            <div class="form-group">
                                <label for="student_personal_email" class="form-label">Personal Email <span class="optional-field">(optional)</span></label>
                                <input type="email" id="student_personal_email" name="personal_email" class="form-input" 
                                       placeholder="Enter personal email address">
                            </div>
                            
                            <div class="form-group">
                                <label for="student_contact_number" class="form-label">Contact Number <span class="optional-field">(optional)</span></label>
                                <input type="tel" id="student_contact_number" name="contact_number" class="form-input" 
                                       placeholder="Enter contact number (e.g., +63 912 345 6789)">
                            </div>
                            
                            <!-- PASSWORD SECTION -->
                            <div class="form-group">
                                <label for="student_password" class="form-label">Password *</label>
                                <div class="password-container">
                                    <input type="password" id="student_password" name="password" class="form-input password-input" 
                                           value="<?php echo $auto_student_password; ?>" required>
                                    <button type="button" class="password-generator-btn" onclick="generateNewStudentPassword()" title="Generate new password">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                    <button type="button" class="password-toggle" onclick="togglePasswordVisibility('student_password')" title="Toggle visibility">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div id="studentPasswordPreview" class="password-preview">
                                    <div>
                                        <span>Auto-generated password: <strong><?php echo $auto_student_password; ?></strong></span>
                                    </div>
                                    <button type="button" class="copy-password-btn" onclick="copyPassword('student_password')">
                                        <i class="fas fa-copy"></i> Copy
                                    </button>
                                </div>
                                <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 5px;">
                                    Auto-generated secure password (6 characters: letter-number pattern)
                                </small>
                            </div>
                            
                            <div class="form-actions">
                                <button type="reset" class="btn btn-secondary" onclick="resetStudentForm()">
                                    <i class="fas fa-undo"></i> Reset
                                </button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Create Student Account
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Students List with Enhanced Filter and Search -->
                    <div class="users-card">
                        <div class="card-header">
                            <i class="fas fa-users" style="color: #3b82f6; font-size: 20px;"></i>
                            <h3 class="card-title">Student Accounts</h3>
                        </div>
                        
                        <!-- Student Count -->
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <small style="color: #6b7280; font-weight: 600;">
                                Showing <?php echo count($students); ?> student(s)
                            </small>
                            <small style="color: #6b7280;">
                                <i class="fas fa-info-circle"></i> Scroll to see more
                            </small>
                        </div>
                        
                        <!-- Enhanced Course Filter and Search Section -->
                        <div class="course-filter">
                            <!-- Search Bar -->
                            <form method="GET" action="" class="search-container">
                                <input type="hidden" name="tab" value="students">
                                <input type="hidden" name="course" value="<?php echo $selected_course; ?>">
                                <input type="text" name="search" class="search-input" 
                                       placeholder="Search by name, student ID, email, or contact..." 
                                       value="<?php echo htmlspecialchars($search_query); ?>">
                                <button type="submit" class="search-btn">
                                    <i class="fas fa-search"></i> Search
                                </button>
                            </form>
                            
                            <!-- Enhanced Course Filter Buttons -->
                            <div class="course-buttons-grid">
                                <a href="?tab=students&course=all<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" 
                                   class="course-btn <?php echo $selected_course === 'all' ? 'active' : ''; ?>">
                                    <div class="course-btn-content">
                                        <div class="course-name">All Students</div>
                                    </div>
                                    <span class="course-count"><?php echo $total_students; ?></span>
                                </a>
                                
                                <?php foreach ($available_courses as $course): ?>
                                    <?php 
                                    $course_abbr = getCourseAbbreviation($course);
                                    $count = isset($course_count_map[$course]) ? $course_count_map[$course] : 0;
                                    $is_active = $selected_course === $course;
                                    ?>
                                    <a href="?tab=students&course=<?php echo urlencode($course); ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" 
                                       class="course-btn <?php echo $is_active ? 'active' : ''; ?>">
                                        <div class="course-btn-content">
                                            <div class="course-name"><?php echo $course_abbr; ?></div>
                                        </div>
                                        <span class="course-count"><?php echo $count; ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Selected Course/Search Info -->
                        <?php if ($selected_course !== 'all' || !empty($search_query)): ?>
                            <div class="selected-course-info">
                                <i class="fas fa-filter"></i>
                                <div>
                                    <strong style="color: #374151;">Current Filters:</strong>
                                    <?php if ($selected_course !== 'all'): ?>
                                        <br>• Course: <?php echo $selected_course; ?>
                                    <?php endif; ?>
                                    <?php if (!empty($search_query)): ?>
                                        <br>• Search: "<?php echo htmlspecialchars($search_query); ?>"
                                    <?php endif; ?>
                                    <div style="margin-top: 8px;">
                                        <a href="?tab=students&course=all" class="clear-filters">
                                            <i class="fas fa-times"></i> Clear Filters
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Bulk Actions Section -->
                        <?php if (count($students) > 0): ?>
                        <div class="bulk-actions">
                            <div class="bulk-actions-left">
                                <input type="checkbox" id="selectAll" class="bulk-checkbox" onchange="toggleSelectAll()">
                                <span class="bulk-select-info" id="selectedCount">0 students selected</span>
                            </div>
                            <div class="bulk-actions-right">
                                <button type="button" class="bulk-btn bulk-select-all" onclick="selectAllStudents()">
                                    <i class="fas fa-check-square"></i> Select All
                                </button>
                                <button type="button" class="bulk-btn bulk-deselect-all" onclick="deselectAllStudents()">
                                    <i class="fas fa-times-circle"></i> Deselect All
                                </button>
                                <button type="button" class="bulk-btn bulk-delete" onclick="showBulkDeleteConfirmation()">
                                    <i class="fas fa-trash"></i> Delete Selected
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Student List -->
                        <div class="students-list">
                            <?php if (count($students) > 0): ?>
                                <form method="POST" action="" id="bulkDeleteForm">
                                    <input type="hidden" name="bulk_delete" value="1">
                                    <?php foreach ($students as $student): ?>
                                        <div class="student-item" id="student-<?php echo $student['id']; ?>">
                                            <div class="student-info">
                                                <input type="checkbox" name="selected_students[]" value="<?php echo $student['id']; ?>" 
                                                       class="student-checkbox" onchange="updateSelectedCount()">
                                                <div class="student-avatar">
                                                    <?php echo getInitials($student['full_name']); ?>
                                                </div>
                                                <div class="student-details">
                                                    <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?>
                                                        <?php if (isset($student['course']) && !empty($student['course'])): ?>
                                                            <span class="student-course-badge course-badge"><?php echo getCourseAbbreviation($student['course']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (isset($student['year_level']) && !empty($student['year_level'])): ?>
                                                            <span class="student-course-badge year-level-badge"><?php echo htmlspecialchars($student['year_level']); ?></span>
                                                        <?php endif; ?>
                                                        <!-- ADDED: Status badge for students -->
                                                        <span class="status-badge <?php echo $student['is_active'] ? 'status-active-badge' : 'status-inactive-badge'; ?>">
                                                            <?php echo $student['is_active'] ? 'Active' : 'Inactive'; ?>
                                                        </span>
                                                    </div>
                                                    <div class="student-meta">
                                                        <strong>Student No:</strong> <?php echo htmlspecialchars($student['student_number']); ?> • 
                                                        <strong>Email:</strong> <?php echo htmlspecialchars($student['email']); ?>
                                                        <?php if ($student['personal_email']): ?>
                                                            • <strong>Personal Email:</strong> <?php echo htmlspecialchars($student['personal_email']); ?>
                                                        <?php endif; ?>
                                                        <?php if ($student['contact_number']): ?>
                                                            • <strong>Contact:</strong> <?php echo htmlspecialchars($student['contact_number']); ?>
                                                        <?php endif; ?>
                                                        <br>
                                                        <?php if (isset($student['course']) && !empty($student['course'])): ?>
                                                            <small><strong>Course:</strong> <?php echo htmlspecialchars($student['course']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="student-actions">
                                                <button type="button" class="action-btn edit-btn" onclick="editStudent(
                                                    <?php echo $student['id']; ?>, 
                                                    '<?php echo htmlspecialchars($student['full_name']); ?>', 
                                                    '<?php echo isset($student['course']) ? htmlspecialchars($student['course']) : ''; ?>', 
                                                    '<?php echo isset($student['year_level']) ? htmlspecialchars($student['year_level']) : ''; ?>', 
                                                    '<?php echo htmlspecialchars($student['email']); ?>',
                                                    '<?php echo htmlspecialchars($student['personal_email']); ?>',
                                                    '<?php echo htmlspecialchars($student['contact_number']); ?>',
                                                    <?php echo $student['is_active'] ? 'true' : 'false'; ?>
                                                )">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <!-- ADDED: Reset Password Button for Students -->
                                                <button type="button" class="action-btn reset-btn" onclick="resetStudentPassword(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['full_name']); ?>')">
                                                    <i class="fas fa-key"></i> Reset
                                                </button>
                                                <form method="POST" action="" style="display: inline;">
                                                    <input type="hidden" name="delete_student_id" value="<?php echo $student['id']; ?>">
                                                    <button type="submit" name="delete_student" class="action-btn delete-btn" onclick="return confirm('Are you sure you want to delete this student? This will also remove all their enrollments and data.')">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </form>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-user-slash"></i>
                                    <h3>No Students Found</h3>
                                    <p>
                                        <?php if ($selected_course === 'all' && empty($search_query)): ?>
                                            No student accounts found. Use the form above to add new students.
                                        <?php else: ?>
                                            No students found matching your criteria.
                                            <?php if ($selected_course !== 'all'): ?>
                                                <br><small>Course: <?php echo $selected_course; ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($search_query)): ?>
                                                <br><small>Search: "<?php echo htmlspecialchars($search_query); ?>"</small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </p>
                                    <?php if ($selected_course !== 'all' || !empty($search_query)): ?>
                                        <a href="?tab=students&course=all" class="btn btn-primary" style="margin-top: 15px;">
                                            <i class="fas fa-times"></i> Clear Filters
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Instructor Modal -->
    <div id="editUserModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Instructor</h3>
                <span class="close" onclick="closeEditModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="editUserForm" method="POST" action="">
                    <input type="hidden" name="update_user" value="1">
                    <input type="hidden" id="edit_user_id" name="user_id" value="">
                    
                    <div class="form-group">
                        <label for="edit_full_name" class="form-label">Full Name *</label>
                        <input type="text" id="edit_full_name" name="full_name" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_username" class="form-label">ID Number *</label>
                        <input type="text" id="edit_username" name="username" class="form-input" required readonly>
                        <div class="id-number-info" style="font-size: 12px; color: #6b7280; margin-top: 5px;">ID Number cannot be changed once created.</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_email" class="form-label">Email *</label>
                        <input type="email" id="edit_email" name="email" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_personal_email" class="form-label">Personal Email <span class="optional-field">(optional)</span></label>
                        <input type="email" id="edit_personal_email" name="personal_email" class="form-input" placeholder="Enter personal email address">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_contact_number" class="form-label">Contact Number <span class="optional-field">(optional)</span></label>
                        <input type="tel" id="edit_contact_number" name="contact_number" class="form-input" placeholder="Enter contact number">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_role" class="form-label">Role *</label>
                        <select id="edit_role" name="role" class="form-select" required>
                            <option value="instructor">Instructor</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_department" class="form-label">Department</label>
                        <select id="edit_department" name="department" class="form-select">
                            <option value="">Select Department</option>
                            <option value="College of Arts and Sciences (CAS)">College of Arts and Sciences (CAS)</option>
                            <option value="College of Business Administration (CBA)">College of Business Administration (CBA)</option>
                            <option value="College of Accountancy">College of Accountancy</option>
                            <option value="College of Criminal Justice (CCJ)">College of Criminal Justice (CCJ)</option>
                            <option value="College of Information, Technology and Computer Studies (CITCS)">College of Information, Technology and Computer Studies (CITCS)</option>
                            <option value="College of Medicine (COM)">College of Medicine (COM)</option>
                            <option value="College of Teacher Education">College of Teacher Education</option>
                            <option value="Institute of Public Policy and Governance">Institute of Public Policy and Governance</option>
                            <option value="Institute of Social Work">Institute of Social Work</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="edit_is_active" name="is_active" class="checkbox" value="1">
                            <label for="edit_is_active" class="form-label">Active User</label>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Instructor
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Student Modal - UPDATED with is_active field -->
    <div id="editStudentModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Student</h3>
                <span class="close" onclick="closeEditStudentModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="editStudentForm" method="POST" action="">
                    <input type="hidden" name="update_student" value="1">
                    <input type="hidden" id="update_student_id" name="update_student_id">
                    
                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="update_full_name" id="update_full_name" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Course</label>
                        <select name="update_course" id="update_course" class="form-select" required>
                            <option value="">Select Course</option>
                            <?php foreach ($available_courses as $course): ?>
                                <option value="<?php echo $course; ?>"><?php echo $course; ?></option>
                            <?php endforeach; ?>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Year Level</label>
                        <select name="update_year_level" id="update_year_level" class="form-select" required>
                            <option value="">Select Year Level</option>
                            <?php foreach ($year_levels as $level): ?>
                                <option value="<?php echo $level; ?>"><?php echo $level; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">PLMUN Email Address</label>
                        <input type="email" name="update_email" id="update_email" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Personal Email</label>
                        <input type="email" name="update_personal_email" id="update_personal_email" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Contact Number</label>
                        <input type="tel" name="update_contact_number" id="update_contact_number" class="form-input">
                    </div>
                    
                    <!-- ADDED: Status checkbox for students -->
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="update_is_active" name="update_is_active" class="checkbox" value="1">
                            <label for="update_is_active" class="form-label">Active Account</label>
                        </div>
                        <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 5px;">
                            Uncheck to deactivate this student account (student won't be able to login)
                        </small>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeEditStudentModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Student
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Student Password Reset Modal -->
    <div id="resetStudentPasswordModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reset Student Password</h3>
                <span class="close" onclick="closeResetStudentPasswordModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="resetStudentPasswordForm" method="POST" action="">
                    <input type="hidden" name="reset_student_password" value="1">
                    <input type="hidden" id="reset_student_id" name="student_id" value="">
                    
                    <div class="form-group">
                        <label for="reset_student_name" class="form-label">Student Name</label>
                        <input type="text" id="reset_student_name" class="form-input" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="reset_new_password" class="form-label">New Password *</label>
                        <div class="password-container">
                            <input type="password" id="reset_new_password" name="new_password" class="form-input password-input" required>
                            <button type="button" class="password-toggle" onclick="togglePasswordVisibility('reset_new_password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 5px;">
                            Enter a new password for the student. Minimum 6 characters.
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_new_password" class="form-label">Confirm New Password *</label>
                        <div class="password-container">
                            <input type="password" id="confirm_new_password" class="form-input password-input" required>
                            <button type="button" class="password-toggle" onclick="togglePasswordVisibility('confirm_new_password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small id="passwordMatchMessage" style="font-size: 12px; margin-top: 5px;"></small>
                    </div>
                    
                    <div class="form-group">
                        <button type="button" class="btn btn-secondary" onclick="generateRandomStudentPassword()">
                            <i class="fas fa-random"></i> Generate Random Password
                        </button>
                        <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 5px;">
                            Click to generate a secure random password (6 characters: letter-number pattern)
                        </small>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="closeResetStudentPasswordModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary" id="submitResetBtn">
                            <i class="fas fa-key"></i> Reset Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Bulk Delete Confirmation Modal -->
    <div id="bulkDeleteConfirmation" class="confirmation-modal">
        <div class="confirmation-content">
            <div class="confirmation-header">
                <div class="confirmation-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h3 class="confirmation-title">Confirm Bulk Delete</h3>
                <p class="confirmation-subtitle">This action cannot be undone</p>
            </div>
            
            <div class="confirmation-body">
                <p class="confirmation-message">
                    You are about to permanently delete the selected student accounts. This will remove all their data including enrollments and emotion records.
                </p>
                
                <div class="confirmation-details">
                    <div class="confirmation-count" id="confirmationCount">0</div>
                    <div class="confirmation-text">Student accounts will be deleted</div>
                </div>
                
                <div class="confirmation-warning">
                    <span class="confirmation-warning-text">This action is irreversible. Please make sure you have selected the correct students.</span>
                </div>
                
                <div class="confirmation-actions">
                    <button type="button" class="btn btn-cancel" onclick="hideBulkDeleteConfirmation()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-confirm-delete" onclick="submitBulkDelete()">
                        <i class="fas fa-trash"></i> Delete Selected Students
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Toast Notification -->
    <div id="toast" class="toast"></div>

    <script>
        // Sidebar toggle for mobile
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
        });
        
        // User menu dropdown
        const userMenuBtn = document.getElementById('userMenuBtn');
        const userMenuDropdown = document.getElementById('userMenuDropdown');
        
        userMenuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            userMenuDropdown.classList.toggle('show');
        });
        
        document.addEventListener('click', () => {
            userMenuDropdown.classList.remove('show');
        });
        
        // Tab Switching
        document.querySelectorAll('.tab-btn').forEach(button => {
            button.addEventListener('click', () => {
                const tab = button.getAttribute('data-tab');
                
                // Update URL without reloading page
                const url = new URL(window.location);
                url.searchParams.set('tab', tab);
                window.history.pushState({}, '', url);
                
                // Update active tab
                document.querySelectorAll('.tab-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                button.classList.add('active');
                
                // Show active tab content
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                document.getElementById(`${tab}-tab`).classList.add('active');
            });
        });

        // Password generation functions
        function generateRandomPassword() {
            const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            const numbers = '0123456789';
            
            let password = '';
            
            // First two letters
            for (let i = 0; i < 2; i++) {
                password += letters[Math.floor(Math.random() * letters.length)];
            }
            
            // One number
            password += numbers[Math.floor(Math.random() * numbers.length)];
            
            // One letter
            password += letters[Math.floor(Math.random() * letters.length)];
            
            // Two numbers
            for (let i = 0; i < 2; i++) {
                password += numbers[Math.floor(Math.random() * numbers.length)];
            }
            
            return password;
        }

        function generateStudentPassword(length = 6) {
            const characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            const numbers = '0123456789';
            let password = '';
            
            // Alternate between letters and numbers
            for (let i = 0; i < length; i++) {
                if (i % 2 === 0) {
                    // Even position: letter
                    password += characters.charAt(Math.floor(Math.random() * characters.length));
                } else {
                    // Odd position: number
                    password += numbers.charAt(Math.floor(Math.random() * numbers.length));
                }
            }
            
            return password;
        }

        // Copy password to clipboard
        function copyPassword(passwordFieldId) {
            const passwordField = document.getElementById(passwordFieldId);
            passwordField.select();
            document.execCommand('copy');
            
            // Show copied feedback
            showToast('Password copied to clipboard!');
        }

        // Generate new student password
        function generateNewStudentPassword() {
            const newPassword = generateStudentPassword();
            document.getElementById('student_password').value = newPassword;
            
            // Update password preview
            const passwordPreview = document.getElementById('studentPasswordPreview');
            const passwordText = passwordPreview.querySelector('span strong');
            passwordText.textContent = newPassword;
            
            // Show success feedback
            showToast('New password generated!');
        }

        // Toggle password visibility
        function togglePasswordVisibility(passwordFieldId) {
            const passwordField = document.getElementById(passwordFieldId);
            const toggleButton = passwordField.nextElementSibling;
            const icon = toggleButton.querySelector('i');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                toggleButton.setAttribute('title', 'Hide password');
            } else {
                passwordField.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                toggleButton.setAttribute('title', 'Show password');
            }
        }

        // Generate instructor email from name
        function generateInstructorEmail() {
            const fullName = document.getElementById('full_name').value.trim();
            
            if (fullName) {
                // Remove extra spaces and split name
                const nameParts = fullName.split(' ').filter(part => part.length > 0);
                
                if (nameParts.length >= 2) {
                    // Get first and last name
                    const firstName = nameParts[0];
                    const lastName = nameParts[nameParts.length - 1];
                    
                    // Remove special characters and convert to lowercase
                    const firstNameClean = firstName.replace(/[^a-zA-Z]/g, '').toLowerCase();
                    const lastNameClean = lastName.replace(/[^a-zA-Z]/g, '').toLowerCase();
                    
                    // Generate email
                    const email = firstNameClean + lastNameClean + '@plmun.edu.ph';
                    
                    // Set the email value in the form
                    document.getElementById('email').value = email;
                }
            }
        }

        // Generate student email preview
        function updateStudentEmailPreview() {
            const fullName = document.getElementById('student_full_name').value.trim();
            const course = document.getElementById('student_course').value;
            const emailPreview = document.getElementById('studentEmailPreview');
            const previewText = document.getElementById('studentPreviewText');
            
            if (fullName && course) {
                // Generate email preview in format: firstnamelastname_bsit@plmun.edu.ph
                const cleanName = fullName.toLowerCase().replace(/[^a-zA-Z\s]/g, '').replace(/\s+/g, '');
                const courseAbbr = getCourseAbbreviation(course).toLowerCase();
                const generatedEmail = cleanName + '_' + courseAbbr + '@plmun.edu.ph';
                
                previewText.textContent = generatedEmail;
                emailPreview.style.display = 'block';
                
                // Auto-fill email field if empty
                if (!document.getElementById('student_email').value) {
                    document.getElementById('student_email').value = generatedEmail;
                }
            } else {
                emailPreview.style.display = 'none';
            }
        }

        // Get course abbreviation (JavaScript version)
        function getCourseAbbreviation(course) {
            const abbreviations = {
                'Bachelor of Science in Business Administration': 'BSBA',
                'Bachelor of Science in Accountancy': 'BSA',
                'Bachelor of Science in Management Accounting': 'BSMA',
                'Bachelor of Arts in Communication': 'BAC',
                'Bachelor of Science in Psychology': 'BSP',
                'Bachelor of Science in Criminology': 'BSC',
                'Bachelor of Science in Industrial Security Management': 'BSISM',
                'Bachelor of Science in Computer Science': 'BSCS',
                'Bachelor of Science in Information Technology': 'BSIT',
                'Associate in Computer Technology': 'ACT',
                'Bachelor of Public Administration': 'BPA',
                'Bachelor of Arts in Political Science': 'BAPS',
                'Bachelor of Science in Social Work': 'BSSW',
                'Doctor of Medicine': 'MD',
                'Bachelor of Elementary Education (BEEd)': 'BEED',
                'Bachelor of Secondary Education (BSEd)': 'BSED'
            };
            
            return abbreviations[course] || 'GEN';
        }

        // Reset forms
        function resetInstructorForm() {
            document.getElementById('full_name').value = '';
            document.getElementById('email').value = '';
            document.getElementById('personal_email').value = '';
            document.getElementById('contact_number').value = '';
            document.getElementById('role').selectedIndex = 0;
            document.getElementById('department').selectedIndex = 0;
            
            // Generate new password
            const newPassword = generateRandomPassword();
            document.getElementById('instructor_password').value = newPassword;
            
            // Update password preview
            const passwordPreview = document.querySelector('.password-preview');
            const passwordText = passwordPreview.querySelector('span strong');
            passwordText.textContent = newPassword;
            
            // Reload page to get new ID
            location.reload();
        }

        function resetStudentForm() {
            document.getElementById('student_full_name').value = '';
            document.getElementById('student_course').selectedIndex = 0;
            document.getElementById('year_level').selectedIndex = 0;
            document.getElementById('student_email').value = '';
            document.getElementById('student_personal_email').value = '';
            document.getElementById('student_contact_number').value = '';
            
            // Generate new password
            const newPassword = generateStudentPassword();
            document.getElementById('student_password').value = newPassword;
            
            // Update password preview
            const passwordPreview = document.getElementById('studentPasswordPreview');
            const passwordText = passwordPreview.querySelector('span strong');
            passwordText.textContent = newPassword;
            
            // Hide email preview
            document.getElementById('studentEmailPreview').style.display = 'none';
            
            // Reload the page to get a new student number
            location.reload();
        }

        // Instructor search functionality
        function searchInstructors() {
            const searchTerm = document.getElementById('instructorSearch').value.toLowerCase();
            const userItems = document.querySelectorAll('#instructors-tab .user-item');
            
            userItems.forEach(item => {
                const userName = item.querySelector('.user-name-compact').textContent.toLowerCase();
                const userMeta = item.querySelector('.user-meta').textContent.toLowerCase();
                
                if (userName.includes(searchTerm) || userMeta.includes(searchTerm)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        // Edit instructor function
        function editUser(userId, fullName, username, email, personalEmail, contactNumber, role, department, isActive) {
            // Populate the form with user data
            document.getElementById('edit_user_id').value = userId;
            document.getElementById('edit_full_name').value = fullName;
            document.getElementById('edit_username').value = username;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_personal_email').value = personalEmail || '';
            document.getElementById('edit_contact_number').value = contactNumber || '';
            document.getElementById('edit_role').value = role;
            
            if (department) {
                document.getElementById('edit_department').value = department;
            } else {
                document.getElementById('edit_department').value = '';
            }
            
            document.getElementById('edit_is_active').checked = isActive;
            
            // Show the modal
            document.getElementById('editUserModal').style.display = 'flex';
        }

        // UPDATED: Edit student function with is_active parameter
        function editStudent(id, name, course, yearLevel, email, personalEmail, contactNumber, isActive) {
            document.getElementById('update_student_id').value = id;
            document.getElementById('update_full_name').value = name;
            document.getElementById('update_course').value = course;
            document.getElementById('update_year_level').value = yearLevel;
            document.getElementById('update_email').value = email || '';
            document.getElementById('update_personal_email').value = personalEmail || '';
            document.getElementById('update_contact_number').value = contactNumber || '';
            document.getElementById('update_is_active').checked = isActive;
            
            document.getElementById('editStudentModal').style.display = 'flex';
        }

        // NEW: Reset student password function
        function resetStudentPassword(studentId, studentName) {
            // Populate the form with student data
            document.getElementById('reset_student_id').value = studentId;
            document.getElementById('reset_student_name').value = studentName;
            
            // Clear password fields
            document.getElementById('reset_new_password').value = '';
            document.getElementById('confirm_new_password').value = '';
            
            // Enable submit button
            document.getElementById('submitResetBtn').disabled = false;
            
            // Show the modal
            document.getElementById('resetStudentPasswordModal').style.display = 'flex';
        }

        // NEW: Generate random password for student reset
        function generateRandomStudentPassword() {
            const newPassword = generateStudentPassword();
            document.getElementById('reset_new_password').value = newPassword;
            document.getElementById('confirm_new_password').value = newPassword;
            
            // Update password match message
            document.getElementById('passwordMatchMessage').textContent = '✓ Passwords match';
            document.getElementById('passwordMatchMessage').style.color = '#10b981';
            
            // Enable submit button
            document.getElementById('submitResetBtn').disabled = false;
            
            // Show success feedback
            showToast('Random password generated!');
        }

        // Close modals
        function closeEditModal() {
            document.getElementById('editUserModal').style.display = 'none';
        }

        function closeEditStudentModal() {
            document.getElementById('editStudentModal').style.display = 'none';
        }

        // NEW: Close student password reset modal
        function closeResetStudentPasswordModal() {
            document.getElementById('resetStudentPasswordModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('editUserModal');
            const studentModal = document.getElementById('editStudentModal');
            const resetPasswordModal = document.getElementById('resetStudentPasswordModal');
            const confirmationModal = document.getElementById('bulkDeleteConfirmation');
            
            if (event.target === modal) {
                closeEditModal();
            }
            if (event.target === studentModal) {
                closeEditStudentModal();
            }
            if (event.target === resetPasswordModal) {
                closeResetStudentPasswordModal();
            }
            if (event.target === confirmationModal) {
                hideBulkDeleteConfirmation();
            }
        }

        // Password validation for student reset form
        document.getElementById('reset_new_password').addEventListener('input', validatePasswordMatch);
        document.getElementById('confirm_new_password').addEventListener('input', validatePasswordMatch);

        function validatePasswordMatch() {
            const password = document.getElementById('reset_new_password').value;
            const confirmPassword = document.getElementById('confirm_new_password').value;
            const message = document.getElementById('passwordMatchMessage');
            const submitBtn = document.getElementById('submitResetBtn');
            
            if (password === '' || confirmPassword === '') {
                message.textContent = '';
                submitBtn.disabled = true;
                return;
            }
            
            if (password.length < 6) {
                message.textContent = '✗ Password must be at least 6 characters';
                message.style.color = '#ef4444';
                submitBtn.disabled = true;
                return;
            }
            
            if (password === confirmPassword) {
                message.textContent = '✓ Passwords match';
                message.style.color = '#10b981';
                submitBtn.disabled = false;
            } else {
                message.textContent = '✗ Passwords do not match';
                message.style.color = '#ef4444';
                submitBtn.disabled = true;
            }
        }

        // Reset password function for instructors
        function resetPassword(userId) {
            const newPassword = prompt('Enter new password for user ID: ' + userId);
            if (newPassword && newPassword.length >= 6) {
                // Create a form and submit it
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const userIdInput = document.createElement('input');
                userIdInput.type = 'hidden';
                userIdInput.name = 'user_id';
                userIdInput.value = userId;
                
                const passwordInput = document.createElement('input');
                passwordInput.type = 'hidden';
                passwordInput.name = 'new_password';
                passwordInput.value = newPassword;
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'reset_password';
                actionInput.value = '1';
                
                form.appendChild(userIdInput);
                form.appendChild(passwordInput);
                form.appendChild(actionInput);
                document.body.appendChild(form);
                form.submit();
            } else if (newPassword) {
                alert('Password must be at least 6 characters long.');
            }
        }

        // Bulk selection functions for students
        function updateSelectedCount() {
            const selectedCheckboxes = document.querySelectorAll('.student-checkbox:checked');
            const selectedCount = selectedCheckboxes.length;
            document.getElementById('selectedCount').textContent = selectedCount + ' student(s) selected';
            
            // Update select all checkbox state
            const totalCheckboxes = document.querySelectorAll('.student-checkbox').length;
            const selectAllCheckbox = document.getElementById('selectAll');
            selectAllCheckbox.checked = selectedCount === totalCheckboxes && totalCheckboxes > 0;
            selectAllCheckbox.indeterminate = selectedCount > 0 && selectedCount < totalCheckboxes;
            
            // Update visual state for selected items
            selectedCheckboxes.forEach(checkbox => {
                const studentItem = checkbox.closest('.student-item');
                if (checkbox.checked) {
                    studentItem.classList.add('selected');
                } else {
                    studentItem.classList.remove('selected');
                }
            });
        }
        
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll').checked;
            const checkboxes = document.querySelectorAll('.student-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll;
                const studentItem = checkbox.closest('.student-item');
                if (selectAll) {
                    studentItem.classList.add('selected');
                } else {
                    studentItem.classList.remove('selected');
                }
            });
            
            updateSelectedCount();
        }
        
        function selectAllStudents() {
            document.getElementById('selectAll').checked = true;
            toggleSelectAll();
        }
        
        function deselectAllStudents() {
            document.getElementById('selectAll').checked = false;
            toggleSelectAll();
        }
        
        function showBulkDeleteConfirmation() {
            const selectedCount = document.querySelectorAll('.student-checkbox:checked').length;
            
            if (selectedCount === 0) {
                alert('Please select at least one student to delete.');
                return;
            }
            
            // Update confirmation modal with selected count
            document.getElementById('confirmationCount').textContent = selectedCount;
            
            // Show confirmation modal
            document.getElementById('bulkDeleteConfirmation').style.display = 'flex';
        }
        
        function hideBulkDeleteConfirmation() {
            document.getElementById('bulkDeleteConfirmation').style.display = 'none';
        }
        
        function submitBulkDelete() {
            document.getElementById('bulkDeleteForm').submit();
        }

        // Handle "Other" course selection
        document.getElementById('student_course').addEventListener('change', function() {
            if (this.value === 'Other') {
                const otherCourse = prompt('Please enter the course name:');
                if (otherCourse) {
                    // Create a new option and select it
                    const newOption = new Option(otherCourse, otherCourse);
                    this.add(newOption);
                    this.value = otherCourse;
                    updateStudentEmailPreview();
                } else {
                    this.selectedIndex = 0;
                }
            } else {
                updateStudentEmailPreview();
            }
        });

        // Handle "Other" course selection in edit modal
        document.getElementById('update_course').addEventListener('change', function() {
            if (this.value === 'Other') {
                const otherCourse = prompt('Please enter the course name:');
                if (otherCourse) {
                    // Create a new option and select it
                    const newOption = new Option(otherCourse, otherCourse);
                    this.add(newOption);
                    this.value = otherCourse;
                } else {
                    this.selectedIndex = 0;
                }
            }
        });

        // Show toast notification
        function showToast(message) {
            const toast = document.getElementById('toast');
            toast.innerHTML = `<i class="fas fa-check-circle"></i> ${message}`;
            toast.classList.add('show');
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // Add interactivity to stat cards
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                card.addEventListener('click', function() {
                    this.classList.toggle('pulse');
                });
            });
            
            // Initialize selected count on page load
            updateSelectedCount();
            
            // Auto-hide alerts after 5 seconds
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    alert.style.display = 'none';
                });
            }, 5000);
        });
    </script>
</body>
</html>