<?php
require_once __DIR__ . '/../config.php';
requireRole(['admin', 'teacher']);

$pageTitle = 'Master List';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$teacherId = getCurrentUserId();
$teacher = null;

try {
    $stmt = $db->prepare("SELECT t.* FROM teachers t JOIN users u ON t.user_id = u.id WHERE u.id = ? LIMIT 1");
    $stmt->execute([$teacherId]);
    $teacher = $stmt->fetch();
} catch (Exception $e) { error_log('master-list teacher: ' . $e->getMessage()); }

/* ---- Gather the subject ids and section ids the admin assigned to this teacher ---- */
$assignedSubjectIds = [];
$assignedSectionIds = [];
if ($teacher) {
    foreach (['grade_section_handled', 'core_subjects_handled', 'track_elective_handled'] as $field) {
        $raw = $teacher[$field] ?? '';
        if (is_string($raw)) { $raw = json_decode($raw, true); }
        if (!is_array($raw)) { continue; }
        foreach ($raw as $item) {
            if (isset($item['subject_id']) && is_numeric($item['subject_id'])) { $assignedSubjectIds[] = (int)$item['subject_id']; }
            if (isset($item['section_id']) && is_numeric($item['section_id'])) { $assignedSectionIds[] = (int)$item['section_id']; }
        }
    }
}
$assignedSubjectIds = array_values(array_unique(array_filter($assignedSubjectIds, function ($id) { return $id > 0; })));
$assignedSectionIds = array_values(array_unique(array_filter($assignedSectionIds, function ($id) { return $id > 0; })));

/* ---- Map each assigned section id to its grade level ---- */
$sectionGradeMap = [];
if (!empty($assignedSectionIds)) {
    $ph = implode(',', array_fill(0, count($assignedSectionIds), '?'));
    try {
        $stmt = $db->prepare("SELECT id, grade_level FROM sections WHERE id IN ($ph)");
        $stmt->execute($assignedSectionIds);
        foreach ($stmt->fetchAll() as $sec) {
            $sectionGradeMap[(int)$sec['id']] = (int)$sec['grade_level'];
        }
    } catch (Exception $e) { error_log('master-list sections: ' . $e->getMessage()); }
}

/* ---- Load the subject rows the teacher is allowed to view ---- */
$mySubjects = [];
if (!empty($assignedSubjectIds)) {
    $ph = implode(',', array_fill(0, count($assignedSubjectIds), '?'));
    try {
        $stmt = $db->prepare("SELECT * FROM subjects WHERE id IN ($ph) ORDER BY subject_name ASC");
        $stmt->execute($assignedSubjectIds);
        $mySubjects = $stmt->fetchAll();
    } catch (Exception $e) { error_log('master-list subjects: ' . $e->getMessage()); }
}

/* ---- Build a map: subject_id -> [grade levels], derived from the paired sections ---- */
$subjectGradesMap = [];
$allGradeLevels = [];
if ($teacher) {
    foreach (['grade_section_handled', 'core_subjects_handled', 'track_elective_handled'] as $field) {
        $raw = $teacher[$field] ?? '';
        if (is_string($raw)) { $raw = json_decode($raw, true); }
        if (!is_array($raw)) { continue; }
        foreach ($raw as $item) {
            $sid   = isset($item['subject_id']) && is_numeric($item['subject_id']) ? (int)$item['subject_id'] : 0;
            $secId = isset($item['section_id']) && is_numeric($item['section_id']) ? (int)$item['section_id'] : 0;
            if (!$sid || !$secId || !isset($sectionGradeMap[$secId])) { continue; }
            $g = $sectionGradeMap[$secId];
            if ($g <= 0) { continue; }
            $subjectGradesMap[$sid][] = $g;
            if (!in_array($g, $allGradeLevels, true)) { $allGradeLevels[] = $g; }
        }
    }
}
foreach ($subjectGradesMap as $sid => $grades) {
    $subjectGradesMap[$sid] = array_values(array_unique($grades));
}
sort($allGradeLevels, SORT_NUMERIC);

$hasSubjects = !empty($mySubjects);

