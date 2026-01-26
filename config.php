<?php
// ==================== SESSION CONFIGURATION ====================
// MUST be set BEFORE any output or session_start()

// Set timezone first
date_default_timezone_set('Asia/Manila');

// Error reporting - set to off in production
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'error_log.txt');

// Session configuration - MUST be set BEFORE session_start()
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
ini_set('session.cookie_samesite', 'Strict'); // Add for CSRF protection

// Now start the session with the configured settings
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==================== DATABASE CONFIGURATION ====================
$host = 'localhost';
$dbname = 'plmun_emotion_ai';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    
    // Test the connection
    $pdo->query("SELECT 1");
    
} catch(PDOException $e) {
    // Log error and show user-friendly message
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please check your database settings and try again.");
}

// ==================== DATABASE TABLE CONSTANTS ====================
define('TABLE_USERS', 'users');
define('TABLE_STUDENTS', 'students');
define('TABLE_CLASSES', 'classes');
define('TABLE_CLASS_ENROLLMENTS', 'class_enrollments');
define('TABLE_LIVE_SESSIONS', 'live_sessions');
define('TABLE_LIVE_SESSION_PARTICIPANTS', 'live_session_participants');
define('TABLE_SESSION_ATTENDANCE', 'session_attendance');
define('TABLE_EMOTION_DATA', 'emotion_data');
define('TABLE_SESSION_ENGAGEMENT_SUMMARY', 'session_engagement_summary');
define('TABLE_CHAT_MESSAGES', 'chat_messages');
define('TABLE_ANNOUNCEMENTS', 'announcements');
define('TABLE_USER_CONSENT', 'user_consent');

// ==================== APPLICATION CONSTANTS ====================
$site_name = "PLMUN Emotion AI";
$site_url = "http://localhost/Emotion_Recognition_System";
$default_timezone = 'Asia/Manila';

// ==================== HELPER FUNCTIONS ====================

// Helper function to get initials from full name
if (!function_exists('getInitials')) {
    function getInitials($name) {
        if (empty($name)) return '??';
        $words = explode(' ', $name);
        $initials = '';
        foreach ($words as $word) {
            if (!empty($word)) {
                $initials .= strtoupper(substr($word, 0, 1));
            }
        }
        return substr($initials, 0, 2);
    }
}

// Function to get user role from session
if (!function_exists('getUserRole')) {
    function getUserRole() {
        return $_SESSION['role'] ?? null;
    }
}

// Function to check if user is admin
if (!function_exists('isAdmin')) {
    function isAdmin() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }
}

// Function to check if user is instructor
if (!function_exists('isInstructor')) {
    function isInstructor() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'instructor';
    }
}

// Function to check if user is student
if (!function_exists('isStudent')) {
    function isStudent() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'student';
    }
}

// Function to get the database user_id (from users table)
if (!function_exists('getDbUserId')) {
    function getDbUserId() {
        global $pdo;
        
        if (isset($_SESSION['user_id'])) {
            return $_SESSION['user_id'];
        } elseif (isset($_SESSION['student_user_id'])) {
            return $_SESSION['student_user_id'];
        }
        
        return null;
    }
}

// Function to get student_id (from students table)
if (!function_exists('getStudentId')) {
    function getStudentId() {
        global $pdo;
        
        if (isStudent() && isset($_SESSION['user_id'])) {
            // Get student_id from students table using user_id
            $stmt = $pdo->prepare("SELECT id FROM " . TABLE_STUDENTS . " WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $student = $stmt->fetch();
            return $student['id'] ?? null;
        }
        
        return null;
    }
}

// Function to check if user is logged in
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['role']) && isset($_SESSION['full_name']);
    }
}

