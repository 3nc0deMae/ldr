<?php
/**
 * End-user guide for Admin, Teacher, and Gate Personnel
 */

$sections = [
    'getting-started' => [
        'title' => 'Getting Started',
        'icon'  => 'bi-rocket',
        'content' => '
            <h5>Welcome to LDB-FRAS</h5>
            <p>The <strong>Liceo de Baleno Facial Recognition Attendance System</strong> is a modern, contactless attendance solution that uses facial recognition technology to automatically record student attendance.</p>
            <h6>System Requirements</h6>
            <ul>
                <li>Modern web browser (Chrome, Firefox, Edge, Safari)</li>
                <li>Webcam or camera device for face scanning</li>
                <li>Internet connection for SMS/email notifications</li>
            </ul>
            <h6>First Time Login</h6>
            <ol>
                <li>Navigate to the system URL in your browser</li>
                <li>Select your user type: Admin, Teacher, or Gate Personnel</li>
                <li>Enter your email and password</li>
                <li>Click "Sign In" to access your dashboard</li>
            </ol>
            <h6>Default Admin Credentials</h6>
            <p><strong>Email:</strong> admin@liceodebaleno.edu.ph<br>
            <strong>Password:</strong> Admin@2026<br>
            <em>Change this password immediately after first login!</em></p>
        '
    ],
    'admin-dashboard' => [
        'title' => 'Admin Dashboard',
        'icon'  => 'bi-speedometer2',
        'content' => '
            <h5>Admin Dashboard Overview</h5>
            <p>The admin dashboard provides a bird\'s-eye view of the entire attendance system.</p>
            <h6>Statistics Cards</h6>
            <ul>
                <li><strong>Total Students</strong> — Number of registered students</li>
                <li><strong>Total Teachers</strong> — Number of active teachers</li>
                <li><strong>Total Subjects</strong> — Number of configured subjects</li>
                <li><strong>Present/Absent/Late Today</strong> — Real-time daily attendance counts</li>
            </ul>
            <h6>Charts</h6>
            <ul>
                <li><strong>Daily Attendance</strong> — Bar chart showing attendance trends over the past week</li>
                <li><strong>Monthly Overview</strong> — Line chart with monthly attendance patterns</li>
                <li><strong>Attendance Rate</strong> — Doughnut chart showing present/absent/late ratio</li>
            </ul>
            <h6>Recent Activities</h6>
            <p>Shows latest logins, attendance sessions, new students, and system events.</p>
        '
    ],
    'student-management' => [
        'title' => 'Student Management',
        'icon'  => 'bi-people',
        'content' => '
            <h5>Managing Students</h5>
            <h6>View Student List</h6>
            <p>Navigate to <strong>Management > Students</strong> in the sidebar. You can:</p>
            <ul>
                <li>Search by name or student ID</li>
                <li>Filter by grade level and section</li>
                <li>See face registration status (green = registered, red = not registered)</li>
                <li>Paginate through large student lists</li>
            </ul>
            <h6>Add New Student</h6>
            <ol>
                <li>Click <strong>"Add Student"</strong> button</li>
                <li>Fill in personal information: Student ID, Name, Age, Gender, Address, Email</li>
                <li>Select Grade Level (7-12) and Section</li>
                <li>Fill in guardian information: Name, Relationship, Phone, Email, Address</li>
                <li>Click <strong>"Save Student"</strong></li>
            </ol>
            <h6>Register Face (3-Angle Capture)</h6>
            <ol>
                <li>Click <strong>"Edit"</strong> on a student record</li>
                <li>Go to the <strong>"Face Registration"</strong> tab</li>
                <li>Allow camera access when prompted</li>
                <li>Capture <strong>Front</strong>, <strong>Left</strong>, and <strong>Right</strong> face angles</li>
                <li>Click <strong>"Register Face"</strong> to save the face encoding</li>
            </ol>
            <h6>Edit / Delete Student</h6>
            <p>Use the action buttons in the student list to edit or remove records. Deletion is permanent.</p>
        '
    ],
    'teacher-management' => [
        'title' => 'Teacher Management',
        'icon'  => 'bi-person-badge',
        'content' => '
            <h5>Managing Teachers</h5>
            <p>Navigate to <strong>Management > Teachers</strong>.</p>
            <h6>Add Teacher</h6>
            <ol>
                <li>Click <strong>"Add Teacher"</strong></li>
                <li>Fill in: Employee ID, First Name, Last Name, Email, Phone</li>
                <li>Enter Department, Subjects Handled, Advisory Class</li>
                <li>Set a temporary password (default: 123456)</li>
                <li>Click <strong>"Save"</strong></li>
            </ol>
            <h6>Teacher Self-Registration</h6>
            <p>Teachers can also register themselves at <code>/register.php</code> using their school email. They must already exist in the teacher database.</p>
        '
    ],
    'gate-attendance' => [
        'title' => 'Gate Attendance',
        'icon'  => 'bi-door-open',
        'content' => '
            <h5>Gate Attendance System</h5>
            <p>Gate personnel manage the school entrance scanning sessions.</p>
            <h6>Start Time-In Session</h6>
            <ol>
                <li>Navigate to <strong>Gate Operations > Start Time-In</strong></li>
                <li>Configure session: Start Time, End Time, Late Threshold (minutes)</li>
                <li>Click <strong>"Start Session"</strong></li>
                <li>Allow camera access</li>
                <li>Click <strong>"Start Scanning"</strong> — the system auto-scans every 3 seconds</li>
                <li>Each recognized student appears in the live log with status (Present/Late)</li>
            </ol>
            <h6>End Session</h6>
            <ol>
                <li>Click <strong>"End Session"</strong></li>
                <li>The system automatically marks unscanned students as <strong>Absent</strong></li>
                <li>SMS/email notifications are sent to parents of absent students</li>
            </ol>
            <h6>Time-Out Session</h6>
            <p>Similar to Time-In but for end-of-day departure tracking. Navigate to <strong>Start Time-Out</strong>.</p>
            <h6>Gate Logs</h6>
            <p>View historical session data and attendance records under <strong>Gate Logs</strong>.</p>
        '
    ],
    'teacher-attendance' => [
        'title' => 'Teacher Attendance',
        'icon'  => 'bi-camera-video',
        'content' => '
            <h5>Class Attendance (Teacher)</h5>
            <h6>Take Attendance</h6>
            <ol>
                <li>Navigate to <strong>Attendance > Take Attendance</strong></li>
                <li>Select: Subject, Grade Level, Section</li>
                <li>Set Time Limit and Late threshold</li>
                <li>Click <strong>"Start Session"</strong></li>
                <li>Point camera at students — auto-scans every 3 seconds</li>
                <li>Each scanned student is logged as Present or Late</li>
                <li>Click <strong>"End Session"</strong> when done</li>
            </ol>
            <h6>View Records</h6>
            <p>Go to <strong>Attendance Records</strong> to see past attendance with filters by date, subject, and status.</p>
            <h6>Reports</h6>
            <p>The <strong>Reports</strong> page shows analytics: total sessions, average attendance rate, perfect attendance students, and low attendance alerts.</p>
        '
    ],
    'announcements' => [
        'title' => 'Announcements',
        'icon'  => 'bi-megaphone',
        'content' => '
            <h5>Announcement System</h5>
            <h6>Create Announcement</h6>
            <ol>
                <li>Navigate to <strong>Communication > Announcements</strong></li>
                <li>Choose a template: Class Suspension, School Event, Achievement, Misconduct, Meeting, or General</li>
                <li>Edit the subject and body (rich text editor supported)</li>
                <li>Select recipients: All Parents or specific Grade levels</li>
                <li>Choose delivery channels: SMS, Email, or both</li>
                <li>Optionally schedule for later delivery</li>
                <li>Click <strong>"Send Announcement"</strong></li>
            </ol>
        '
    ],
    'settings' => [
        'title' => 'Settings',
        'icon'  => 'bi-gear',
        'content' => '
            <h5>System Settings</h5>
            <p>Navigate to <strong>System > Settings</strong>. Settings are organized in tabs:</p>
            <h6>School Information</h6>
            <p>School name, address, school year, principal name, contact number.</p>
            <h6>SMTP / Email Configuration</h6>
            <p>Enable email notifications, configure SMTP host/port, username/password, from email. Use <strong>"Test Email"</strong> to verify.</p>
            <h6>SMS Configuration</h6>
            <p>Enable SMS, set Semaphore API key, sender ID. Use <strong>"Test SMS"</strong> to verify.</p>
            <h6>Attendance Rules</h6>
            <p>Default late threshold, gate session times, face match confidence, auto-mark absent toggle.</p>
        '
    ],
    'reports' => [
        'title' => 'Reports',
        'icon'  => 'bi-file-earmark-bar-graph',
        'content' => '
            <h5>Attendance Reports</h5>
            <h6>Admin Reports</h6>
            <p>Navigate to <strong>Attendance > Reports</strong>. Filter by grade, section, subject, and date range.</p>
            <ul>
                <li><strong>Charts:</strong> Doughnut chart (present/late/absent breakdown), stacked bar (daily trend)</li>
                <li><strong>Export:</strong> Click "Export Excel" for CSV download, or "Print/PDF" for printable report</li>
            </ul>
            <h6>Teacher Reports</h6>
            <p>Teachers see their own class analytics: session count, average attendance, perfect/low attendance student lists, and trend charts.</p>
        '
    ],
    'troubleshooting' => [
        'title' => 'Troubleshooting',
        'icon'  => 'bi-wrench',
        'content' => '
            <h5>Common Issues</h5>
            <h6>Camera not working</h6>
            <ul>
                <li>Ensure browser has camera permission (check address bar lock icon)</li>
                <li>Use Chrome or Edge for best compatibility</li>
                <li>Check that no other application is using the camera</li>
            </ul>
            <h6>Face not recognized</h6>
            <ul>
                <li>Ensure student face is registered (3 angles: front, left, right)</li>
                <li>Good lighting improves recognition accuracy</li>
                <li>Face should be clearly visible, no masks or sunglasses</li>
                <li>Check face match confidence threshold in Settings</li>
            </ul>
            <h6>Notifications not sending</h6>
            <ul>
                <li>Verify SMTP settings in Admin > Settings > Email</li>
                <li>Verify Semaphore API key in Settings > SMS</li>
                <li>Use "Test Email" and "Test SMS" buttons to verify</li>
            </ul>
            <h6>Python API not responding</h6>
            <ul>
                <li>Check service: <code>sudo systemctl status face-recognition</code></li>
                <li>Restart: <code>sudo systemctl restart face-recognition</code></li>
                <li>Check logs: <code>sudo journalctl -u face-recognition -f</code></li>
            </ul>
        '
    ],
];

