<?php

require_once __DIR__ . '/../config.php';
requireRole(['admin', 'teacher', 'gate']);

$studentId = intval($_GET['id'] ?? 0);
$activeTab = sanitize($_GET['tab'] ?? 'info');
$currentRole = getCurrentUserRole();
$isReadOnly = $currentRole !== 'admin';

if (!$studentId) {
    redirect('/admin/students.php', 'Invalid student ID.', 'danger');
}

$student = getStudentById($db, $studentId);
if (!$student) {
    redirect('/admin/students.php', 'Student not found.', 'danger');
}

// Safe defaults — prevents "Undefined array key" warnings
$student['name_extension']   = $student['name_extension'] ?? '';
$student['middle_name']      = $student['middle_name'] ?? '';
$student['age']              = $student['age'] ?? '';
$student['address']          = $student['address'] ?? '';
$student['email']            = $student['email'] ?? '';
$student['section']          = $student['section'] ?? '';
$student['photo']            = $student['photo'] ?? '';
$student['guardian_name']    = $student['guardian_name'] ?? '';
$student['relationship']     = $student['relationship'] ?? '';
$student['guardian_phone']   = $student['guardian_phone'] ?? '';
$student['guardian_email']   = $student['guardian_email'] ?? '';
$student['guardian_address'] = $student['guardian_address'] ?? '';

$gradeSectionsMap = [];
try {
    $stmt = $db->query("SELECT grade_level, section_name FROM sections ORDER BY CAST(grade_level AS UNSIGNED), section_name");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $gradeSectionsMap[$row['grade_level']][] = $row['section_name'];
    }
} catch (Exception $e) {}

$errors = [];

// Handle update — BEFORE any output (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentRole === 'admin' && isset($_POST['update_info'])) {
    $data = [
        'name_extension' => sanitize($_POST['name_extension'] ?? ''),
        'first_name'     => sanitize($_POST['first_name'] ?? ''),
        'middle_name'    => sanitize($_POST['middle_name'] ?? ''),
        'last_name'      => sanitize($_POST['last_name'] ?? ''),
        'age'            => intval($_POST['age'] ?? 0),
        'gender'         => sanitize($_POST['gender'] ?? ''),
        'address'        => sanitize($_POST['address'] ?? ''),
        'email'          => sanitize($_POST['email'] ?? ''),
        'grade_level'    => sanitize($_POST['grade_level'] ?? ''),
        'section'        => sanitize($_POST['section'] ?? '')
    ];

    if (empty($data['first_name']))  $errors[] = 'First name is required.';
    if (empty($data['last_name']))   $errors[] = 'Last name is required.';
    if (empty($data['gender']))      $errors[] = 'Gender is required.';
    if (empty($data['grade_level'])) $errors[] = 'Grade level is required.';

    $guardianPhone = trim($_POST['guardian_phone'] ?? '');
    if (!empty($guardianPhone) && !isValidPhilippinePhone($guardianPhone)) {
        $errors[] = 'Guardian phone number must start with +63 and contain 11 digits only.';
    }

    if (empty($errors)) {
        if (updateStudent($db, $studentId, $data)) {
            saveGuardian($db, $studentId, [
                'guardian_name' => sanitize($_POST['guardian_name'] ?? ''),
                'relationship'  => sanitize($_POST['relationship'] ?? ''),
                'phone'         => normalizePhilippinePhone($_POST['guardian_phone'] ?? ''),
                'email'         => sanitize($_POST['guardian_email'] ?? ''),
                'address'       => sanitize($_POST['guardian_address'] ?? '')
            ]);
            redirect("/admin/student-edit.php?id=$studentId", 'Student updated successfully.', 'success');
        } else {
            $errors[] = 'Failed to update student.';
        }
    }

    $student = array_merge($student, $data);
}

$hasFace = false;
try {
    $stmt = $db->prepare("SELECT * FROM student_faces WHERE student_id = ?");
    $stmt->execute([$studentId]);
    $faceData = $stmt->fetch();
    $hasFace = $faceData && !empty($faceData['face_encoding']);
} catch (Exception $e) {
    $faceData = null;
}

// Now include header and sidebar
$pageTitle = $isReadOnly ? 'Student Profile' : 'Edit Student';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- ===== GLOBAL THEME ===== -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">