// Function to get complete user data
if (!function_exists('getUserData')) {
    function getUserData() {
        global $pdo;
        
        if (!isLoggedIn()) return null;
        
        $userId = $_SESSION['user_id'];
        $role = $_SESSION['role'];
        
        // Get base user info from users table
        $stmt = $pdo->prepare("SELECT id, username, full_name, email, personal_email, contact_number, department, role, is_active FROM " . TABLE_USERS . " WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) return null;
        
        $userData = $user;
        
        // If student, get additional student data
        if ($role === 'student') {
            $stmt = $pdo->prepare("SELECT id as student_id, student_number, course, year_level FROM " . TABLE_STUDENTS . " WHERE user_id = ?");
            $stmt->execute([$userId]);
            $studentData = $stmt->fetch();
            
            if ($studentData) {
                $userData = array_merge($userData, $studentData);
            }
        }
        
        return $userData;
    }
}

// Function to redirect if not logged in
if (!function_exists('requireLogin')) {
    function requireLogin() {
        if (!isLoggedIn()) {
            $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'] ?? '/';
            header('Location: login.php');
            exit();
        }
    }
}

// Function to redirect based on role
if (!function_exists('requireRole')) {
    function requireRole($allowedRoles) {
        requireLogin();
        
        if (!in_array($_SESSION['role'], (array)$allowedRoles)) {
            $_SESSION['error'] = "You don't have permission to access this page.";
            
            // Redirect based on role
            switch ($_SESSION['role']) {
                case 'admin':
                    header('Location: admin_dashboard.php');
                    break;
                case 'instructor':
                    header('Location: instructor_dashboard.php');
                    break;
                case 'student':
                    header('Location: student_dashboard.php');
                    break;
                default:
                    header('Location: login.php');
            }
            exit();
        }
    }
}

// Function to require admin access
if (!function_exists('requireAdmin')) {
    function requireAdmin() {
        requireRole('admin');
    }
}

// Function to require instructor access
if (!function_exists('requireInstructor')) {
    function requireInstructor() {
        requireRole(['instructor', 'admin']);
    }
}

// Function to require student access
if (!function_exists('requireStudent')) {
    function requireStudent() {
        requireRole('student');
    }
}

// Function to sanitize input
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data) {
        if (is_array($data)) {
            return array_map('sanitizeInput', $data);
        }
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        return $data;
    }
}

// Function to format date for display
if (!function_exists('formatDate')) {
    function formatDate($dateString, $format = 'F j, Y H:i') {
        if (empty($dateString) || $dateString == '0000-00-00 00:00:00') return '';
        try {
            $date = new DateTime($dateString);
            return $date->format($format);
        } catch (Exception $e) {
            return '';
        }
    }
}

// Function to get relative time
if (!function_exists('relativeTime')) {
    function relativeTime($dateString) {
        if (empty($dateString) || $dateString == '0000-00-00 00:00:00') return '';
        
        try {
            $date = new DateTime($dateString);
            $now = new DateTime();
            
            if ($date > $now) {
                return 'In the future';
            }
            
            $diff = $now->diff($date);
            
            if ($diff->y > 0) {
                return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
            } elseif ($diff->m > 0) {
                return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
            } elseif ($diff->d > 0) {
                return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
            } elseif ($diff->h > 0) {
                return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
            } elseif ($diff->i > 0) {
                return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
            } else {
                return 'Just now';
            }
        } catch (Exception $e) {
            return '';
        }
    }
}

// Function to validate email
if (!function_exists('validateEmail')) {
    function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}

// Function to generate random token
if (!function_exists('generateToken')) {
    function generateToken($length = 32) {
        try {
            return bin2hex(random_bytes($length));
        } catch (Exception $e) {
            // Fallback for systems without random_bytes
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $charactersLength = strlen($characters);
            $randomString = '';
            for ($i = 0; $i < $length * 2; $i++) {
                $randomString .= $characters[rand(0, $charactersLength - 1)];
            }
            return $randomString;
        }
    }
}

