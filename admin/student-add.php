<?php

require_once __DIR__ . '/../config.php';
requireRole(['admin']);

$gradeSectionsMap = [];
try {
    $stmt = $db->query("SELECT grade_level, section_name FROM sections ORDER BY CAST(grade_level AS UNSIGNED), section_name");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $gradeSectionsMap[$row['grade_level']][] = $row['section_name'];
    }
} catch (Exception $e) {}

$errors = [];
$old = [];

// Process form BEFORE including header and sidebar (before any output)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;

    // Personal info
    $data = [
        'student_id'      => trim($_POST['student_id'] ?? ''),
        'name_extension'  => sanitize($_POST['name_extension'] ?? ''),
        'first_name'      => sanitize($_POST['first_name'] ?? ''),
        'middle_name'     => sanitize($_POST['middle_name'] ?? ''),
        'last_name'       => sanitize($_POST['last_name'] ?? ''),
        'age'             => intval($_POST['age'] ?? 0),
        'gender'          => sanitize($_POST['gender'] ?? ''),
        'address'         => sanitize($_POST['address'] ?? ''),
        'email'           => sanitize($_POST['email'] ?? ''),
        'grade_level'     => sanitize($_POST['grade_level'] ?? ''),
        'section'         => sanitize($_POST['section'] ?? '')
    ];

    // ---- LRN Validation ----
    if (empty($data['student_id'])) {
        $errors[] = 'LRN is required.';
    } elseif (!preg_match('/^\d{12}$/', $data['student_id'])) {
        $errors[] = 'LRN must be exactly 12 digits.';
    } elseif (strpos($data['student_id'], '1134') !== 0) {
        $errors[] = 'LRN must start with 1134.';
    }

    // ---- Other Validation ----
    if (empty($data['first_name']))  $errors[] = 'First name is required.';
    if (empty($data['last_name']))   $errors[] = 'Last name is required.';
    if (empty($data['gender']))      $errors[] = 'Gender is required.';
    if (empty($data['grade_level'])) $errors[] = 'Grade level is required.';
    if ($data['age'] < 5 || $data['age'] > 25) $errors[] = 'Age must be between 5 and 25.';
    if (!empty($data['email']) && !isValidEmail($data['email'])) $errors[] = 'Invalid email address.';
    $guardianPhone = trim($_POST['guardian_phone'] ?? '');
    if (!empty($guardianPhone) && !isValidPhilippinePhone($guardianPhone)) {
        $errors[] = 'Guardian phone number must start with +63 and contain 11 digits only.';
    }

    // Check duplicate LRN
    if (empty($errors)) {
        $existing = getStudentByStudentId($db, $data['student_id']);
        if ($existing) $errors[] = 'This LRN already exists in the system.';
    }

    if (empty($errors)) {
        $studentDbId = addStudent($db, $data);

        if ($studentDbId) {
            saveGuardian($db, $studentDbId, [
                'guardian_name' => sanitize($_POST['guardian_name'] ?? ''),
                'relationship'  => sanitize($_POST['relationship'] ?? ''),
                'phone'         => normalizePhilippinePhone($_POST['guardian_phone'] ?? ''),
                'email'         => sanitize($_POST['guardian_email'] ?? ''),
                'address'       => sanitize($_POST['guardian_address'] ?? '')
            ]);

            redirect('/admin/students.php', 'Student "' . $data['first_name'] . ' ' . $data['last_name'] . '" added successfully.', 'success');
        } else {
            $errors[] = 'Failed to add student. Please try again.';
        }
    }
}

// Now include header and sidebar after processing
$pageTitle = 'Add Student';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<!-- ===== GLOBAL THEME ===== -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">

