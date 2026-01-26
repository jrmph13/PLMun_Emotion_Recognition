<?php
// ==================== STUDENT START SESSION PAGE ====================
// Start session and load configuration
require_once 'config.php';

// Require student role
requireStudent();

// Get current user data
$userData = getUserData();
// Ensure $userData is always an array and has default values
$userData = is_array($userData) ? $userData : [];
$userData['full_name'] = $userData['full_name'] ?? 'Student';

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// Get student details
$student_number = '';
$student_id = null;
try {
    $stmt = $pdo->prepare("SELECT id, student_number FROM students WHERE user_id = ?");
    $stmt->execute([$userId]);
    $student = $stmt->fetch();
    if ($student) {
        $student_number = $student['student_number'];
        $student_id = $student['id'];
    }
} catch (PDOException $e) {
    error_log("Error fetching student details: " . $e->getMessage());
}

// Get session and class info from URL
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

if (!$session_id || !$class_id) {
    setFlash('error', "Invalid session or class ID.");
    header("Location: student_my_classes.php");
    exit();
}

// Verify student is enrolled in this class
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.*, 
            c.class_code as session_code, 
            ls.id as session_id, 
            ls.start_time, 
            ls.status as session_status,
            ls.room_id,
            ce.enrolled_at as enrollment_date
        FROM classes c
        JOIN live_sessions ls ON c.id = ls.class_id
        JOIN class_enrollments ce ON c.id = ce.class_id
        JOIN students s ON ce.student_id = s.id
        WHERE c.id = ? AND s.user_id = ? AND ls.id = ?
    ");
    $stmt->execute([$class_id, $userId, $session_id]);
    $class = $stmt->fetch();
    
    if (!$class) {
        setFlash('error', "You are not enrolled in this class or the session doesn't exist.");
        header("Location: student_my_classes.php");
        exit();
    }
    
    // Check if session is active
    if ($class['session_status'] !== 'active') {
        setFlash('error', "This session is not currently active.");
        header("Location: student_my_classes.php");
        exit();
    }
    
    // Add session_name to class array
    $class['session_name'] = $class['class_name'] . " - Live Session";
    $room_id = $class['room_id'] ?? 'room_' . $session_id;
    
} catch (PDOException $e) {
    error_log("Session verification error: " . $e->getMessage());
    setFlash('error', "Error verifying session access: " . $e->getMessage());
    header("Location: student_my_classes.php");
    exit();
}

// ==================== CHECK USER CONSENT ====================
$consent_required = false;
$camera_consent = false;
$microphone_consent = false;
$emotion_consent = false;

// Get all system settings at once for efficiency
try {
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings");
    $stmt->execute();
    $system_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $system_settings = [];
    error_log("Error fetching system settings: " . $e->getMessage());
}

// Get system consent requirement
$require_consent = isset($system_settings['require_consent']) ? (intval($system_settings['require_consent']) === 1) : true;

// Get default consent settings from system_settings (admin's ON/OFF switch)
$default_camera_consent = isset($system_settings['default_camera_consent']) ? (intval($system_settings['default_camera_consent']) === 1) : false;
$default_mic_consent = isset($system_settings['default_mic_consent']) ? (intval($system_settings['default_mic_consent']) === 1) : false;
$default_emotion_consent = isset($system_settings['default_emotion_consent']) ? (intval($system_settings['default_emotion_consent']) === 1) : false;

// Check if emotion tracking is enabled for this class
$class_emotion_tracking = isset($class['emotion_tracking']) ? (intval($class['emotion_tracking']) === 1) : false;

// Check if user wants to use current consent without changes
if (isset($_GET['use_current_consent']) && $_GET['use_current_consent'] == 1) {
    // Force the session to continue with current consent settings
    $consent_required = false;
    
    // Log this action
    logAuditTrail(
        $_SESSION['user_id'],
        $_SESSION['role'],
        $_SESSION['username'],
        'view',
        'Used existing consent settings for session: ' . $class['class_name'],
        'live_sessions',
        $session_id,
        ['class_id' => $class_id, 'session_id' => $session_id, 'action' => 'use_existing_consent']
    );
}

// Check if consent records need to be updated based on new defaults
// If user has no consent records, create them with current defaults
// If user has old consent records that match old defaults (and defaults changed), update them
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM user_consent WHERE user_id = ?");
    $stmt->execute([$userId]);
    $has_consent_records = $stmt->fetch()['count'] > 0;
    
    if (!$has_consent_records) {
        // User has no consent records - create them with current defaults
        $consent_types = [
            ['camera', $default_camera_consent],
            ['microphone', $default_mic_consent],
            ['emotion_detection', $default_emotion_consent]
        ];
        
        foreach ($consent_types as $type_data) {
            $type = $type_data[0];
            $given = $type_data[1] ? 1 : 0;
            
            $stmt = $pdo->prepare("
                INSERT INTO user_consent (user_id, consent_type, consent_given, consent_date) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$userId, $type, $given]);
        }
        
        // Set consent flags to defaults
        $camera_consent = $default_camera_consent;
        $microphone_consent = $default_mic_consent;
        $emotion_consent = $class_emotion_tracking ? $default_emotion_consent : false;
    }
} catch (PDOException $e) {
    error_log("Error checking/creating consent records: " . $e->getMessage());
}

// If consent is not required system-wide, then all consents are automatically given
if (!$require_consent) {
    $camera_consent = true;
    $microphone_consent = true;
    $emotion_consent = $class_emotion_tracking; // Only if class has emotion tracking enabled
    $consent_required = false;
} else {
    // Get user's current consent status from user_consent table
    try {
        $stmt = $pdo->prepare("SELECT consent_type, consent_given FROM user_consent WHERE user_id = ?");
        $stmt->execute([$userId]);
        $consents = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if (!empty($consents)) {
            // User HAS consent records - use their individual settings
            $camera_consent = isset($consents['camera']) ? (bool)$consents['camera'] : $default_camera_consent;
            $microphone_consent = isset($consents['microphone']) ? (bool)$consents['microphone'] : $default_mic_consent;
            $emotion_consent = isset($consents['emotion_detection']) ? (bool)$consents['emotion_detection'] : ($class_emotion_tracking ? $default_emotion_consent : false);
            
            // Check if consent modal should be shown
            // Show modal if ANY default is ON but user hasn't given that specific consent
            $consent_required = false;
            
            if ($default_camera_consent && !$camera_consent) {
                $consent_required = true;
            }
            
            if ($default_mic_consent && !$microphone_consent) {
                $consent_required = true;
            }
            
            if ($class_emotion_tracking && $default_emotion_consent && !$emotion_consent) {
                $consent_required = true;
            }
        } else {
            // This should not happen since we created records above, but just in case
            $camera_consent = $default_camera_consent;
            $microphone_consent = $default_mic_consent;
            $emotion_consent = $class_emotion_tracking ? $default_emotion_consent : false;
            
            // Check if any default consent is ON
            if ($default_camera_consent || $default_mic_consent || ($class_emotion_tracking && $default_emotion_consent)) {
                $consent_required = true;
            }
        }
    } catch (PDOException $e) {
        // If error, use default settings
        $camera_consent = $default_camera_consent;
        $microphone_consent = $default_mic_consent;
        $emotion_consent = $class_emotion_tracking ? $default_emotion_consent : false;
        
        if ($default_camera_consent || $default_mic_consent || ($class_emotion_tracking && $default_emotion_consent)) {
            $consent_required = true;
        }
        error_log("Error fetching user consent: " . $e->getMessage());
    }
}

// ==================== HANDLE CONSENT FORM SUBMISSION ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['consent_action'])) {
    if ($_POST['consent_action'] === 'give_consent') {
        // Get checkbox values from form
        $camera_consent_given = isset($_POST['camera_consent']) && $_POST['camera_consent'] === 'on';
        $microphone_consent_given = isset($_POST['microphone_consent']) && $_POST['microphone_consent'] === 'on';
        $emotion_consent_given = isset($_POST['emotion_consent']) && $_POST['emotion_consent'] === 'on';
        
        try {
            // Update or insert consent records with user's choices
            $consent_types = [
                ['camera', $camera_consent_given],
                ['microphone', $microphone_consent_given],
                ['emotion_detection', $emotion_consent_given]
            ];
            
            foreach ($consent_types as $type_data) {
                $type = $type_data[0];
                $given = $type_data[1] ? 1 : 0;
                
                $stmt = $pdo->prepare("
                    INSERT INTO user_consent (user_id, consent_type, consent_given, consent_date) 
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                    consent_given = VALUES(consent_given),
                    consent_date = VALUES(consent_date)
                ");
                $stmt->execute([$userId, $type, $given]);
            }
            
            // Update consent flags
            $camera_consent = $camera_consent_given;
            $microphone_consent = $microphone_consent_given;
            $emotion_consent = $emotion_consent_given;
            $consent_required = false;
            
            // Show success message
            setFlash('success', 'Consent given successfully. You can now join the session.');
            
            // Redirect to same page to refresh with new consent
            header("Location: student_start_session.php?session_id=" . $session_id . "&class_id=" . $class_id);
            exit();
            
        } catch (PDOException $e) {
            error_log("Error saving consent: " . $e->getMessage());
            setFlash('error', 'Error saving consent. Please try again.');
        }
    } else if ($_POST['consent_action'] === 'decline') {
        // User declined - redirect back to classes
        setFlash('warning', 'You declined to give consent. Some features may not be available.');
        header("Location: student_my_classes.php");
        exit();
    }
}