<!-- ============================================================ -->
<!-- ===== PAGE-SPECIFIC STYLES ================================== -->
<!-- ============================================================ -->
<style>

    /* ---- Content Spacing ---- */
    .content-area { padding: 24px 28px; }

    /* =====================================================
       NAVBAR
       ===================================================== */
    .top-navbar {
        padding: 16px 28px;
        display: flex; justify-content: space-between;
        align-items: center; gap: 16px; flex-wrap: wrap;
        position: sticky; top: 0; z-index: 100;
    }
    .navbar-left { display: flex; align-items: center; gap: 16px; min-width: 0; }
    .page-title h5 { font-size: 20px; font-weight: 800; letter-spacing: -0.03em; margin: 0; }
    .page-title small { font-size: 13px; font-weight: 500; }
    .navbar-brand-mob { display: none; align-items: center; gap: 10px; min-width: 0; flex: 1; }
    .navbar-brand-logo { border-radius: 10px; object-fit: contain; background: rgba(255,255,255,0.08); padding: 3px; flex-shrink: 0; }
    .navbar-brand-text { display: flex; flex-direction: column; line-height: 1.25; min-width: 0; }
    .navbar-brand-name { font-size: 13px; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .navbar-brand-sub { font-size: 9px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    #sidebarToggle { width: 40px; height: 40px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s cubic-bezier(0.4,0,0.2,1); font-size: 18px; flex-shrink: 0; background: transparent; border: none; color: inherit; }
    #sidebarToggle:hover { transform: translateY(-1px); opacity: 0.8; }
    .navbar-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .nav-action-icon { width: 36px; height: 36px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 15px; transition: all 0.2s cubic-bezier(0.4,0,0.2,1); flex-shrink: 0; text-decoration: none; }
    .nav-action-icon:hover { transform: translateY(-1px); }

    /* =====================================================
       PAGE HEADER (mobile only)
       ===================================================== */
    .page-header-section { padding: 0 0 16px; }
    .page-header-section h5 { font-size: 20px; font-weight: 800; letter-spacing: -0.03em; margin: 0; }
    .page-header-section .mobile-student-meta { font-size: 12px; font-weight: 500; margin-top: 4px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

    /* =====================================================
       BUTTONS
       ===================================================== */
    .btn-theme {
        font-weight: 600; border-radius: 10px; padding: 9px 18px; font-size: 13px;
        transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 6px;
        cursor: pointer; text-decoration: none; line-height: 1.4; white-space: nowrap;
        border: 1px solid transparent; font-family: inherit;
    }
    .btn-theme-lg { padding: 12px 28px; font-size: 14px; border-radius: 12px; }
    .btn-theme-primary {
        background: rgba(0,102,254,0.5); border-color: rgba(0,102,254,0.6); color: #fff;
        box-shadow: 0 4px 12px rgba(0,102,254,0.2);
    }
    .btn-theme-primary:hover {
        background: rgba(0,102,254,0.65); border-color: rgba(0,102,254,0.8); color: #fff;
        transform: translateY(-1px); box-shadow: 0 6px 16px rgba(0,102,254,0.3); text-decoration: none;
    }
    .btn-theme-success {
        background: rgba(16,185,129,0.5); border-color: rgba(16,185,129,0.6); color: #fff;
        box-shadow: 0 4px 12px rgba(16,185,129,0.2);
    }
    .btn-theme-success:hover {
        background: rgba(16,185,129,0.65); border-color: rgba(16,185,129,0.8); color: #fff;
        transform: translateY(-1px); text-decoration: none;
    }
    .btn-theme-danger {
        background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.3); color: #F87171;
    }
    .btn-theme-danger:hover {
        background: rgba(239,68,68,0.25); border-color: rgba(239,68,68,0.4); color: #FCA5A5; text-decoration: none;
    }
    .btn-theme-outline {
        background: rgba(255,255,255,0.06); border-color: rgba(255,255,255,0.15);
        color: rgba(255,255,255,0.65);
    }
    .btn-theme-outline:hover {
        background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,0.25);
        color: #fff; text-decoration: none;
    }
    .btn-theme-back {
        background: #3b82f6; border-color: #3b82f6; color: #fff;
        transition: all 0.2s ease;
    }
    .btn-theme-back:hover {
        background: #2563eb; border-color: #2563eb; color: #fff;
        transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,0.35);
        text-decoration: none;
    }
    .btn-theme:disabled { opacity: 0.4; pointer-events: none; cursor: not-allowed; }
    .btn-theme .spinner-border { width: 14px; height: 14px; border-width: 2px; }

    /* =====================================================
       FORM
       ===================================================== */
    .form-section-title { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: #ffffff; }
    .form-section-title i { color: #60A5FA; font-size: 16px; }
    .form-field-label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: rgba(255,255,255,0.45); margin-bottom: 6px; }
    .form-field-label .req { color: #F87171; margin-left: 2px; }
    .form-field-label .optional { color: rgba(255,255,255,0.25); font-weight: 500; font-size: 10px; margin-left: 4px; text-transform: none; letter-spacing: 0; }
    .form-input, .form-select-input, .form-textarea { width: 100%; background: rgba(255,255,255,0.06); border: 1.5px solid rgba(255,255,255,0.12); color: #ffffff; border-radius: 10px; font-size: 13px; padding: 10px 14px; transition: all 0.2s ease; font-family: inherit; }
    .form-input::placeholder, .form-textarea::placeholder { color: rgba(255,255,255,0.25); }
    .form-input:focus, .form-select-input:focus, .form-textarea:focus { outline: none; border-color: #60A5FA; box-shadow: 0 0 0 3px rgba(96,165,250,0.15); background: rgba(255,255,255,0.08); }
    .form-input:disabled { background: rgba(255,255,255,0.03); color: rgba(255,255,255,0.35); cursor: not-allowed; border-color: rgba(255,255,255,0.08); }
    .form-select-input { appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23ffffff80' viewBox='0 0 16 16'%3E%3Cpath d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; background-size: 12px; padding-right: 36px; cursor: pointer; }
    .form-select-input option { background: #0A224C; color: #ffffff; }
    .form-textarea { resize: vertical; min-height: 60px; }
    .lrn-display { font-family: 'JetBrains Mono', monospace; letter-spacing: 0.06em; font-weight: 500; font-size: 14px; }
    .step-circle { width: 32px; height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; transition: all 0.2s ease; }
    .step-circle.active { background: #3b82f6; color: #fff; box-shadow: 0 0 0 4px rgba(59,130,246,0.25); }
    .step-circle.done { background: #10b981; color: #fff; }
    .step-circle.pending { background: rgba(255,255,255,0.1); color: rgba(255,255,255,0.35); }

    /* =====================================================
       ALERTS
       ===================================================== */
    .form-alert { display: flex; align-items: flex-start; gap: 12px; padding: 16px 18px; border-radius: 12px; font-size: 13px; line-height: 1.5; margin-bottom: 24px; }
    .form-alert i { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
    .form-alert div div { margin-bottom: 3px; }
    .form-alert div div:last-child { margin-bottom: 0; }
    .form-alert-danger { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.2); color: rgba(255,255,255,0.85); }
    .form-alert-danger i { color: #F87171; }
    .form-alert-success { background: rgba(16,185,129,0.12); border: 1px solid rgba(16,185,129,0.2); color: rgba(255,255,255,0.85); }
    .form-alert-success i { color: #34D399; }
    .form-alert-info { background: rgba(59,130,246,0.12); border: 1px solid rgba(59,130,246,0.2); color: rgba(255,255,255,0.85); }
    .form-alert-info i { color: #93C5FD; }
    .form-alert-warning { background: rgba(245,158,11,0.12); border: 1px solid rgba(245,158,11,0.2); color: rgba(255,255,255,0.85); }
    .form-alert-warning i { color: #FBBF24; }
    .student-code { display: inline-block; background: rgba(0,102,254,0.15); color: #60A5FA; padding: 2px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; font-family: 'JetBrains Mono','DM Mono','Fira Code',monospace; }

    /* =====================================================
       TABS
       ===================================================== */
    .edit-tabs { display: flex; gap: 4px; margin-bottom: 24px; border-bottom: 1px solid rgba(255,255,255,0.08); }
    .edit-tab { display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px; font-size: 13px; font-weight: 600; color: rgba(255,255,255,0.45); text-decoration: none; border-bottom: 2px solid transparent; transition: all 0.2s ease; margin-bottom: -1px; cursor: pointer; }
    .edit-tab:hover { color: rgba(255,255,255,0.7); text-decoration: none; }
    .edit-tab.active { color: #60A5FA; border-bottom-color: #60A5FA; }
    .edit-tab i { font-size: 14px; }
    .tab-badge-done { display: inline-block; background: rgba(16,185,129,0.2); color: #34D399; font-size: 9px; font-weight: 700; padding: 2px 7px; border-radius: 10px; text-transform: uppercase; letter-spacing: 0.04em; }

    /* =====================================================
       ACTIONS BAR
       ===================================================== */
    .form-actions { display: flex; justify-content: flex-end; align-items: center; padding: 0; }

    /* =====================================================
       TABLET (max-width: 991px)
       ===================================================== */
    @media (max-width: 991px) {
        .content-area { padding: 20px; }
    }

    /* =====================================================
       MOBILE (max-width: 767px)
       ===================================================== */
    @media (max-width: 767px) {
        .top-navbar { padding: 10px 12px; flex-wrap: nowrap; gap: 8px; align-items: center; }
        .navbar-left { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0; }
        #sidebarToggle { width: 38px; height: 38px; font-size: 20px; flex-shrink: 0; }
        .navbar-brand-mob { display: flex; min-width: 0; flex: 1; }
        .navbar-brand-logo { width: 38px; height: 38px; }
        .navbar-brand-name { font-size: 11px; }
        .navbar-brand-sub { font-size: 8px; opacity: 0.7; }
        .desktop-title { display: none !important; }
        .desktop-actions { display: none !important; }
        .navbar-actions { display: flex !important; gap: 4px; flex-shrink: 0; align-items: center; }
        .nav-action-icon { width: 34px; height: 34px; font-size: 14px; }

        /* Sidebar overlay */


        .content-area { padding: 8px 10px 28px; }
        .page-header-section { padding: 8px 2px 12px; }
        .page-header-section .mobile-student-meta { font-size: 11px; }
        .page-header-section .student-code { font-size: 11px; padding: 1px 6px; }

        .edit-tabs { gap: 2px; margin-bottom: 18px; overflow-x: auto; }
        .edit-tab { padding: 8px 14px; font-size: 12px; white-space: nowrap; }
        .edit-tab i { font-size: 13px; }

        .card { border-radius: 12px !important; }
        .card-body { padding: 14px !important; }
        .form-section-title { font-size: 13px; }
        .form-section-title i { font-size: 14px; }
        .form-field-label { font-size: 10px; margin-bottom: 5px; }
        .form-input, .form-select-input, .form-textarea { font-size: 14px; padding: 11px 14px; border-radius: 10px; }
        .form-textarea { min-height: 50px; }
        .lrn-display { font-size: 13px; }

        .form-alert { padding: 12px 14px; font-size: 12px; gap: 10px; margin-bottom: 18px; border-radius: 10px; }
        .form-alert i { font-size: 16px; }

        .form-actions { justify-content: stretch; }
        .form-actions .btn-theme { width: 100%; justify-content: center; padding: 14px 24px; font-size: 15px; }
    }

    /* =====================================================
       SMALL PHONE (max-width: 576px)
       ===================================================== */
    @media (max-width: 576px) {
        .top-navbar { padding: 8px 8px; gap: 6px; }
        .navbar-brand-logo { width: 34px; height: 34px; }
        .navbar-brand-name { font-size: 10px; letter-spacing: 0.02em; }
        .navbar-brand-sub { font-size: 7px; opacity: 0.65; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .navbar-actions { gap: 3px; }
        .nav-action-icon { width: 32px; height: 32px; font-size: 13px; }
        #sidebarToggle { width: 34px; height: 34px; font-size: 18px; }
        .content-area { padding: 6px 6px 24px; }
        .page-header-section .mobile-student-meta { font-size: 10px; }
        .card-body { padding: 12px !important; }
        .form-section-title { font-size: 12px; }
        .form-field-label { font-size: 10px; }
        .form-input, .form-select-input, .form-textarea { padding: 10px 12px; font-size: 14px; }
        .form-alert { padding: 10px 12px; font-size: 11px; }
        .edit-tab { padding: 8px 10px; font-size: 11px; }
        .sidebar { width: 260px; }
    }

    /* =====================================================
       DESKTOP — hide mobile-only elements
       ===================================================== */
    @media (min-width: 768px) {
        .navbar-brand-mob { display: none !important; }
        .page-header-section { display: none !important; }
    }
    /* Mirror webcam feed for natural selfie capture */
    #cameraVideo {
        transform: scaleX(-1);
    }
    .readonly-form .form-input,
    .readonly-form .form-select-input,
    .readonly-form .form-textarea {
        pointer-events: none;
        background: rgba(255,255,255,0.03) !important;
        color: rgba(255,255,255,0.5) !important;
        border-color: rgba(255,255,255,0.06) !important;
    }
</style>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">

<!-- ═══ TOP NAVBAR ═══ -->
<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">
    <div class="content-area">

        <!-- ===== DESKTOP PAGE HEADER ===== -->
        <div class="d-none d-md-flex justify-content-between align-items-start gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0"><?= sanitize($student['first_name'] . ' ' . ($student['name_extension'] ? $student['name_extension'] . ' ' : '') . $student['last_name']) ?></h5>
                <small>LRN: <span class="student-code"><?= sanitize($student['student_id']) ?></span> &nbsp;|&nbsp; Grade <?= $student['grade_level'] ?> &ndash; <?= sanitize($student['section']) ?></small>
            </div>
            <a href="<?= BASE_URL ?>/admin/students.php" class="btn-theme btn-theme-back"><i class="bi bi-arrow-left"></i> Back to List</a>
        </div>

        <!-- ===== MOBILE PAGE HEADER ===== -->
        <div class="page-header-section">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                <div>
                    <h5><?= sanitize($student['first_name'] . ' ' . ($student['name_extension'] ? $student['name_extension'] . ' ' : '') . $student['last_name']) ?></h5>
                    <div class="mobile-student-meta">
                        <span class="student-code"><?= sanitize($student['student_id']) ?></span>
                        <span>Grade <?= $student['grade_level'] ?> &ndash; <?= sanitize($student['section']) ?></span>
                    </div>
                </div>
                <a href="<?= BASE_URL ?>/admin/students.php" class="btn-theme btn-theme-back" style="flex-shrink:0;"><i class="bi bi-arrow-left"></i> Back</a>
            </div>
        </div>

        <?= displayFlashMessage() ?>

        <!-- ===== VALIDATION ERRORS ===== -->
        <?php if (!empty($errors)): ?>
            <div class="form-alert form-alert-danger">
                <i class="bi bi-exclamation-circle-fill"></i>
                <div><?php foreach ($errors as $e): ?><div><?= $e ?></div><?php endforeach; ?></div>
            </div>
        <?php endif; ?>

        <!-- ===== TABS ===== -->
        <div class="edit-tabs">
            <a class="edit-tab <?= $activeTab === 'info' ? 'active' : '' ?>"
               href="?id=<?= $studentId ?>&tab=info">
                <i class="bi bi-person"></i> Student Info
            </a>
            <a class="edit-tab <?= $activeTab === 'face' ? 'active' : '' ?>"
               href="?id=<?= $studentId ?>&tab=face">
                <i class="bi bi-camera"></i> Face Registration
                <?php if ($hasFace): ?>
                    <span class="tab-badge-done">Done</span>
                <?php endif; ?>
            </a>
        </div>

        <?php if ($activeTab === 'info'): ?>
        <!-- ===== TAB 1: EDIT INFO ===== -->
        <form method="POST" action="?id=<?= $studentId ?>&tab=info" <?= $isReadOnly ? 'class="readonly-form"' : '' ?>>
            <?= csrfField() ?>
            <input type="hidden" name="update_info" value="1">

            <!-- Personal Information -->
            <div class="card mb-4">
                <div class="card-header">
                    <span class="form-section-title"><i class="bi bi-person-fill"></i> Personal Information</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">

                        <!-- LRN (read-only) -->
                        <div class="col-12 col-md-4">
                            <label class="form-field-label">LRN (Learner Reference Number)</label>
                            <input type="text" class="form-input lrn-display"
                                   value="<?= sanitize($student['student_id']) ?>" disabled>
                        </div>

                        <!-- Name Extension (optional) -->
                        <div class="col-6 col-md-2">
                            <label class="form-field-label">
                                Name Extension <span class="optional">(optional)</span>
                            </label>
                            <select name="name_extension" class="form-select-input">
                                <option value="">None</option>
                                <?php foreach (['Jr.', 'Sr.', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'] as $ext): ?>
                                    <option value="<?= $ext ?>" <?= $student['name_extension'] === $ext ? 'selected' : '' ?>><?= $ext ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-3">
                            <label class="form-field-label">First Name <span class="req">*</span></label>
                            <input type="text" class="form-input" name="first_name"
                                   value="<?= sanitize($student['first_name']) ?>" required>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-field-label">Middle Name</label>
                            <input type="text" class="form-input" name="middle_name"
                                   value="<?= sanitize($student['middle_name']) ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-field-label">Last Name <span class="req">*</span></label>
                            <input type="text" class="form-input" name="last_name"
                                   value="<?= sanitize($student['last_name']) ?>" required>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-field-label">Age</label>
                            <input type="number" class="form-input" name="age" min="7" max="100"
                                   value="<?= sanitize($student['age']) ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-field-label">Gender <span class="req">*</span></label>
                            <select name="gender" class="form-select-input" required>
                                <option value="Male"   <?= $student['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= $student['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-field-label">Email</label>
                            <input type="email" class="form-input" name="email"
                                   value="<?= sanitize($student['email']) ?>">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-field-label">Grade Level <span class="req">*</span></label>
                            <select name="grade_level" class="form-select-input" required>
                                <?php foreach (['7','8','9','10','11','12'] as $g): ?>
                                    <option value="<?= $g ?>" <?= $student['grade_level'] === $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-field-label">Section</label>
                            <select name="section" class="form-select-input" id="sectionSelect">
                                <option value="">Select Section</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-field-label">Address</label>
                            <textarea class="form-textarea" name="address" rows="2"
                                      placeholder="Complete address"><?= sanitize($student['address']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Guardian Information -->
            <div class="card mb-4">
                <div class="card-header">
                    <span class="form-section-title"><i class="bi bi-people-fill"></i> Guardian / Parent Information</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-field-label">Guardian Name</label>
                            <input type="text" class="form-input" name="guardian_name"
                                   value="<?= sanitize($student['guardian_name']) ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-field-label">Relationship</label>
                            <select name="relationship" class="form-select-input">
                                <?php foreach (['parents','guardian','sibling','relative','others'] as $rel): ?>
                                    <option value="<?= $rel ?>" <?= $student['relationship'] === $rel ? 'selected' : '' ?>><?= $rel ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-field-label">Phone</label>
                            <input type="tel" class="form-input" name="guardian_phone"
                                   value="<?= sanitize($student['guardian_phone']) ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-field-label">Guardian Email</label>
                            <input type="email" class="form-input" name="guardian_email"
                                   value="<?= sanitize($student['guardian_email']) ?>">
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-field-label">Guardian Address</label>
                            <textarea class="form-textarea" name="guardian_address" rows="2"><?= sanitize($student['guardian_address']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <?php if (!$isReadOnly): ?>
                <button type="submit" class="btn-theme btn-theme-primary btn-theme-lg">
                    <i class="bi bi-check-lg"></i> Update Student
                </button>
                <?php else: ?>
                <span class="badge bg-secondary">View Only</span>
                <?php endif; ?>
            </div>
        </form>

        <?php else: ?>
        <!-- ===== TAB 2: FACE REGISTRATION ===== -->
        <div class="row g-4">
            <!-- Camera Section -->
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-camera-video-fill me-2"></i>Capture Face</span>
                        <span class="badge-status <?= $hasFace ? 'badge-present' : 'badge-late' ?>">
                            <?= $hasFace ? 'Registered' : 'Not Registered' ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <!-- Camera View -->
                        <div class="scanner-container mb-3" id="scannerContainer">
                            <video id="cameraVideo" autoplay playsinline></video>
                            <div class="scanner-overlay"></div>
                            <div class="scanner-status" id="scannerStatus">
                                <span class="pulse-dot"></span> Camera Active
                            </div>
                        </div>

                        <!-- Compliance Checkbox -->
                        <div class="mb-3">
                            <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;user-select:none;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:12px;">
                                <input type="checkbox" id="consentVerificationCheckbox" style="margin-top:3px;width:16px;height:16px;border-radius:4px;border:1px solid #d1d5db;accent-color:#2563eb;cursor:pointer;flex-shrink:0;">
                                <span style="font-size:13px;color:#374151;line-height:1.5;">I certify that a physical, signed Parental Consent and Biometric Waiver form is on file for this specific student profile.</span>
                            </label>
                        </div>

                        <!-- Step Tracker -->
                        <div class="mb-3">
                            <div class="d-flex align-items-center justify-content-center gap-3" id="stepTracker">
                                <div class="text-center">
                                    <span class="step-circle pending" id="step1">1</span>
                                    <small class="d-block mt-1" style="font-size:11px;color:rgba(255,255,255,0.4);font-weight:700;">FRONT</small>
                                </div>
                                <div class="h-px bg-gray-600" style="width:40px;"></div>
                                <div class="text-center">
                                    <span class="step-circle pending" id="step2">2</span>
                                    <small class="d-block mt-1" style="font-size:11px;color:rgba(255,255,255,0.4);font-weight:700;">LEFT</small>
                                </div>
                                <div class="h-px bg-gray-600" style="width:40px;"></div>
                                <div class="text-center">
                                    <span class="step-circle pending" id="step3">3</span>
                                    <small class="d-block mt-1" style="font-size:11px;color:rgba(255,255,255,0.4);font-weight:700;">RIGHT</small>
                                </div>
                            </div>
                            <p id="stepText" class="text-center mt-2" style="font-size:12px;color:rgba(255,255,255,0.5);">Complete all three angles</p>
                        </div>

                        <!-- Capture Buttons -->
                        <div class="d-flex flex-column gap-2 mb-3">
                            <button type="button" class="btn-theme btn-theme-primary" id="btnFront" disabled>
                                <i class="bi bi-camera"></i> Capture Front Profile
                            </button>
                            <button type="button" class="btn-theme btn-theme-primary" id="btnLeft" disabled>
                                <i class="bi bi-camera"></i> Capture Left Profile
                            </button>
                            <button type="button" class="btn-theme btn-theme-primary" id="btnRight" disabled>
                                <i class="bi bi-camera"></i> Capture Right Profile
                            </button>
                        </div>

                        <div class="d-flex justify-content-center gap-2 mb-3">
                            <button type="button" class="btn-theme btn-theme-primary d-none" id="startCameraBtn" onclick="initCamera()">
                                <i class="bi bi-camera-video"></i> Start Camera
                            </button>
                            <button type="button" class="btn-theme btn-theme-danger d-none" id="stopCameraBtn" onclick="stopCameraFn()">
                                <i class="bi bi-stop-fill"></i> Stop
                            </button>
                        </div>

                        <div id="captureMessage"></div>
                    </div>
                </div>
            </div>

            <!-- Captured Images -->
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header" style="justify-content:flex-start;gap:2px;"><i class="bi bi-images me-2"></i>Captured Faces</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-4 text-center">
                                <div class="border rounded-xl p-2" style="aspect-ratio:1;background:rgba(255,255,255,0.04);border-color:rgba(255,255,255,0.12);" id="frontPreview">
                                    <i class="bi bi-person" style="font-size:40px;color:rgba(255,255,255,0.15);"></i>
                                </div>
                                <small class="fw-600 mt-1 d-block" style="color:rgba(255,255,255,0.4);font-weight:700;text-transform:uppercase;letter-spacing:0.06em;font-size:11px;">Front</small>
                                <button type="button" class="btn-theme btn-theme-outline retake-btn mt-1" data-type="front" style="display:none;padding:4px 10px;font-size:11px;border-radius:6px;" onclick="retakeFace('front')">
                                    <i class="bi bi-arrow-counterclockwise"></i> Retake
                                </button>
                            </div>
                            <div class="col-4 text-center">
                                <div class="border rounded-xl p-2" style="aspect-ratio:1;background:rgba(255,255,255,0.04);border-color:rgba(255,255,255,0.12);" id="leftPreview">
                                    <i class="bi bi-person" style="font-size:40px;color:rgba(255,255,255,0.15);"></i>
                                </div>
                                <small class="fw-600 mt-1 d-block" style="color:rgba(255,255,255,0.4);font-weight:700;text-transform:uppercase;letter-spacing:0.06em;font-size:11px;">Left</small>
                                <button type="button" class="btn-theme btn-theme-outline retake-btn mt-1" data-type="left" style="display:none;padding:4px 10px;font-size:11px;border-radius:6px;" onclick="retakeFace('left')">
                                    <i class="bi bi-arrow-counterclockwise"></i> Retake
                                </button>
                            </div>
                            <div class="col-4 text-center">
                                <div class="border rounded-xl p-2" style="aspect-ratio:1;background:rgba(255,255,255,0.04);border-color:rgba(255,255,255,0.12);" id="rightPreview">
                                    <i class="bi bi-person" style="font-size:40px;color:rgba(255,255,255,0.15);"></i>
                                </div>
                                <small class="fw-600 mt-1 d-block" style="color:rgba(255,255,255,0.4);font-weight:700;text-transform:uppercase;letter-spacing:0.06em;font-size:11px;">Right</small>
                                <button type="button" class="btn-theme btn-theme-outline retake-btn mt-1" data-type="right" style="display:none;padding:4px 10px;font-size:11px;border-radius:6px;" onclick="retakeFace('right')">
                                    <i class="bi bi-arrow-counterclockwise"></i> Retake
                                </button>
                            </div>
                        </div>

                        <!-- Register Face Button -->
                        <button type="button" class="btn-theme btn-theme-success w-100 mt-3" id="registerFaceBtn"
                                onclick="registerFace()" disabled>
                            <i class="bi bi-cpu"></i> Register Face Encoding
                        </button>
                        <div id="registerMessage" class="mt-2"></div>

                        <?php if ($hasFace): ?>
                        <div class="form-alert form-alert-success mt-3" style="font-size:13px;margin-bottom:0;">
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Face encoding is registered and active for recognition.</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // ============================================================
            // FACE CAPTURE STATE
            // ============================================================
            var frontImage = null, leftImage = null, rightImage = null;
            var studentId = <?= $studentId ?>;
            var frontCaptured = false, leftCaptured = false, rightCaptured = false;

            function updateStepTracker() {
                var step1 = document.getElementById('step1');
                var step2 = document.getElementById('step2');
                var step3 = document.getElementById('step3');
                var stepText = document.getElementById('stepText');
                
                step1.className = 'step-circle ' + (frontCaptured ? 'done' : 'active');
                step2.className = 'step-circle ' + (leftCaptured ? 'done' : (frontCaptured ? 'active' : 'pending'));
                step3.className = 'step-circle ' + (rightCaptured ? 'done' : (leftCaptured ? 'active' : 'pending'));
                
                var completed = (frontCaptured ? 1 : 0) + (leftCaptured ? 1 : 0) + (rightCaptured ? 1 : 0);
                if (completed === 0) {
                    stepText.textContent = 'Complete all three angles';
                } else if (completed < 3) {
                    stepText.textContent = 'Step ' + completed + '/3 complete';
                } else {
                    stepText.textContent = 'All angles captured!';
                }
            }

            function updateCaptureButtons() {
                var checkbox = document.getElementById('consentVerificationCheckbox');
                var btnFront = document.getElementById('btnFront');
                var btnLeft = document.getElementById('btnLeft');
                var btnRight = document.getElementById('btnRight');
                var registerBtn = document.getElementById('registerFaceBtn');
                
                if (!checkbox) return;
                var verified = checkbox.checked;
                
                if (btnFront) btnFront.disabled = !verified || frontCaptured;
                if (btnLeft) btnLeft.disabled = !verified || leftCaptured;
                if (btnRight) btnRight.disabled = !verified || rightCaptured;
                
                if (registerBtn) {
                    registerBtn.disabled = !(frontCaptured && leftCaptured && rightCaptured);
                }
            }

            function showPreview(containerId, imageData) {
                var container = document.getElementById(containerId);
                if (!container || !imageData) return;
                container.innerHTML = '';
                var img = document.createElement('img');
                img.src = imageData;
                img.alt = containerId;
                img.style.width = '100%';
                img.style.height = '100%';
                img.style.objectFit = 'cover';
                img.style.borderRadius = '8px';
                container.appendChild(img);
            }

            function updateRetakeButtons() {
                var retakeBtns = document.querySelectorAll('.retake-btn');
                retakeBtns.forEach(function(btn) {
                    var type = btn.getAttribute('data-type');
                    if (type === 'front' && frontCaptured) btn.style.display = 'inline-flex';
                    else if (type === 'left' && leftCaptured) btn.style.display = 'inline-flex';
                    else if (type === 'right' && rightCaptured) btn.style.display = 'inline-flex';
                    else btn.style.display = 'none';
                });
            }

            function retakeFace(type) {
                var previewId = type + 'Preview';
                var container = document.getElementById(previewId);
                if (!container) return;

                if (type === 'front') frontCaptured = false;
                if (type === 'left') leftCaptured = false;
                if (type === 'right') rightCaptured = false;

                container.innerHTML = '<i class="bi bi-person" style="font-size:40px;color:rgba(255,255,255,0.15);"></i>';

                updateStepTracker();
                updateCaptureButtons();
                updateRetakeButtons();
            }

            function captureFront() {
                frontImage = captureFrameInZone('cameraVideo', 0.15);
                showPreview('frontPreview', frontImage);
                frontCaptured = true;
                updateStepTracker();
                updateCaptureButtons();
                updateRetakeButtons();
            }

            function captureLeft() {
                leftImage = captureFrameInZone('cameraVideo', 0.15);
                showPreview('leftPreview', leftImage);
                leftCaptured = true;
                updateStepTracker();
                updateCaptureButtons();
                updateRetakeButtons();
            }

            function captureRight() {
                rightImage = captureFrameInZone('cameraVideo', 0.15);
                showPreview('rightPreview', rightImage);
                rightCaptured = true;
                updateStepTracker();
                updateCaptureButtons();
                updateRetakeButtons();
            }

            // ============================================================
            // CAMERA & CAPTURE
            // ============================================================
            async function initCamera() {
                try {
                    await startCamera('cameraVideo');
                    document.getElementById('startCameraBtn').classList.add('d-none');
                    document.getElementById('stopCameraBtn').classList.remove('d-none');
                    updateCaptureButtons();
                } catch (e) {
                    document.getElementById('captureMessage').innerHTML =
                        '<div class="form-alert form-alert-danger" style="margin-bottom:0;">' +
                        '<i class="bi bi-exclamation-circle-fill"></i>' +
                        '<div>Camera access denied.</div></div>';
                }
            }

            function stopCameraFn() {
                stopCamera();
                document.getElementById('startCameraBtn').classList.remove('d-none');
                document.getElementById('stopCameraBtn').classList.add('d-none');
            }

            // Compliance checkbox: enable capture buttons and control camera
            var consentCheckbox = document.getElementById('consentVerificationCheckbox');
            if (consentCheckbox) {
                consentCheckbox.addEventListener('change', function() {
                    var btnFront = document.getElementById('btnFront');
                    var btnLeft = document.getElementById('btnLeft');
                    var btnRight = document.getElementById('btnRight');
                    var verified = this.checked;
                    
                    if (verified) {
                        initCamera();
                        if (btnFront) btnFront.disabled = false;
                        if (btnLeft) btnLeft.disabled = false;
                        if (btnRight) btnRight.disabled = false;
                    } else {
                        stopCameraFn();
                        if (btnFront) btnFront.disabled = true;
                        if (btnLeft) btnLeft.disabled = true;
                        if (btnRight) btnRight.disabled = true;
                    }
                    
                    updateCaptureButtons();
                });
            }

            // Individual capture buttons
            var btnFront = document.getElementById('btnFront');
            var btnLeft = document.getElementById('btnLeft');
            var btnRight = document.getElementById('btnRight');
            
            if (btnFront) btnFront.addEventListener('click', function() { captureFront(); });
            if (btnLeft) btnLeft.addEventListener('click', function() { captureLeft(); });
            if (btnRight) btnRight.addEventListener('click', function() { captureRight(); });

            // Initialize button states on load
            updateStepTracker();
            updateCaptureButtons();

            function registerFace() {
                var msg = document.getElementById('registerMessage');
                var btn = document.getElementById('registerFaceBtn');
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Encoding...';

                var csrfToken = document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : '';
                var formData = new FormData();
                formData.append('csrf_token', csrfToken);
                formData.append('action', 'register_face');
                formData.append('student_id', studentId);
                formData.append('front_face', frontImage || '');
                formData.append('left_face', leftImage || '');
                formData.append('right_face', rightImage || '');

                fetch(window.BASE_URL + '/api/students.php', { method: 'POST', body: formData })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            msg.innerHTML = '<div class="form-alert form-alert-success" style="margin-bottom:0;">' +
                                '<i class="bi bi-check-circle-fill"></i><div>' + (data.message || 'Face registered successfully.') + '</div></div>';
                            btn.innerHTML = '<i class="bi bi-check-lg"></i> Registered';
                            btn.className = 'btn-theme btn-theme-success w-100 mt-3';
                        } else {
                            msg.innerHTML = '<div class="form-alert form-alert-danger" style="margin-bottom:0;">' +
                                '<i class="bi bi-exclamation-circle-fill"></i><div>' + (data.error || data.message || 'Registration failed') + '</div></div>';
                            btn.disabled = false;
                            btn.innerHTML = '<i class="bi bi-cpu"></i> Register Face Encoding';
                        }
                    })
                    .catch(function() {
                        msg.innerHTML = '<div class="form-alert form-alert-danger" style="margin-bottom:0;">' +
                            '<i class="bi bi-exclamation-circle-fill"></i><div>Network error. Is the Python service running?</div></div>';
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-cpu"></i> Register Face Encoding';
                    });
            }
        </script>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    'use strict';

    var gradeLevelSelect = document.querySelector('select[name="grade_level"]');
    var sectionSelect = document.getElementById('sectionSelect');
    var gradeSectionsMap = <?= json_encode($gradeSectionsMap) ?>;
    var oldSection = <?= json_encode($student['section'] ?? '') ?>;

    function updateSections() {
        if (!sectionSelect) return;
        var grade = gradeLevelSelect ? gradeLevelSelect.value : '';
        var sections = gradeSectionsMap[grade] || [];
        sectionSelect.innerHTML = '<option value="">Select Section</option>';
        sections.forEach(function(s) {
            var opt = document.createElement('option');
            opt.value = s;
            opt.textContent = s;
            sectionSelect.appendChild(opt);
        });
        if (oldSection && sections.indexOf(oldSection) !== -1) {
            sectionSelect.value = oldSection;
        }
    }

    if (gradeLevelSelect && sectionSelect) {
        gradeLevelSelect.addEventListener('change', updateSections);
        updateSections();
    }
})();
</script>

<!-- ===== SIDEBAR OVERLAY (mobile) ===== -->
<div class="sidebar-overlay"></div>

<script>
(function() {
    'use strict';

    // ============================================================
    // Responsive visibility via CSS
    // ============================================================
    (function() {
        var style = document.createElement('style');
        style.textContent = [
            '@media (min-width: 768px) {',
            '  .mobile-only-btn { display: none !important; }',
            '}',
            '@media (max-width: 767px) {',
            '  .desktop-actions { display: none !important; }',
            '}'
        ].join('\n');
        document.head.appendChild(style);
    })();


})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>