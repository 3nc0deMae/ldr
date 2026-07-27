<?php
/**
 * System architecture, technology stack, and developer reference
 */

$sections = [
    'architecture' => [
        'title' => 'System Architecture',
        'icon'  => 'bi-diagram-3',
        'content' => '
            <h5>System Architecture Overview</h5>
            <p>LDB-FRAS follows a three-tier architecture with a PHP web application, Python face recognition microservice, and MySQL database.</p>
            <h6>Architecture Flow</h6>
            <pre class="bg-light p-3 rounded" style="font-size:13px;">
Camera (WebRTC)
    ↓
JavaScript captures frame → base64 image
    ↓
PHP Backend (AJAX POST)
    ↓
Python Flask API (Face Recognition)
    ↓
MySQL Database (Store attendance)
    ↓
Email/SMS Service (Notify parents)</pre>

            <h6>Component Diagram</h6>
            <table class="table table-sm">
                <thead><tr><th>Component</th><th>Technology</th><th>Port</th></tr></thead>
                <tbody>
                    <tr><td>Web Application</td><td>PHP 8+ / Apache</td><td>80/443</td></tr>
                    <tr><td>Face Recognition</td><td>Python Flask</td><td>5000</td></tr>
                    <tr><td>Database</td><td>MySQL 8+</td><td>3306</td></tr>
                    <tr><td>Frontend</td><td>Bootstrap 5 / Chart.js</td><td>—</td></tr>
                    <tr><td>Email</td><td>SMTP / PHP mail()</td><td>587</td></tr>
                    <tr><td>SMS</td><td>Semaphore API</td><td>443</td></tr>
                </tbody>
            </table>
        '
    ],
    'directory-structure' => [
        'title' => 'Directory Structure',
        'icon'  => 'bi-folder',
        'content' => '
            <h5>Project Directory Layout</h5>
            <pre class="bg-light p-3 rounded" style="font-size:13px;">
ldb-fras/
├── admin/                  # Admin dashboard and management pages
│   ├── index.php           # Admin dashboard with stats and charts
│   ├── students.php        # Student list with search/filter
│   ├── student-add.php     # Add student form
│   ├── student-edit.php    # Edit student + face registration
│   ├── teachers.php        # Teacher management
│   ├── subjects.php        # Subject management
│   ├── strands.php         # Strand management
│   ├── announcements.php   # Announcement system
│   ├── reports.php         # Attendance reports (admin)
│   ├── settings.php        # System settings
│   └── audit-logs.php      # Security audit trail
├── api/                    # Backend API endpoints
│   ├── auth.php            # Authentication (OTP, reset, register)
│   ├── students.php        # Student CRUD
│   ├── teachers.php        # Teacher CRUD
│   ├── subjects.php        # Subject/strand CRUD
│   ├── gate.php            # Gate session management
│   ├── teacher-attendance.php  # Class attendance sessions
│   ├── announcements.php   # Announcement actions
│   ├── notifications.php   # Notification sending
│   ├── reports.php         # Report data
│   └── settings.php        # Settings update
├── assets/
│   ├── css/style.css       # Custom CSS design system
│   └── js/app.js           # Shared JavaScript
├── gate/                   # Gate personnel pages
│   ├── timein.php          # Time-in scanning session
│   ├── timeout.php         # Time-out scanning session
│   └── logs.php            # Gate session history
├── includes/               # Shared PHP modules
│   ├── db.php              # Database connection (PDO)
│   ├── functions.php       # Core CRUD functions
│   ├── session.php         # Session/auth management
│   ├── security.php        # Security utilities
│   ├── notifications.php   # Email/SMS helpers
│   ├── face_api.php        # Python API bridge
│   ├── header.php          # HTML header template
│   ├── sidebar.php         # Navigation sidebar
│   └── footer.php          # HTML footer template
├── python/                 # Face recognition service
│   ├── app.py              # Flask API application
│   └── requirements.txt    # Python dependencies
├── teacher/                # Teacher pages
│   ├── attendance.php      # Take class attendance
│   ├── records.php         # View attendance records
│   └── reports.php         # Teacher analytics
├── tests/                  # Testing (Phase 19)
│   ├── bootstrap.php       # Test framework
│   ├── test-runner.php     # Visual test dashboard
│   ├── sus-survey.php      # SUS questionnaire
│   ├── unit/               # Unit tests
│   └── integration/        # Integration tests
├── deploy/                 # Deployment (Phase 20)
│   ├── deploy.sh           # Deployment script
│   ├── setup.sh            # Server setup script
│   ├── .htaccess.production
│   ├── ldb-fras.conf       # Apache virtual host
│   └── face-recognition.service  # systemd unit
├── docs/                   # Documentation (Phase 20)
├── uploads/faces/          # Student face images
├── config.php              # Main configuration
├── database.sql            # Database schema
├── index.php               # Landing page
├── login.php               # Login page
├── register.php            # Teacher registration
├── forgot-password.php     # Password recovery
├── logout.php              # User logout
└── .htaccess               # Apache rewrite rules</pre>
        '
    ],
    'database' => [
        'title' => 'Database Schema',
        'icon'  => 'bi-database',
        'content' => '
            <h5>MySQL Database Design</h5>
            <h6>Core Tables</h6>
            <table class="table table-sm">
                <thead><tr><th>Table</th><th>Description</th><th>Key Relations</th></tr></thead>
                <tbody>
                    <tr><td><code>users</code></td><td>All system accounts (admin, teacher, gate, parent)</td><td>—</td></tr>
                    <tr><td><code>students</code></td><td>Student personal/academic info</td><td>grade_level, section</td></tr>
                    <tr><td><code>guardians</code></td><td>Parent/guardian contact info</td><td>FK → students.id</td></tr>
                    <tr><td><code>student_faces</code></td><td>Face images and encodings</td><td>FK → students.id</td></tr>
                    <tr><td><code>teachers</code></td><td>Teacher profiles</td><td>FK → users.id</td></tr>
                    <tr><td><code>subjects</code></td><td>Course subjects</td><td>grade_level</td></tr>
                    <tr><td><code>strands</code></td><td>SHS academic strands</td><td>—</td></tr>
                    <tr><td><code>attendance</code></td><td>Class attendance records</td><td>FK → students.id, subjects.id</td></tr>
                    <tr><td><code>gate_sessions</code></td><td>Gate scanning sessions</td><td>created_by → users.id</td></tr>
                    <tr><td><code>attendance_records</code></td><td>Gate attendance records</td><td>FK → students.id, gate_sessions.id</td></tr>
                    <tr><td><code>announcements</code></td><td>School announcements</td><td>created_by → users.id</td></tr>
                    <tr><td><code>notifications</code></td><td>Notification log</td><td>—</td></tr>
                    <tr><td><code>settings</code></td><td>System configuration (key-value)</td><td>—</td></tr>
                    <tr><td><code>audit_logs</code></td><td>Security audit trail</td><td>FK → users.id</td></tr>
                </tbody>
            </table>
            <h6>Import Database</h6>
            <pre class="bg-light p-3 rounded" style="font-size:13px;">mysql -u root -p < database.sql</pre>
        '
    ],
    'security' => [
        'title' => 'Security Features',
        'icon'  => 'bi-shield-lock',
        'content' => '
            <h5>Security Implementation (Phase 18)</h5>
            <h6>Authentication</h6>
            <ul>
                <li><strong>Bcrypt password hashing</strong> — cost factor 12</li>
                <li><strong>Session management</strong> — session_regenerate_id() on login</li>
                <li><strong>Session timeout</strong> — 30-minute inactivity auto-logout</li>
                <li><strong>Role-based access control</strong> — admin, teacher, gate, parent</li>
                <li><strong>Remember me</strong> — secure token stored in HTTP-only cookie</li>
            </ul>
            <h6>CSRF Protection</h6>
            <ul>
                <li>Token generated per session and embedded in meta tag</li>
                <li>Validated on all POST requests via csrfMiddleware()</li>
                <li>Use <code>csrfField()</code> helper in forms</li>
            </ul>
            <h6>Input Validation</h6>
            <ul>
                <li><code>sanitize()</code> — htmlspecialchars + strip_tags</li>
                <li><code>validateRequired()</code>, <code>validateLength()</code>, <code>validateNumeric()</code></li>
                <li>Prepared statements (PDO) prevent SQL injection</li>
            </ul>
            <h6>Rate Limiting</h6>
            <ul>
                <li>File-based sliding window counter</li>
                <li>API: 60 requests per 60 seconds</li>
                <li>Login: 5 attempts per 300 seconds</li>
            </ul>
            <h6>HTTP Security Headers</h6>
            <ul>
                <li>X-Content-Type-Options: nosniff</li>
                <li>X-Frame-Options: SAMEORIGIN</li>
                <li>X-XSS-Protection: 1; mode=block</li>
                <li>Strict-Transport-Security (HSTS)</li>
            </ul>
        '
    ],
    'face-recognition' => [
        'title' => 'Face Recognition Engine',
        'icon'  => 'bi-camera',
        'content' => '
            <h5>Python Face Recognition Service</h5>
            <p>The face recognition engine runs as a standalone Flask microservice on port 5000.</p>
            <h6>Technology</h6>
            <ul>
                <li><strong>face_recognition</strong> library (dlib-based)</li>
                <li><strong>OpenCV</strong> for image decoding</li>
                <li><strong>NumPy</strong> for encoding operations</li>
            </ul>
            <h6>Endpoints</h6>
            <table class="table table-sm">
                <thead><tr><th>Endpoint</th><th>Method</th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>/api/health</code></td><td>GET</td><td>Health check</td></tr>
                    <tr><td><code>/api/encode-face</code></td><td>POST</td><td>Encode face from base64 image</td></tr>
                    <tr><td><code>/api/recognize-face</code></td><td>POST</td><td>Match against known faces</td></tr>
                    <tr><td><code>/api/detect-face</code></td><td>POST</td><td>Detect face locations</td></tr>
                    <tr><td><code>/api/batch-encode</code></td><td>POST</td><td>Encode front/left/right angles</td></tr>
                </tbody>
            </table>
            <h6>Recognition Process</h6>
            <ol>
                <li>JavaScript captures webcam frame as base64</li>
                <li>PHP sends base64 + known face encodings to Python API</li>
                <li>Python decodes image, detects face, encodes it</li>
                <li>Compares against all student encodings (tolerance: 0.6)</li>
                <li>Returns best match with confidence score</li>
                <li>PHP records attendance if confidence > threshold</li>
            </ol>
            <h6>Start Service</h6>
            <pre class="bg-light p-3 rounded" style="font-size:13px;">cd python && python3 app.py
# Or as systemd service:
sudo systemctl start face-recognition</pre>
        '
    ],
    'deployment' => [
        'title' => 'Deployment Guide',
        'icon'  => 'bi-cloud-upload',
        'content' => '
            <h5>Production Deployment</h5>
            <h6>Server Requirements</h6>
            <ul>
                <li>Ubuntu 20.04+ or Debian 11+</li>
                <li>2GB RAM minimum (4GB recommended)</li>
                <li>20GB disk space</li>
                <li>Apache 2.4+ with mod_rewrite</li>
                <li>PHP 8.2+ with PDO, cURL, GD, mbstring</li>
                <li>MySQL 8.0+</li>
                <li>Python 3.8+ with pip</li>
            </ul>
            <h6>Deployment Steps</h6>
            <ol>
                <li>Run <code>sudo bash deploy/setup.sh</code> to install server dependencies</li>
                <li>Copy project files to <code>/var/www/ldb-fras/</code></li>
                <li>Run <code>sudo bash deploy/deploy.sh</code> to configure Apache and services</li>
                <li>Import database: <code>mysql -u root -p < database.sql</code></li>
                <li>Update database credentials in <code>includes/db.php</code></li>
                <li>Configure SMTP and SMS in Admin → Settings</li>
                <li>Install SSL: <code>sudo certbot --apache -d your-domain.com</code></li>
                <li>Change default admin password</li>
            </ol>
            <h6>Environment Configuration</h6>
            <p>Edit <code>config.php</code> for production:</p>
            <ul>
                <li>Set <code>display_errors</code> to 0</li>
                <li>Update <code>PYTHON_API_URL</code> if not localhost</li>
                <li>Change <code>PYTHON_API_KEY</code> to a strong random key</li>
            </ul>
        '
    ],
];