<!-- ============================================================ -->
<!-- ===== PAGE-SPECIFIC STYLES ================================== -->
<!-- ============================================================ -->
<style>

    /* ---- Content Spacing ---- */
    .content-area {
        padding: 24px 28px;
    }

    /* ═══════════════════════════════════════════════════════════════════
       PAGE HEADER ROW
       ═══════════════════════════════════════════════════════════════════ */
    .page-header-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;margin-bottom:24px}
    .page-header-left h5{font-size:22px;font-weight:800;letter-spacing:-0.03em;margin:0;color:#fff}
    .page-header-left small{font-size:13px;font-weight:500;color:rgba(255,255,255,0.55);display:block;margin-top:3px}
    .page-header-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap}

    .btn-bar-primary{padding:10px 18px;border-radius:var(--pg-radius-sm);font-size:13px;font-weight:700;cursor:pointer;transition:all var(--pg-spring);display:inline-flex;align-items:center;gap:6px;border:none;text-decoration:none;font-family:var(--pg-font);color:#fff}
    .btn-bar-primary:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(0,0,0,0.3);color:#fff;text-decoration:none}
    .btn-bar-primary i{font-size:14px}
    .btn-bar-outline{padding:10px 18px;border-radius:var(--pg-radius-sm);font-size:13px;font-weight:700;cursor:pointer;transition:all var(--pg-transition);display:inline-flex;align-items:center;gap:6px;border:1.5px solid rgba(255,255,255,0.15);background:rgba(255,255,255,0.04);color:rgba(255,255,255,0.7);text-decoration:none;font-family:var(--pg-font)}
    .btn-bar-outline:hover{border-color:rgba(255,255,255,0.3);color:#fff;background:rgba(255,255,255,0.1);transform:translateY(-1px);text-decoration:none}
    .btn-bar-outline i{font-size:14px}

    .page-header-mobile{display:none;padding:8px 2px 14px}
    .page-header-mobile h5{font-size:18px;font-weight:800;letter-spacing:-0.03em;margin:0;color:#fff}
    .page-header-mobile small{font-size:12px;font-weight:500;color:rgba(255,255,255,0.55);display:block;margin-top:2px}
    .page-header-mobile-actions{display:flex;align-items:center;gap:6px;margin-top:12px;flex-wrap:wrap}
    .page-header-mobile-actions .btn-bar-primary{padding:8px 14px;font-size:12px}
    .page-header-mobile-actions .btn-bar-outline{padding:8px 14px;font-size:12px}

    /* =====================================================
       FORM
       ===================================================== */
    .form-section-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
        font-weight: 700;
        color: #ffffff;
    }
    .form-section-title i {
        color: #60A5FA;
        font-size: 16px;
    }
    .form-field-label {
        display: block;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: rgba(255, 255, 255, 0.45);
        margin-bottom: 6px;
    }
    .form-field-label .req {
        color: #F87171;
        margin-left: 2px;
    }
    .form-field-label .optional {
        color: rgba(255,255,255,0.25);
        font-weight: 500;
        font-size: 10px;
        margin-left: 4px;
        text-transform: none;
        letter-spacing: 0;
    }
    .form-input,
    .form-select-input,
    .form-textarea {
        width: 100%;
        background: rgba(255, 255, 255, 0.06);
        border: 1.5px solid rgba(255, 255, 255, 0.12);
        color: #ffffff;
        border-radius: 10px;
        font-size: 13px;
        padding: 10px 14px;
        transition: all 0.2s ease;
        font-family: inherit;
    }
    .form-input::placeholder,
    .form-textarea::placeholder {
        color: rgba(255, 255, 255, 0.25);
    }
    .form-input:focus,
    .form-select-input:focus,
    .form-textarea:focus {
        outline: none;
        border-color: #60A5FA;
        box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.15);
        background: rgba(255, 255, 255, 0.08);
    }
    .form-select-input {
        appearance: none;
        -webkit-appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23ffffff80' viewBox='0 0 16 16'%3E%3Cpath d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
        background-size: 12px;
        padding-right: 36px;
        cursor: pointer;
    }
    .form-select-input option {
        background: #0A224C;
        color: #ffffff;
    }
    .form-textarea {
        resize: vertical;
        min-height: 60px;
    }

    /* LRN prefix hint */
    .lrn-input-wrapper {
        position: relative;
    }
    .lrn-prefix {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 13px;
        font-weight: 600;
        color: rgba(96, 165, 250, 0.6);
        pointer-events: none;
        font-family: 'JetBrains Mono', monospace;
        letter-spacing: 0.03em;
    }
    .lrn-input {
        font-family: 'JetBrains Mono', monospace;
        letter-spacing: 0.06em;
        font-weight: 500;
    }
    .lrn-hint {
        font-size: 11px;
        color: rgba(255,255,255,0.3);
        margin-top: 5px;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .lrn-hint i { font-size: 12px; }
    .lrn-counter {
        font-family: 'JetBrains Mono', monospace;
        font-size: 10px;
        font-weight: 600;
        padding: 2px 8px;
        border-radius: 6px;
        margin-left: auto;
        transition: all 0.2s ease;
    }
    .lrn-counter.valid {
        background: rgba(16, 185, 129, 0.15);
        color: #10b981;
    }
    .lrn-counter.partial {
        background: rgba(245, 158, 11, 0.15);
        color: #f59e0b;
    }
    .lrn-counter.empty {
        background: rgba(255,255,255,0.06);
        color: rgba(255,255,255,0.25);
    }
    .form-input.lrn-valid {
        border-color: rgba(16, 185, 129, 0.5);
    }
    .form-input.lrn-invalid {
        border-color: rgba(239, 68, 68, 0.5);
    }

    /* ---- Alert ---- */
    .form-alert {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 16px 18px;
        border-radius: 12px;
        font-size: 13px;
        line-height: 1.5;
        margin-bottom: 24px;
    }
    .form-alert i {
        font-size: 18px;
        flex-shrink: 0;
        margin-top: 1px;
    }
    .form-alert-danger {
        background: rgba(239, 68, 68, 0.12);
        border: 1px solid rgba(239, 68, 68, 0.2);
        color: rgba(255, 255, 255, 0.85);
    }
    .form-alert-danger i { color: #F87171; }
    .form-alert-danger div div {
        margin-bottom: 3px;
    }
    .form-alert-danger div div:last-child {
        margin-bottom: 0;
    }

    /* ---- Actions Bar ---- */
    .form-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 24px;
        background: rgba(255,255,255,0.04);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 12px;
        gap: 12px;
    }

    /* =====================================================
       BUTTONS
       ===================================================== */
    .btn-bar-primary,
    .btn-bar-outline {
        font-weight: 600; border-radius: 10px; padding: 9px 18px; font-size: 13px;
        transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 6px;
        cursor: pointer; text-decoration: none; line-height: 1.4; white-space: nowrap;
        border: 1px solid transparent; font-family: inherit;
    }
    .btn-bar-lg { padding: 12px 28px; font-size: 14px; border-radius: 12px; }
    .btn-bar-primary {
        background: rgba(0,102,254,0.5); border-color: rgba(0,102,254,0.6); color: #fff;
        box-shadow: 0 4px 12px rgba(0,102,254,0.2);
    }
    .btn-bar-primary:hover {
        background: rgba(0,102,254,0.65); border-color: rgba(0,102,254,0.8); color: #fff;
        transform: translateY(-1px); box-shadow: 0 6px 16px rgba(0,102,254,0.3); text-decoration: none;
    }
    .btn-bar-outline {
        background: rgba(239,68,68,0.12); border-color: rgba(239,68,68,0.3);
        color: rgba(255,255,255,0.8);
    }
    .btn-bar-outline:hover {
        background: rgba(239,68,68,0.25); border-color: rgba(248,113,113,0.5);
        color: #fff; text-decoration: none;
    }
    .btn-bar-primary:disabled, .btn-bar-outline:disabled { opacity: 0.5; pointer-events: none; cursor: not-allowed; }
    .btn-bar .spinner-border { width: 14px; height: 14px; border-width: 2px; }

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
        /* ----- Content area ----- */
        .content-area { padding: 8px 10px 28px; }

        /* ----- Page header ----- */
        .page-header-row { display: none !important; }
        .page-header-mobile { display: block !important; }

        /* ----- Form ----- */
        .card { border-radius: 12px !important; }
        .card-body { padding: 14px !important; }
        .form-section-title { font-size: 13px; }
        .form-section-title i { font-size: 14px; }
        .form-field-label { font-size: 10px; margin-bottom: 5px; }
        .form-input,
        .form-select-input,
        .form-textarea {
            font-size: 14px;
            padding: 11px 14px;
            border-radius: 10px;
        }
        .form-textarea { min-height: 50px; }
        .lrn-prefix { font-size: 12px; left: 12px; }
        .lrn-hint { font-size: 10px; }

        /* ----- Alert ----- */
        .form-alert {
            padding: 12px 14px;
            font-size: 12px;
            gap: 10px;
            margin-bottom: 18px;
            border-radius: 10px;
        }
        .form-alert i { font-size: 16px; }

        /* ----- Actions ----- */
        .form-actions {
            flex-direction: column-reverse;
            gap: 10px;
        }
        .form-actions .btn-bar-primary {
            width: 100%;
            justify-content: center;
        }
        .form-actions .btn-bar-outline {
            width: 100%;
            justify-content: center;
        }
    }

    /* =====================================================
       SMALL PHONE (max-width: 576px)
       ===================================================== */
    @media (max-width: 576px) {
        .content-area { padding: 6px 6px 24px; }
        .card-body { padding: 12px !important; }
        .form-section-title { font-size: 12px; }
        .form-field-label { font-size: 10px; }
        .form-input,
        .form-select-input,
        .form-textarea {
            padding: 10px 12px;
            font-size: 14px;
        }
        .lrn-prefix { font-size: 11px; left: 10px; }
        .form-alert { padding: 10px 12px; font-size: 11px; }
    }
</style>

<!-- ===== JETBRAINS MONO FOR LRN INPUT ===== -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

<!-- ═══ TOP NAVBAR ═══ -->
<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">

    <!-- ═══ CONTENT ═══ -->
    <div class="content-area">
        
        <!-- PAGE HEADER — DESKTOP -->
        <div class="page-header-row">
            <div class="page-header-left">
                <h5>Add New Student</h5>
                <small>Fill in student personal and guardian information</small>
            </div>
            <div class="page-header-right">
                <a href="<?= BASE_URL ?>/admin/students.php" class="btn-bar-outline"><i class="bi bi-arrow-left"></i> Back to List</a>
            </div>
        </div>

        <!-- PAGE HEADER — MOBILE -->
        <div class="page-header-mobile">
            <h5>Add New Student</h5>
            <small>Fill in student personal and guardian information</small>
            <div class="page-header-mobile-actions">
                <a href="<?= BASE_URL ?>/admin/students.php" class="btn-bar-outline"><i class="bi bi-arrow-left"></i> Back</a>
            </div>
        </div>

        <!-- ===== VALIDATION ERRORS ===== -->
        <?php if (!empty($errors)): ?>
            <div class="form-alert form-alert-danger">
                <i class="bi bi-exclamation-circle-fill"></i>
                <div>
                    <?php foreach ($errors as $err): ?>
                        <div><?= $err ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

<div id="actualAddStudentForm">
        <form method="POST" action="" id="addStudentForm" novalidate>
            <?= csrfField() ?>

            <!-- ===== PERSONAL INFORMATION ===== -->
            <div class="card mb-4">
                <div class="card-header">
                    <span class="form-section-title"><i class="bi bi-person-fill"></i> Personal Information</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">

                        <!-- LRN — full width, monospaced, 12 digits starting with 1134 -->
                        <div class="col-12 col-md-6">
                            <label class="form-field-label">
                                LRN (Learner Reference Number) <span class="req">*</span>
                            </label>
                            <div class="lrn-input-wrapper">
                                <input type="text"
                                       class="form-input lrn-input"
                                       name="student_id"
                                       id="lrnInput"
                                       inputmode="numeric"
                                       maxlength="12"
                                       pattern="\d{12}"
                                       placeholder="113400000001"
                                       value="<?= sanitize($old['student_id'] ?? '') ?>"
                                       autocomplete="off"
                                       required>
                            </div>
                            <div class="lrn-hint">
                                <i class="bi bi-info-circle"></i>
                                12 digits, must begin with <strong style="color:rgba(96,165,250,0.7);margin:0 2px;">1134</strong>
                                <span class="lrn-counter empty" id="lrnCounter">0 / 12</span>
                            </div>
                        </div>

                        <!-- spacer for alignment -->
                        <div class="col-12 col-md-6"></div>

                        <div class="col-6 col-md-3">
                            <label class="form-field-label">First Name <span class="req">*</span></label>
                            <input type="text" class="form-input" name="first_name"
                                   value="<?= sanitize($old['first_name'] ?? '') ?>"
                                   placeholder="Juan" required>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-field-label">Middle Name</label>
                            <input type="text" class="form-input" name="middle_name"
                                   value="<?= sanitize($old['middle_name'] ?? '') ?>"
                                   placeholder="Santos">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-field-label">Last Name <span class="req">*</span></label>
                            <input type="text" class="form-input" name="last_name"
                                   value="<?= sanitize($old['last_name'] ?? '') ?>"
                                   placeholder="Dela Cruz" required>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-field-label">
                                Name Extension <span class="optional">(optional)</span>
                            </label>
                            <select name="name_extension" class="form-select-input">
                                <option value="">None</option>
                                <?php
                                $extensions = [
                                    'Jr.', 'Sr.', 'II', 'III', 'IV', 'V',
                                    'VI', 'VII', 'VIII', 'IX', 'X'
                                ];
                                foreach ($extensions as $ext):
                                ?>
                                    <option value="<?= $ext ?>" <?= ($old['name_extension'] ?? '') === $ext ? 'selected' : '' ?>><?= $ext ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-2">
                            <label class="form-field-label">Age <span class="req">*</span></label>
                            <input type="number" class="form-input" name="age" min="5" max="25"
                                   value="<?= sanitize($old['age'] ?? '') ?>"
                                   placeholder="16" required>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-field-label">Gender <span class="req">*</span></label>
                            <select name="gender" class="form-select-input" required>
                                <option value="">Select Gender</option>
                                <option value="Male"   <?= ($old['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= ($old['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-field-label">Email</label>
                            <input type="email" class="form-input" name="email"
                                   value="<?= sanitize($old['email'] ?? '') ?>"
                                   placeholder="student@email.com">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-field-label">Grade Level <span class="req">*</span></label>
                            <select name="grade_level" class="form-select-input" required>
                                <option value="">Select Grade</option>
                                <?php foreach (['7','8','9','10','11','12'] as $g): ?>
                                    <option value="<?= $g ?>" <?= ($old['grade_level'] ?? '') === $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
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
                                      placeholder="Complete address"><?= sanitize($old['address'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== GUARDIAN INFORMATION ===== -->
            <div class="card mb-4">
                <div class="card-header">
                    <span class="form-section-title"><i class="bi bi-people-fill"></i> Guardian / Parent Information</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-field-label">Guardian Name</label>
                            <input type="text" class="form-input" name="guardian_name"
                                   value="<?= sanitize($old['guardian_name'] ?? '') ?>"
                                   placeholder="Full name of guardian">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-field-label">Relationship</label>
                            <select name="relationship" class="form-select-input">
                                <?php foreach (['parents','guardian','sibling','relative','others'] as $rel): ?>
                                    <option value="<?= $rel ?>" <?= ($old['relationship'] ?? '') === $rel ? 'selected' : '' ?>><?= $rel ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-field-label">Phone Number</label>
                            <input type="tel" class="form-input" name="guardian_phone"
                                   value="<?= sanitize($old['guardian_phone'] ?? '') ?>"
                                   placeholder="09171234567"
                                   pattern="^09\d{9}$"
                                   maxlength="11"
                                   title="Use Philippine format starting with 09 and 11 digits total">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-field-label">Guardian Email</label>
                            <input type="email" class="form-input" name="guardian_email"
                                   value="<?= sanitize($old['guardian_email'] ?? '') ?>"
                                   placeholder="guardian@email.com">
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-field-label">Guardian Address</label>
                            <textarea class="form-textarea" name="guardian_address" rows="2"
                                      placeholder="Guardian's complete address"><?= sanitize($old['guardian_address'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== ACTIONS ===== -->
            <div class="form-actions">
                <a href="<?= BASE_URL ?>/admin/students.php" class="btn-bar-outline">
                    <i class="bi bi-x-lg"></i> Cancel
                </a>
                <button type="submit" class="btn-bar-primary btn-bar-lg" id="submitBtn">
                    <span class="spinner-border spinner-border-sm me-2 d-none" id="submitSpinner" role="status" aria-hidden="true"></span>
                    <i class="bi bi-check-lg" id="submitIcon"></i> Save Student
                </button>
            </div>
        </form>
    </div>

    <!-- /actualAddStudentForm wrapper -->
</div>

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



    // ============================================================
    // LRN INPUT — allow only digits, enforce max 12, show counter
    // ============================================================
    var lrnInput   = document.getElementById('lrnInput');
    var lrnCounter = document.getElementById('lrnCounter');
    var submitBtn  = document.getElementById('submitBtn');

    if (lrnInput && lrnCounter) {
        // Strip non-digits on input
        lrnInput.addEventListener('input', function() {
            var raw = this.value.replace(/\D/g, '');
            if (raw.length > 12) raw = raw.slice(0, 12);
            this.value = raw;
            updateLrnUI(raw);
        });

        // Block non-numeric keypresses
        lrnInput.addEventListener('keypress', function(e) {
            var charCode = e.which || e.keyCode;
            if (charCode < 48 || charCode > 57) {
                e.preventDefault();
            }
        });

        // Handle paste — strip non-digits
        lrnInput.addEventListener('paste', function(e) {
            e.preventDefault();
            var text = (e.clipboardData || window.clipboardData).getData('text');
            var digits = text.replace(/\D/g, '').slice(0, 12);
            this.value = digits;
            updateLrnUI(digits);
        });

        function updateLrnUI(val) {
            var len = val.length;
            lrnCounter.textContent = len + ' / 12';

            // Remove old classes
            lrnInput.classList.remove('lrn-valid', 'lrn-invalid');
            lrnCounter.classList.remove('valid', 'partial', 'empty');

            if (len === 0) {
                lrnCounter.classList.add('empty');
            } else if (len === 12) {
                if (val.indexOf('1134') === 0) {
                    lrnInput.classList.add('lrn-valid');
                    lrnCounter.classList.add('valid');
                } else {
                    lrnInput.classList.add('lrn-invalid');
                    lrnCounter.classList.add('partial');
                }
            } else {
                lrnCounter.classList.add('partial');
                if (len >= 4 && val.indexOf('1134') !== 0) {
                    lrnInput.classList.add('lrn-invalid');
                }
            }
        }

        // Initialize on page load if there's an existing value
        updateLrnUI(lrnInput.value.replace(/\D/g, ''));
    }

    // ============================================================
    // FORM SUBMIT — validate LRN before allowing submission
    // ============================================================
    var form = document.getElementById('addStudentForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            var lrn = lrnInput ? lrnInput.value.trim() : '';

            if (!lrn) {
                e.preventDefault();
                showAlertModal('Please enter the LRN (Learner Reference Number).', { title: 'Missing LRN', icon: 'exclamation-circle-fill', type: 'warning' });
                lrnInput.focus();
                return;
            }

            if (!/^\d{12}$/.test(lrn)) {
                e.preventDefault();
                showAlertModal('LRN must be exactly 12 digits.', { title: 'Invalid LRN', icon: 'exclamation-circle-fill', type: 'warning' });
                lrnInput.focus();
                return;
            }

            if (lrn.indexOf('1134') !== 0) {
                e.preventDefault();
                showAlertModal('LRN must start with 1134.', { title: 'Invalid LRN', icon: 'exclamation-circle-fill', type: 'warning' });
                lrnInput.focus();
                return;
            }

            var submitBtn = document.getElementById('submitBtn');
            var submitSpinner = document.getElementById('submitSpinner');
            var submitIcon = document.getElementById('submitIcon');
            if (submitBtn) {
                submitBtn.disabled = true;
                if (submitSpinner) submitSpinner.classList.remove('d-none');
                if (submitIcon) submitIcon.classList.add('d-none');
            }
        });
    }

    // ============================================================
    // GRADE -> SECTION DROPDOWN DEPENDENCY
    // ============================================================
    (function() {
        var gradeLevelSelect = document.querySelector('select[name="grade_level"]');
        var sectionSelect = document.getElementById('sectionSelect');
        var gradeSectionsMap = <?= json_encode($gradeSectionsMap) ?>;
        var oldSection = <?= json_encode($old['section'] ?? '') ?>;

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
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>