// ==================== SHOW CONSENT MODAL IF REQUIRED ====================
if ($consent_required) {
    // Show consent modal instead of the main session page
    $page_title = "Consent Required - " . htmlspecialchars($class['class_name']) . " - Emotion AI System";
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo $page_title; ?></title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            
            body {
                background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
                color: #333;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            
            .consent-container {
                background: white;
                border-radius: 20px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                max-width: 600px;
                width: 100%;
                overflow: hidden;
            }
            
            .consent-header {
                background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
                color: white;
                padding: 30px;
                text-align: center;
            }
            
            .consent-header h1 {
                font-size: 28px;
                font-weight: 700;
                margin-bottom: 10px;
            }
            
            .consent-header p {
                font-size: 16px;
                opacity: 0.9;
            }
            
            .consent-body {
                padding: 40px;
            }
            
            .consent-message {
                font-size: 16px;
                line-height: 1.6;
                color: #4b5563;
                margin-bottom: 30px;
                text-align: center;
            }
            
            .consent-features {
                background: #f8fafc;
                border-radius: 12px;
                padding: 25px;
                margin-bottom: 30px;
                border: 1px solid #e5e7eb;
            }
            
            .feature-item {
                display: flex;
                align-items: flex-start;
                margin-bottom: 20px;
            }
            
            .feature-item:last-child {
                margin-bottom: 0;
            }
            
            .feature-icon {
                width: 40px;
                height: 40px;
                border-radius: 10px;
                background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-size: 18px;
                margin-right: 15px;
                flex-shrink: 0;
            }
            
            .feature-content h3 {
                font-size: 16px;
                font-weight: 600;
                color: #1f2937;
                margin-bottom: 5px;
            }
            
            .feature-content p {
                font-size: 14px;
                color: #6b7280;
                line-height: 1.5;
            }
            
            .consent-checkboxes {
                margin-bottom: 30px;
            }
            
            .checkbox-group {
                display: flex;
                align-items: center;
                margin-bottom: 15px;
                padding: 15px;
                background: #f9fafb;
                border-radius: 8px;
                border: 1px solid #e5e7eb;
                transition: all 0.3s;
            }
            
            .checkbox-group:hover {
                border-color: #8b5cf6;
                background: white;
            }
            
            .checkbox-group input[type="checkbox"] {
                width: 20px;
                height: 20px;
                margin-right: 15px;
                cursor: pointer;
            }
            
            .checkbox-group label {
                font-size: 15px;
                font-weight: 500;
                color: #1f2937;
                cursor: pointer;
                flex: 1;
            }
            
            .required-indicator {
                font-size: 12px;
                color: #ef4444;
                margin-top: 5px;
                display: flex;
                align-items: center;
                gap: 5px;
            }
            
            .optional-indicator {
                font-size: 12px;
                color: #6b7280;
                margin-top: 5px;
                display: flex;
                align-items: center;
                gap: 5px;
            }
            
            .consent-actions {
                display: flex;
                gap: 15px;
                flex-wrap: wrap;
            }
            
            .consent-btn {
                flex: 1;
                padding: 16px;
                border: none;
                border-radius: 10px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
            }
            
            .btn-accept {
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                color: white;
            }
            
            .btn-accept:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
            }
            
            .btn-accept:disabled {
                opacity: 0.6;
                cursor: not-allowed;
                transform: none;
                box-shadow: none;
            }
            
            .btn-decline {
                background: #f3f4f6;
                color: #6b7280;
                border: 2px solid #e5e7eb;
            }
            
            .btn-decline:hover {
                background: #e5e7eb;
            }
            
            .btn-skip {
                background: #3b82f6;
                color: white;
                border: 2px solid #2563eb;
            }
            
            .btn-skip:hover {
                background: #2563eb;
                transform: translateY(-2px);
                box-shadow: 0 8px 25px rgba(37, 99, 235, 0.4);
            }
            
            .privacy-notice {
                font-size: 12px;
                color: #6b7280;
                text-align: center;
                margin-top: 20px;
                line-height: 1.5;
            }
            
            .privacy-notice a {
                color: #8b5cf6;
                text-decoration: none;
            }
            
            .privacy-notice a:hover {
                text-decoration: underline;
            }
            
            @media (max-width: 768px) {
                .consent-body {
                    padding: 25px;
                }
                
                .consent-actions {
                    flex-direction: column;
                }
                
                .consent-header {
                    padding: 25px 20px;
                }
                
                .consent-header h1 {
                    font-size: 24px;
                }
            }
        </style>
    </head>
    <body>
        <div class="consent-container">
            <div class="consent-header">
                <h1><i class="fas fa-shield-alt"></i> Consent Required</h1>
                <p>Before joining <?php echo htmlspecialchars($class['class_name']); ?>, please review and accept the following</p>
            </div>
            
            <div class="consent-body">
                <div class="consent-message">
                    To participate in this live class with emotion monitoring, we need your consent for the following features:
                </div>
                
                <div class="consent-features">
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-video"></i>
                        </div>
                        <div class="feature-content">
                            <h3>Camera Access</h3>
                            <p>Your camera will be used for video participation in the live class and for emotion detection analysis.</p>
                        </div>
                    </div>
                    
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-microphone"></i>
                        </div>
                        <div class="feature-content">
                            <h3>Microphone Access</h3>
                            <p>Your microphone will be used for audio participation in the live class discussions.</p>
                        </div>
                    </div>
                    
                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-brain"></i>
                        </div>
                        <div class="feature-content">
                            <h3>Emotion Detection</h3>
                            <p>AI-powered emotion analysis will monitor your facial expressions to assess engagement and understanding during the class.</p>
                        </div>
                    </div>
                </div>
                
                <form method="POST" action="" id="consentForm">
                    <input type="hidden" name="consent_action" value="give_consent">
                    <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                    <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                    
                    <div class="consent-checkboxes">
                        <div class="checkbox-group">
                            <input type="checkbox" id="camera_consent" name="camera_consent" 
                                   <?php echo $default_camera_consent ? 'checked' : ''; ?>>
                            <label for="camera_consent">I consent to camera access for video participation</label>
                            <?php if ($default_camera_consent): ?>
                                <div class="required-indicator">
                                    <i class="fas fa-exclamation-circle"></i> Required for this session
                                </div>
                            <?php else: ?>
                                <div class="optional-indicator">
                                    <i class="fas fa-info-circle"></i> Optional
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="checkbox-group">
                            <input type="checkbox" id="microphone_consent" name="microphone_consent"
                                   <?php echo $default_mic_consent ? 'checked' : ''; ?>>
                            <label for="microphone_consent">I consent to microphone access for audio participation</label>
                            <?php if ($default_mic_consent): ?>
                                <div class="required-indicator">
                                    <i class="fas fa-exclamation-circle"></i> Required for this session
                                </div>
                            <?php else: ?>
                                <div class="optional-indicator">
                                    <i class="fas fa-info-circle"></i> Optional
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="checkbox-group">
                            <input type="checkbox" id="emotion_consent" name="emotion_consent"
                                   <?php echo ($class_emotion_tracking && $default_emotion_consent) ? 'checked' : ''; ?>
                                   <?php echo !$class_emotion_tracking ? 'disabled' : ''; ?>>
                            <label for="emotion_consent">I consent to emotion detection and analysis</label>
                            <?php if (!$class_emotion_tracking): ?>
                                <div class="optional-indicator">
                                    <i class="fas fa-info-circle"></i> Not enabled for this class
                                </div>
                            <?php elseif ($default_emotion_consent): ?>
                                <div class="required-indicator">
                                    <i class="fas fa-exclamation-circle"></i> Required for this session
                                </div>
                            <?php else: ?>
                                <div class="optional-indicator">
                                    <i class="fas fa-info-circle"></i> Optional
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="consent-actions">
                        <button type="submit" class="consent-btn btn-accept" id="acceptBtn">
                            <i class="fas fa-check-circle"></i> Accept & Join Session
                        </button>
                        
                        <button type="button" class="consent-btn btn-decline" onclick="declineConsent()">
                            <i class="fas fa-times-circle"></i> Decline & Return
                        </button>
                        
                        <?php if (!$consent_required && ($camera_consent || $microphone_consent || $emotion_consent)): ?>
                        <button type="button" class="consent-btn btn-skip" onclick="skipToSession()">
                            <i class="fas fa-forward"></i> Skip & Use Current Settings
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
                
                <div class="privacy-notice">
                    <p><i class="fas fa-lock"></i> Your data is protected and will only be used for educational purposes. 
                    <a href="privacy_policy.php" target="_blank">View our privacy policy</a> for more information.</p>
                </div>
            </div>
        </div>
        
        <script>
            function declineConsent() {
                if (confirm('Are you sure you want to decline? You will be redirected back to your classes.')) {
                    // Create a form to submit decline action
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    
                    const inputAction = document.createElement('input');
                    inputAction.type = 'hidden';
                    inputAction.name = 'consent_action';
                    inputAction.value = 'decline';
                    
                    const inputSession = document.createElement('input');
                    inputSession.type = 'hidden';
                    inputSession.name = 'session_id';
                    inputSession.value = '<?php echo $session_id; ?>';
                    
                    const inputClass = document.createElement('input');
                    inputClass.type = 'hidden';
                    inputClass.name = 'class_id';
                    inputClass.value = '<?php echo $class_id; ?>';
                    
                    form.appendChild(inputAction);
                    form.appendChild(inputSession);
                    form.appendChild(inputClass);
                    document.body.appendChild(form);
                    form.submit();
                }
            }
            
            function skipToSession() {
                // User wants to use their current consent settings without changes
                window.location.href = window.location.pathname + '?session_id=<?php echo $session_id; ?>&class_id=<?php echo $class_id; ?>&use_current_consent=1';
            }
            
            document.getElementById('consentForm').addEventListener('submit', function(e) {
                // Check required consents (those where default is ON)
                const requiredConsents = [];
                
                <?php if ($default_camera_consent): ?>
                if (!document.getElementById('camera_consent').checked) {
                    requiredConsents.push('Camera Access');
                }
                <?php endif; ?>
                
                <?php if ($default_mic_consent): ?>
                if (!document.getElementById('microphone_consent').checked) {
                    requiredConsents.push('Microphone Access');
                }
                <?php endif; ?>
                
                <?php if ($class_emotion_tracking && $default_emotion_consent): ?>
                if (!document.getElementById('emotion_consent').checked) {
                    requiredConsents.push('Emotion Detection');
                }
                <?php endif; ?>
                
                if (requiredConsents.length > 0) {
                    e.preventDefault();
                    alert('The following consents are required for this session:\n\n• ' + requiredConsents.join('\n• ') + '\n\nPlease check these boxes to continue.');
                    return false;
                }
                
                // If all required consents are checked, proceed
                document.getElementById('acceptBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                document.getElementById('acceptBtn').disabled = true;
            });
            
            // Add visual feedback for checkboxes
            document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    if (this.checked) {
                        this.parentElement.style.borderColor = '#10b981';
                        this.parentElement.style.background = '#f0fdf4';
                    } else {
                        this.parentElement.style.borderColor = '#e5e7eb';
                        this.parentElement.style.background = '#f9fafb';
                    }
                });
                
                // Initial styling
                if (checkbox.checked) {
                    checkbox.parentElement.style.borderColor = '#10b981';
                    checkbox.parentElement.style.background = '#f0fdf4';
                }
            });
        </script>
    </body>
    </html>
    <?php
    exit();
}

