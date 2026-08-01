<?php
/**
 * Complete reference for all REST API endpoints
 */
$pageTitle = 'API Documentation';

$endpoints = [
    'Authentication' => [
        ['method' => 'POST', 'url' => '/login.php', 'desc' => 'User login with email and password',
         'params' => ['email (required)', 'password (required)', 'remember (optional)'],
         'response' => 'Redirects to role dashboard on success'],
        ['method' => 'POST', 'url' => '/api/auth.php', 'desc' => 'Authentication actions',
         'params' => ['action: forgot_password | verify_otp | reset_password | teacher_register'],
         'response' => 'JSON: {success, message}'],
        ['method' => 'GET', 'url' => '/logout.php', 'desc' => 'Destroy session and logout',
         'params' => [], 'response' => 'Redirects to landing page'],
    ],
    'Students' => [
        ['method' => 'POST', 'url' => '/api/students.php', 'desc' => 'Add, update, or delete student',
         'params' => ['action: add | update | delete', 'student_id, first_name, last_name, age, gender, etc.'],
         'response' => 'Redirect with flash message'],
        ['method' => 'GET', 'url' => '/admin/students.php', 'desc' => 'List students with search/filter',
         'params' => ['search, grade_level, section, page'],
         'response' => 'HTML page with student list'],
    ],
    'Teachers' => [
        ['method' => 'POST', 'url' => '/api/teachers.php', 'desc' => 'Add, update, or delete teacher',
         'params' => ['action: add | update | delete', 'employee_id, first_name, last_name, email, etc.'],
         'response' => 'Redirect with flash message'],
    ],
    'Subjects & Strands' => [
        ['method' => 'POST', 'url' => '/api/subjects.php', 'desc' => 'Manage subjects and strands',
         'params' => ['action: add | update | delete | add_strand | update_strand | delete_strand'],
         'response' => 'Redirect with flash message'],
    ],
    'Gate Attendance' => [
        ['method' => 'POST', 'url' => '/api/gate.php', 'desc' => 'Gate session management',
         'params' => ['action: start_session | end_session | recognize_attendance | mark_absent'],
         'response' => 'JSON: {success, message, student_name, status, confidence}'],
    ],
    'Teacher Attendance' => [
        ['method' => 'POST', 'url' => '/api/teacher-attendance.php', 'desc' => 'Class attendance session',
         'params' => ['action: start_session | end_session | recognize_class'],
         'response' => 'JSON or redirect depending on action'],
    ],
    'Announcements' => [
        ['method' => 'POST', 'url' => '/api/announcements.php', 'desc' => 'Create and manage announcements',
         'params' => ['action: create | update | delete', 'title, body, template_type, recipients (JSON), channels (JSON)'],
         'response' => 'Redirect with flash message'],
    ],
    'Notifications' => [
        ['method' => 'POST', 'url' => '/api/notifications.php', 'desc' => 'Send notifications',
         'params' => ['action: test_email | test_sms | send_attendance_alert | send_bulk'],
         'response' => 'JSON: {success, message}'],
    ],
    'Reports' => [
        ['method' => 'GET', 'url' => '/admin/reports.php', 'desc' => 'Attendance reports with export',
         'params' => ['grade_level, section, subject_id, date_from, date_to, export=csv'],
         'response' => 'HTML report page or CSV download'],
        ['method' => 'GET', 'url' => '/teacher/reports.php', 'desc' => 'Teacher attendance analytics',
         'params' => ['subject_id, date_from, date_to'],
         'response' => 'HTML report with charts'],
    ],
    'Settings' => [
        ['method' => 'POST', 'url' => '/api/settings.php', 'desc' => 'Update system settings',
         'params' => ['setting_key, setting_value pairs'],
         'response' => 'Redirect with flash message'],
    ],
    'Face Recognition (Python)' => [
        ['method' => 'GET', 'url' => ':5000/api/health', 'desc' => 'Health check',
         'params' => [], 'response' => 'JSON: {status, service, timestamp}'],
        ['method' => 'POST', 'url' => ':5000/api/encode-face', 'desc' => 'Encode face from image',
         'params' => ['image (base64)'], 'response' => 'JSON: {encoding, status}'],
        ['method' => 'POST', 'url' => ':5000/api/recognize-face', 'desc' => 'Match face against known students',
         'params' => ['image (base64)', 'known_faces (array)'], 'response' => 'JSON: {matched, student_id, confidence}'],
        ['method' => 'POST', 'url' => ':5000/api/detect-face', 'desc' => 'Detect face locations',
         'params' => ['image (base64)'], 'response' => 'JSON: {faces, count}'],
        ['method' => 'POST', 'url' => ':5000/api/batch-encode', 'desc' => 'Encode multiple face angles',
         'params' => ['images: {front, left, right}'], 'response' => 'JSON: {encoding, faces_encoded}'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Documentation - LDB-FRAS</title>
    <link href="../assets/vendor/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/vendor/fonts/fonts.css" rel="stylesheet">

    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; }
        .doc-header { background: linear-gradient(135deg, #0A224C, #122d5e); color: white; padding: 50px 0; }
        .endpoint-card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 12px; padding: 20px; }
        .method-badge { padding: 3px 10px; border-radius: 6px; font-weight: 700; font-size: 12px; color: white; }
        .method-GET { background: #28A745; }
        .method-POST { background: #0066FE; }
        .method-PUT { background: #FFC107; color: #333; }
        .method-DELETE { background: #DC3545; }
        .section-title { font-size: 18px; font-weight: 800; color: #0A224C; margin: 30px 0 16px; padding-bottom: 8px; border-bottom: 2px solid #0A224C; }
        code { background: #f0f2f5; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
        .param-list { font-size: 13px; color: #555; }
    </style>
</head>
<body>
    <div class="doc-header">
        <div class="container">
            <h2 class="fw-800 mb-1"><i class="bi bi-code-slash me-2"></i>LDB-FRAS API Documentation</h2>
            <p style="opacity:0.6;">Liceo de Baleno Facial Recognition Attendance System — REST API Reference</p>
            <div class="mt-3">
                <span class="badge bg-light text-dark me-2">PHP 8+</span>
                <span class="badge bg-light text-dark me-2">MySQL</span>
                <span class="badge bg-light text-dark me-2">Python Flask</span>
                <span class="badge bg-light text-dark">REST API</span>
            </div>
        </div>
    </div>

    <div class="container pb-5" style="max-width:900px;">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h6 class="fw-700 mb-2">Base URLs</h6>
                <p class="mb-1" style="font-size:14px;"><strong>PHP API:</strong> <code>http://your-server/</code></p>
                <p class="mb-0" style="font-size:14px;"><strong>Python Face API:</strong> <code>http://your-server:5000/</code></p>
            </div>
        </div>

        <?php foreach ($endpoints as $section => $eps): ?>
        <h4 class="section-title"><?= $section ?></h4>
        <?php foreach ($eps as $ep): ?>
        <div class="endpoint-card">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="method-badge method-<?= $ep['method'] ?>"><?= $ep['method'] ?></span>
                <code style="font-size:14px;font-weight:600;"><?= $ep['url'] ?></code>
            </div>
            <p class="mb-2" style="font-size:14px;"><?= $ep['desc'] ?></p>
            <?php if (!empty($ep['params'])): ?>
                <div class="param-list"><strong>Parameters:</strong> <?= implode(', ', $ep['params']) ?></div>
            <?php endif; ?>
            <div class="param-list"><strong>Response:</strong> <?= $ep['response'] ?></div>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</body>
</html>