// Function to hash password
if (!function_exists('hashPassword')) {
    function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

// Function to verify password
if (!function_exists('verifyPassword')) {
    function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
}

// Function to check if user has given consent
if (!function_exists('hasConsent')) {
    function hasConsent($userId, $consentType) {
        global $pdo;
        
        $stmt = $pdo->prepare("SELECT consent_given FROM " . TABLE_USER_CONSENT . " WHERE user_id = ? AND consent_type = ? ORDER BY consent_date DESC LIMIT 1");
        $stmt->execute([$userId, $consentType]);
        $consent = $stmt->fetch();
        
        return $consent && $consent['consent_given'] == 1;
    }
}

// Function to check user consent status
if (!function_exists('checkConsentStatus')) {
    function checkConsentStatus($userId) {
        global $pdo;
        
        $requiredConsents = ['camera', 'microphone', 'emotion_detection'];
        $consentStatus = [
            'needs_attention' => false,
            'consents' => []
        ];
        
        foreach ($requiredConsents as $consentType) {
            $stmt = $pdo->prepare("
                SELECT consent_given 
                FROM " . TABLE_USER_CONSENT . " 
                WHERE user_id = ? AND consent_type = ? 
                ORDER BY consent_date DESC 
                LIMIT 1
            ");
            $stmt->execute([$userId, $consentType]);
            $consent = $stmt->fetch();
            
            $hasConsent = $consent && $consent['consent_given'] == 1;
            $consentStatus['consents'][$consentType] = $hasConsent;
            
            if (!$hasConsent) {
                $consentStatus['needs_attention'] = true;
            }
        }
        
        return $consentStatus;
    }
}

// Function to get user's classes (for students)
if (!function_exists('getUserClasses')) {
    function getUserClasses($userId, $role) {
        global $pdo;
        
        if ($role === 'student') {
            // Get student_id first
            $stmt = $pdo->prepare("SELECT id FROM " . TABLE_STUDENTS . " WHERE user_id = ?");
            $stmt->execute([$userId]);
            $student = $stmt->fetch();
            
            if (!$student) return [];
            
            $stmt = $pdo->prepare("
                SELECT c.*, u.full_name as instructor_name 
                FROM " . TABLE_CLASSES . " c
                JOIN " . TABLE_CLASS_ENROLLMENTS . " ce ON c.id = ce.class_id
                JOIN " . TABLE_USERS . " u ON c.instructor_id = u.id
                WHERE ce.student_id = ? AND c.is_active = 1
                ORDER BY c.class_name
            ");
            $stmt->execute([$student['id']]);
            return $stmt->fetchAll();
        } elseif ($role === 'instructor' || $role === 'admin') {
            $stmt = $pdo->prepare("
                SELECT c.*, u.full_name as instructor_name,
                (SELECT COUNT(*) FROM " . TABLE_CLASS_ENROLLMENTS . " WHERE class_id = c.id) as student_count
                FROM " . TABLE_CLASSES . " c
                JOIN " . TABLE_USERS . " u ON c.instructor_id = u.id
                WHERE c.instructor_id = ? AND c.is_active = 1
                ORDER BY c.class_name
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        }
        
        return [];
    }
}

// ==================== SESSION MANAGEMENT ====================

// Regenerate session ID periodically for security
if (!isset($_SESSION['last_regeneration'])) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
} elseif (time() - $_SESSION['last_regeneration'] > 1800) { // 30 minutes
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

// Set default session variables if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateToken();
}

// Initialize user data in session if not present (simplified approach)
if (isLoggedIn() && !isset($_SESSION['user_data'])) {
    $userData = getUserData();
    if ($userData) {
        $_SESSION['user_data'] = $userData;
    }
}

// Function to get CSRF token
if (!function_exists('getCSRFToken')) {
    function getCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = generateToken();
        }
        return $_SESSION['csrf_token'];
    }
}

// Function to validate CSRF token
if (!function_exists('validateCSRFToken')) {
    function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

// Function to generate and store CSRF token in form
if (!function_exists('csrfField')) {
    function csrfField() {
        return '<input type="hidden" name="csrf_token" value="' . getCSRFToken() . '">';
    }
}

// ==================== DEBUG AND LOGGING ====================

// Debug function (for development only)
if (!function_exists('debug')) {
    function debug($data, $exit = true) {
        echo '<pre style="background: #f4f4f4; padding: 10px; border: 1px solid #ddd; margin: 10px;">';
        var_dump($data);
        echo '</pre>';
        if ($exit) exit;
    }
}

// Log function
if (!function_exists('logMessage')) {
    function logMessage($message, $level = 'INFO') {
        $log = '[' . date('Y-m-d H:i:s') . '] ' . $level . ': ' . $message . PHP_EOL;
        file_put_contents('app.log', $log, FILE_APPEND);
    }
}

// ==================== ERROR HANDLING ====================

// Custom error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Don't throw error if error reporting is turned off
    if (!(error_reporting() & $errno)) {
        return;
    }
    
    $error_types = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING',
        E_PARSE => 'PARSE',
        E_NOTICE => 'NOTICE',
        E_CORE_ERROR => 'CORE_ERROR',
        E_CORE_WARNING => 'CORE_WARNING',
        E_COMPILE_ERROR => 'COMPILE_ERROR',
        E_COMPILE_WARNING => 'COMPILE_WARNING',
        E_USER_ERROR => 'USER_ERROR',
        E_USER_WARNING => 'USER_WARNING',
        E_USER_NOTICE => 'USER_NOTICE',
        E_STRICT => 'STRICT',
        E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
        E_DEPRECATED => 'DEPRECATED',
        E_USER_DEPRECATED => 'USER_DEPRECATED'
    ];
    
    $error_type = isset($error_types[$errno]) ? $error_types[$errno] : 'UNKNOWN_ERROR';
    
    $log_message = sprintf(
        "[%s] %s: %s in %s on line %d",
        date('Y-m-d H:i:s'),
        $error_type,
        $errstr,
        $errfile,
        $errline
    );
    
    error_log($log_message);
    
    // Only show errors in development
    if (ini_get('display_errors')) {
        echo '<div style="background: #ffebee; color: #c62828; padding: 10px; margin: 10px; border: 1px solid #ef9a9a; border-radius: 4px;">';
        echo '<strong>' . $error_type . ':</strong> ' . $errstr . ' in <strong>' . basename($errfile) . '</strong> on line <strong>' . $errline . '</strong>';
        echo '</div>';
    }
    
    return true;
});