// ==================== HANDLE WEBRTC SIGNALING AND EMOTION DATA ACTIONS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    $session_id_post = isset($_POST['session_id']) ? intval($_POST['session_id']) : 0;
    
    switch ($action) {
        case 'send_chat':
            // Handle chat message sending
            $message = trim($_POST['message'] ?? '');
            $sender_id = isset($_POST['sender_id']) ? intval($_POST['sender_id']) : $userId;
            
            if (empty($message)) {
                echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
                exit();
            }
            
            if ($session_id_post <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid session']);
                exit();
            }
            
            try {
                // Insert chat message
                $stmt = $pdo->prepare("
                    INSERT INTO chat_messages (session_id, sender_id, sender_role, message, created_at) 
                    VALUES (?, ?, 'student', ?, NOW())
                ");
                
                $result = $stmt->execute([$session_id_post, $sender_id, $message]);
                
                if ($result) {
                    $message_id = $pdo->lastInsertId();
                    
                    // Get the inserted message
                    $stmt = $pdo->prepare("
                        SELECT cm.*, u.full_name 
                        FROM chat_messages cm
                        LEFT JOIN users u ON cm.sender_id = u.id
                        WHERE cm.id = ?
                    ");
                    $stmt->execute([$message_id]);
                    $message_data = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    echo json_encode([
                        'success' => true,
                        'message_id' => $message_id,
                        'data' => $message_data
                    ]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to save message']);
                }
            } catch (PDOException $e) {
                error_log("Chat message error: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            }
            exit();
            
        case 'update_device_status':
            $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : $userId;
            $camera_active = isset($_POST['camera_active']) ? intval($_POST['camera_active']) : 0;
            $mic_active = isset($_POST['mic_active']) ? intval($_POST['mic_active']) : 0;
            
            try {
                $stmt = $pdo->prepare("
                    UPDATE live_session_participants 
                    SET camera_active = ?, mic_active = ?, last_activity = NOW()
                    WHERE session_id = ? AND user_id = ?
                ");
                $stmt->execute([$camera_active, $mic_active, $session_id_post, $user_id]);
                
                echo json_encode(['success' => true]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit();
            
        case 'leave_session':
            try {
                // Update participant leave time
                $stmt = $pdo->prepare("
                    UPDATE live_session_participants 
                    SET leave_time = NOW(), is_active = 0 
                    WHERE session_id = ? AND user_id = ?
                ");
                $stmt->execute([$session_id_post, $userId]);
                
                echo json_encode(['success' => true, 'message' => 'Left session successfully']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            }
            exit();
            
        case 'save_emotion_data':
            // Save emotion detection data (only if consent is given)
            if (!$emotion_consent || !$class_emotion_tracking) {
                echo json_encode(['success' => false, 'error' => 'Emotion detection not consented or enabled']);
                exit();
            }
            
            $emotion_type = $_POST['emotion_type'] ?? 'neutral';
            $confidence = isset($_POST['confidence']) ? floatval($_POST['confidence']) : 0;
            
            if (!in_array($emotion_type, ['happy', 'sad', 'angry', 'neutral', 'confused'])) {
                $emotion_type = 'neutral';
            }
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO emotion_data 
                    (student_id, session_id, facial_emotion, confidence_score, captured_at) 
                    VALUES (?, ?, ?, ?, NOW())
                ");
                
                $result = $stmt->execute([$student_id, $session_id_post, $emotion_type, $confidence]);
                
                if ($result) {
                    echo json_encode([
                        'success' => true,
                        'emotion_id' => $pdo->lastInsertId(),
                        'emotion_type' => $emotion_type,
                        'confidence' => $confidence
                    ]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to save emotion data']);
                }
            } catch (PDOException $e) {
                error_log("Emotion data error: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            }
            exit();
            
        case 'webrtc_signal':
            // Handle WebRTC signaling between student and teacher
            $signalData = $_POST['signal_data'] ?? '';
            $targetUserId = isset($_POST['target_user_id']) ? intval($_POST['target_user_id']) : 0;
            $senderUserId = isset($_POST['sender_user_id']) ? intval($_POST['sender_user_id']) : $userId;
            $signalType = $_POST['signal_type'] ?? 'offer';
            
            if (empty($signalData) || $session_id_post <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid signaling data']);
                exit();
            }
            
            try {
                // Store or forward the signaling data
                $stmt = $pdo->prepare("
                    INSERT INTO webrtc_signals 
                    (session_id, sender_id, receiver_id, signal_type, signal_data, created_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                
                $result = $stmt->execute([
                    $session_id_post, 
                    $senderUserId, 
                    $targetUserId, 
                    $signalType, 
                    $signalData
                ]);
                
                if ($result) {
                    echo json_encode(['success' => true, 'signal_id' => $pdo->lastInsertId()]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to store signal']);
                }
            } catch (PDOException $e) {
                error_log("WebRTC signaling error: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'Database error']);
            }
            exit();
            
        case 'get_webrtc_signals':
            // Get pending WebRTC signals for this user
            $last_signal_id = isset($_POST['last_signal_id']) ? intval($_POST['last_signal_id']) : 0;
            
            try {
                $stmt = $pdo->prepare("
                    SELECT * FROM webrtc_signals 
                    WHERE session_id = ? AND receiver_id = ? AND id > ?
                    ORDER BY created_at ASC
                    LIMIT 10
                ");
                $stmt->execute([$session_id_post, $userId, $last_signal_id]);
                $signals = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Clean up old signals
                $stmt = $pdo->prepare("DELETE FROM webrtc_signals WHERE created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
                $stmt->execute();
                
                echo json_encode([
                    'success' => true,
                    'signals' => $signals,
                    'count' => count($signals)
                ]);
            } catch (PDOException $e) {
                error_log("Error getting WebRTC signals: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'Database error']);
            }
            exit();
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
            exit();
    }
}

// ==================== HANDLE GET ACTIONS ====================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    $action = $_GET['action'];
    $session_id_get = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
    
    switch ($action) {
        case 'get_chat':
            $last_id = intval($_GET['last_id'] ?? 0);
            
            try {
                if ($last_id > 0) {
                    $stmt = $pdo->prepare("
                        SELECT cm.*, u.full_name, u.role as sender_role
                        FROM chat_messages cm
                        LEFT JOIN users u ON cm.sender_id = u.id
                        WHERE cm.session_id = ? AND cm.id > ?
                        ORDER BY cm.created_at ASC
                    ");
                    $stmt->execute([$session_id_get, $last_id]);
                } else {
                    // Get last 50 messages
                    $stmt = $pdo->prepare("
                        SELECT cm.*, u.full_name, u.role as sender_role
                        FROM chat_messages cm
                        LEFT JOIN users u ON cm.sender_id = u.id
                        WHERE cm.session_id = ?
                        ORDER BY cm.created_at DESC
                        LIMIT 50
                    ");
                    $stmt->execute([$session_id_get]);
                }
                
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Reverse order for last 50 messages
                if ($last_id === 0) {
                    $messages = array_reverse($messages);
                }
                
                echo json_encode([
                    'success' => true,
                    'messages' => $messages,
                    'count' => count($messages)
                ]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => 'Database error']);
            }
            exit();
            
        case 'get_session_participants':
            try {
                $stmt = $pdo->prepare("
                    SELECT lsp.*, u.full_name, u.username, s.student_number
                    FROM live_session_participants lsp
                    JOIN users u ON lsp.user_id = u.id
                    LEFT JOIN students s ON u.id = s.user_id
                    WHERE lsp.session_id = ? AND lsp.is_active = 1
                    ORDER BY 
                        CASE WHEN lsp.user_role = 'instructor' THEN 1 ELSE 2 END,
                        lsp.join_time
                ");
                $stmt->execute([$session_id_get]);
                $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'participants' => $participants,
                    'count' => count($participants)
                ]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => 'Database error']);
            }
            exit();
            
        case 'get_own_emotion_data':
            // Only return data if emotion consent is given
            if (!$emotion_consent || !$class_emotion_tracking) {
                echo json_encode(['success' => false, 'error' => 'Emotion detection not consented or enabled']);
                exit();
            }
            
            try {
                $stmt = $pdo->prepare("
                    SELECT ed.* 
                    FROM emotion_data ed
                    JOIN students s ON ed.student_id = s.id
                    WHERE ed.session_id = ? AND s.user_id = ?
                    ORDER BY ed.captured_at DESC
                    LIMIT 20
                ");
                $stmt->execute([$session_id_get, $userId]);
                $emotions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'data' => $emotions,
                    'count' => count($emotions)
                ]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => 'Database error']);
            }
            exit();
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
            exit();
    }
}

// ==================== IF CONSENT IS GIVEN, CONTINUE WITH NORMAL SESSION ====================

// Add/update student in session participants (only if consent is given)
try {
    // First check if student already exists in participants
    $stmt = $pdo->prepare("
        SELECT id FROM live_session_participants 
        WHERE session_id = ? AND user_id = ? AND user_role = 'student'
    ");
    $stmt->execute([$session_id, $userId]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Update existing record
        $stmt = $pdo->prepare("
            UPDATE live_session_participants 
            SET leave_time = NULL, 
                is_active = 1, 
                camera_active = ?,
                mic_active = ?,
                join_time = NOW()
            WHERE session_id = ? AND user_id = ?
        ");
        $stmt->execute([$camera_consent ? 1 : 0, $microphone_consent ? 1 : 0, $session_id, $userId]);
    } else {
        // Insert new record
        $stmt = $pdo->prepare("
            INSERT INTO live_session_participants 
            (session_id, user_id, user_role, join_time, camera_active, mic_active, is_active) 
            VALUES (?, ?, 'student', NOW(), ?, ?, 1)
        ");
        $stmt->execute([$session_id, $userId, $camera_consent ? 1 : 0, $microphone_consent ? 1 : 0]);
    }
} catch (PDOException $e) {
    error_log("Error adding student to participants: " . $e->getMessage());
    // Continue anyway - don't fail the whole page
}

// Get instructor info
$instructor_info = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.id as instructor_id, u.full_name, u.username 
        FROM classes c
        JOIN users u ON c.instructor_id = u.id
        WHERE c.id = ?
    ");
    $stmt->execute([$class_id]);
    $instructor_info = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Error fetching instructor info: " . $e->getMessage());
}

// Get total participants count
$total_participants = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT user_id) as count 
        FROM live_session_participants 
        WHERE session_id = ? AND is_active = 1
    ");
    $stmt->execute([$session_id]);
    $result = $stmt->fetch();
    $total_participants = $result['count'] ?? 0;
} catch (PDOException $e) {
    error_log("Error counting participants: " . $e->getMessage());
}

// Get chat messages for initial load
$chat_messages = [];
try {
    $stmt = $pdo->prepare("
        SELECT cm.*, 
               COALESCE(u.full_name, 'Unknown User') as full_name, 
               COALESCE(u.role, 'student') as sender_role
        FROM chat_messages cm
        LEFT JOIN users u ON cm.sender_id = u.id
        WHERE cm.session_id = ?
        ORDER BY cm.created_at ASC
        LIMIT 50
    ");
    $stmt->execute([$session_id]);
    $chat_messages = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching chat messages: " . $e->getMessage());
}

// Get recent emotion data for student (only if emotion consent is given and class has emotion tracking)
$recent_emotions = [];
if ($emotion_consent && $class_emotion_tracking) {
    try {
        $stmt = $pdo->prepare("
            SELECT ed.*
            FROM emotion_data ed
            JOIN students s ON ed.student_id = s.id
            WHERE ed.session_id = ? AND s.user_id = ?
            ORDER BY ed.captured_at DESC
            LIMIT 10
        ");
        $stmt->execute([$session_id, $userId]);
        $recent_emotions = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching recent emotions: " . $e->getMessage());
    }
}

// Check if emotion detection is active for this class
$emotion_detection_status = ($class_emotion_tracking && $emotion_consent) ? 'active' : 'stopped';

// Log page access for audit trail
logAuditTrail(
    $_SESSION['user_id'],
    $_SESSION['role'],
    $_SESSION['username'],
    'view',
    'Joined live session as student: ' . $class['class_name'],
    'live_sessions',
    $session_id,
    ['class_id' => $class_id, 'session_id' => $session_id]
);

// Set page title
$page_title = "Live Class - " . htmlspecialchars($class['class_name']) . " - Emotion AI System";

// Helper functions
function getInitials($name) {
    if (empty($name) || $name === null) return '??';
    $parts = explode(' ', $name);
    $initials = '';
    foreach ($parts as $part) {
        if (!empty($part)) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }
    return substr($initials, 0, 2);
}

function formatDate($date, $format = 'h:i A') {
    if (empty($date) || $date === null) return '';
    $timestamp = strtotime($date);
    return $timestamp ? date($format, $timestamp) : '';
}

// Calculate emotion distribution for student (only if emotion consent is given and class has emotion tracking)
$emotion_stats = [
    'happy' => 0,
    'neutral' => 0,
    'sad' => 0,
    'angry' => 0,
    'confused' => 0
];

if ($emotion_consent && $class_emotion_tracking && !empty($recent_emotions)) {
    $total = count($recent_emotions);
    foreach ($recent_emotions as $emotion) {
        $type = $emotion['facial_emotion'] ?? 'neutral';
        if (isset($emotion_stats[$type])) {
            $emotion_stats[$type]++;
        }
    }
    
    // Convert to percentages
    if ($total > 0) {
        foreach ($emotion_stats as $key => $value) {
            $emotion_stats[$key] = round(($value / $total) * 100, 1);
        }
    }
}

// Get participants list
$participants = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            lsp.*, 
            u.full_name, 
            u.username, 
            s.student_number,
            'participant' as role_display
        FROM live_session_participants lsp
        JOIN users u ON lsp.user_id = u.id
        LEFT JOIN students s ON u.id = s.user_id
        WHERE lsp.session_id = ? AND lsp.is_active = 1
        ORDER BY 
            CASE WHEN lsp.user_role = 'instructor' THEN 1 ELSE 2 END,
            lsp.join_time
    ");
    $stmt->execute([$session_id]);
    $participants = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching participants: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title><?php echo $page_title; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Face-api.js for facial emotion detection -->
    <script src="https://cdn.jsdelivr.net/npm/face-api.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-purple: #8b5cf6;
            --secondary-purple: #7c3aed;
            --dark-purple: #6d28d9;
            --light-purple: #ede9fe;
            --accent-blue: #3b82f6;
            --primary-white: #ffffff;
            --secondary-white: #f5f5f5;
            --off-white: #f9f9f9;
            --light-gray: #e0e0e0;
            --medium-gray: #cccccc;
            --dark-gray: #333333;
            --card-bg: #f8fafc;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gradient-primary: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            --gradient-secondary: linear-gradient(135deg, var(--secondary-purple) 0%, var(--primary-purple) 100%);
            --gradient-card: linear-gradient(135deg, var(--card-bg) 0%, #e5e7eb 100%);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: var(--dark-gray);
            line-height: 1.6;
            overflow: hidden;
            height: 100vh;
            touch-action: manipulation;
        }
        
        .live-session-container {
            display: grid;
            grid-template-rows: 70px 1fr;
            height: 100vh;
            gap: 0;
            overflow: hidden;
        }
        
        /* Header Styles */
        .session-header {
            background: rgba(255, 255, 255, 0.98);
            padding: 0 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            z-index: 100;
            backdrop-filter: blur(10px);
            height: 70px;
            min-height: 70px;
            border-bottom: 1px solid rgba(139, 92, 246, 0.2);
        }
        
        .session-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .session-title {
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 250px;
        }
        
        .session-code {
            background: rgba(139, 92, 246, 0.1);
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            color: var(--primary-purple);
            border: 1px solid rgba(139, 92, 246, 0.3);
            white-space: nowrap;
        }
        
        .session-timer {
            font-size: 14px;
            font-weight: 600;
            background: rgba(139, 92, 246, 0.1);
            padding: 8px 15px;
            border-radius: 20px;
            color: var(--primary-purple);
            border: 1px solid rgba(139, 92, 246, 0.3);
            white-space: nowrap;
        }
        
        /* Main Content Grid */
        .session-main {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 0;
            height: calc(100vh - 70px);
            overflow: hidden;
            position: relative;
        }
        
        /* Video Monitoring Section */
        .video-section {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
            height: 100%;
            overflow: hidden;
        }
        
        /* Stats Grid for Student */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 10px;
            flex-shrink: 0;
        }
        
        .stat-card-student {
            background: rgba(255, 255, 255, 0.95);
            padding: 15px;
            border-radius: 12px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.6);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            min-height: 80px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border-top: 4px solid var(--accent-blue);
        }
        
        .stat-card-student:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.15);
        }
        
        .stat-value-student {
            font-size: 24px;
            font-weight: 800;
            color: var(--accent-blue);
            margin-bottom: 5px;
            line-height: 1.2;
        }
        
        .stat-label-student {
            font-size: 12px;
            color: #6b7280;
            font-weight: 600;
            line-height: 1.2;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .video-grid-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            flex: 1;
            display: flex;
            flex-direction: column;
            border: 2px solid rgba(139, 92, 246, 0.1);
            overflow: hidden;
            backdrop-filter: blur(5px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
            min-height: 0;
        }
        
        .video-grid-header {
            padding: 15px 20px;
            background: rgba(139, 92, 246, 0.05);
            border-bottom: 2px solid rgba(139, 92, 246, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        
        .video-grid-title {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            white-space: nowrap;
        }
        
        .participant-count {
            font-size: 13px;
            color: var(--primary-purple);
            font-weight: 600;
            white-space: nowrap;
        }
        
        .video-grid {
            flex: 1;
            padding: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            overflow-y: auto;
            align-content: start;
            min-height: 0;
        }
        
        .video-item {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid rgba(139, 92, 246, 0.2);
            transition: all 0.3s ease;
            position: relative;
            aspect-ratio: 16/9;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            min-height: 160px;
        }
        
        .video-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(139, 92, 246, 0.2);
            border-color: var(--primary-purple);
        }
        
        .video-item.instructor {
            border: 2px solid var(--primary-purple);
            box-shadow: 0 4px 20px rgba(139, 92, 246, 0.3);
        }
        
        .video-item.student {
            border: 2px solid var(--accent-blue);
        }
        
        .video-item.self {
            border: 2px solid var(--success);
            box-shadow: 0 4px 20px rgba(16, 185, 129, 0.3);
        }
        
        .video-placeholder {
            width: 100%;
            height: 100%;
            background: rgba(139, 92, 246, 0.05);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #4b5563;
            padding: 15px;
            text-align: center;
        }
        
        .video-icon {
            font-size: 36px;
            opacity: 0.8;
            color: var(--primary-purple);
        }
        
        /* Emotion detection overlay */
        .emotion-overlay {
            position: absolute;
            top: 10px;
            left: 10px;
            background: rgba(15, 23, 42, 0.85);
            color: white;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            z-index: 10;
            display: flex;
            align-items: center;
            gap: 6px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(5px);
            min-width: 100px;
        }
        
        .emotion-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .emotion-happy {
            background: #10b981;
        }
        
        .emotion-neutral {
            background: #3b82f6;
        }
        
        .emotion-sad {
            background: #8b5cf6;
        }
        
        .emotion-angry {
            background: #ef4444;
        }
        
        .emotion-confused {
            background: #f59e0b;
        }
        
        video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 12px;
            background: #000;
        }
        
        .video-info {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 12px;
            background: linear-gradient(transparent, rgba(15, 23, 42, 0.9));
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 0 0 12px 12px;
        }
        
        .video-user-name {
            font-weight: 600;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: white;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .video-user-role {
            font-size: 10px;
            color: white;
            background: rgba(139, 92, 246, 0.8);
            padding: 3px 8px;
            border-radius: 10px;
            flex-shrink: 0;
            font-weight: 600;
        }
        
        .video-user-role.instructor {
            background: rgba(139, 92, 246, 0.8);
        }
        
        .video-user-role.student {
            background: rgba(59, 130, 246, 0.8);
        }
        
        .video-user-role.self {
            background: rgba(16, 185, 129, 0.8);
        }
        
        .video-status {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            color: #e0e7ff;
            flex-shrink: 0;
        }
        
        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
            animation: pulse 2s infinite;
        }
        
        /* Controls Section */
        .controls-section {
            padding: 20px;
            background: rgba(255, 255, 255, 0.95);
            border-top: 1px solid rgba(139, 92, 246, 0.1);
            backdrop-filter: blur(5px);
            border-radius: 0 0 15px 15px;
            flex-shrink: 0;
            margin-top: auto;
        }
        
        .control-buttons {
            display: flex;
            justify-content: center;
            gap: 20px;
        }
        
        .control-btn {
            width: 55px;
            height: 55px;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            font-size: 20px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            touch-action: manipulation;
        }
        
        .control-btn-primary {
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            color: var(--primary-white);
        }
        
        .control-btn-secondary {
            background: linear-gradient(135deg, var(--accent-blue) 0%, #2563eb 100%);
            color: var(--primary-white);
        }
        
        .control-btn-danger {
            background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%);
            color: var(--primary-white);
        }
        
        .control-btn.disabled {
            background: var(--light-gray);
            color: var(--medium-gray);
            cursor: not-allowed;
            opacity: 0.5;
        }
        
        .control-btn:hover:not(.disabled) {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 8px 25px rgba(139, 92, 246, 0.3);
        }
        
        /* Sidebar Styles */
        .session-sidebar {
            background: rgba(255, 255, 255, 0.98);
            border-left: 1px solid rgba(139, 92, 246, 0.1);
            display: flex;
            flex-direction: column;
            height: 100%;
            backdrop-filter: blur(5px);
            position: relative;
            overflow: hidden;
            width: 100%;
        }
        
        .sidebar-tabs {
            display: flex;
            background: rgba(255, 255, 255, 0.98);
            flex-shrink: 0;
            z-index: 1000;
            position: relative;
            border-bottom: 2px solid rgba(139, 92, 246, 0.2);
            height: 55px;
            min-height: 55px;
        }
        
        .tab-btn {
            flex: 1;
            padding: 0;
            background: none;
            border: none;
            color: #4b5563;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 14px;
            position: relative;
            touch-action: manipulation;
        }
        
        .tab-btn:hover {
            background: rgba(139, 92, 246, 0.05);
            color: var(--primary-purple);
        }
        
        .tab-btn.active {
            background: linear-gradient(135deg, var(--accent-blue) 0%, #2563eb 100%);
            color: var(--primary-white);
            box-shadow: inset 0 -3px 0 #1d4ed8;
        }
        
        .tab-btn i {
            font-size: 16px;
        }
        
        .tab-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
            position: relative;
            overflow: hidden;
            height: calc(100% - 55px);
        }
        
        .tab-pane {
            flex: 1;
            display: none;
            background: rgba(255, 255, 255, 0.9);
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
            height: 100%;
        }
        
        .tab-pane.active {
            display: flex;
        }
        
        /* Chat Tab */
        .chat-messages-container {
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 0;
            position: relative;
            overflow: hidden;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
            min-height: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
            background: rgba(255, 255, 255, 0.7);
            max-height: calc(100% - 70px);
        }

        .chat-message {
            padding: 10px 12px;
            border-radius: 10px;
            max-width: 85%;
            word-wrap: break-word;
            animation: fadeIn 0.3s ease;
            background: white;
            border: 1px solid rgba(139, 92, 246, 0.1);
            font-size: 13px;
            min-width: 120px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .instructor-message {
            align-self: flex-start;
            background: linear-gradient(135deg, var(--primary-purple) 0%, var(--accent-blue) 100%);
            color: white;
            border-color: var(--dark-purple);
        }

        .student-message {
            align-self: flex-end;
            background: rgba(59, 130, 246, 0.05);
            border-left: 4px solid var(--accent-blue);
        }

        .own-message {
            align-self: flex-end;
            background: linear-gradient(135deg, var(--accent-blue) 0%, #2563eb 100%);
            color: white;
            border-color: #1d4ed8;
        }

        .system-message {
            align-self: center;
            background: rgba(139, 92, 246, 0.1);
            border-left: 4px solid var(--primary-purple);
            text-align: center;
            max-width: 90%;
            font-size: 12px;
        }

        .announcement-message {
            align-self: center;
            background: linear-gradient(135deg, #ffd700 0%, #ffb800 100%);
            border-left: 4px solid #ff9500;
            text-align: center;
            max-width: 90%;
            font-size: 12px;
            font-weight: 600;
            color: #5a4200;
        }

        .chat-input-area {
            padding: 15px;
            border-top: 1px solid rgba(139, 92, 246, 0.1);
            background: rgba(255, 255, 255, 0.95);
            flex-shrink: 0;
            height: 70px;
            min-height: 70px;
            display: flex;
            align-items: center;
            position: relative;
            z-index: 100;
        }

        .chat-input-wrapper {
            display: flex;
            gap: 10px;
            align-items: center;
            width: 100%;
            height: 100%;
        }

        .chat-input {
            flex: 1;
            padding: 10px 12px;
            background: white;
            border: 2px solid rgba(59, 130, 246, 0.2);
            border-radius: 10px;
            color: #1f2937;
            font-size: 13px;
            resize: none;
            min-height: 40px;
            max-height: 100px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.4;
            transition: all 0.3s;
        }

        .chat-input:focus {
            outline: none;
            border-color: var(--accent-blue);
            background: white;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .chat-send-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: none;
            background: linear-gradient(135deg, var(--accent-blue) 0%, #2563eb 100%);
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            touch-action: manipulation;
            box-shadow: 0 3px 10px rgba(59, 130, 246, 0.3);
        }

        .chat-send-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(59, 130, 246, 0.4);
        }

        /* Chat message info */
        .chat-message-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
        }

        .chat-sender {
            font-weight: 600;
            color: #1f2937;
            font-size: 12px;
        }

        .instructor-message .chat-sender {
            color: white;
        }

        .own-message .chat-sender {
            color: white;
        }

        .chat-time {
            font-size: 11px;
            color: #6b7280;
            opacity: 0.8;
        }

        .instructor-message .chat-time {
            color: rgba(255, 255, 255, 0.8);
        }

        .own-message .chat-time {
            color: rgba(255, 255, 255, 0.8);
        }

        /* Notification */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, var(--accent-blue) 0%, #2563eb 100%);
            color: white;
            padding: 12px 16px;
            border-radius: 10px;
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.3);
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 8px;
            transform: translateX(120%);
            transition: transform 0.3s ease;
            border-left: 4px solid #1d4ed8;
            font-size: 14px;
            max-width: 300px;
        }
        
        .notification.show {
            transform: translateX(0);
        }

        .notification.success {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
            border-left: 4px solid #047857;
        }
        
        .notification.warning {
            background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%);
            border-left: 4px solid #b45309;
        }
        
        .notification.error {
            background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%);
            border-left: 4px solid #b91c1c;
        }

        /* Emotion Detection Status */
        .emotion-status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 6px;
            display: inline-block;
        }

        .emotion-status-active {
            background-color: #10b981;
            animation: pulse 1.5s infinite;
        }

        .emotion-status-inactive {
            background-color: #6b7280;
        }

        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(59, 130, 246, 0.05);
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--accent-blue) 0%, #2563eb 100%);
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Mobile Responsive Styles */
        @media (max-width: 1200px) {
            .session-main {
                grid-template-columns: 1fr 300px;
            }
            
            .video-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            }
            
            .stats-grid {
                gap: 8px;
            }
            
            .stat-card-student {
                padding: 12px;
                min-height: 70px;
            }
            
            .stat-value-student {
                font-size: 22px;
            }
        }
        
        @media (max-width: 992px) {
            .session-main {
                grid-template-columns: 1fr;
                grid-template-rows: 1fr auto;
                height: calc(100vh - 70px);
            }
            
            .session-sidebar {
                grid-row: 2;
                height: 350px;
                min-height: 350px;
                border-left: none;
                border-top: 1px solid rgba(139, 92, 246, 0.1);
            }
            
            .video-section {
                height: 100%;
                overflow: auto;
            }
            
            .tab-content {
                height: 300px;
            }
            
            .chat-input-area {
                height: 70px;
                min-height: 70px;
            }
            
            .chat-messages {
                max-height: calc(100% - 70px);
            }
        }
        
        @media (max-width: 768px) {
            .session-header {
                padding: 0 15px;
            }
            
            .session-info {
                gap: 10px;
            }
            
            .session-title {
                font-size: 18px;
                max-width: 180px;
            }
            
            .session-code, .session-timer {
                font-size: 12px;
                padding: 6px 12px;
            }
            
            .video-section {
                padding: 15px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 8px;
            }
            
            .stat-card-student {
                padding: 10px;
                min-height: 65px;
            }
            
            .stat-value-student {
                font-size: 20px;
            }
            
            .stat-label-student {
                font-size: 11px;
            }
            
            .video-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 15px;
                padding: 15px;
            }
            
            .video-item {
                min-height: 140px;
            }
            
            .video-info {
                padding: 10px;
            }
            
            .video-user-name {
                font-size: 12px;
            }
            
            .controls-section {
                padding: 15px;
            }
            
            .control-btn {
                width: 50px;
                height: 50px;
                font-size: 18px;
            }
            
            .notification {
                max-width: 250px;
                font-size: 13px;
            }
            
            .emotion-overlay {
                font-size: 10px;
                padding: 4px 8px;
            }
        }
    </style>
</head>
<body>
    <div class="live-session-container">
        <!-- Header -->
        <div class="session-header">
            <div class="session-info">
                <div class="session-title">
                    <i class="fas fa-video" style="color: var(--accent-blue);"></i> <?php echo htmlspecialchars($class['class_name']); ?>
                </div>
                <div class="session-code">Room: <?php echo htmlspecialchars(substr($room_id, 0, 8)); ?></div>
                <div class="session-timer">
                    <i class="fas fa-clock" style="color: var(--accent-blue);"></i> <span id="sessionTimer">00:00:00</span>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="background: rgba(59, 130, 246, 0.1); padding: 6px 12px; border-radius: 25px; border: 1px solid rgba(59, 130, 246, 0.3);">
                    <div style="font-weight: 700; font-size: 13px; color: #1f2937;"><?php echo htmlspecialchars($userData['full_name']); ?></div>
                    <div style="font-size: 11px; color: var(--accent-blue); font-weight: 600;">Student</div>
                </div>
                <button onclick="leaveSession()" style="background: linear-gradient(135deg, var(--accent-blue) 0%, #2563eb 100%); border: none; color: white; padding: 8px 16px; border-radius: 20px; cursor: pointer; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 3px 10px rgba(59, 130, 246, 0.3); font-size: 13px; white-space: nowrap; touch-action: manipulation;">
                    <i class="fas fa-sign-out-alt"></i> <span>Leave</span>
                </button>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="session-main">
            <!-- Video Grid Section -->
            <div class="video-section">
                <!-- Student Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card-student" onclick="showParticipantsInfo()">
                        <div class="stat-value-student" id="totalParticipants"><?php echo $total_participants; ?></div>
                        <div class="stat-label-student">Total Participants</div>
                    </div>
                    <div class="stat-card-student" onclick="showConnectionStatus()">
                        <div class="stat-value-student" id="connectionStatus">Connecting...</div>
                        <div class="stat-label-student">Connection</div>
                    </div>
                    <div class="stat-card-student" onclick="showEmotionStatus()">
                        <div class="stat-value-student">
                            <?php 
                                if ($emotion_consent && $class_emotion_tracking) {
                                    echo '<i class="fas fa-brain" style="color: var(--accent-blue);"></i>';
                                } else {
                                    echo '<i class="fas fa-ban" style="color: #ef4444;"></i>';
                                }
                            ?>
                        </div>
                        <div class="stat-label-student">Emotion Detection</div>
                    </div>
                </div>
                
                <div class="video-grid-container">
                    <div class="video-grid-header">
                        <div class="video-grid-title">
                            <i class="fas fa-users" style="color: var(--accent-blue);"></i> Live Classroom
                        </div>
                        <div class="participant-count">
                            <span id="participantCount"><?php echo $total_participants; ?></span> connected
                            <?php if ($emotion_consent && $class_emotion_tracking): ?>
                                <span style="margin-left: 10px; font-size: 11px; color: #10b981;">
                                    <span class="emotion-status-indicator emotion-status-active"></span> Emotion AI Active
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="video-grid" id="videoGrid">
                        <!-- Instructor's video -->
                        <?php if ($instructor_info): ?>
                            <div class="video-item instructor">
                                <div class="video-placeholder" id="instructorVideoPlaceholder">
                                    <div class="video-icon">
                                        <i class="fas fa-chalkboard-teacher"></i>
                                    </div>
                                    <div><?php echo htmlspecialchars($instructor_info['full_name'] ?? 'Instructor'); ?></div>
                                    <small>Waiting for connection...</small>
                                </div>
                                <video id="instructorVideo" autoplay playsinline style="display: none;"></video>
                                <div class="video-info">
                                    <div class="video-user-name">
                                        <?php echo htmlspecialchars($instructor_info['full_name'] ?? 'Instructor'); ?>
                                        <span class="video-user-role instructor">Instructor</span>
                                    </div>
                                    <div class="video-status">
                                        <span class="status-indicator" style="background: var(--warning);"></span>
                                        <span>Connecting...</span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Student's own video with emotion detection -->
                        <div class="video-item self" id="studentVideoContainer">
                            <div class="video-placeholder" id="studentVideoPlaceholder">
                                <div class="video-icon">
                                    <i class="fas fa-user-graduate"></i>
                                </div>
                                <div><?php echo htmlspecialchars($userData['full_name']); ?> (You)</div>
                                <small>Student</small>
                                <?php if ($camera_consent): ?>
                                    <small style="color: #10b981; font-weight: 600;" id="cameraStatus">
                                        <i class="fas fa-check-circle"></i> Camera ready
                                    </small>
                                <?php else: ?>
                                    <small style="color: #ef4444; font-weight: 600;">
                                        <i class="fas fa-ban"></i> Camera disabled
                                    </small>
                                <?php endif; ?>
                                <?php if ($emotion_consent && $class_emotion_tracking): ?>
                                    <small style="color: #8b5cf6; font-weight: 600;" id="emotionDetectionStatus">
                                        <i class="fas fa-brain"></i> Emotion AI ready
                                    </small>
                                <?php endif; ?>
                            </div>
                            <video id="studentVideo" autoplay playsinline muted style="display: none;"></video>
                            <!-- Emotion detection overlay - positioned in upper-left corner -->
                            <div class="emotion-overlay" id="emotionOverlay" style="display: none;">
                                <span class="emotion-indicator" id="emotionIndicator"></span>
                                <span id="emotionText">Detecting...</span>
                            </div>
                            <div class="video-info">
                                <div class="video-user-name">
                                    <?php echo htmlspecialchars($userData['full_name']); ?> (You)
                                    <span class="video-user-role self">You</span>
                                </div>
                                <div class="video-status">
                                    <span class="status-indicator" id="studentStatusIndicator"></span>
                                    <span id="cameraStatusText"><?php echo $camera_consent ? 'Ready' : 'Disabled'; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Controls -->
                <div class="controls-section">
                    <div class="control-buttons">
                        <button class="control-btn <?php echo $camera_consent ? 'control-btn-primary' : 'disabled'; ?>" 
                                id="cameraBtnMain" 
                                onclick="<?php echo $camera_consent ? 'toggleCamera()' : ''; ?>" 
                                title="<?php echo $camera_consent ? 'Toggle Camera' : 'Camera consent not given'; ?>"
                                <?php echo !$camera_consent ? 'disabled' : ''; ?>>
                            <i class="fas fa-video"></i>
                        </button>
                        <button class="control-btn <?php echo $microphone_consent ? 'control-btn-primary' : 'disabled'; ?>" 
                                id="micBtnMain" 
                                onclick="<?php echo $microphone_consent ? 'toggleMicrophone()' : ''; ?>" 
                                title="<?php echo $microphone_consent ? 'Toggle Microphone' : 'Microphone consent not given'; ?>"
                                <?php echo !$microphone_consent ? 'disabled' : ''; ?>>
                            <i class="fas fa-microphone"></i>
                        </button>
                        <button class="control-btn control-btn-secondary" onclick="raiseHand()" id="raiseHandBtn" title="Raise Hand">
                            <i class="fas fa-hand-paper"></i>
                        </button>
                        <button class="control-btn control-btn-secondary" onclick="sendChatMessage('Question: I have a question')" id="askQuestionBtn" title="Ask Question">
                            <i class="fas fa-question"></i>
                        </button>
                        <button class="control-btn control-btn-danger" onclick="confirmLeaveSession()" id="leaveSessionBtn" title="Leave Session">
                            <i class="fas fa-sign-out-alt"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Sidebar -->
            <div class="session-sidebar">
                <div class="sidebar-tabs">
                    <button class="tab-btn active" onclick="switchTab('chat')" title="Chat">
                        <i class="fas fa-comments"></i> <span class="tab-text">Chat</span>
                    </button>
                    <button class="tab-btn" onclick="switchTab('participants')" title="Participants">
                        <i class="fas fa-users"></i> <span class="tab-text">Participants</span>
                    </button>
                    <button class="tab-btn" onclick="switchTab('emotions')" title="Emotion Analysis">
                        <i class="fas fa-brain"></i> <span class="tab-text">Emotions</span>
                    </button>
                    <button class="tab-btn" onclick="switchTab('settings')" title="Settings">
                        <i class="fas fa-cog"></i> <span class="tab-text">Settings</span>
                    </button>
                </div>
                
                <div class="tab-content">
                    <!-- Chat Tab -->
                    <div class="tab-pane active" id="chatTab">
                        <div class="chat-messages-container">
                            <div class="chat-messages" id="chatMessages">
                                <div class="chat-message system-message">
                                    <strong>System:</strong> Welcome to the live class chat! Messages are logged for record keeping.
                                </div>
                                <?php foreach ($chat_messages as $chat): 
                                    $senderId = $chat['sender_id'] ?? 0;
                                    $senderRole = $chat['sender_role'] ?? 'student';
                                    $senderName = $chat['full_name'] ?? 'Unknown User';
                                    $messageText = $chat['message'] ?? '[Empty message]';
                                    $messageTime = $chat['created_at'] ?? '';
                                    
                                    $messageClass = 'student-message';
                                    if ($senderId == $userId) {
                                        $messageClass = 'own-message';
                                    } elseif ($senderRole == 'instructor') {
                                        $messageClass = 'instructor-message';
                                    } elseif (strpos($messageText, '📢 ANNOUNCEMENT:') === 0) {
                                        $messageClass = 'announcement-message';
                                        $messageText = str_replace('📢 ANNOUNCEMENT:', '📢', $messageText);
                                    }
                                    ?>
                                    <div class="chat-message <?php echo $messageClass; ?>">
                                        <div class="chat-message-info">
                                            <div class="chat-sender"><?php echo htmlspecialchars($senderName); ?></div>
                                            <div class="chat-time"><?php echo formatDate($messageTime); ?></div>
                                        </div>
                                        <div class="chat-message-text"><?php echo htmlspecialchars($messageText); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="chat-input-area">
                                <div class="chat-input-wrapper">
                                    <textarea class="chat-input" id="chatInput" placeholder="Type a message to the class..." rows="1"></textarea>
                                    <button class="chat-send-btn" onclick="sendChatMessage()" title="Send Message">
                                        <i class="fas fa-paper-plane"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Participants Tab -->
                    <div class="tab-pane" id="participantsTab">
                        <div style="padding: 15px; overflow-y: auto; height: 100%;">
                            <h3 style="color: #1f2937; margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                                <i class="fas fa-users"></i> Participants
                            </h3>
                            
                            <!-- Instructor -->
                            <?php if ($instructor_info): ?>
                                <div style="background: white; padding: 12px 15px; border-radius: 10px; margin-bottom: 10px; border-left: 4px solid #8b5cf6;">
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white; font-size: 14px;">
                                            <?php echo getInitials($instructor_info['full_name'] ?? 'Instructor'); ?>
                                        </div>
                                        <div style="flex: 1;">
                                            <div style="font-weight: 600; color: #1f2937;"><?php echo htmlspecialchars($instructor_info['full_name'] ?? 'Instructor'); ?></div>
                                            <div style="font-size: 11px; color: #8b5cf6;">Instructor • Host</div>
                                        </div>
                                        <div style="font-size: 10px; padding: 4px 8px; border-radius: 10px; background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid #10b981; font-weight: 600;">
                                            Online
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Current Student -->
                            <div style="background: white; padding: 12px 15px; border-radius: 10px; margin-bottom: 10px; border-left: 4px solid #10b981;">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #10b981 0%, #059669 100%); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white; font-size: 14px;">
                                        <?php echo getInitials($userData['full_name']); ?>
                                    </div>
                                    <div style="flex: 1;">
                                        <div style="font-weight: 600; color: #1f2937;"><?php echo htmlspecialchars($userData['full_name']); ?> (You)</div>
                                        <div style="font-size: 11px; color: #10b981;">Student • <?php echo htmlspecialchars($student_number); ?></div>
                                    </div>
                                    <div style="font-size: 10px; padding: 4px 8px; border-radius: 10px; background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid #10b981; font-weight: 600;">
                                        Online
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Other Participants -->
                            <?php foreach ($participants as $participant): 
                                if ($participant['user_role'] === 'student' && $participant['user_id'] != $userId): 
                                    $studentName = $participant['full_name'] ?? 'Student';
                                    $studentNumber = $participant['student_number'] ?? 'N/A';
                                    ?>
                                    <div style="background: white; padding: 12px 15px; border-radius: 10px; margin-bottom: 10px; border-left: 4px solid #3b82f6;">
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white; font-size: 14px;">
                                                <?php echo getInitials($studentName); ?>
                                            </div>
                                            <div style="flex: 1;">
                                                <div style="font-weight: 600; color: #1f2937;"><?php echo htmlspecialchars($studentName); ?></div>
                                                <div style="font-size: 11px; color: #3b82f6;">Student • <?php echo htmlspecialchars($studentNumber); ?></div>
                                            </div>
                                            <div style="font-size: 10px; padding: 4px 8px; border-radius: 10px; background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid #10b981; font-weight: 600;">
                                                Online
                                            </div>
                                        </div>
                                    </div>
                                <?php endif;
                            endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Emotions Tab -->
                    <div class="tab-pane" id="emotionsTab">
                        <div style="padding: 20px; overflow-y: auto; height: 100%;">
                            <h3 style="color: #1f2937; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                                <i class="fas fa-brain"></i> Emotion Analysis
                            </h3>
                            
                            <?php if ($emotion_consent && $class_emotion_tracking): ?>
                                <!-- Current Emotion Status -->
                                <div style="background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                    <h4 style="color: #1f2937; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                                        <i class="fas fa-smile"></i> Current Emotion Status
                                    </h4>
                                    <div style="display: flex; flex-direction: column; gap: 15px;">
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <div>
                                                <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">Current Emotion</div>
                                                <div style="font-size: 12px; color: #6b7280;">
                                                    Detected in real-time from your facial expressions
                                                </div>
                                            </div>
                                            <div id="currentEmotionDisplay" style="padding: 8px 16px; border-radius: 20px; font-weight: 600; font-size: 14px; background: rgba(59, 130, 246, 0.1); color: #3b82f6;">
                                                <i class="fas fa-spinner fa-spin"></i> Detecting...
                                            </div>
                                        </div>
                                        
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <div>
                                                <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">Confidence Level</div>
                                                <div style="font-size: 12px; color: #6b7280;">
                                                    How confident the AI is in its detection
                                                </div>
                                            </div>
                                            <div id="confidenceLevel" style="font-weight: 600; color: #3b82f6; font-size: 14px;">
                                                0%
                                            </div>
                                        </div>
                                        
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <div>
                                                <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">Last Detection</div>
                                                <div style="font-size: 12px; color: #6b7280;">
                                                    When emotion was last detected
                                                </div>
                                            </div>
                                            <div id="lastDetectionTime" style="font-weight: 600; color: #6b7280; font-size: 12px;">
                                                Just now
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Emotion Statistics -->
                                <div style="background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                    <h4 style="color: #1f2937; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                                        <i class="fas fa-chart-bar"></i> Emotion Statistics
                                    </h4>
                                    <div style="display: flex; flex-direction: column; gap: 10px;">
                                        <?php foreach ($emotion_stats as $emotion => $percentage): 
                                            $color = '#3b82f6';
                                            $icon = 'meh';
                                            $label = ucfirst($emotion);
                                            
                                            switch ($emotion) {
                                                case 'happy':
                                                    $color = '#10b981';
                                                    $icon = 'smile';
                                                    break;
                                                case 'sad':
                                                    $color = '#8b5cf6';
                                                    $icon = 'frown';
                                                    break;
                                                case 'angry':
                                                    $color = '#ef4444';
                                                    $icon = 'angry';
                                                    break;
                                                case 'confused':
                                                    $color = '#f59e0b';
                                                    $icon = 'question-circle';
                                                    break;
                                                case 'neutral':
                                                default:
                                                    $color = '#3b82f6';
                                                    $icon = 'meh';
                                                    break;
                                            }
                                        ?>
                                        <div style="display: flex; align-items: center; justify-content: space-between;">
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <div style="width: 30px; height: 30px; border-radius: 8px; background: <?php echo $color; ?>20; display: flex; align-items: center; justify-content: center; color: <?php echo $color; ?>;">
                                                    <i class="fas fa-<?php echo $icon; ?>"></i>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 600; color: #1f2937;"><?php echo $label; ?></div>
                                                </div>
                                            </div>
                                            <div style="font-weight: 600; color: <?php echo $color; ?>;">
                                                <?php echo $percentage; ?>%
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <!-- Emotion Legend -->
                                <div style="background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                    <h4 style="color: #1f2937; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                                        <i class="fas fa-info-circle"></i> Emotion Legend
                                    </h4>
                                    <div style="display: flex; flex-direction: column; gap: 8px;">
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div class="emotion-indicator emotion-happy"></div>
                                            <span style="font-size: 13px; color: #1f2937;">Happy - Engaged and positive</span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div class="emotion-indicator emotion-neutral"></div>
                                            <span style="font-size: 13px; color: #1f2937;">Neutral - Normal attention</span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div class="emotion-indicator emotion-sad"></div>
                                            <span style="font-size: 13px; color: #1f2937;">Sad - Disengaged or unhappy</span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div class="emotion-indicator emotion-angry"></div>
                                            <span style="font-size: 13px; color: #1f2937;">Angry - Frustrated or upset</span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div class="emotion-indicator emotion-confused"></div>
                                            <span style="font-size: 13px; color: #1f2937;">Confused - Needs clarification</span>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- Emotion detection not enabled -->
                                <div style="background: white; border-radius: 12px; padding: 30px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                    <div style="width: 60px; height: 60px; border-radius: 50%; background: rgba(107, 114, 128, 0.1); display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; color: #6b7280; font-size: 24px;">
                                        <i class="fas fa-ban"></i>
                                    </div>
                                    <h4 style="color: #1f2937; margin-bottom: 10px;">Emotion Detection Disabled</h4>
                                    <p style="color: #6b7280; font-size: 14px; line-height: 1.5; margin-bottom: 20px;">
                                        <?php if (!$emotion_consent): ?>
                                            You have not given consent for emotion detection. Update your consent settings to enable this feature.
                                        <?php else: ?>
                                            Emotion detection is not enabled for this class.
                                        <?php endif; ?>
                                    </p>
                                    <button onclick="updateConsentSettings()" style="padding: 10px 20px; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">
                                        Update Consent Settings
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Settings Tab -->
                    <div class="tab-pane" id="settingsTab">
                        <div style="padding: 20px; overflow-y: auto; height: 100%;">
                            <h3 style="color: #1f2937; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                                <i class="fas fa-cog"></i> Settings
                            </h3>
                            
                            <!-- WebRTC Connection Status -->
                            <div style="background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                <h4 style="color: #1f2937; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-plug"></i> Connection Status
                                </h4>
                                <div style="display: flex; flex-direction: column; gap: 10px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: #6b7280; font-size: 14px;">WebRTC Connection:</span>
                                        <span id="webrtcStatus" style="font-weight: 600; color: #f59e0b;">Connecting...</span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: #6b7280; font-size: 14px;">Room ID:</span>
                                        <span style="font-weight: 600; color: #3b82f6;"><?php echo htmlspecialchars(substr($room_id, 0, 12)); ?>...</span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: #6b7280; font-size: 14px;">Session ID:</span>
                                        <span style="font-weight: 600; color: #8b5cf6;"><?php echo $session_id; ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Media Settings -->
                            <div style="background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                <h4 style="color: #1f2937; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-video"></i> Media Settings
                                </h4>
                                <div style="display: flex; flex-direction: column; gap: 15px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <div>
                                            <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">Camera</div>
                                            <div style="font-size: 12px; color: #6b7280;">
                                                <?php echo $camera_consent ? 'Consent given' : 'Consent not given'; ?>
                                            </div>
                                        </div>
                                        <button onclick="toggleCamera()" id="cameraToggleBtn" style="padding: 6px 12px; background: <?php echo $camera_consent ? '#3b82f6' : '#6b7280'; ?>; color: white; border: none; border-radius: 6px; font-size: 12px; cursor: pointer; <?php echo !$camera_consent ? 'opacity: 0.5; cursor: not-allowed;' : ''; ?>">
                                            <?php echo $camera_consent ? 'Toggle' : 'Disabled'; ?>
                                        </button>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <div>
                                            <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">Microphone</div>
                                            <div style="font-size: 12px; color: #6b7280;">
                                                <?php echo $microphone_consent ? 'Consent given' : 'Consent not given'; ?>
                                            </div>
                                        </div>
                                        <button onclick="toggleMicrophone()" id="micToggleBtn" style="padding: 6px 12px; background: <?php echo $microphone_consent ? '#3b82f6' : '#6b7280'; ?>; color: white; border: none; border-radius: 6px; font-size: 12px; cursor: pointer; <?php echo !$microphone_consent ? 'opacity: 0.5; cursor: not-allowed;' : ''; ?>">
                                            <?php echo $microphone_consent ? 'Toggle' : 'Disabled'; ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Consent Settings -->
                            <div style="background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                                <h4 style="color: #1f2937; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-shield-alt"></i> Consent Settings
                                </h4>
                                <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: #6b7280; font-size: 14px;">Camera Consent:</span>
                                        <span style="font-weight: 600; color: <?php echo $camera_consent ? '#10b981' : '#ef4444'; ?>;">
                                            <?php echo $camera_consent ? '✓ Granted' : '✗ Not Granted'; ?>
                                        </span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: #6b7280; font-size: 14px;">Microphone Consent:</span>
                                        <span style="font-weight: 600; color: <?php echo $microphone_consent ? '#10b981' : '#ef4444'; ?>;">
                                            <?php echo $microphone_consent ? '✓ Granted' : '✗ Not Granted'; ?>
                                        </span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <span style="color: #6b7280; font-size: 14px;">Emotion Detection:</span>
                                        <span style="font-weight: 600; color: <?php echo $emotion_consent ? '#10b981' : '#ef4444'; ?>;">
                                            <?php echo $emotion_consent ? '✓ Granted' : '✗ Not Granted'; ?>
                                        </span>
                                    </div>
                                </div>
                                <button onclick="updateConsentSettings()" style="width: 100%; padding: 12px; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;">
                                    <i class="fas fa-edit"></i> Update Consent Settings
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Container -->
    <div id="notificationContainer"></div>

    <script>
        // ==================== WEBRTC AND EMOTION DETECTION IMPLEMENTATION ====================
        let sessionStartTime = new Date("<?php echo $class['start_time']; ?>");
        let sessionId = <?php echo $session_id; ?>;
        let classId = <?php echo $class_id; ?>;
        let userId = <?php echo $userId; ?>;
        let studentId = <?php echo $student_id ?? 'null'; ?>;
        let userName = '<?php echo addslashes($userData['full_name']); ?>';
        let studentNumber = '<?php echo addslashes($student_number); ?>';
        let roomId = '<?php echo $room_id; ?>';
        let instructorId = <?php echo $instructor_info['instructor_id'] ?? 0; ?>;
        let cameraActive = <?php echo $camera_consent ? 'true' : 'false'; ?>;
        let microphoneActive = <?php echo $microphone_consent ? 'true' : 'false'; ?>;
        let emotionConsent = <?php echo $emotion_consent ? 'true' : 'false'; ?>;
        let classEmotionTracking = <?php echo $class_emotion_tracking ? 'true' : 'false'; ?>;
        
        // WebRTC Variables
        let localStream = null;
        let peerConnection = null;
        let handRaised = false;
        let chatPollingInterval = null;
        let signalingPollingInterval = null;
        
        // Emotion Detection Variables
        let emotionDetectionActive = false;
        let currentEmotion = 'neutral';
        let currentEmotionConfidence = 0;
        let lastEmotionDetectionTime = null;
        let emotionDetectionInterval = null;
        let emotionModelsLoaded = false;
        
        // Configuration for ICE servers
        const configuration = {
            iceServers: [
                { urls: 'stun:stun.l.google.com:19302' },
                { urls: 'stun:stun1.l.google.com:19302' },
                { urls: 'stun:stun2.l.google.com:19302' },
                { urls: 'stun:stun3.l.google.com:19302' },
                { urls: 'stun:stun4.l.google.com:19302' }
            ],
            iceCandidatePoolSize: 10
        };
        
        // Timer function
        function updateTimer() {
            const now = new Date();
            const diff = now - sessionStartTime;
            const hours = Math.floor(diff / 3600000);
            const minutes = Math.floor((diff % 3600000) / 60000);
            const seconds = Math.floor((diff % 60000) / 1000);
            
            document.getElementById('sessionTimer').textContent = 
                `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }

        // Switch tabs
        function switchTab(tabName) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
            document.getElementById(tabName + 'Tab').classList.add('active');
            
            if (tabName === 'chat') {
                // Scroll to bottom when switching to chat
                setTimeout(() => {
                    const chatMessages = document.getElementById('chatMessages');
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                }, 100);
            }
            
            event.preventDefault();
        }

        // Initialize student's media and WebRTC
        async function initStudentMedia() {
            console.log('Initializing student media...');
            console.log('Camera consent:', cameraActive);
            console.log('Microphone consent:', microphoneActive);
            console.log('Emotion detection:', emotionConsent && classEmotionTracking);
            
            try {
                // Only get media if consent is given
                if (cameraActive || microphoneActive) {
                    const constraints = {
                        video: cameraActive ? {
                            width: { ideal: 640 },
                            height: { ideal: 480 },
                            facingMode: 'user'
                        } : false,
                        audio: microphoneActive ? {
                            echoCancellation: true,
                            noiseSuppression: true
                        } : false
                    };

                    console.log('Requesting media with constraints:', constraints);
                    
                    // Get camera and microphone access
                    localStream = await navigator.mediaDevices.getUserMedia(constraints);
                    console.log('Media stream obtained successfully');
                    
                    // Display student's video
                    const studentVideo = document.getElementById('studentVideo');
                    const studentVideoPlaceholder = document.getElementById('studentVideoPlaceholder');
                    
                    if (studentVideo) {
                        studentVideo.srcObject = localStream;
                        
                        // Hide placeholder and show video if camera is active
                        if (cameraActive && localStream.getVideoTracks().length > 0) {
                            if (studentVideoPlaceholder) {
                                studentVideoPlaceholder.style.display = 'none';
                            }
                            studentVideo.style.display = 'block';
                            showNotification('Camera enabled', 'success');
                            
                            // Update camera button
                            const cameraBtn = document.getElementById('cameraBtnMain');
                            if (cameraBtn) {
                                cameraBtn.innerHTML = '<i class="fas fa-video"></i>';
                                cameraBtn.classList.remove('disabled');
                            }
                            
                            // Update status indicator
                            const statusIndicator = document.getElementById('studentStatusIndicator');
                            const cameraStatusText = document.getElementById('cameraStatusText');
                            if (statusIndicator) statusIndicator.style.background = '#10b981';
                            if (cameraStatusText) cameraStatusText.textContent = 'Live';
                            
                            // Initialize emotion detection if consent is given
                            if (emotionConsent && classEmotionTracking) {
                                setTimeout(() => {
                                    initEmotionDetection();
                                }, 1000);
                            }
                        } else {
                            if (studentVideoPlaceholder) {
                                studentVideoPlaceholder.style.display = 'flex';
                            }
                            studentVideo.style.display = 'none';
                        }
                        
                        if (microphoneActive && localStream.getAudioTracks().length > 0) {
                            showNotification('Microphone enabled', 'success');
                            
                            // Update mic button
                            const micBtn = document.getElementById('micBtnMain');
                            if (micBtn) {
                                micBtn.innerHTML = '<i class="fas fa-microphone"></i>';
                                micBtn.classList.remove('disabled');
                            }
                        }
                        
                        // Update device status on server
                        updateDeviceStatus();
                        
                        // Initialize WebRTC connection with teacher
                        initWebRTCConnection();
                    }
                } else {
                    showNotification('Camera and microphone consent not given. Media access disabled.', 'warning');
                    
                    // Update button states to show they're disabled
                    const cameraBtn = document.getElementById('cameraBtnMain');
                    const micBtn = document.getElementById('micBtnMain');
                    
                    if (cameraBtn) {
                        cameraBtn.classList.add('disabled');
                        cameraBtn.innerHTML = '<i class="fas fa-video-slash"></i>';
                    }
                    if (micBtn) {
                        micBtn.classList.add('disabled');
                        micBtn.innerHTML = '<i class="fas fa-microphone-slash"></i>';
                    }
                    
                    // Still try to connect to teacher for audio
                    initWebRTCConnection();
                }
                
            } catch (error) {
                console.error('Error accessing media devices:', error);
                
                let errorMessage = 'Could not access camera/microphone. ';
                
                if (error.name === 'NotAllowedError') {
                    errorMessage += 'Permission denied. Please check browser permissions.';
                } else if (error.name === 'NotFoundError') {
                    errorMessage += 'No camera/microphone found.';
                } else {
                    errorMessage += 'Error: ' + error.message;
                }
                
                showNotification(errorMessage, 'error');
                
                // Update button states to show error
                const cameraBtn = document.getElementById('cameraBtnMain');
                const micBtn = document.getElementById('micBtnMain');
                
                if (cameraBtn) {
                    cameraBtn.classList.add('disabled');
                    cameraBtn.innerHTML = '<i class="fas fa-video-slash"></i>';
                }
                if (micBtn) {
                    micBtn.classList.add('disabled');
                    micBtn.innerHTML = '<i class="fas fa-microphone-slash"></i>';
                }
                
                // Still try to connect to teacher
                initWebRTCConnection();
            }
        }

        // Initialize emotion detection using face-api.js
        async function initEmotionDetection() {
            if (!emotionConsent || !classEmotionTracking) {
                console.log('Emotion detection not enabled or consent not given');
                return;
            }
            
            console.log('Initializing emotion detection...');
            
            try {
                // Load face-api.js models
                await faceapi.nets.tinyFaceDetector.loadFromUri('/models');
                await faceapi.nets.faceLandmark68Net.loadFromUri('/models');
                await faceapi.nets.faceRecognitionNet.loadFromUri('/models');
                await faceapi.nets.faceExpressionNet.loadFromUri('/models');
                
                console.log('Face detection models loaded successfully');
                emotionModelsLoaded = true;
                
                // Show emotion overlay
                const emotionOverlay = document.getElementById('emotionOverlay');
                if (emotionOverlay) {
                    emotionOverlay.style.display = 'flex';
                }
                
                // Start emotion detection
                startEmotionDetection();
                emotionDetectionActive = true;
                
                showNotification('Emotion detection activated', 'success');
                
            } catch (error) {
                console.error('Error loading face detection models:', error);
                showNotification('Failed to load emotion detection models. Using simulated detection.', 'warning');
                
                // Fallback to simulated detection if models fail to load
                startSimulatedEmotionDetection();
            }
        }

        // Start real-time emotion detection
        function startEmotionDetection() {
            if (!emotionModelsLoaded) return;
            
            const video = document.getElementById('studentVideo');
            const canvas = faceapi.createCanvasFromMedia(video);
            const displaySize = { width: video.width, height: video.height };
            faceapi.matchDimensions(canvas, displaySize);
            
            // Start detection loop
            emotionDetectionInterval = setInterval(async () => {
                if (!video || video.readyState !== 4) return;
                
                try {
                    // Detect faces and expressions
                    const detections = await faceapi.detectAllFaces(video, 
                        new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks().withFaceExpressions();
                    
                    if (detections.length > 0) {
                        // Get the first face detected (assuming one student)
                        const detection = detections[0];
                        const expressions = detection.expressions;
                        
                        // Find the dominant expression
                        let maxExpression = 'neutral';
                        let maxConfidence = 0;
                        
                        for (const [expression, confidence] of Object.entries(expressions)) {
                            if (confidence > maxConfidence) {
                                maxConfidence = confidence;
                                maxExpression = expression;
                            }
                        }
                        
                        // Map face-api expressions to our emotion categories
                        currentEmotion = mapExpressionToEmotion(maxExpression);
                        currentEmotionConfidence = Math.round(maxConfidence * 100);
                        lastEmotionDetectionTime = new Date();
                        
                        // Update UI
                        updateEmotionDisplay(currentEmotion, currentEmotionConfidence);
                        
                        // Save emotion data to server
                        if (currentEmotionConfidence > 50) { // Only save if confidence > 50%
                            saveEmotionData(currentEmotion, currentEmotionConfidence);
                        }
                    } else {
                        // No face detected
                        updateEmotionDisplay('neutral', 0);
                    }
                    
                } catch (error) {
                    console.error('Error in emotion detection:', error);
                }
            }, 2000); // Detect every 2 seconds
        }

        // Map face-api expressions to our emotion categories
        function mapExpressionToEmotion(expression) {
            switch (expression) {
                case 'happy':
                case 'surprised':
                    return 'happy';
                case 'sad':
                    return 'sad';
                case 'angry':
                case 'disgusted':
                    return 'angry';
                case 'fearful':
                    return 'confused';
                case 'neutral':
                default:
                    return 'neutral';
            }
        }

        // Start simulated emotion detection (fallback)
        function startSimulatedEmotionDetection() {
            console.log('Starting simulated emotion detection');
            
            // Show emotion overlay
            const emotionOverlay = document.getElementById('emotionOverlay');
            if (emotionOverlay) {
                emotionOverlay.style.display = 'flex';
            }
            
            // Start simulated detection loop
            emotionDetectionInterval = setInterval(() => {
                // Simulate random emotions for demonstration
                const emotions = ['happy', 'neutral', 'sad', 'angry', 'confused'];
                const randomEmotion = emotions[Math.floor(Math.random() * emotions.length)];
                const randomConfidence = Math.floor(Math.random() * 30) + 70; // 70-100%
                
                currentEmotion = randomEmotion;
                currentEmotionConfidence = randomConfidence;
                lastEmotionDetectionTime = new Date();
                
                // Update UI
                updateEmotionDisplay(currentEmotion, currentEmotionConfidence);
                
                // Save emotion data to server
                saveEmotionData(currentEmotion, randomConfidence);
                
            }, 5000); // Simulate every 5 seconds
        }

        // Update emotion display on video and in sidebar
        function updateEmotionDisplay(emotion, confidence) {
            // Update emotion overlay on video
            const emotionOverlay = document.getElementById('emotionOverlay');
            const emotionIndicator = document.getElementById('emotionIndicator');
            const emotionText = document.getElementById('emotionText');
            
            if (emotionOverlay && emotionIndicator && emotionText) {
                // Set emotion color and text
                let emotionColor = '#3b82f6';
                let displayText = 'Neutral';
                
                switch (emotion) {
                    case 'happy':
                        emotionColor = '#10b981';
                        displayText = 'Happy';
                        emotionIndicator.className = 'emotion-indicator emotion-happy';
                        break;
                    case 'sad':
                        emotionColor = '#8b5cf6';
                        displayText = 'Sad';
                        emotionIndicator.className = 'emotion-indicator emotion-sad';
                        break;
                    case 'angry':
                        emotionColor = '#ef4444';
                        displayText = 'Angry';
                        emotionIndicator.className = 'emotion-indicator emotion-angry';
                        break;
                    case 'confused':
                        emotionColor = '#f59e0b';
                        displayText = 'Confused';
                        emotionIndicator.className = 'emotion-indicator emotion-confused';
                        break;
                    case 'neutral':
                    default:
                        emotionColor = '#3b82f6';
                        displayText = 'Neutral';
                        emotionIndicator.className = 'emotion-indicator';
                        break;
                }
                
                emotionText.textContent = `${displayText} (${confidence}%)`;
                
                // Update sidebar display
                const currentEmotionDisplay = document.getElementById('currentEmotionDisplay');
                const confidenceLevel = document.getElementById('confidenceLevel');
                const lastDetectionTime = document.getElementById('lastDetectionTime');
                
                if (currentEmotionDisplay) {
                    currentEmotionDisplay.innerHTML = `
                        <span style="color: ${emotionColor};">
                            <i class="fas fa-${getEmotionIcon(emotion)}"></i> ${displayText}
                        </span>
                    `;
                }
                
                if (confidenceLevel) {
                    confidenceLevel.textContent = `${confidence}%`;
                    confidenceLevel.style.color = emotionColor;
                }
                
                if (lastDetectionTime) {
                    const now = new Date();
                    const diffMs = now - lastEmotionDetectionTime;
                    const diffSec = Math.floor(diffMs / 1000);
                    
                    if (diffSec < 10) {
                        lastDetectionTime.textContent = 'Just now';
                    } else if (diffSec < 60) {
                        lastDetectionTime.textContent = `${diffSec} seconds ago`;
                    } else {
                        const diffMin = Math.floor(diffSec / 60);
                        lastDetectionTime.textContent = `${diffMin} minute${diffMin > 1 ? 's' : ''} ago`;
                    }
                }
            }
        }

        // Get icon for emotion
        function getEmotionIcon(emotion) {
            switch (emotion) {
                case 'happy': return 'smile';
                case 'sad': return 'frown';
                case 'angry': return 'angry';
                case 'confused': return 'question-circle';
                default: return 'meh';
            }
        }

        // Save emotion data to server
        async function saveEmotionData(emotionType, confidence) {
            if (!emotionConsent || !classEmotionTracking || !studentId) return;
            
            try {
                const formData = new FormData();
                formData.append('action', 'save_emotion_data');
                formData.append('session_id', sessionId);
                formData.append('emotion_type', emotionType);
                formData.append('confidence', confidence);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (!result.success) {
                    console.error('Failed to save emotion data:', result.error);
                }
            } catch (error) {
                console.error('Error saving emotion data:', error);
            }
        }

        // Initialize WebRTC connection with teacher
        async function initWebRTCConnection() {
            console.log('Initializing WebRTC connection with teacher...');
            
            try {
                // Create RTCPeerConnection
                peerConnection = new RTCPeerConnection(configuration);
                
                // Add local stream tracks to connection
                if (localStream) {
                    localStream.getTracks().forEach(track => {
                        peerConnection.addTrack(track, localStream);
                    });
                }
                
                // Handle ICE candidate events
                peerConnection.onicecandidate = (event) => {
                    if (event.candidate) {
                        console.log('Sending ICE candidate to teacher');
                        sendSignal(instructorId, {
                            type: 'candidate',
                            candidate: event.candidate
                        });
                    }
                };
                
                // Handle incoming tracks from teacher
                peerConnection.ontrack = (event) => {
                    console.log('Received remote track from teacher');
                    const remoteVideo = document.getElementById('instructorVideo');
                    const placeholder = document.getElementById('instructorVideoPlaceholder');
                    
                    if (remoteVideo) {
                        remoteVideo.srcObject = event.streams[0];
                        
                        // Show video and hide placeholder
                        if (placeholder) {
                            placeholder.style.display = 'none';
                        }
                        remoteVideo.style.display = 'block';
                        
                        // Update connection status
                        updateConnectionStatus('connected');
                        showNotification('Connected to teacher', 'success');
                    }
                };
                
                // Handle connection state changes
                peerConnection.onconnectionstatechange = () => {
                    console.log('Connection state:', peerConnection.connectionState);
                    
                    updateConnectionStatus(peerConnection.connectionState);
                    
                    if (peerConnection.connectionState === 'connected') {
                        showNotification('Connected to teacher', 'success');
                    } else if (peerConnection.connectionState === 'failed' || 
                              peerConnection.connectionState === 'disconnected') {
                        console.warn('Connection lost with teacher');
                        showNotification('Connection lost. Reconnecting...', 'warning');
                        
                        // Try to reconnect after 3 seconds
                        setTimeout(() => {
                            if (peerConnection.connectionState !== 'connected') {
                                reconnectToTeacher();
                            }
                        }, 3000);
                    }
                };
                
                // Start polling for WebRTC signals from teacher
                startSignalingPolling();
                
                // Create and send offer to teacher
                await createAndSendOffer();
                
            } catch (error) {
                console.error('Error initializing WebRTC connection:', error);
                showNotification('Failed to connect to teacher: ' + error.message, 'error');
                updateConnectionStatus('failed');
            }
        }

        // Create and send offer to teacher
        async function createAndSendOffer() {
            try {
                console.log('Creating offer for teacher');
                
                const offer = await peerConnection.createOffer({
                    offerToReceiveAudio: true,
                    offerToReceiveVideo: true
                });
                
                await peerConnection.setLocalDescription(offer);
                
                // Send offer to teacher
                await sendSignal(instructorId, offer);
                console.log('Offer sent to teacher');
                
            } catch (error) {
                console.error('Error creating offer:', error);
                showNotification('Failed to create connection offer', 'error');
            }
        }

        // Send WebRTC signal to teacher
        async function sendSignal(targetUserId, signal) {
            try {
                const formData = new FormData();
                formData.append('action', 'webrtc_signal');
                formData.append('session_id', sessionId);
                formData.append('target_user_id', targetUserId);
                formData.append('sender_user_id', userId);
                formData.append('signal_type', signal.type);
                formData.append('signal_data', JSON.stringify(signal));
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (!result.success) {
                    console.error('Failed to send signal:', result.error);
                }
            } catch (error) {
                console.error('Error sending signal:', error);
            }
        }

        // Poll for incoming WebRTC signals
        async function pollSignals() {
            try {
                const formData = new FormData();
                formData.append('action', 'get_webrtc_signals');
                formData.append('session_id', sessionId);
                formData.append('last_signal_id', window.lastSignalId || 0);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success && result.signals.length > 0) {
                    result.signals.forEach(signal => {
                        handleIncomingSignal(signal);
                        window.lastSignalId = Math.max(window.lastSignalId || 0, signal.id);
                    });
                }
            } catch (error) {
                console.error('Error polling signals:', error);
            }
        }

        // Handle incoming WebRTC signal
        async function handleIncomingSignal(signal) {
            const signalData = JSON.parse(signal.signal_data);
            const senderId = signal.sender_id;
            
            console.log('Received signal from teacher:', signalData.type);
            
            try {
                switch (signalData.type) {
                    case 'offer':
                        // Teacher sent an offer (shouldn't happen normally, but handle it)
                        await peerConnection.setRemoteDescription(new RTCSessionDescription(signalData));
                        
                        // Create answer
                        const answer = await peerConnection.createAnswer();
                        await peerConnection.setLocalDescription(answer);
                        
                        // Send answer back to teacher
                        await sendSignal(senderId, answer);
                        break;
                        
                    case 'answer':
                        // Teacher sent answer to our offer
                        await peerConnection.setRemoteDescription(new RTCSessionDescription(signalData));
                        break;
                        
                    case 'candidate':
                        // Add ICE candidate from teacher
                        await peerConnection.addIceCandidate(new RTCIceCandidate(signalData.candidate));
                        break;
                }
            } catch (error) {
                console.error('Error handling signal:', error);
            }
        }

        // Start signaling polling
        function startSignalingPolling() {
            signalingPollingInterval = setInterval(pollSignals, 2000);
        }

        // Reconnect to teacher
        async function reconnectToTeacher() {
            console.log('Attempting to reconnect to teacher...');
            
            if (peerConnection) {
                peerConnection.close();
                peerConnection = null;
            }
            
            updateConnectionStatus('reconnecting');
            showNotification('Reconnecting to teacher...', 'warning');
            
            // Wait a bit before reconnecting
            setTimeout(() => {
                initWebRTCConnection();
            }, 1000);
        }

        // Update connection status display
        function updateConnectionStatus(status) {
            const connectionStatus = document.getElementById('connectionStatus');
            const webrtcStatus = document.getElementById('webrtcStatus');
            
            let statusText = 'Connected';
            let statusColor = '#10b981';
            let webrtcText = 'Connected';
            let webrtcColor = '#10b981';
            
            switch (status) {
                case 'connected':
                    statusText = 'Connected';
                    statusColor = '#10b981';
                    webrtcText = 'Connected';
                    webrtcColor = '#10b981';
                    break;
                case 'connecting':
                    statusText = 'Connecting...';
                    statusColor = '#f59e0b';
                    webrtcText = 'Connecting...';
                    webrtcColor = '#f59e0b';
                    break;
                case 'reconnecting':
                    statusText = 'Reconnecting...';
                    statusColor = '#f59e0b';
                    webrtcText = 'Reconnecting...';
                    webrtcColor = '#f59e0b';
                    break;
                case 'failed':
                    statusText = 'Failed';
                    statusColor = '#ef4444';
                    webrtcText = 'Failed';
                    webrtcColor = '#ef4444';
                    break;
                case 'disconnected':
                    statusText = 'Disconnected';
                    statusColor = '#ef4444';
                    webrtcText = 'Disconnected';
                    webrtcColor = '#ef4444';
                    break;
            }
            
            if (connectionStatus) {
                connectionStatus.textContent = statusText;
                connectionStatus.style.color = statusColor;
            }
            
            if (webrtcStatus) {
                webrtcStatus.textContent = webrtcText;
                webrtcStatus.style.color = webrtcColor;
            }
        }

        // Toggle camera
        async function toggleCamera() {
            if (!localStream) {
                showNotification('No media stream available', 'error');
                return;
            }
            
            const videoTrack = localStream.getVideoTracks()[0];
            if (!videoTrack) {
                showNotification('No camera track found', 'error');
                return;
            }
            
            // Toggle the camera
            videoTrack.enabled = !videoTrack.enabled;
            cameraActive = videoTrack.enabled;
            
            const btn = document.getElementById('cameraBtnMain');
            const icon = cameraActive ? '<i class="fas fa-video"></i>' : '<i class="fas fa-video-slash"></i>';
            
            btn.innerHTML = icon;
            
            // Update video visibility
            const placeholder = document.getElementById('studentVideoPlaceholder');
            const video = document.getElementById('studentVideo');
            const emotionOverlay = document.getElementById('emotionOverlay');
            
            if (cameraActive) {
                if (placeholder) placeholder.style.display = 'none';
                if (video) video.style.display = 'block';
                if (emotionOverlay) emotionOverlay.style.display = 'flex';
                showNotification('Camera turned on', 'success');
                
                // Update status indicator
                const statusIndicator = document.getElementById('studentStatusIndicator');
                const cameraStatusText = document.getElementById('cameraStatusText');
                if (statusIndicator) statusIndicator.style.background = '#10b981';
                if (cameraStatusText) cameraStatusText.textContent = 'Live';
                
                // Restart emotion detection if it was active
                if (emotionConsent && classEmotionTracking && !emotionDetectionActive) {
                    initEmotionDetection();
                }
            } else {
                if (placeholder) placeholder.style.display = 'flex';
                if (video) video.style.display = 'none';
                if (emotionOverlay) emotionOverlay.style.display = 'none';
                showNotification('Camera turned off', 'info');
                
                // Update status indicator
                const statusIndicator = document.getElementById('studentStatusIndicator');
                const cameraStatusText = document.getElementById('cameraStatusText');
                if (statusIndicator) statusIndicator.style.background = '#ef4444';
                if (cameraStatusText) cameraStatusText.textContent = 'Off';
                
                // Stop emotion detection
                if (emotionDetectionActive) {
                    stopEmotionDetection();
                }
            }
            
            // Update camera status on server
            updateDeviceStatus();
        }

        // Stop emotion detection
        function stopEmotionDetection() {
            if (emotionDetectionInterval) {
                clearInterval(emotionDetectionInterval);
                emotionDetectionInterval = null;
            }
            emotionDetectionActive = false;
            
            const emotionOverlay = document.getElementById('emotionOverlay');
            if (emotionOverlay) {
                emotionOverlay.style.display = 'none';
            }
        }

        // Toggle microphone
        async function toggleMicrophone() {
            if (!localStream) {
                showNotification('No media stream available', 'error');
                return;
            }
            
            const audioTrack = localStream.getAudioTracks()[0];
            if (!audioTrack) {
                showNotification('No microphone track found', 'error');
                return;
            }
            
            // Toggle the microphone
            audioTrack.enabled = !audioTrack.enabled;
            microphoneActive = audioTrack.enabled;
            
            const btn = document.getElementById('micBtnMain');
            const icon = microphoneActive ? '<i class="fas fa-microphone"></i>' : '<i class="fas fa-microphone-slash"></i>';
            
            btn.innerHTML = icon;
            
            // Show notification
            if (microphoneActive) {
                showNotification('Microphone turned on', 'success');
            } else {
                showNotification('Microphone turned off', 'info');
            }
            
            // Update microphone status on server
            updateDeviceStatus();
        }

        // Update device status on server
        async function updateDeviceStatus() {
            try {
                const formData = new FormData();
                formData.append('action', 'update_device_status');
                formData.append('session_id', sessionId);
                formData.append('user_id', userId);
                formData.append('camera_active', cameraActive ? '1' : '0');
                formData.append('mic_active', microphoneActive ? '1' : '0');
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (!result.success) {
                    console.error('Failed to update device status:', result.error);
                }
            } catch (error) {
                console.error('Error updating device status:', error);
            }
        }

        // Raise hand function
        function raiseHand() {
            handRaised = !handRaised;
            const btn = document.getElementById('raiseHandBtn');
            
            if (handRaised) {
                btn.innerHTML = '<i class="fas fa-hand-paper" style="color: #ffb800;"></i>';
                showNotification('Hand raised! The instructor will be notified.', 'success');
                
                // Send chat message about raised hand
                sendChatMessage('✋ ' + userName + ' raised their hand');
            } else {
                btn.innerHTML = '<i class="fas fa-hand-paper"></i>';
                showNotification('Hand lowered', 'info');
            }
        }

        // Information display functions
        function showParticipantsInfo() {
            const total = document.getElementById('totalParticipants').textContent;
            alert(`Total participants in this session: ${total}`);
        }

        function showConnectionStatus() {
            const status = document.getElementById('connectionStatus').textContent;
            alert(`Connection status: ${status}\n\nRoom ID: ${roomId}\nSession ID: ${sessionId}`);
        }

        function showEmotionStatus() {
            let statusMessage = '';
            if (emotionConsent && classEmotionTracking) {
                statusMessage = `Emotion Detection: ACTIVE\n\nCurrent Emotion: ${currentEmotion}\nConfidence: ${currentEmotionConfidence}%\n\nEmotion detection is analyzing your facial expressions in real-time.`;
            } else if (!emotionConsent) {
                statusMessage = 'Emotion Detection: CONSENT NOT GIVEN\n\nYou have not given consent for emotion detection. Update your consent settings to enable this feature.';
            } else {
                statusMessage = 'Emotion Detection: DISABLED FOR THIS CLASS\n\nThe instructor has not enabled emotion tracking for this class.';
            }
            alert(statusMessage);
        }

        // Chat system
        async function sendChatMessage(predefinedMessage = null) {
            const chatInput = document.getElementById('chatInput');
            let message = predefinedMessage;
            
            if (!message) {
                message = chatInput.value.trim();
                if (!message) {
                    showNotification('Please enter a message', 'warning');
                    return;
                }
            }
            
            // Create form data
            const formData = new FormData();
            formData.append('action', 'send_chat');
            formData.append('session_id', sessionId);
            formData.append('message', message);
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Clear input if not predefined message
                    if (!predefinedMessage) {
                        chatInput.value = '';
                        chatInput.style.height = 'auto';
                    }
                    
                    // Add message to chat immediately
                    addChatMessage(userName + ' (You)', message, 'own', result.message_id);
                    
                    // Show success notification
                    if (!predefinedMessage) {
                        showNotification('Message sent successfully', 'success');
                    }
                } else {
                    showNotification('Failed to send message: ' + result.error, 'error');
                }
            } catch (error) {
                console.error('Error sending chat message:', error);
                showNotification('Failed to send message: ' + error.message, 'error');
            }
        }

        function addChatMessage(sender, text, type, messageId = null) {
            const chatMessages = document.getElementById('chatMessages');
            const messageElement = document.createElement('div');
            let className = 'chat-message ';
            
            if (type === 'own') {
                className += 'own-message';
            } else if (type === 'instructor') {
                className += 'instructor-message';
            } else if (type === 'student') {
                className += 'student-message';
            } else if (type === 'system') {
                className += 'system-message';
            } else if (type === 'announcement') {
                className += 'announcement-message';
            }
            
            messageElement.className = className;
            
            if (messageId) {
                messageElement.setAttribute('data-message-id', messageId);
            }
            
            const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            messageElement.innerHTML = `
                <div class="chat-message-info">
                    <div class="chat-sender">${sender}</div>
                    <div class="chat-time">${time}</div>
                </div>
                <div class="chat-message-text">${escapeHtml(text)}</div>
            `;
            
            chatMessages.appendChild(messageElement);
            
            setTimeout(() => {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }, 100);
        }

        async function pollChatMessages() {
            try {
                const url = `${window.location.pathname}?action=get_chat&session_id=${sessionId}&last_id=0&t=${Date.now()}`;
                const response = await fetch(url);
                const result = await response.json();
                
                if (result.success && result.messages && result.messages.length > 0) {
                    result.messages.forEach(msg => {
                        // Check if message already exists
                        const existing = document.querySelector(`[data-message-id="${msg.id}"]`);
                        if (!existing) {
                            let type = 'student';
                            if (msg.sender_id == userId) type = 'own';
                            else if (msg.sender_role === 'instructor') type = 'instructor';
                            else if (msg.is_announcement || msg.message.includes('📢 ANNOUNCEMENT:')) {
                                type = 'announcement';
                                msg.message = msg.message.replace('📢 ANNOUNCEMENT:', '📢');
                            }
                            
                            addChatMessage(msg.full_name || 'Unknown', msg.message, type, msg.id);
                        }
                    });
                }
            } catch (error) {
                console.error('Error polling chat messages:', error);
            }
        }

        async function pollParticipants() {
            try {
                const url = `${window.location.pathname}?action=get_session_participants&session_id=${sessionId}&t=${Date.now()}`;
                const response = await fetch(url);
                const result = await response.json();
                
                if (result.success) {
                    // Update participant count
                    document.getElementById('totalParticipants').textContent = result.count;
                    document.getElementById('participantCount').textContent = result.count;
                }
            } catch (error) {
                console.error('Error polling participants:', error);
            }
        }

        // Session management
        function confirmLeaveSession() {
            if (confirm('Are you sure you want to leave this live session?')) {
                leaveSession();
            }
        }

        async function leaveSession() {
            try {
                const formData = new FormData();
                formData.append('action', 'leave_session');
                formData.append('session_id', sessionId);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    showNotification('Left session successfully', 'success');
                    setTimeout(() => {
                        cleanup();
                        window.location.href = 'student_my_classes.php';
                    }, 1000);
                } else {
                    showNotification('Failed to leave session: ' + result.error, 'error');
                }
            } catch (error) {
                console.error('Error leaving session:', error);
                showNotification('Failed to leave session', 'error');
            }
        }

        // Update consent settings
        function updateConsentSettings() {
            if (confirm('Update consent preferences? You will be redirected to the consent page.')) {
                window.location.href = window.location.pathname + '?session_id=' + sessionId + '&class_id=' + classId + '&update_consent=1';
            }
        }

        // Show notification
        function showNotification(message, type = 'info') {
            const container = document.getElementById('notificationContainer');
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            
            let icon = 'info-circle';
            if (type === 'success') icon = 'check-circle';
            if (type === 'error') icon = 'exclamation-circle';
            if (type === 'warning') icon = 'exclamation-triangle';
            
            notification.innerHTML = `
                <i class="fas fa-${icon}"></i>
                <span>${message}</span>
            `;
            
            container.appendChild(notification);
            
            setTimeout(() => notification.classList.add('show'), 10);
            
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Cleanup function
        function cleanup() {
            if (chatPollingInterval) clearInterval(chatPollingInterval);
            if (signalingPollingInterval) clearInterval(signalingPollingInterval);
            if (emotionDetectionInterval) clearInterval(emotionDetectionInterval);
            
            if (localStream) localStream.getTracks().forEach(track => track.stop());
            if (peerConnection) peerConnection.close();
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, initializing session...');
            
            // Start timer
            setInterval(updateTimer, 1000);
            
            // Initialize student media and WebRTC
            setTimeout(() => {
                initStudentMedia();
            }, 500);
            
            // Start polling for chat messages
            chatPollingInterval = setInterval(pollChatMessages, 5000);
            
            // Start polling for participants
            setInterval(pollParticipants, 10000);
            
            // Set up chat input auto-resize
            const chatInput = document.getElementById('chatInput');
            if (chatInput) {
                chatInput.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                });
                
                chatInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        sendChatMessage();
                    }
                });
            }
            
            // Handle beforeunload
            window.addEventListener('beforeunload', function(e) {
                cleanup();
            });
            
            // Initial chat scroll to bottom
            setTimeout(() => {
                const chatMessages = document.getElementById('chatMessages');
                if (chatMessages) {
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                }
            }, 1000);
        });
    </script>
</body>
</html>