/* ---- Load the sections the teacher is assigned to ---- */
$teacherSections = [];
$sectionGradeMap = [];
if (!empty($assignedSectionIds)) {
    $ph = implode(',', array_fill(0, count($assignedSectionIds), '?'));
    try {
        $stmt = $db->prepare("SELECT s.id, s.section_name, s.grade_level, st.strand_name, st.strand_code FROM sections s LEFT JOIN strands st ON s.strand_id = st.id WHERE s.id IN ($ph) ORDER BY CAST(s.grade_level AS UNSIGNED), s.section_name");
        $stmt->execute($assignedSectionIds);
        $teacherSections = $stmt->fetchAll();
        foreach ($teacherSections as $sec) {
            $sectionGradeMap[(int)$sec['id']] = (int)$sec['grade_level'];
        }
    } catch (Exception $e) { error_log('master-list sections load: ' . $e->getMessage()); }
}

// Get teacher name for print
$teacherName = '';
if ($teacher) {
    $teacherName = $teacher['first_name'] ?? '';
    if (!empty($teacher['middle_name'])) {
        $teacherName .= ' ' . strtoupper($teacher['middle_name'][0]) . '.';
    }
    $teacherName .= ' ' . ($teacher['last_name'] ?? '');
}
if (empty($teacherName)) $teacherName = 'ANGELYN S. PARRABA';
$principalName = 'ERWIN M. ESPENILLA';

/* Advisory class (JHS/SHS) assigned to this teacher by the admin — when the
   printed grade & section is the advisory, the signature reads "Adviser". */
$advisoryRecord = getAdvisorySectionRecord($db);
$advisoryGrade        = $advisoryRecord ? (string)$advisoryRecord['grade_level']   : '';
$advisorySectionName  = $advisoryRecord ? trim((string)$advisoryRecord['section_name']) : '';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/print.css">