// ==================== SECURITY HEADERS ====================

// Set security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ==================== FLASH MESSAGES ====================

// Function to set flash message
if (!function_exists('setFlash')) {
    function setFlash($type, $message) {
        $_SESSION['flash'] = [
            'type' => $type,
            'message' => $message
        ];
    }
}

// Function to get and clear flash message
if (!function_exists('getFlash')) {
    function getFlash() {
        if (isset($_SESSION['flash'])) {
            $flash = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $flash;
        }
        return null;
    }
}

// Function to display flash message
if (!function_exists('displayFlash')) {
    function displayFlash() {
        $flash = getFlash();
        if ($flash) {
            $type = $flash['type'];
            $message = $flash['message'];
            
            $bg_color = '';
            $text_color = '';
            $border_color = '';
            
            switch ($type) {
                case 'success':
                    $bg_color = 'bg-green-50';
                    $text_color = 'text-green-800';
                    $border_color = 'border-green-200';
                    break;
                case 'error':
                    $bg_color = 'bg-red-50';
                    $text_color = 'text-red-800';
                    $border_color = 'border-red-200';
                    break;
                case 'warning':
                    $bg_color = 'bg-yellow-50';
                    $text_color = 'text-yellow-800';
                    $border_color = 'border-yellow-200';
                    break;
                case 'info':
                default:
                    $bg_color = 'bg-blue-50';
                    $text_color = 'text-blue-800';
                    $border_color = 'border-blue-200';
                    break;
            }
            
            echo '<div class="' . $bg_color . ' ' . $border_color . ' border rounded-md p-4 mb-4">';
            echo '<div class="flex">';
            echo '<div class="flex-shrink-0">';
            echo '<svg class="h-5 w-5 ' . $text_color . '" viewBox="0 0 20 20" fill="currentColor">';
            echo '<path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />';
            echo '</svg>';
            echo '</div>';
            echo '<div class="ml-3">';
            echo '<p class="text-sm font-medium ' . $text_color . '">' . htmlspecialchars($message) . '</p>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
    }
}

// ==================== INPUT VALIDATION ====================

// Function to validate required fields
if (!function_exists('validateRequired')) {
    function validateRequired($fields, $data) {
        $errors = [];
        foreach ($fields as $field) {
            if (empty($data[$field])) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        return $errors;
    }
}

// Function to validate email format
if (!function_exists('validateEmailFormat')) {
    function validateEmailFormat($email) {
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Invalid email format';
        }
        return null;
    }
}

// Function to validate password strength
if (!function_exists('validatePassword')) {
    function validatePassword($password) {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters long';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return 'Password must contain at least one uppercase letter';
        }
        if (!preg_match('/[a-z]/', $password)) {
            return 'Password must contain at least one lowercase letter';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return 'Password must contain at least one number';
        }
        return null;
    }
}