$activeSection = $_GET['section'] ?? 'architecture';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technical Documentation - LDB-FRAS</title>
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
        pre { white-space: pre-wrap; word-wrap: break-word; }
    </style>
</head>
<body>
    <div class="doc-header">
        <div class="container">
            <h2 class="fw-800 mb-1"><i class="bi bi-cpu me-2"></i>LDB-FRAS Technical Documentation</h2>
            <p style="opacity:0.6;">System architecture, database design, security, and deployment reference</p>
            <div class="mt-3">
                <span class="badge bg-light text-dark me-2">Version 1.0.0</span>
                <span class="badge bg-light text-dark me-2">PHP 8.2+</span>
                <span class="badge bg-light text-dark me-2">MySQL 8.0+</span>
                <span class="badge bg-light text-dark me-2">Python 3.8+</span>
                <span class="badge bg-light text-dark">Apache 2.4+</span>
            </div>
        </div>
    </div>

    <div class="container pb-5">
        <div class="row g-4">
            <div class="col-md-3">
                <div class="sidebar-nav">
                    <h6 class="fw-700 px-3 mb-2" style="font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#999;">Documentation</h6>
                    <nav class="nav flex-column">
                        <?php foreach ($sections as $key => $sec): ?>
                        <a class="nav-link <?= $activeSection === $key ? 'active' : '' ?>"
                           href="?section=<?= $key ?>">
                            <i class="bi <?= $sec['icon'] ?> me-2"></i><?= $sec['title'] ?>
                        </a>
                        <?php endforeach; ?>
                    </nav>
                    <hr>
                    <nav class="nav flex-column">
                        <a class="nav-link" href="API-Documentation.php"><i class="bi bi-code-slash me-2"></i>API Docs</a>
                        <a class="nav-link" href="User-Manual.php"><i class="bi bi-book me-2"></i>User Manual</a>
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