<style>
    :root {
        --td-font: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        --td-mono: 'JetBrains Mono', monospace;
        --td-primary: #4f46e5; --td-primary-light: rgba(79,70,229,0.15); --td-primary-dark: #3730a3; --td-primary-glow: rgba(79,70,229,0.2);
        --td-success: #10b981; --td-success-light: rgba(16,185,129,0.15);
        --td-danger: #ef4444; --td-danger-light: rgba(239,68,68,0.15);
        --td-warning: #f59e0b; --td-warning-light: rgba(245,158,11,0.15);
        --td-info: #06b6d4; --td-info-light: rgba(6,182,212,0.15);
        --td-radius: 14px; --td-radius-sm: 10px; --td-radius-xs: 8px;
        --td-shadow-sm: 0 1px 3px rgba(0,0,0,0.2); --td-shadow: 0 4px 16px rgba(0,0,0,0.25);
        --td-shadow-lg: 0 12px 40px rgba(0,0,0,0.3);
        --td-transition: 0.2s cubic-bezier(0.4,0,0.2,1);
    }

    .page-title h5 { font-size: 20px; font-weight: 800; letter-spacing: -0.03em; margin: 0; }
    .page-title small { font-size: 13px; font-weight: 500; }
    .mobile-title-left h5 { font-size: 18px; font-weight: 800; letter-spacing: -0.03em; margin: 0; }
    .mobile-title-left small { font-size: 12px; font-weight: 500; }
    .date-display { font-size: 13px; font-weight: 600; padding: 8px 16px; border-radius: var(--td-radius-sm); display: flex; align-items: center; gap: 8px; }
    .date-display i { font-size: 14px; }

    .content-area { padding: 28px; }

    .filter-card { border-radius: var(--td-radius); overflow: hidden; }
    .filter-card .card-header { font-size: 14px; font-weight: 700; letter-spacing: -0.01em; padding: 16px 20px; display: flex; align-items: center; justify-content: flex-start; gap: 6px; }
    .filter-card .card-header i { font-size: 15px; line-height: 1; }
    .filter-card .card-body { padding: 20px; }
    .filter-row { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; }
    .filter-group { flex: 1; min-width: 200px; }
    .filter-group label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 6px; }
    .filter-group select { width: 100%; padding: 10px 14px; border-radius: var(--td-radius-sm); font-size: 13px; font-weight: 500; border: 1.5px solid rgba(255,255,255,0.15); background: rgba(255,255,255,0.05); color: #fff; transition: all var(--td-transition); }
    .filter-group select:focus { outline: none; box-shadow: 0 0 0 3px var(--td-primary-glow); border-color: var(--td-primary); }
    .filter-group select:disabled { opacity: 0.65; cursor: not-allowed; background: rgba(79,70,229,0.12); border-color: rgba(79,70,229,0.4); }
    .filter-group select option { background: #1a2340; color: #fff; }
    .btn-filter { padding: 10px 24px; border-radius: var(--td-radius-sm); font-weight: 600; font-size: 13px; border: none; cursor: pointer; transition: all var(--td-transition); background: var(--td-primary); color: #fff; display: inline-flex; align-items: center; gap: 8px; }
    .btn-filter:hover { background: var(--td-primary-dark); transform: translateY(-1px); }
    .btn-filter:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

    .result-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
    .result-header h6 { font-size: 14px; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 8px; }
    .result-count { font-size: 12px; font-weight: 600; padding: 4px 12px; border-radius: 20px; background: var(--td-primary-light); color: var(--td-primary); }

    .gender-column { border-radius: var(--td-radius); overflow: hidden; }
    .gender-column .card-header { font-size: 13px; font-weight: 700; letter-spacing: -0.01em; padding: 14px 18px; display: flex; align-items: center; gap: 10px; }
    .gender-column .card-body { padding: 16px; max-height: 560px; overflow-y: auto; }
    .gender-column .card-body::-webkit-scrollbar { width: 4px; }
    .gender-column .card-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 10px; }

    .master-list-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .master-list-table thead th { position: sticky; top: 0; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; padding: 8px 10px; border-bottom: 1px solid rgba(255,255,255,0.12); background: #16203c; text-align: left; }
    .master-list-table tbody td { padding: 8px 10px; border-bottom: 1px solid rgba(255,255,255,0.06); font-weight: 500; }
    .master-list-table tbody tr:hover { background: rgba(255,255,255,0.04); }
    .master-list-table .ml-no { width: 48px; text-align: center; font-family: var(--td-mono); font-weight: 700; opacity: 0.7; }
    .master-list-table .ml-mi { width: 70px; }
    .gender-column .badge { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; }
    .male-header-bg .badge { background: var(--td-info-light); color: #3b82f6; }
    .female-header-bg .badge { background: rgba(236,72,153,0.15); color: #ec4899; }

    .empty-state { text-align: center; padding: 36px 20px; }
    .empty-state i { font-size: 36px; opacity: 0.3; margin-bottom: 12px; display: block; }
    .empty-state p { font-size: 13px; opacity: 0.6; margin: 0; }

    .male-bg { background: rgba(59,130,246,0.15); color: #3b82f6; }
    .female-bg { background: rgba(236,72,153,0.15); color: #ec4899; }
    .male-header-bg { background: rgba(59,130,246,0.12); }
    .female-header-bg { background: rgba(236,72,153,0.12); }

    .state-card { border-radius: var(--td-radius); }
    .state-card .card-body { text-align: center; padding: 48px 24px; }
    .state-card .state-icon { font-size: 48px; opacity: 0.2; display: block; margin-bottom: 16px; }
    .state-card h6 { font-weight: 700; margin-bottom: 8px; }
    .state-card p { font-size: 13px; opacity: 0.6; margin: 0; max-width: 420px; margin-left: auto; margin-right: auto; }

    .loading-wrap { text-align: center; padding: 60px 20px; }
    .spinner-ring { width: 38px; height: 38px; border: 3px solid rgba(255,255,255,0.15); border-top-color: var(--td-primary); border-radius: 50%; animation: ml-spin 0.8s linear infinite; margin: 0 auto 14px; }
    @keyframes ml-spin { to { transform: rotate(360deg); } }

    .btn-print-master { border: 1px solid rgba(255,255,255,0.15); color: rgba(255,255,255,0.7); border-radius: var(--td-radius-xs); font-weight: 600; font-size: 13px; padding: 8px 16px; background: transparent; transition: all var(--td-transition); }
    .btn-print-master:hover:not(:disabled) { background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.25); color: #fff; transform: translateY(-1px); }
    .btn-print-master:disabled { opacity: 0.5; cursor: not-allowed; }

    @media (max-width: 767px) {
        .content-area { padding: 16px; }
        .filter-row { flex-direction: column; }
        .filter-group { min-width: 100%; }
        .btn-filter { width: 100%; justify-content: center; }
        .mobile-title { display: block; }
    }

    /* ===== PRINT LAYOUT: Portrait mode matching the image ===== */
    #printMasterArea { display: none; }
    .print-header-img { display: none; }
    @media print {
        .print-header-img { display: block !important; margin-bottom: 25px; }
        body { padding: 0 !important; }
        @page { margin-top: 0; }
    }
</style>

<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">

    <div class="content-area">
        <?= displayFlashMessage() ?>

        <div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0">Master List</h5>
                <small>View students by subject and grade level</small>
            </div>
        </div>

        <div class="page-title mobile-title">
            <div class="mobile-title-inner">
                <div class="mobile-title-left">
                    <h5>Master List</h5>
                    <small>View students by subject and grade level</small>
                </div>
            </div>
        </div>

        <?php if (!$hasSubjects): ?>
            <div class="card state-card">
                <div class="card-body">
                    <i class="bi bi-exclamation-circle state-icon"></i>
                    <h6>No Subjects Assigned</h6>
                    <p>You are not assigned to any subject yet. Please contact your administrator to assign the subjects you handle so you can view the master list.</p>
                </div>
            </div>
        <?php else: ?>

        <!-- ===================== FILTER CONTAINER ===================== -->
        <div class="card filter-card mb-4">
            <div class="card-header d-flex align-items-center gap-1">
                <i class="bi bi-funnel"></i>
                <span>Filter Students</span>
            </div>
            <div class="card-body">
                <form id="masterListFilterForm" autocomplete="off">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="subjectFilter">Subject (handled by you)</label>
                            <select name="subject_id" id="subjectFilter">
                                <option value="">-- All Subjects --</option>
                                <?php foreach ($mySubjects as $subj): ?>
                                    <option value="<?= (int)$subj['id'] ?>">
                                        <?= sanitize($subj['subject_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="gradeFilter">Grade Level</label>
                            <select name="grade_level" id="gradeFilter">
                                <option value="">-- Select Grade Level --</option>
                                <?php foreach ($allGradeLevels as $gl): ?>
                                    <option value="<?= sanitize($gl) ?>">Grade <?= sanitize($gl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="sectionFilter">Section</label>
                            <select name="section_id" id="sectionFilter">
                                <option value="">-- All Sections --</option>
                                <?php foreach ($teacherSections as $sec): ?>
                                    <?php
                                    $secLabel = sanitize($sec['section_name']);
                                    if (!empty($sec['strand_code'])) { $secLabel .= ' (' . sanitize($sec['strand_code']) . ')'; }
                                    elseif (!empty($sec['strand_name'])) { $secLabel .= ' (' . sanitize($sec['strand_name']) . ')'; }
                                    ?>
                                    <option value="<?= (int)$sec['id'] ?>" data-grade="<?= sanitize($sec['grade_level']) ?>" data-strand="<?= sanitize($sec['strand_name'] ?? '') ?>">
                                        <?= $secLabel ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn-filter" id="applyFilterBtn">
                            <i class="bi bi-search"></i> Show Master List
                        </button>
                        <div class="d-flex gap-2">
                            <select id="printOrientation" class="form-select" style="width:auto;display:inline-block;appearance:none;-webkit-appearance:none;background-image:url(&quot;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='rgba(255,255,255,0.4)' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E&quot;);background-repeat:no-repeat;background-position:right 10px center;background-size:12px;padding-right:32px;cursor:pointer;">
                                <option value="portrait">Portrait</option>
                                <option value="landscape">Landscape</option>
                            </select>
                            <button type="button" class="btn btn-outline-secondary btn-print-master d-none d-md-flex" id="printMasterBtn" disabled>
                                <i class="bi bi-printer me-1"></i> Print
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-print-master d-md-none" id="printMasterBtnMobile" disabled>
                                <i class="bi bi-printer me-1"></i> Print
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- ===================== STUDENT CONTAINER ===================== -->
        <div id="studentContainer">
            <div class="card state-card">
                <div class="card-body">
                    <i class="bi bi-people state-icon"></i>
                    <h6>No Filter Applied</h6>
                    <p>Select a subject (optional) and a grade level from the filters above, then click <strong>"Show Master List"</strong> to view the students separated by gender in alphabetical order.</p>
                </div>
            </div>
        </div>

        <!-- ===================== PRINT AREA (hidden on screen) ===================== -->
        <div id="printMasterArea" aria-hidden="true"></div>
        <!-- Hidden print header for reuse in JS -->
        <div id="printHeaderClone" style="display:none;" aria-hidden="true">
            <img src="<?= BASE_URL ?>/assets/images/header.jpg" alt="Header" class="print-header-img" style="width:100%;max-height:180px;object-fit:contain;">
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($hasSubjects): ?>
<script>
    // Subject id -> list of grade levels the teacher handles for that subject.
    const subjectGradesMap = <?= json_encode($subjectGradesMap) ?>;
    const allGradeLevels   = <?= json_encode($allGradeLevels) ?>;
    const sectionGradeMap  = <?= json_encode($sectionGradeMap) ?>;
    
    const teacherName = '<?= addslashes(sanitize($teacherName)) ?>';
    const principalName = '<?= addslashes(sanitize($principalName)) ?>';
    const advisoryGrade = '<?= addslashes(sanitize($advisoryGrade)) ?>';
    const advisorySectionName = '<?= addslashes(sanitize($advisorySectionName)) ?>';

    const subjectSelect = document.getElementById('subjectFilter');
    const gradeSelect   = document.getElementById('gradeFilter');
    const sectionSelect = document.getElementById('sectionFilter');
    const filterForm    = document.getElementById('masterListFilterForm');
    const container     = document.getElementById('studentContainer');
    const applyBtn      = document.getElementById('applyFilterBtn');
    const printBtn      = document.getElementById('printMasterBtn');
    const printBtnMobile= document.getElementById('printMasterBtnMobile');
    const printArea     = document.getElementById('printMasterArea');

    let lastMasterData = null;
    let selectedStrandName = '';

    function refreshGradeOptions() {
        const subjectId = subjectSelect.value;
        let grades = allGradeLevels;
        if (subjectId && subjectGradesMap[subjectId] && subjectGradesMap[subjectId].length) {
            grades = subjectGradesMap[subjectId];
        }
        gradeSelect.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- Select Grade Level --';
        gradeSelect.appendChild(placeholder);
        grades.forEach(g => {
            const opt = document.createElement('option');
            opt.value = g;
            opt.textContent = 'Grade ' + g;
            gradeSelect.appendChild(opt);
        });

        gradeSelect.disabled = false;
        gradeSelect.value = '';
        refreshSectionOptions();
    }

    function refreshSectionOptions() {
        const grade = gradeSelect.value;
        Array.prototype.forEach.call(sectionSelect.options, function (opt) {
            if (opt.value === '') return;
            const g = opt.getAttribute('data-grade');
            const match = (grade === '' || g === grade);
            opt.style.display = match ? '' : 'none';
            opt.disabled = !match;
        });
        if (sectionSelect.value && sectionSelect.selectedOptions.length && sectionSelect.selectedOptions[0].disabled) {
            sectionSelect.value = '';
        }
        if (sectionSelect.value && sectionSelect.selectedOptions.length) {
            selectedStrandName = sectionSelect.selectedOptions[0].getAttribute('data-strand') || '';
        } else {
            selectedStrandName = '';
        }
    }

    subjectSelect.addEventListener('change', refreshGradeOptions);
    gradeSelect.addEventListener('change', refreshSectionOptions);
    sectionSelect.addEventListener('change', function() {
        if (this.value && this.selectedOptions.length) {
            selectedStrandName = this.selectedOptions[0].getAttribute('data-strand') || '';
        } else {
            selectedStrandName = '';
        }
    });
    refreshGradeOptions();

    filterForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const gradeLevel = gradeSelect.value;
        const subjectId  = subjectSelect.value;
        const sectionId  = sectionSelect.value;

        if (sectionId && sectionSelect.selectedOptions.length) {
            selectedStrandName = sectionSelect.selectedOptions[0].getAttribute('data-strand') || '';
        } else {
            selectedStrandName = '';
        }

        if (!gradeLevel) {
            container.innerHTML = stateCard(
                'bi-exclamation-triangle',
                'Select a Grade Level',
                'Please choose a grade level' + (subjectId ? ' for the selected subject' : '') + ' to view the master list.'
            );
            return;
        }

        applyBtn.disabled = true;
        if (printBtn) { printBtn.disabled = true; }
        if (printBtnMobile) { printBtnMobile.disabled = true; }
        lastMasterData = null;
        container.innerHTML =
            '<div class="loading-wrap"><div class="spinner-ring"></div>' +
            '<div style="font-size:13px;opacity:.6;">Loading master list…</div></div>';

        const params = new URLSearchParams({ grade_level: gradeLevel });
        if (subjectId) { params.set('subject_id', subjectId); }
        if (sectionId) { params.set('section_id', sectionId); }

        fetch('<?= BASE_URL ?>/api/teacher-master-list.php?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            if (!data || data.success !== true) {
                container.innerHTML = stateCard('bi-exclamation-circle', 'Unable to Load', (data && data.message) ? data.message : 'Something went wrong while loading the list.');
                return;
            }
            if (data.section && data.section.strand_name) {
                selectedStrandName = data.section.strand_name;
            }
            renderStudents(data);
        })
        .catch(() => {
            container.innerHTML = stateCard('bi-wifi-off', 'Connection Error', 'Could not reach the server. Please try again.');
        })
        .finally(() => { applyBtn.disabled = false; });
    });

    function stateCard(icon, title, text) {
        return '<div class="card state-card"><div class="card-body">' +
            '<i class="bi ' + icon + ' state-icon"></i>' +
            '<h6>' + title + '</h6>' +
            '<p>' + text + '</p></div></div>';
    }

    function studentTable(title, icon, headerClass, list) {
        let body;
        if (!list.length) {
            body = '<div class="empty-state"><i class="bi bi-person-x"></i>' +
                   '<p>No ' + title.toLowerCase() + ' found for this grade level.</p></div>';
        } else {
            let rows = '';
            list.forEach(function (s, i) {
                const mi = s.middle_name ? s.middle_name.charAt(0).toUpperCase() + '.' : '';
                rows += '<tr>' +
                    '<td class="ml-no">' + (i + 1) + '</td>' +
                    '<td class="ml-last">' + escapeHtml(s.last_name) + (s.name_extension ? ' ' + escapeHtml(s.name_extension) : '') + '</td>' +
                    '<td class="ml-first">' + escapeHtml(s.first_name) + '</td>' +
                    '<td class="ml-mi">' + escapeHtml(mi) + '</td>' +
                '</tr>';
            });
            body = '<div class="table-responsive"><table class="table master-list-table">' +
                '<thead><tr>' +
                    '<th class="ml-no">No.</th>' +
                    '<th class="ml-last">Lastname</th>' +
                    '<th class="ml-first">Firstname</th>' +
                    '<th class="ml-mi">MI</th>' +
                '</tr></thead><tbody>' + rows + '</tbody></table></div>';
        }
        return '<div class="col-md-6 mb-4">' +
            '<div class="card gender-column">' +
                '<div class="card-header ' + headerClass + '">' +
                    '<i class="bi ' + icon + '"></i><span>' + title + '</span>' +
                    '<span class="badge ms-auto">' + list.length + '</span>' +
                '</div>' +
                '<div class="card-body">' + body + '</div>' +
            '</div></div>';
    }

    function renderStudents(data) {
        const subjectLabel = data.subject
            ? ' &middot; ' + escapeHtml(data.subject.subject_name)
            : '';
        const sectionLabel = data.section
            ? ' &middot; Section ' + escapeHtml(data.section.section_name || data.section)
            : '';
        const header =
            '<div class="result-header">' +
                '<h6><i class="bi bi-people me-1"></i>' +
                    'Students — Grade ' + escapeHtml(data.grade_level) + subjectLabel + sectionLabel +
                    '<span class="result-count">' + data.total + ' total</span>' +
                '</h6>' +
            '</div>';

        const columns =
            '<div class="row g-4">' +
                studentTable('Boys', 'bi-gender-male', 'male-header-bg', data.boys) +
                studentTable('Girls', 'bi-gender-female', 'female-header-bg', data.girls) +
            '</div>';

        container.innerHTML = header + columns;

        lastMasterData = data;
        if (printBtn) { printBtn.disabled = false; }
        if (printBtnMobile) { printBtnMobile.disabled = false; }
    }

    /* ---- Build a printable report matching the CLASS MASTERLIST format from the image ---- */
    function buildPrintReport(data) {
        const schoolYear = 'SY 2025 – 2026';
        const gradeLevel = data.grade_level || '';
        const sectionName = data.section || '';
        const strandName = selectedStrandName || '';

        const isAdvisory = String(gradeLevel) === advisoryGrade &&
            (!advisorySectionName || String(sectionName) === advisorySectionName);
        
        let gradeSectionHeader = 'GRADE ' + gradeLevel;
        
        if (sectionName) {
            gradeSectionHeader += '-' + sectionName;
        }
        if (strandName) {
            gradeSectionHeader += ' - ' + strandName;
        }

        const boyCount = (data.boys || []).length;
        const girlCount = (data.girls || []).length;
        const totalCount = boyCount + girlCount;

        // Build male table
        let maleRows = '';
        if (!boyCount) {
            maleRows = '<tr><td colspan="2" style="border:1px solid #333;padding:8px 6px;text-align:center;font-size:10px;">No male students found.</td></tr>';
        } else {
            data.boys.forEach(function(s, i) {
                const fullName = s.last_name + ', ' + s.first_name + (s.name_extension ? ' ' + s.name_extension : '') + (s.middle_name ? ' ' + s.middle_name.charAt(0).toUpperCase() + '.' : '');
                maleRows += '<tr>' +
                    '<td style="border:1px solid #333;padding:4px 6px;text-align:center;width:40px;">' + (i + 1) + '</td>' +
                    '<td style="border:1px solid #333;padding:4px 6px;">' + escapeHtml(fullName) + '</td>' +
                '</tr>';
            });
        }

        // Build female table
        let femaleRows = '';
        if (!girlCount) {
            femaleRows = '<tr><td colspan="2" style="border:1px solid #333;padding:8px 6px;text-align:center;font-size:10px;">No female students found.</td></tr>';
        } else {
            data.girls.forEach(function(s, i) {
                const fullName = s.last_name + ', ' + s.first_name + (s.name_extension ? ' ' + s.name_extension : '') + (s.middle_name ? ' ' + s.middle_name.charAt(0).toUpperCase() + '.' : '');
                femaleRows += '<tr>' +
                    '<td style="border:1px solid #333;padding:4px 6px;text-align:center;width:40px;">' + (i + 1) + '</td>' +
                    '<td style="border:1px solid #333;padding:4px 6px;">' + escapeHtml(fullName) + '</td>' +
                '</tr>';
            });
        }

        const headerEl = document.getElementById('printHeaderClone');
        const headerHTML = headerEl ? headerEl.innerHTML : '';

        return headerHTML + `
            <div class="ml-report-container">
                <div class="ml-report-title">CLASS MASTERLIST</div>
                <div class="ml-report-meta">${schoolYear}</div>
                <div class="ml-report-meta">${gradeSectionHeader}</div>
                
                <div class="ml-print-row">
                    <div class="ml-print-col">
                        <table class="ml-print-table">
                            <thead>
                                <tr>
                                    <th style="border:1px solid #333;padding:4px 6px;text-align:center;width:40px;background:#e8f0fe;font-weight:700;text-transform:uppercase;font-size:10px;">NO</th>
                                    <th style="border:1px solid #333;padding:4px 6px;background:#e8f0fe;font-weight:700;text-transform:uppercase;font-size:10px;">MALE</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${maleRows}
                            </tbody>
                        </table>
                        <div style="margin-top:4px;font-weight:700;font-size:11px;">MALE - ${boyCount}</div>
                    </div>
                    <div class="ml-print-col">
                        <table class="ml-print-table">
                            <thead>
                                <tr>
                                    <th style="border:1px solid #333;padding:4px 6px;text-align:center;width:40px;background:#fde7f3;font-weight:700;text-transform:uppercase;font-size:10px;">NO</th>
                                    <th style="border:1px solid #333;padding:4px 6px;background:#fde7f3;font-weight:700;text-transform:uppercase;font-size:10px;">FEMALE</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${femaleRows}
                            </tbody>
                        </table>
                        <div style="margin-top:4px;font-weight:700;font-size:11px;">FEMALE - ${girlCount}</div>
                    </div>
                </div>

                <div style="text-align:center;font-weight:700;font-size:12px;font-family:Arial,sans-serif;margin-top:12px;">TOTAL — ${totalCount}</div>

<div style="margin-top:20px;padding-top:8px;">
                <div style="display:flex;justify-content:space-between;gap:30px;">
                        <div style="flex:1;text-align:center;">
                            <hr style="border:none;border-top:1px solid #94a3b8;margin:0 auto 4px auto;width:70%;">
                            <strong>${teacherName}</strong><br>
                            <span>${isAdvisory ? 'Adviser' : 'Subject Teacher'}</span>
                        </div>
                        <div style="flex:1;text-align:center;">
                            <hr style="border:none;border-top:1px solid #94a3b8;margin:0 auto 4px auto;width:70%;">
                            <strong>${principalName}</strong><br>
                            <span>OIC/Assistant Principal</span>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    function printMasterList() {
        if (!lastMasterData) { return; }
        const orient = (document.getElementById('printOrientation') || {}).value || 'portrait';
        const isLandscape = orient === 'landscape';
        printArea.innerHTML = buildPrintReport(lastMasterData);
        var pw = window.open('', '_blank', 'width=' + (isLandscape ? '1200' : '900') + ',height=' + (isLandscape ? '800' : '700'));
        var pageSize = isLandscape ? 'A4 landscape' : 'A4 portrait';
        var bodyPad = isLandscape ? '12px 15px' : '20px 25px';
        pw.document.write(
            '<!DOCTYPE html><html><head><title>Class Masterlist</title>' +
            '<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/print.css">' +
            '<style>' +
            'body.print-new-window{margin:0;padding:' + bodyPad + ';background:#fff;font-family:"Segoe UI",Arial,sans-serif;color:#333;}' +
            '@page{size:' + pageSize + ';margin:0 12mm 15mm 12mm;}' +
            '.print-header-img{width:100%;max-height:160px;object-fit:contain;display:block;margin-bottom:20px;}' +
            '.ml-report-container{max-width:100%;padding:0;}' +
            '.ml-report-title{text-align:center;font-size:18px;font-weight:800;letter-spacing:0.05em;text-transform:uppercase;margin-bottom:2px;font-family:Arial,sans-serif;}' +
            '.ml-report-meta{text-align:center;font-size:13px;font-weight:600;margin-bottom:2px;color:#000;font-family:Arial,sans-serif;}' +
            '.ml-print-row{display:flex;gap:20px;align-items:flex-start;margin-top:12px;}' +
            '.ml-print-col{flex:1;min-width:0;}' +
            '.ml-print-table{width:100%;border-collapse:collapse;font-size:11px;font-family:Arial,sans-serif;}' +
            '.ml-print-table th{border:1px solid #333;padding:4px 6px;background:#f1f1f1;font-weight:700;text-transform:uppercase;font-size:10px;text-align:center;}' +
            '.ml-print-table td{border:1px solid #333;padding:4px 6px;font-size:10px;font-family:Arial,sans-serif;}' +
            '.ml-print-table tr:nth-child(even){background:#fafafa;}' +
'.ml-print-table th:first-child,.ml-print-table td:first-child{width:40px;text-align:center;}' +
              '.ml-col-header-male{background:#e8f0fe;}' +
             '.ml-col-header-female{background:#fde7f3;}' +
             '@media print{body.print-new-window{padding:0 !important;margin:0;}.ml-print-row{gap:10px;}}' +
             '</style></head>' +
            '<body class="print-new-window">' +
            printArea.innerHTML +
            '<script>window.onload=function(){setTimeout(function(){window.print();},100);};<\/script>' +
            '</body></html>'
        );
        pw.document.close();
    }

    if (printBtn) { printBtn.addEventListener('click', printMasterList); }
    if (printBtnMobile) { printBtnMobile.addEventListener('click', printMasterList); }

    function escapeHtml(str) {
        if (str === null || str === undefined) { return ''; }
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>