// Function to log audit trail
function logAuditTrail($user_id, $user_role, $username, $action_type, $action_description, $table_affected = null, $record_id = null, $additional_data = null) {
    global $pdo;
    
    try {
        $sql = "INSERT INTO audit_logs (
                    user_id, 
                    user_role, 
                    username, 
                    action_type, 
                    action_description, 
                    table_affected, 
                    record_id, 
                    ip_address, 
                    user_agent, 
                    additional_data
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $additional_data_json = null;
        if ($additional_data) {
            $additional_data_json = json_encode($additional_data, JSON_UNESCAPED_UNICODE);
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $user_id,
            $user_role,
            $username,
            $action_type,
            $action_description,
            $table_affected,
            $record_id,
            $ip_address,
            $user_agent,
            $additional_data_json
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Audit Trail Logging Error: " . $e->getMessage());
        return false;
    }
}

// Function to log user login
function logUserLogin($user_id, $username, $user_role, $additional_data = []) {
    $description = "User logged in successfully";
    return logAuditTrail($user_id, $user_role, $username, 'login', $description, null, null, $additional_data);
}

// Function to log user logout
function logUserLogout($user_id, $username, $user_role) {
    $description = "User logged out";
    return logAuditTrail($user_id, $user_role, $username, 'logout', $description);
}

// Function to log user creation
function logUserCreate($user_id, $username, $user_role, $created_user_id, $created_username, $additional_data = []) {
    $description = "Created new user: {$created_username}";
    $additional_data['created_user_id'] = $created_user_id;
    $additional_data['created_username'] = $created_username;
    return logAuditTrail($user_id, $user_role, $username, 'create', $description, 'users', $created_user_id, $additional_data);
}

// Function to log user update
function logUserUpdate($user_id, $username, $user_role, $updated_user_id, $updated_username, $changes = []) {
    $description = "Updated user: {$updated_username}";
    $additional_data = [
        'updated_user_id' => $updated_user_id,
        'updated_username' => $updated_username,
        'changes' => $changes
    ];
    return logAuditTrail($user_id, $user_role, $username, 'update', $description, 'users', $updated_user_id, $additional_data);
}

// Function to log user deletion
function logUserDelete($user_id, $username, $user_role, $deleted_user_id, $deleted_username) {
    $description = "Deleted user: {$deleted_username}";
    $additional_data = [
        'deleted_user_id' => $deleted_user_id,
        'deleted_username' => $deleted_username
    ];
    return logAuditTrail($user_id, $user_role, $username, 'delete', $description, 'users', $deleted_user_id, $additional_data);
}

// Function to log settings change
function logSettingsChange($user_id, $username, $user_role, $setting_name, $old_value, $new_value) {
    $description = "Changed setting: {$setting_name}";
    $additional_data = [
        'setting_name' => $setting_name,
        'old_value' => $old_value,
        'new_value' => $new_value
    ];
    return logAuditTrail($user_id, $user_role, $username, 'settings_change', $description, null, null, $additional_data);
}

// Function to log security event
function logSecurityEvent($user_id, $username, $user_role, $event_description, $additional_data = []) {
    return logAuditTrail($user_id, $user_role, $username, 'security_event', $event_description, null, null, $additional_data);
}

// Function to log failed login attempt
function logFailedLogin($username, $ip_address, $reason) {
    $description = "Failed login attempt for username: {$username} - {$reason}";
    $additional_data = [
        'attempted_username' => $username,
        'ip_address' => $ip_address,
        'reason' => $reason
    ];
    return logAuditTrail(null, 'system', 'system', 'security_event', $description, null, null, $additional_data);
}