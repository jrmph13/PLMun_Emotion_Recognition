<?php
// export_students.php
require_once 'config.php';
requireInstructor();

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=students_' . date('Y-m-d') . '.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Write CSV headers
fputcsv($output, [
    'Student Number',
    'Full Name',
    'Email',
    'Course',
    'Year Level',
    'Classes Enrolled',
    'Sessions Attended',
    'Average Engagement',
    'Status',
    'Last Session'
]);

// Fetch students data (similar to main query)
// ... (same query logic as teacher_students.php)

// Write data rows
foreach ($students as $student) {
    fputcsv($output, [
        $student['student_number'],
        $student['full_name'],
        $student['email'],
        $student['course'],
        $student['year_level'],
        $student['total_classes'],
        $student['total_sessions_attended'],
        round($student['avg_engagement'], 1) . '%',
        $student['is_active'] ? 'Active' : 'Inactive',
        $student['last_session_date'] ? date('Y-m-d', strtotime($student['last_session_date'])) : 'Never'
    ]);
}

fclose($output);
exit();