$activeSection = $_GET['section'] ?? 'getting-started';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Manual - LDB-FRAS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; }
        .doc-header { background: linear-gradient(135deg, #0A224C, #122d5e); color: white; padding: 50px 0; }
        .sidebar-nav { position: sticky; top: 20px; }
        .sidebar-nav .nav-link { padding: 8px 16px; border-radius: 8px; font-size: 13px; color: #555; }
        .sidebar-nav .nav-link.active { background: rgba(0,102,254,0.08); color: #0066FE; font-weight: 600; }
        .sidebar-nav .nav-link:hover { background: #f0f2f5; }
        .content-area { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .content-area h5 { color: #0A224C; font-weight: 800; margin-bottom: 16px; }
        .content-area h6 { color: #0A224C; font-weight: 700; margin-top: 20px; margin-bottom: 8px; }
    </style>
</head>
<body>
    <div class="doc-header">
        <div class="container">
            <h2 class="fw-800 mb-1"><i class="bi bi-book me-2"></i>LDB-FRAS User Manual</h2>
            <p style="opacity:0.6;">Complete guide for administrators, teachers, and gate personnel</p>
        </div>
    </div>

    <div class="container pb-5">
        <div class="row g-4">
            <div class="col-md-3">
                <div class="sidebar-nav">
                    <h6 class="fw-700 px-3 mb-2" style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#999;">Sections</h6>
                    <nav class="nav flex-column">
                        <?php foreach ($sections as $key => $sec): ?>
                        <a class="nav-link <?= $activeSection === $key ? 'active' : '' ?>"
                           href="?section=<?= $key ?>">
                            <i class="bi <?= $sec['icon'] ?> me-2"></i><?= $sec['title'] ?>
                        </a>
                        <?php endforeach; ?>
                    </nav>
                </div>
            </div>
            <div class="col-md-9">
                <div class="content-area">
                    <?php if (isset($sections[$activeSection])): ?>
                        <?= $sections[$activeSection]['content'] ?>
                    <?php else: ?>
                        <h5>Section Not Found</h5>
                        <p>Please select a section from the sidebar.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
