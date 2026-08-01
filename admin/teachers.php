<?php

require_once __DIR__ . '/../config.php';
requireRole(['admin']);
$pageTitle = 'Teacher Management';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

try {
    $col = $db->query("SHOW COLUMNS FROM teachers LIKE 'grade_section_handled'")->fetchAll();
    if (empty($col)) $db->exec("ALTER TABLE teachers ADD COLUMN grade_section_handled TEXT DEFAULT NULL AFTER subjects_handled");
} catch (Exception $e) { error_log('teachers mig grade_section_handled: ' . $e->getMessage()); }
try {
    $col = $db->query("SHOW COLUMNS FROM teachers LIKE 'core_subjects_handled'")->fetchAll();
    if (empty($col)) $db->exec("ALTER TABLE teachers ADD COLUMN core_subjects_handled TEXT DEFAULT NULL AFTER grade_section_handled");
} catch (Exception $e) { error_log('teachers mig core_subjects_handled: ' . $e->getMessage()); }
try {
    $col = $db->query("SHOW COLUMNS FROM teachers LIKE 'track_elective_handled'")->fetchAll();
    if (empty($col)) $db->exec("ALTER TABLE teachers ADD COLUMN track_elective_handled TEXT DEFAULT NULL AFTER core_subjects_handled");
} catch (Exception $e) { error_log('teachers mig track_elective_handled: ' . $e->getMessage()); }
try {
    $col = $db->query("SHOW COLUMNS FROM teachers LIKE 'middle_name'")->fetchAll();
    if (empty($col)) $db->exec("ALTER TABLE teachers ADD COLUMN middle_name VARCHAR(100) DEFAULT '' AFTER first_name");
} catch (Exception $e) { error_log('teachers mig middle_name: ' . $e->getMessage()); }


$search = sanitize($_GET['search'] ?? '');
$page   = max(1, intval($_GET['page'] ?? 1));
$perPage = PER_PAGE;

$filters = $search ? ['search' => $search] : [];
$totalTeachers = count(getTeachers($db, $filters));
$totalPages = max(1, ceil($totalTeachers / $perPage));
$teachers = getTeachers($db, $filters, $perPage, ($page - 1) * $perPage);

$activeTeachers = 0;
$advisoryCount  = 0;
try { $activeTeachers = $db->query("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND status = 'active'")->fetchColumn(); } catch (Exception $e) {}
try { $advisoryCount = $db->query("SELECT COUNT(*) FROM teachers WHERE advisory_class IS NOT NULL AND advisory_class != ''")->fetchColumn(); } catch (Exception $e) {}

$teacherSubjects = [];
$teacherSections = [];
$shsSections = [];
$tracks = [];
$electives = [];
$electiveSubjects = [];
$electiveSubjectIds = [];
$coreSubjectOptions = [];
try {
    $teacherSubjects = $db->query("SELECT id, subject_name, grade_level, grade_level_end, strand_id FROM subjects ORDER BY subject_name ASC")->fetchAll();
} catch (Exception $e) {}
try {
    $teacherSections = $db->query("SELECT s.id, s.section_name, s.grade_level, s.strand_id, st.strand_name, st.strand_code FROM sections s LEFT JOIN strands st ON s.strand_id = st.id ORDER BY CAST(s.grade_level AS UNSIGNED) ASC, s.section_name ASC")->fetchAll();
} catch (Exception $e) {}
try {
    $shsSections = $db->query("SELECT s.id, s.section_name, s.grade_level, s.strand_id, st.strand_name, st.strand_code FROM sections s LEFT JOIN strands st ON s.strand_id = st.id WHERE CAST(s.grade_level AS UNSIGNED) >= 11 ORDER BY CAST(s.grade_level AS UNSIGNED) ASC, s.section_name ASC")->fetchAll();
} catch (Exception $e) {}
try {
    $tracks = $db->query("SELECT id, track_name FROM tracks ORDER BY track_name ASC")->fetchAll();
} catch (Exception $e) {}
try {
    $electives = $db->query("SELECT id, track_id, elective_name FROM electives ORDER BY elective_name ASC")->fetchAll();
} catch (Exception $e) {}
try {
    $electiveSubjects = $db->query("SELECT es.id, es.elective_id, s.id as subject_id, s.subject_name FROM elective_subjects es LEFT JOIN subjects s ON es.subject_id = s.id ORDER BY s.subject_name ASC")->fetchAll();
    $electiveSubjectIds = array_column($electiveSubjects, 'subject_id');
} catch (Exception $e) {}
$coreSubjectOptions = array_values(array_filter($teacherSubjects, function($s) use ($electiveSubjectIds) {
    return !in_array($s['id'], $electiveSubjectIds, true);
}));

function buildTeacherSubjectOptions($subjects, $selectedValues = []) {
    $selected = [];
    if (!empty($selectedValues)) {
        if (!is_array($selectedValues)) {
            $selectedValues = array_map('trim', preg_split('/[\r\n,;]+/', (string)$selectedValues));
        }
        $selected = array_values(array_filter(array_map('trim', $selectedValues)));
    }

    $html = '';
    foreach ($subjects as $subject) {
        $value = trim((string)($subject['subject_name'] ?? ''));
        if ($value === '') {
            continue;
        }
        $label = $value;
        if (!empty($subject['grade_level'])) {
            $label .= ' (Grade ' . $subject['grade_level'];
            if (!empty($subject['grade_level_end']) && $subject['grade_level_end'] !== $subject['grade_level']) {
                $label .= '-' . $subject['grade_level_end'];
            }
            $label .= ')';
        }
        $checked = in_array($value, $selected, true) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . $checked . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }

    return $html;
}

function buildTeacherAdvisoryOptions($sections, $selectedValue = '') {
    $options = ['<option value="">Select advisory class</option>'];
    foreach ($sections as $section) {
        $grade = trim((string)($section['grade_level'] ?? ''));
        $sectionName = trim((string)($section['section_name'] ?? ''));
        $strandName = trim((string)($section['strand_name'] ?? ''));
        $label = formatAdvisoryClassLabel($grade, $sectionName, $strandName);
        $value = $label;
        $selected = $selectedValue !== '' && $value === $selectedValue ? ' selected' : '';
        $options[] = '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }

    return implode('', $options);
}

function getTeacherCoreSubjectNames($teacher) {
    $raw = $teacher['core_subjects_handled'] ?? '';
    if (empty($raw)) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    $ids = array_values(array_filter(array_map(function ($item) {
        return is_numeric($item['subject_id'] ?? '') ? (int)$item['subject_id'] : 0;
    }, $data)));
    return $ids;
}

function getTeacherTrackElectiveDetails($teacher, $sections = [], $subjects = [], $tracks = [], $electives = []) {
    $raw = $teacher['track_elective_handled'] ?? '';
    if (empty($raw)) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    
    $sectionMap = [];
    foreach ($sections as $s) { $sectionMap[$s['id']] = $s; }
    $subjectMap = [];
    foreach ($subjects as $s) { $subjectMap[$s['id']] = $s; }
    $trackMap = [];
    foreach ($tracks as $t) { $trackMap[$t['id']] = $t; }
    $electiveMap = [];
    foreach ($electives as $e) { $electiveMap[$e['id']] = $e; }
    
    $results = [];
    foreach ($data as $item) {
        $sid = $item['section_id'] ?? '';
        $tid = $item['track_id'] ?? '';
        $eid = $item['elective_id'] ?? '';
        $subjid = $item['subject_id'] ?? '';
        
        $sectionText = '';
        if ($sid && isset($sectionMap[$sid])) {
            $s = $sectionMap[$sid];
            $sectionText = trim((string)($s['grade_level'] . ($s['section_name'] ? ' - ' . $s['section_name'] : '')));
        }
        
        $trackText = $tid && isset($trackMap[$tid]) ? $trackMap[$tid]['track_name'] : '';
        $electiveText = $eid && isset($electiveMap[$eid]) ? $electiveMap[$eid]['elective_name'] : '';
        $subjectText = $subjid && isset($subjectMap[$subjid]) ? $subjectMap[$subjid]['subject_name'] : '';
        
        $results[] = [
            'section' => $sectionText,
            'track' => $trackText,
            'elective' => $electiveText,
            'subject' => $subjectText,
        ];
    }
    return $results;
}

function getTeacherGradeSectionDetails($teacher, $sections = [], $subjects = []) {
    $raw = $teacher['grade_section_handled'] ?? '';
    if (empty($raw)) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    
    $sectionMap = [];
    foreach ($sections as $s) { $sectionMap[$s['id']] = $s; }
    $subjectMap = [];
    foreach ($subjects as $s) { $subjectMap[$s['id']] = $s; }
    
    $results = [];
    foreach ($data as $item) {
        $sid = $item['section_id'] ?? '';
        $subjid = $item['subject_id'] ?? '';
        
        $sectionText = '';
        if ($sid && isset($sectionMap[$sid])) {
            $s = $sectionMap[$sid];
            $sectionText = trim((string)($s['grade_level'] . ($s['section_name'] ? ' - ' . $s['section_name'] : '')));
        }
        
        $subjectText = $subjid && isset($subjectMap[$subjid]) ? $subjectMap[$subjid]['subject_name'] : '';
        
        $results[] = [
            'section' => $sectionText,
            'subject' => $subjectText,
        ];
    }
    return $results;
}

function formatSubjectDetailRows($items) {
    if (empty($items)) return '';
    $html = '<div style="display:flex;flex-direction:column;gap:2px;margin-top:4px">';
    foreach ($items as $item) {
        $line = trim(($item['section'] ? $item['section'] . ' - ' : '') . ($item['subject'] ? $item['subject'] : ''));
        if ($line) {
            $html .= '<span style="font-size:11px;color:#000;line-height:1.35">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</span>';
        }
    }
    $html .= '</div>';
    return $html;
}

function getTeacherCoreSubjectDetails($teacher, $sections = [], $subjects = []) {
    $raw = $teacher['core_subjects_handled'] ?? '';
    if (empty($raw)) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    
    $sectionMap = [];
    foreach ($sections as $s) { $sectionMap[$s['id']] = $s; }
    $subjectMap = [];
    foreach ($subjects as $s) { $subjectMap[$s['id']] = $s; }
    
    $results = [];
    foreach ($data as $item) {
        $sid = $item['section_id'] ?? '';
        $subjid = $item['subject_id'] ?? '';
        
        $sectionText = '';
        if ($sid && isset($sectionMap[$sid])) {
            $s = $sectionMap[$sid];
            $sectionText = trim((string)($s['grade_level'] . ($s['section_name'] ? ' - ' . $s['section_name'] : '')));
        }
        
        $subjectText = $subjid && isset($subjectMap[$subjid]) ? $subjectMap[$subjid]['subject_name'] : '';
        
        $results[] = [
            'section' => $sectionText,
            'subject' => $subjectText,
        ];
    }
    return $results;
}

function formatTeacherTrackElectiveText($details) {
    if (empty($details)) return '';
    $parts = [];
    foreach ($details as $d) {
        $line = trim(($d['section'] ? $d['section'] . ' - ' : '') . ($d['track'] ? $d['track'] : '') . ($d['elective'] ? ' - ' . $d['elective'] : '') . ($d['subject'] ? ' - ' . $d['subject'] : ''));
        if ($line) $parts[] = $line;
    }
    return implode('; ', $parts);
}

?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<style>
    :root {
        --tm-font: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        --tm-mono: 'JetBrains Mono', monospace;
        --tm-primary: #4f46e5; --tm-primary-light: rgba(79,70,229,0.15); --tm-primary-dark: #3730a3; --tm-primary-glow: rgba(79,70,229,0.2);
        --tm-success: #10b981; --tm-success-light: rgba(16,185,129,0.15);
        --tm-danger: #ef4444; --tm-danger-light: rgba(239,68,68,0.15);
        --tm-warning: #f59e0b; --tm-warning-light: rgba(245,158,11,0.15);
        --tm-info: #06b6d4; --tm-info-light: rgba(6,182,212,0.15);
        --tm-radius: 14px; --tm-radius-sm: 10px; --tm-radius-xs: 8px;
        --tm-shadow: 0 4px 16px rgba(0,0,0,0.25);
        --tm-transition: 0.2s cubic-bezier(0.4,0,0.2,1);
        --select-bg: #1e2a4a; --select-bg-hover: #2563eb; --select-bg-placeholder: #151d33;
        --select-text: #ffffff; --select-text-muted: rgba(255,255,255,0.45);
    }

    /* NAVBAR */
    .page-title h5
    select,.form-select{appearance:none;-webkit-appearance:none;-moz-appearance:none;background-color:rgba(255,255,255,0.05);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='rgba(255,255,255,0.5)' viewBox='0 0 16 16'%3E%3Cpath d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;background-size:12px;padding-right:40px;cursor:pointer;border:1.5px solid rgba(255,255,255,0.12);border-radius:var(--tm-radius-sm);color:inherit;font-size:14px;font-family:var(--tm-font);transition:all var(--tm-transition)}
    select:focus,.form-select:focus{outline:none;border-color:var(--tm-primary);box-shadow:0 0 0 3px var(--tm-primary-glow)}
    select option,.form-select option{background:var(--select-bg);color:var(--select-text);padding:10px 14px;font-size:14px;line-height:1.6}
    select option:hover,select option:checked,select option:active{background:var(--select-bg-hover);color:var(--select-text)}
    select option[value=""]{color:var(--select-text-muted);background:var(--select-bg-placeholder)}
    select:disabled{opacity:0.45;cursor:not-allowed}

    /* STAT CARDS */
    .stat-card{transition:all var(--tm-transition);position:relative;overflow:hidden}
    .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--tm-radius) var(--tm-radius) 0 0;opacity:0;transition:opacity var(--tm-transition)}
    .stat-card:hover{transform:translateY(-3px)}.stat-card:hover::before{opacity:1}
    .stat-card:nth-child(1)::before{background:var(--tm-primary)}.stat-card:nth-child(2)::before{background:var(--tm-success)}.stat-card:nth-child(3)::before{background:var(--tm-warning)}
    @keyframes tmFadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
    .stat-card{animation:tmFadeUp .5s ease forwards;opacity:0}
    .stat-card:nth-child(1){animation-delay:.05s}.stat-card:nth-child(2){animation-delay:.1s}.stat-card:nth-child(3){animation-delay:.15s}

    /* SEARCH */
    .search-form .input-icon-wrapper{position:relative}
    .search-form .input-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:rgba(255,255,255,0.35);font-size:15px;pointer-events:none}
    .search-form .form-control{padding-left:42px}

    /* PAGE HEADER */
    .page-header-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;margin-bottom:24px}
    .page-header-left h5{font-size:22px;font-weight:800;letter-spacing:-0.03em;margin:0;color:#fff}
    .page-header-left small{font-size:13px;font-weight:500;color:rgba(255,255,255,0.55);display:block;margin-top:3px}
    .page-header-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
    .page-header-mobile{display:none;padding:8px 2px 14px}
    .page-header-mobile h5{font-size:18px;font-weight:800;letter-spacing:-0.03em;margin:0;color:#fff}
    .page-header-mobile small{font-size:12px;font-weight:500;color:rgba(255,255,255,0.55);display:block;margin-top:2px}
    .page-header-mobile-actions{display:flex;align-items:center;gap:6px;margin-top:12px;flex-wrap:wrap}
    .page-header-mobile-actions .btn-add-teacher{padding:8px 14px;font-size:12px}

    /* TABLE */
    .teacher-table{margin:0}
    .teacher-table thead th{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:14px 16px;border-bottom:1px solid rgba(255,255,255,0.06)}
    .teacher-table tbody td{padding:14px 16px;vertical-align:middle;font-size:13px;border-bottom:1px solid rgba(255,255,255,0.04)}
    .teacher-table tbody tr{transition:background var(--tm-transition)}.teacher-table tbody tr:hover{background:rgba(255,255,255,0.02)}
    .teacher-name{font-weight:600;font-size:14px}
    .teacher-id code{font-family:var(--tm-mono);font-size:12px;font-weight:500;padding:3px 8px;border-radius:6px}
    .teacher-email a{font-size:13px;text-decoration:none;transition:color var(--tm-transition)}.teacher-email a:hover{text-decoration:underline}
    .teacher-subjects .badge,.teacher-advisory .badge{font-size:11px;font-weight:600;padding:4px 10px;border-radius:6px}
    .action-btns{display:flex;gap:6px}
    .btn-icon{width:34px;height:34px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:var(--tm-radius-xs);transition:all var(--tm-transition)}.btn-icon:hover{transform:translateY(-1px)}.btn-icon i{font-size:14px}

    /* TABLE SCROLL */
    .teacher-table-wrapper{overflow:hidden}
    .table-scroll-wrapper{overflow-x:auto;-webkit-overflow-scrolling:touch}
    .table-scroll-wrapper::-webkit-scrollbar{height:6px}
    .table-scroll-wrapper::-webkit-scrollbar-track{background:rgba(255,255,255,0.03)}
    .table-scroll-wrapper::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.12);border-radius:6px}
    .table-scroll-wrapper::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,0.2)}
    .teacher-table{min-width:900px}

    /* PAGINATION */
    .pagination-wrapper{padding:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
    .pagination-info{font-size:13px;font-weight:500}.pagination-info strong{font-weight:700;font-family:var(--tm-mono)}
    .pagination .page-link{font-size:13px;font-weight:600;padding:8px 14px;border-radius:var(--tm-radius-xs)!important;margin:0 2px;transition:all var(--tm-transition)}.page-link:hover{transform:translateY(-1px)}
    .pagination-page-info{display:none;font-size:12px;font-weight:600}

    /* =====================================================
       MODAL — compact form fits perfectly
       ===================================================== */
    .event-modal-overlay{
        position:fixed;inset:0;
        background:rgba(26,29,46,0.5);
        backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);
        z-index:9998;display:none;
        align-items:center;justify-content:center;padding:20px;
    }
    .event-modal-overlay.show{display:flex}
    .event-modal{
        border-radius:20px;width:min(620px, 100%);max-width:100%;
        height:90vh;max-height:90vh;
        display:flex;flex-direction:column;overflow:hidden;
        animation:modalSlideIn .35s cubic-bezier(.34,1.56,.64,1);
    }
    .event-modal form{
        display:flex;flex-direction:column;flex:1 1 auto;min-height:0;
    }
    @keyframes modalSlideIn{from{opacity:0;transform:translateY(24px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}

    /* Header — pinned top */
    .event-modal-header{
        display:flex;justify-content:space-between;align-items:center;
        padding:18px 22px;flex-shrink:0;
    }
    .event-modal-title{display:flex;align-items:center;gap:10px;font-size:16px;font-weight:800;letter-spacing:-0.02em}
    .event-modal-title i{font-size:20px}
    .event-modal-close{width:32px;height:32px;border:none;border-radius:var(--tm-radius-xs);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all var(--tm-transition);font-size:13px;background:rgba(255,255,255,0.06);color:inherit}
    .event-modal-close:hover{background:rgba(255,255,255,0.14)}

    /* Body — scrollable if needed */
    .event-modal-body{
        padding:0 22px 14px;
        overflow-y:auto;overflow-x:hidden;
        flex:1 1 auto;min-height:0;
        -webkit-overflow-scrolling:touch;
    }
    .event-modal-body::-webkit-scrollbar{width:5px}
    .event-modal-body::-webkit-scrollbar-track{background:transparent;margin:4px 0}
    .event-modal-body::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.15);border-radius:10px}
    .event-modal-body::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,0.25)}
    .event-modal-body{scrollbar-width:thin;scrollbar-color:rgba(255,255,255,0.15) transparent}

    /* Footer — pinned bottom */
    .event-modal-footer{
        display:flex;justify-content:flex-end;gap:10px;
        padding:12px 22px;flex-shrink:0;
        border-top:1px solid rgba(255,255,255,0.06);
        background:rgba(255,255,255,0.02);
    }

    /* =====================================================
       FORM FIELDS — compact spacing
       ===================================================== */
    .evt-field{margin-bottom:12px}.evt-field:last-child{margin-bottom:0}
    .evt-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;line-height:1.3}
    .evt-field label .required{color:var(--tm-danger)}
    .evt-field label small{font-weight:500;text-transform:none;letter-spacing:0;opacity:0.55}
    .evt-field input[type="text"],.evt-field input[type="email"],.evt-field input[type="tel"],.evt-field textarea,.evt-field select{
        width:100%;padding:9px 12px;border:1.5px solid rgba(255,255,255,0.12);
        border-radius:var(--tm-radius-sm);font-size:13px;transition:all var(--tm-transition);
        font-family:var(--tm-font);background:rgba(255,255,255,0.05);color:inherit;
    }
    .evt-field input:focus,.evt-field textarea:focus,.evt-field select:focus{outline:none;border-color:var(--tm-primary);box-shadow:0 0 0 3px var(--tm-primary-glow)}
    .evt-field input::placeholder{color:rgba(255,255,255,0.25)}
    .evt-field .form-hint{font-size:10px;margin-top:3px;opacity:0.4;line-height:1.4}
    .evt-field select{background-color:rgba(255,255,255,0.05);padding-right:36px}
    .evt-row{display:flex;gap:10px}.evt-flex-1{flex:1;min-width:0}
    .evt-separator{border:none;border-top:1px solid rgba(255,255,255,0.06);margin:10px 0}
    .evt-section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;display:flex;align-items:center;gap:6px;opacity:0.5}
    .evt-btn{padding:10px 18px;border:none;border-radius:var(--tm-radius-sm);font-size:13px;font-weight:700;cursor:pointer;transition:all var(--tm-transition);display:flex;align-items:center;gap:6px}
    .evt-btn-cancel{background:rgba(255,255,255,0.08);color:inherit}.evt-btn-cancel:hover{background:rgba(255,255,255,0.14)}
    .evt-btn-save{background:var(--tm-primary);color:#fff}.evt-btn-save:hover{background:var(--tm-primary-dark);box-shadow:0 4px 16px var(--tm-primary-glow)}
    .evt-btn-save.loading{opacity:0.7;pointer-events:none}
    .evt-btn-danger{background:var(--tm-danger);color:#fff}.evt-btn-danger:hover{background:#dc2626;box-shadow:0 4px 14px rgba(239,68,68,0.3)}

    /* Delete modal */
    .delete-modal{width:400px;height:auto;max-height:90vh}
    .delete-modal-icon{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:24px}
    .delete-modal-text{text-align:center}
    .delete-modal-text h6{font-weight:700;font-size:16px;letter-spacing:-0.02em;margin-bottom:6px}
    .delete-modal-text p{font-size:13px;max-width:280px;margin:0 auto;line-height:1.5}

    /* TOAST */
    .toast-container{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none}
    .toast-notification{padding:14px 22px;border-radius:var(--tm-radius-sm);color:#fff;font-size:13px;font-weight:600;display:flex;align-items:center;gap:12px;max-width:400px;pointer-events:auto;animation:tmToastIn .4s cubic-bezier(.34,1.56,.64,1),tmToastOut .4s ease 3.6s forwards;font-family:var(--tm-font)}
    .toast-notification i{font-size:18px;flex-shrink:0;opacity:0.9}
    .toast-success{background:#059669}.toast-error{background:#dc2626}.toast-info{background:#0284c7}
    @keyframes tmToastIn{from{opacity:0;transform:translateX(40px) scale(.95)}to{opacity:1;transform:translateX(0) scale(1)}}
    @keyframes tmToastOut{from{opacity:1;transform:translateX(0)}to{opacity:0;transform:translateX(40px)}}

    .empty-state{text-align:center;padding:48px 20px}
    .empty-icon{width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px}
    .empty-state h6{font-weight:700;font-size:16px;margin-bottom:6px}.empty-state p{font-size:13px}

    /* GRADE HANDLED REPEATABLE */
    .add-grade-btn{background:rgba(255,255,255,0.06);color:inherit;border:1.5px dashed rgba(255,255,255,0.18);padding:8px 16px;border-radius:var(--tm-radius-sm);cursor:pointer;font-size:13px;font-weight:700;transition:all var(--tm-transition);display:inline-flex;align-items:center;gap:6px;margin-bottom:12px}
    .add-grade-btn:hover{background:rgba(255,255,255,0.1);border-color:rgba(255,255,255,0.3)}
    .grade-handled-repeatable{display:flex;flex-direction:column;gap:10px}
    .grade-handled-row{display:flex;gap:10px;align-items:flex-end;padding:12px;border:1px solid rgba(255,255,255,0.08);border-radius:var(--tm-radius-sm);background:rgba(255,255,255,0.02)}
    .grade-handled-row .evt-field{flex:1;min-width:0;margin-bottom:0}
    .grade-handled-row .rm-row-btn{background:var(--tm-danger-light);color:var(--tm-danger);border:none;width:32px;height:32px;border-radius:var(--tm-radius-sm);cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;transition:all var(--tm-transition);flex-shrink:0;margin-bottom:2px}
    .grade-handled-row .rm-row-btn:hover{background:var(--tm-danger);color:#fff}

    /* CORE SUBJECTS REPEATABLE */
    .core-subject-row{display:flex;gap:10px;align-items:flex-end;padding:12px;border:1px solid rgba(255,255,255,0.08);border-radius:var(--tm-radius-sm);background:rgba(255,255,255,0.02)}
    .core-subject-row .evt-field{flex:1;min-width:0;margin-bottom:0}
    .core-subject-row .rm-row-btn{background:var(--tm-danger-light);color:var(--tm-danger);border:none;width:32px;height:32px;border-radius:var(--tm-radius-sm);cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;transition:all var(--tm-transition);flex-shrink:0;margin-bottom:2px}
    .core-subject-row .rm-row-btn:hover{background:var(--tm-danger);color:#fff}

    /* TRACK ELECTIVE REPEATABLE */
    .track-elective-row{display:flex;flex-direction:column;gap:10px;align-items:stretch;padding:12px;border:1px solid rgba(255,255,255,0.08);border-radius:var(--tm-radius-sm);background:rgba(255,255,255,0.02)}
    .track-elective-inner-row{display:flex;gap:10px;flex-wrap:wrap}
    .track-elective-inner-row .evt-field{flex:1;min-width:0;margin-bottom:0}
    .track-elective-row .rm-row-btn{background:var(--tm-danger-light);color:var(--tm-danger);border:none;width:32px;height:32px;border-radius:var(--tm-radius-sm);cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;transition:all var(--tm-transition);flex-shrink:0;margin-bottom:2px;align-self:flex-end}
    .track-elective-row .rm-row-btn:hover{background:var(--tm-danger);color:#fff}

    /* TABLET */
    @media(max-width:991px){
        .content-area{padding:20px}
        .event-modal{width:460px}
        .teacher-table thead th{font-size:10px;padding:12px 10px}
        .teacher-table tbody td{padding:12px 10px;font-size:12px}.teacher-name{font-size:13px}
        .navbar-center{max-width:300px}
        .search-box .search-shortcut{display:none}
    }

    /* MOBILE */
    @media(max-width:767px){
        .top-navbar{padding:10px 12px;flex-wrap:wrap;gap:8px;height:auto}
        .navbar-left{flex:1;gap:10px}
        .navbar-center{display:none!important}
        .navbar-right{gap:6px}
        .navbar-profile-compact{max-width:none;padding:4px 10px}
        .navbar-profile-info{display:none}
        .sidebar-toggle{width:38px;height:38px;font-size:20px}
        .brand-logo{width:36px;height:36px}
        .brand-name{font-size:11px}
        .brand-subtitle{font-size:8px}
        .btn-add-teacher{padding:8px 12px!important;font-size:12px!important}.btn-add-teacher .btn-text{display:none}
        .page-header-row{display:none !important}.page-header-mobile{display:block !important}

        .content-area{padding:10px 12px 28px}
        .stat-card{padding:14px 12px}.stat-value{font-size:22px}.stat-label{font-size:10px;margin-top:3px}
        .stat-icon{width:36px;height:36px;font-size:14px;border-radius:10px}
        .stat-card{animation:none;opacity:1}
        .search-form .row{row-gap:8px}.search-form .input-icon{font-size:14px;left:12px}.search-form .form-control{padding-left:38px;font-size:14px}
        .teacher-table-wrapper{overflow:hidden}
        .table-scroll-wrapper{overflow-x:auto;-webkit-overflow-scrolling:touch}
        .table-scroll-wrapper::-webkit-scrollbar{height:6px}
        .table-scroll-wrapper::-webkit-scrollbar-track{background:rgba(255,255,255,0.03)}
        .table-scroll-wrapper::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.12);border-radius:6px}
        .table-scroll-wrapper::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,0.2)}
        .teacher-table{min-width:900px}
        .teacher-table thead th{padding:12px 10px;font-size:10px}
        .teacher-table tbody td{padding:12px 10px;font-size:12px}.teacher-name{font-size:13px}
        .pagination-wrapper{padding:16px 12px;justify-content:center}.pagination-info{display:none}
        .pagination-page-info{display:block;text-align:center;margin-bottom:8px;font-size:12px}
        .pagination .page-link{padding:7px 12px;font-size:12px}

        /* Modal — mobile bottom sheet */
        .event-modal-overlay{align-items:flex-end;justify-content:center;padding:0}
        .event-modal{width:100%;max-width:100vw;height:92vh;max-height:92vh;border-radius:16px 16px 0 0;animation:modalSheetUp .3s ease-out}
        @keyframes modalSheetUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
        .event-modal-header{padding:14px 18px}
        .event-modal-title{font-size:14px;gap:9px}.event-modal-title i{font-size:17px}
        .event-modal-body{padding:0 18px 12px;-webkit-overflow-scrolling:touch}
        .evt-field{margin-bottom:10px}
        .evt-field input[type="text"],.evt-field input[type="email"],.evt-field input[type="tel"],.evt-field textarea,.evt-field select{padding:9px 12px;font-size:13px}
        .evt-row{flex-direction:column;gap:0}
        .event-modal-footer{padding:10px 18px;padding-bottom:calc(10px + env(safe-area-inset-bottom, 0px));border-top:1px solid rgba(255,255,255,0.06)}
        .evt-btn{padding:9px 16px;font-size:12px}
        select,.form-select{background-position:right 12px center;padding-right:36px}
        select option{padding:8px 12px;font-size:13px}
        .event-modal::before{content:'';display:block;width:36px;height:4px;border-radius:4px;background:rgba(255,255,255,0.2);margin:8px auto 0;flex-shrink:0}
        .delete-modal{width:100%;max-width:100vw;height:auto;max-height:92vh}
        .toast-container{bottom:24px;right:12px;left:12px}.toast-notification{max-width:100%;font-size:12px;padding:12px 16px}
    }

    /* SMALL PHONE */
    @media(max-width:576px){
        .top-navbar{padding:10px 10px}.brand-logo{width:38px;height:38px}
        .brand-name{font-size:11px}.brand-subtitle{font-size:8px}
        .navbar-actions{gap:4px}.nav-icon-btn{width:34px;height:34px;font-size:14px}
        #sidebarToggle{width:34px;height:34px;font-size:18px}
        .content-area{padding:8px 8px 24px}
        .stat-card{padding:12px 10px}.stat-value{font-size:20px}.stat-label{font-size:9px}
        .stat-icon{width:32px;height:32px;font-size:13px}
        .event-modal{max-height:95vh;height:95vh}
        .event-modal-body{padding:0 14px 10px}
        .event-modal-header{padding:12px 14px}
        .event-modal-footer{padding:10px 14px;padding-bottom:calc(10px + env(safe-area-inset-bottom, 0px))}
        .sidebar{width:260px}
    }
</style>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">

    <?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
    <div class="main-content">

        <div class="content-area">
            <?= displayFlashMessage() ?>

            <!-- PAGE HEADER — DESKTOP -->
            <div class="page-header-row">
                <div class="page-header-left">
                    <h5>Teacher Management</h5>
                    <small>Manage teacher profiles, subjects handled &amp; advisory classes</small>
                </div>
                <div class="page-header-right">
                    <button class="btn btn-primary btn-add-teacher" id="openAddTeacher"><i class="bi bi-plus-lg"></i> <span class="btn-text">Add Teacher</span></button>
                </div>
            </div>

            <!-- PAGE HEADER — MOBILE -->
            <div class="page-header-mobile">
                <h5>Teacher Management</h5>
                <small>Manage teacher profiles, subjects handled &amp; advisory classes</small>
                <div class="page-header-mobile-actions">
                    <button class="btn btn-primary btn-add-teacher" id="openAddTeacherMobile"><i class="bi bi-plus-lg"></i> <span class="btn-text">Add</span></button>
                </div>
            </div>

        <div class="card mb-4"><div class="card-body py-3 search-form"><form method="GET" class="row g-2 align-items-end">
            <div class="col-md-8"><div class="input-icon-wrapper"><i class="bi bi-search input-icon"></i><input type="text" class="form-control" name="search" placeholder="Search by name or email" value="<?= sanitize($search) ?>"></div></div>
            <div class="col-6 col-md-2"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Search</button></div>
            <div class="col-6 col-md-2"><a href="<?= BASE_URL ?>/admin/teachers.php" class="btn btn-outline-secondary w-100">Clear</a></div>
        </form></div></div>

        <!-- DESKTOP TABLE -->
        <div class="card teacher-table-wrapper">
            <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-table me-2"></i>Teacher Records</span><span class="text-muted" style="font-size:12px">Showing <?= ($page-1)*$perPage+1 ?>&ndash;<?= min($page*$perPage,$totalTeachers) ?> of <?= $totalTeachers ?></span></div>
            <div class="card-body p-0">
                <?php if(empty($teachers)): ?><div class="empty-state"><div class="empty-icon"><i class="bi bi-person-badge"></i></div><h6>No Teachers Found</h6><p>Add a teacher or adjust your search filters.</p></div>
                <?php else: ?><div class="table-scroll-wrapper"><table class="table table-hover teacher-table mb-0">
                    <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Subjects</th><th>Advisory</th><th>Department</th><th style="width:120px">Actions</th></tr></thead>
                    <tbody><?php foreach($teachers as $t): 
                        $coreSubjectIds = getTeacherCoreSubjectNames($t);
                        $trackElectiveDetails = getTeacherTrackElectiveDetails($t, $teacherSections, $teacherSubjects, $tracks, $electives);
                        $coreSubjectNames = [];
                        if (!empty($coreSubjectIds)) {
                            $ph = implode(',', array_fill(0, count($coreSubjectIds), '?'));
                            $stmt = $db->prepare("SELECT subject_name FROM subjects WHERE id IN ($ph)");
                            $stmt->execute($coreSubjectIds);
                            foreach ($stmt->fetchAll() as $s) { $coreSubjectNames[] = $s['subject_name']; }
                        }
                        $trackElectiveText = formatTeacherTrackElectiveText($trackElectiveDetails);
                        $jhDetails = getTeacherGradeSectionDetails($t, $teacherSections, $teacherSubjects);
                        $shsDetails = getTeacherCoreSubjectDetails($t, $teacherSections, $teacherSubjects);
                    ?><tr>
                        <td class="teacher-name"><?= sanitize($t['first_name'] . (!empty($t['middle_name']) ? ' ' . strtoupper($t['middle_name'][0]) . '.' : '') . ' ' . $t['last_name']) ?></td>
                        <td class="teacher-email"><a href="mailto:<?= sanitize($t['email']) ?>"><?= sanitize($t['email']) ?></a></td>
                        <td style="font-size:13px"><?= sanitize($t['phone']??'-') ?></td>
                        <td class="teacher-subjects">
                            <?php if(!empty($jhDetails)): ?>
<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#000;margin-bottom:2px">Junior High</div>
                                <?= formatSubjectDetailRows($jhDetails) ?>
                            <?php endif; ?>
                            <?php if(!empty($shsDetails)): ?>
                                <?php if(!empty($jhDetails)): ?><div style="border-top:1px solid rgba(255,255,255,0.06);margin:6px 0"></div><?php endif; ?>
<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#000;margin-bottom:2px">Senior High</div>
                                <?= formatSubjectDetailRows($shsDetails) ?>
                            <?php endif; ?>
                            <?php if(!empty($trackElectiveDetails)): ?>
                                <?php if(!empty($jhDetails) || !empty($shsDetails)): ?><div style="border-top:1px solid rgba(255,255,255,0.06);margin:6px 0"></div><?php endif; ?>
<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#000;margin-bottom:2px">Track & Elective</div>
                                <?php foreach($trackElectiveDetails as $d): ?>
                                    <?php $line = trim(($d['section'] ? $d['section'] . ' - ' : '') . ($d['track'] ? $d['track'] . ' - ' : '') . ($d['elective'] ? $d['elective'] . ' - ' : '') . ($d['subject'] ? $d['subject'] : '')); ?>
                                    <?php if($line): ?>
                                        <div style="font-size:11px;color:#000;line-height:1.35;margin-top:1px"><?= sanitize($line) ?></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if(empty($jhDetails) && empty($shsDetails) && empty($trackElectiveDetails)): ?><span class="text-muted" style="font-size:12px">None</span><?php endif; ?>
                        </td>
                        <td class="teacher-advisory"><?php if(!empty($t['advisory_class'])): ?><span class="badge bg-primary-soft text-primary"><?= sanitize($t['advisory_class']) ?></span><?php else: ?><span class="text-muted" style="font-size:12px">-</span><?php endif; ?></td>
                        <td class="teacher-department"><?php if(!empty($t['department'])): ?><span class="badge bg-success-soft text-success"><?= sanitize($t['department']) ?></span><?php else: ?><span class="text-muted" style="font-size:12px">-</span><?php endif; ?></td>
                        <td><div class="action-btns">
                            <button class="btn btn-icon btn-sm btn-outline-primary tm-edit-trigger" data-teacher='<?= htmlspecialchars(json_encode($t),ENT_QUOTES,'UTF-8') ?>' title="Edit"><i class="bi bi-pencil"></i></button>
                            <?php
                        $deleteName = $t['first_name'];
                        if (!empty($t['middle_name'])) {
                            $deleteName .= ' ' . strtoupper($t['middle_name'][0]) . '.';
                        }
                        $deleteName .= ' ' . $t['last_name'];
                        ?>
                            <button class="btn btn-icon btn-sm btn-outline-danger tm-delete-trigger" data-delete-id="<?= (int)$t['id'] ?>" data-delete-name="<?= sanitize($deleteName) ?>" title="Delete"><i class="bi bi-trash3"></i></button>
                        </div></td>
                    </tr><?php endforeach; ?></tbody>
                </table></div><?php endif; ?>
            </div>
            <?php if($totalPages>1): ?><div class="pagination-wrapper">
                <div class="pagination-info">Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong> &middot; <?= $totalTeachers ?> teacher<?= $totalTeachers!==1?'s':'' ?></div>
                <nav><div class="pagination-page-info">Page <?= $page ?> of <?= $totalPages ?></div><ul class="pagination mb-0">
                    <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>"><i class="bi bi-chevron-left"></i></a></li>
                    <?php $sP=max(1,$page-2);$eP=min($totalPages,$page+2);if($sP>1):?><li class="page-item"><a class="page-link" href="?page=1&search=<?= urlencode($search) ?>">1</a></li><?php if($sP>2):?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif;endif;for($p=$sP;$p<=$eP;$p++):?><li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" href="?page=<?= $p ?>&search=<?= urlencode($search) ?>"><?= $p ?></a></li><?php endfor;if($eP<$totalPages):if($eP<$totalPages-1):?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif;?><li class="page-item"><a class="page-link" href="?page=<?= $totalPages ?>&search=<?= urlencode($search) ?>"><?= $totalPages ?></a></li><?php endif;?>
                    <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>"><i class="bi bi-chevron-right"></i></a></li>
                </ul></nav>
            </div><?php endif; ?>
        </div>
    </div>
</div>

<!-- ADD TEACHER -->
<div class="event-modal-overlay" id="addTeacherOverlay">
    <div class="event-modal">
        <div class="event-modal-header">
            <div class="event-modal-title"><i class="bi bi-person-plus-fill"></i><span>Add New Teacher</span></div>
            <button class="event-modal-close" id="addTeacherClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <form id="addTeacherForm" autocomplete="off">
            <input type="hidden" name="action" value="add">
            <div class="event-modal-body">
                <div class="evt-section-label"><i class="bi bi-person"></i> Personal Information</div>
                <div class="evt-row">
                    <div class="evt-field evt-flex-1"><label>First Name <span class="required">*</span></label><input type="text" name="first_name" placeholder="e.g. Juan" required maxlength="100"></div>
                    <div class="evt-field evt-flex-1"><label>Middle Name</label><input type="text" name="middle_name" placeholder="e.g. Delos" maxlength="100"></div>
                    <div class="evt-field evt-flex-1"><label>Last Name <span class="required">*</span></label><input type="text" name="last_name" placeholder="e.g. Dela Cruz" required maxlength="100"></div>
                </div>
                <div class="evt-field"><label>Email Address <span class="required">*</span></label><input type="email" name="email" placeholder="e.g. juan.delacruz@liceo.edu.ph" required maxlength="150"><div class="form-hint">This will be used as the teacher's login credential.</div></div>
                <div class="evt-field"><label>Phone Number <small>(optional)</small></label><input type="tel" id="add-phone" name="phone" placeholder="09XX-XXX-XXXX" maxlength="13"></div>
                <div class="evt-separator"></div>
                <div class="evt-section-label"><i class="bi bi-book"></i> Academic Details</div>
                <div class="evt-section-label" style="opacity:0.7">JUNIOR HIGH</div>
                <div class="evt-field">
                    <label>Grade Handled</label>
                    <button type="button" class="add-grade-btn" id="addGradeBtn"><i class="bi bi-plus-lg"></i> Add Grade Handled</button>
                    <div id="gradeHandledRepeatable"></div>
                </div>
                <input type="hidden" name="grade_section_handled" id="grade_section_handled" value="[]">
                <div class="evt-section-label" style="opacity:0.7">SENIOR HIGH</div>
                <div class="evt-field">
                    <label>Core Subjects Handled</label>
                    <button type="button" class="add-grade-btn" id="addCoreSubjectBtn"><i class="bi bi-plus-lg"></i> Add Core Subject</button>
                    <div id="coreSubjectRepeatable"></div>
                    <input type="hidden" name="core_subjects_handled" id="core_subjects_handled" value="[]">
                </div>
                <div class="evt-field">
                    <label>Track and Elective Handled</label>
                    <button type="button" class="add-grade-btn" id="addTrackElectiveBtn"><i class="bi bi-plus-lg"></i> Add Track and Elective</button>
                    <div id="trackElectiveRepeatable"></div>
                    <input type="hidden" name="track_elective_handled" id="track_elective_handled" value="[]">
                </div>
                <div class="evt-field"><label>Advisory Class <small>(optional)</small></label><select name="advisory_class"><option value="">Select advisory class</option><?= buildTeacherAdvisoryOptions($teacherSections) ?></select></div>
                <div class="evt-field"><label>Department <small>(optional)</small></label><select name="department"><option value="">Select department</option><option value="Junior High">Junior High</option><option value="Senior High">Senior High</option><option value="Both">Both</option></select></div>
            </div>
            <div class="event-modal-footer">
                <button type="button" class="evt-btn evt-btn-cancel" id="addTeacherCancel">Cancel</button>
                <button type="submit" class="evt-btn evt-btn-save" id="addTeacherSave"><i class="bi bi-person-plus me-1"></i> Add Teacher</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT TEACHER -->
<div class="event-modal-overlay" id="editTeacherOverlay">
    <div class="event-modal">
        <div class="event-modal-header">
            <div class="event-modal-title"><i class="bi bi-pencil-square"></i><span>Edit Teacher</span></div>
            <button class="event-modal-close" id="editTeacherClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <form id="editTeacherForm" autocomplete="off">
            <input type="hidden" name="action" value="update"><input type="hidden" name="id" id="edit-id">
            <div class="event-modal-body">
                <div class="evt-section-label"><i class="bi bi-person"></i> Personal Information</div>
                <div class="evt-row">
                    <div class="evt-field evt-flex-1"><label>First Name <span class="required">*</span></label><input type="text" id="edit-first_name" name="first_name" required maxlength="100"></div>
                    <div class="evt-field evt-flex-1"><label>Middle Name</label><input type="text" id="edit-middle_name" name="middle_name" maxlength="100"></div>
                    <div class="evt-field evt-flex-1"><label>Last Name <span class="required">*</span></label><input type="text" id="edit-last_name" name="last_name" required maxlength="100"></div>
                </div>
                <div class="evt-field"><label>Email Address</label><input type="email" id="edit-email" name="email" maxlength="150"></div>
                <div class="evt-field"><label>Phone Number</label><input type="tel" id="edit-phone" name="phone" maxlength="13"></div>
                <div class="evt-separator"></div>
                <div class="evt-section-label"><i class="bi bi-book"></i> Academic Details</div>
                <div class="evt-section-label" style="opacity:0.7">JUNIOR HIGH</div>
                <div class="evt-field">
                    <label>Grade Handled</label>
                    <button type="button" class="add-grade-btn" id="editAddGradeBtn"><i class="bi bi-plus-lg"></i> Add Grade Handled</button>
                    <div id="editGradeHandledRepeatable"></div>
                </div>
                <input type="hidden" name="grade_section_handled" id="edit_grade_section_handled" value="[]">
                <div class="evt-section-label" style="opacity:0.7">SENIOR HIGH</div>
                <div class="evt-field">
                    <label>Core Subjects Handled</label>
                    <button type="button" class="add-grade-btn" id="editAddCoreSubjectBtn"><i class="bi bi-plus-lg"></i> Add Core Subject</button>
                    <div id="editCoreSubjectRepeatable"></div>
                    <input type="hidden" name="core_subjects_handled" id="edit_core_subjects_handled" value="[]">
                </div>
                <div class="evt-field">
                    <label>Track and Elective Handled</label>
                    <button type="button" class="add-grade-btn" id="editAddTrackElectiveBtn"><i class="bi bi-plus-lg"></i> Add Track and Elective</button>
                    <div id="editTrackElectiveRepeatable"></div>
                    <input type="hidden" name="track_elective_handled" id="edit_track_elective_handled" value="[]">
                </div>
                <div class="evt-field"><label>Advisory Class</label><select id="edit-advisory_class" name="advisory_class"><option value="">Select advisory class</option><?= buildTeacherAdvisoryOptions($teacherSections) ?></select></div>
                <div class="evt-field"><label>Department</label><select id="edit-department" name="department"><option value="">Select department</option><option value="Junior High">Junior High</option><option value="Senior High">Senior High</option><option value="Both">Both</option></select></div>
            </div>
            <div class="event-modal-footer">
                <button type="button" class="evt-btn evt-btn-cancel" id="editTeacherCancel">Cancel</button>
                <button type="submit" class="evt-btn evt-btn-save" id="editTeacherSave"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="event-modal-overlay" id="deleteTeacherOverlay">
    <div class="event-modal delete-modal">
        <div class="event-modal-header" style="padding-bottom:0"><div></div><button class="event-modal-close" id="deleteTeacherClose"><i class="bi bi-x-lg"></i></button></div>
        <div class="event-modal-body" style="padding-top:4px">
            <div class="delete-modal-icon" style="background:var(--tm-danger-light);color:var(--tm-danger)"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div class="delete-modal-text"><h6>Delete Teacher?</h6><p>This will permanently remove <strong id="deleteTeacherNameDisplay"></strong> and all associated records. This action cannot be undone.</p></div>
        </div>
        <div class="event-modal-footer" style="justify-content:center">
            <button type="button" class="evt-btn evt-btn-cancel" id="deleteTeacherCancel">Cancel</button>
            <a href="#" class="evt-btn evt-btn-danger" id="confirmDeleteBtn"><i class="bi bi-trash3 me-1"></i> Delete</a>
        </div>
    </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>
(function() {
    'use strict';

    /* MODAL */
    var cur=null;
    function openModal(el){if(!el)return;el.classList.add('show');document.body.style.overflow='hidden';cur=el.id;var f=el.querySelector('input[type="text"],input[type="email"],input[type="tel"],textarea,select');if(f)setTimeout(function(){f.focus();},200);}
    function closeModal(el){if(!el)return;el.classList.remove('show');if(!document.querySelector('.event-modal-overlay.show')){document.body.style.overflow='';cur=null;}}
    function closeAll(){document.querySelectorAll('.event-modal-overlay.show').forEach(function(e){e.classList.remove('show');});document.body.style.overflow='';cur=null;}
    document.querySelectorAll('.event-modal-overlay').forEach(function(o){o.addEventListener('click',function(e){if(e.target===o)closeModal(o);});});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')closeAll();});

    /* PHONE FORMAT: 09XX-XXX-XXXX */
    function formatPhone(input){input.addEventListener('input',function(){var v=this.value.replace(/\D/g,'');if(v.length>11)v=v.substring(0,11);var f='';if(v.length>0)f=v.substring(0,4);if(v.length>4)f+='-'+v.substring(4,7);if(v.length>7)f+='-'+v.substring(7,11);this.value=f;});}
    formatPhone(document.getElementById('add-phone'));
    formatPhone(document.getElementById('edit-phone'));

    function syncSubjectSelection(selectEl, selectedValues){if(!selectEl)return;var values=Array.isArray(selectedValues)?selectedValues:String(selectedValues||'').split(',');Array.from(selectEl.options).forEach(function(opt){var isSelected=values.some(function(value){return String(value).trim()===opt.value;});opt.selected=isSelected;});}

    var gradeSectionOptions = <?= json_encode(array_map(function($s){return ['value'=>$s['id'],'text'=>trim((string)($s['grade_level'].($s['section_name']?' - '.$s['section_name']:'')).($s['strand_code']?' ('.$s['strand_code'].')':''))];}, $teacherSections), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    var shsGradeSectionOptions = <?= json_encode(array_map(function($s){return ['value'=>$s['id'],'text'=>trim((string)($s['grade_level'].($s['section_name']?' - '.$s['section_name']:'')).($s['strand_code']?' ('.$s['strand_code'].')':''))];}, $shsSections), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    var sectionGradeMap = <?= json_encode(array_combine(array_column($teacherSections,'id'), array_column($teacherSections,'grade_level')), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    var subjectOptions = <?= json_encode(array_map(function($s){return ['value'=>$s['id'],'text'=>$s['subject_name'],'grade_level'=>$s['grade_level'],'grade_level_end'=>$s['grade_level_end']??$s['grade_level']];}, $teacherSubjects), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    var coreSubjectOptions = <?= json_encode(array_map(function($s){return ['value'=>$s['id'],'text'=>$s['subject_name'],'grade_level'=>$s['grade_level'],'grade_level_end'=>$s['grade_level_end']??$s['grade_level']];}, $coreSubjectOptions), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    var trackOptions = <?= json_encode(array_map(function($t){return ['value'=>$t['id'],'text'=>$t['track_name']];}, $tracks), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    var electiveOptions = <?= json_encode(array_map(function($e){return ['value'=>$e['id'],'text'=>$e['elective_name'],'track_id'=>$e['track_id']];}, $electives), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    var electiveSubjectOptions = <?= json_encode(array_map(function($es){return ['value'=>$es['subject_id'],'text'=>$es['subject_name'],'elective_id'=>$es['elective_id']];}, $electiveSubjects), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

    function buildGradeHandledRow(data){
        var row=document.createElement('div');row.className='grade-handled-row';
        var gsWrap=document.createElement('div');gsWrap.className='evt-field';
        var gsLbl=document.createElement('label');gsLbl.innerHTML='<span class="required">*</span> Grade & Section';
        var gsSel=document.createElement('select');gsSel.className='form-select grade-section-select';gsSel.required=true;
        var defOpt=document.createElement('option');defOpt.value='';defOpt.textContent='Select';gsSel.appendChild(defOpt);
        (gradeSectionOptions||[]).forEach(function(o){var opt=document.createElement('option');opt.value=o.value;opt.textContent=o.text;gsSel.appendChild(opt);});
        gsWrap.appendChild(gsLbl);gsWrap.appendChild(gsSel);row.appendChild(gsWrap);

        var subjWrap=document.createElement('div');subjWrap.className='evt-field';
        var subjLbl=document.createElement('label');subjLbl.innerHTML='<span class="required">*</span> Subject Handled';
        var subjSel=document.createElement('select');subjSel.className='form-select subject-select';subjSel.required=true;
        var defOpt2=document.createElement('option');defOpt2.value='';defOpt2.textContent='Select';subjSel.appendChild(defOpt2);
        subjWrap.appendChild(subjLbl);subjWrap.appendChild(subjSel);row.appendChild(subjWrap);

        var rm=document.createElement('button');rm.type='button';rm.className='rm-row-btn';rm.title='Remove';rm.innerHTML='<i class="bi bi-x-lg"></i>';
        rm.addEventListener('click',function(){if(row.parentNode&&row.parentNode.children.length>1)row.remove();});
        row.appendChild(rm);

        function updateSubjects(){
            var gid=gsSel.value;
            var grade=sectionGradeMap[gid]||null;
            subjSel.innerHTML='';
            var def=document.createElement('option');def.value='';def.textContent='Select';subjSel.appendChild(def);
            (subjectOptions||[]).forEach(function(o){
                if(grade && o.grade_level && o.grade_level_end){
                    var g=parseInt(grade), gl=parseInt(o.grade_level), ge=parseInt(o.grade_level_end||o.grade_level);
                    if(g>=gl && g<=ge){var opt=document.createElement('option');opt.value=o.value;opt.textContent=o.text;subjSel.appendChild(opt);}
                } else if(!grade){
                    var opt=document.createElement('option');opt.value=o.value;opt.textContent=o.text;subjSel.appendChild(opt);
                }
            });
        }
        updateSubjects();
        gsSel.addEventListener('change', updateSubjects);

        if(data && data.section_id){ gsSel.value = String(data.section_id); updateSubjects(); }
        if(data && data.subject_id) subjSel.value = String(data.subject_id);

        return row;
    }

    function getGradeHandledData(container){
        var rows=container?container.querySelectorAll('.grade-handled-row'):[];
        var data=[];
        rows.forEach(function(row){
            var gs=row.querySelector('.grade-section-select');
            var subj=row.querySelector('.subject-select');
            if(gs && subj && gs.value && subj.value){
                data.push({section_id:gs.value, subject_id:subj.value});
            }
        });
        return data;
    }

    function setGradeHandledData(container, items){
        if(!container)return;
        container.innerHTML='';
        if(!items||!items.length){container.appendChild(buildGradeHandledRow({}));return;}
        items.forEach(function(item){container.appendChild(buildGradeHandledRow(item));});
    }

    function updateGradeHandledHidden(container, hiddenInput){
        var data = getGradeHandledData(container);
        if(hiddenInput) hiddenInput.value = JSON.stringify(data);
    }

    /* CORE SUBJECTS HANDLED — SHS */
    function buildCoreSubjectRow(data){
        var row=document.createElement('div');row.className='core-subject-row';
        var gsWrap=document.createElement('div');gsWrap.className='evt-field';
        var gsLbl=document.createElement('label');gsLbl.innerHTML='Grade & Section <span class="required">*</span>';
        var gsSel=document.createElement('select');gsSel.className='form-select shs-grade-section-select';gsSel.required=true;
        var defOpt=document.createElement('option');defOpt.value='';defOpt.textContent='Select';gsSel.appendChild(defOpt);
        (shsGradeSectionOptions||[]).forEach(function(o){var opt=document.createElement('option');opt.value=o.value;opt.textContent=o.text;gsSel.appendChild(opt);});
        gsWrap.appendChild(gsLbl);gsWrap.appendChild(gsSel);row.appendChild(gsWrap);

        var subjWrap=document.createElement('div');subjWrap.className='evt-field';
        var subjLbl=document.createElement('label');subjLbl.innerHTML='Core Subject Handled <span class="required">*</span>';
        var subjSel=document.createElement('select');subjSel.className='form-select core-subject-select';subjSel.required=true;
        var defOpt2=document.createElement('option');defOpt2.value='';defOpt2.textContent='Select';subjSel.appendChild(defOpt2);
        (coreSubjectOptions||[]).forEach(function(o){
            var gl=parseInt(o.grade_level), ge=parseInt(o.grade_level_end||o.grade_level);
            if(gl>=11 || ge>=11){var opt=document.createElement('option');opt.value=o.value;opt.textContent=o.text;subjSel.appendChild(opt);}
        });
        subjWrap.appendChild(subjLbl);subjWrap.appendChild(subjSel);row.appendChild(subjWrap);

        var rm=document.createElement('button');rm.type='button';rm.className='rm-row-btn';rm.title='Remove';rm.innerHTML='<i class="bi bi-x-lg"></i>';
        rm.addEventListener('click',function(){if(row.parentNode&&row.parentNode.children.length>1)row.remove();});
        row.appendChild(rm);

        if(data && data.section_id) gsSel.value = String(data.section_id);
        if(data && data.subject_id) subjSel.value = String(data.subject_id);

        return row;
    }

    function getCoreSubjectData(container){
        var rows=container?container.querySelectorAll('.core-subject-row'):[];
        var data=[];
        rows.forEach(function(row){
            var gs=row.querySelector('.shs-grade-section-select');
            var subj=row.querySelector('.core-subject-select');
            if(gs && subj && gs.value && subj.value){
                data.push({section_id:gs.value, subject_id:subj.value});
            }
        });
        return data;
    }

    function setCoreSubjectData(container, items){
        if(!container)return;
        container.innerHTML='';
        if(!items||!items.length){container.appendChild(buildCoreSubjectRow({}));return;}
        items.forEach(function(item){container.appendChild(buildCoreSubjectRow(item));});
    }

    function updateCoreSubjectHidden(container, hiddenInput){
        var data = getCoreSubjectData(container);
        if(hiddenInput) hiddenInput.value = JSON.stringify(data);
    }

    /* TRACK AND ELECTIVE HANDLED — SHS */
    function buildTrackElectiveRow(data){
        var row=document.createElement('div');row.className='track-elective-row';
        var topRow=document.createElement('div');topRow.className='track-elective-inner-row';
        var gsWrap=document.createElement('div');gsWrap.className='evt-field';
        var gsLbl=document.createElement('label');gsLbl.innerHTML='Grade & Section <span class="required">*</span>';
        var gsSel=document.createElement('select');gsSel.className='form-select shs-grade-section-select-te';gsSel.required=true;
        var defOpt=document.createElement('option');defOpt.value='';defOpt.textContent='Select';gsSel.appendChild(defOpt);
        (shsGradeSectionOptions||[]).forEach(function(o){var opt=document.createElement('option');opt.value=o.value;opt.textContent=o.text;gsSel.appendChild(opt);});
        gsWrap.appendChild(gsLbl);gsWrap.appendChild(gsSel);topRow.appendChild(gsWrap);

        var trackWrap=document.createElement('div');trackWrap.className='evt-field';
        var trackLbl=document.createElement('label');trackLbl.innerHTML='Track <span class="required">*</span>';
        var trackSel=document.createElement('select');trackSel.className='form-select track-select';trackSel.required=true;
        var defOpt2=document.createElement('option');defOpt2.value='';defOpt2.textContent='Select';trackSel.appendChild(defOpt2);
        (trackOptions||[]).forEach(function(o){var opt=document.createElement('option');opt.value=o.value;opt.textContent=o.text;trackSel.appendChild(opt);});
        trackWrap.appendChild(trackLbl);trackWrap.appendChild(trackSel);topRow.appendChild(trackWrap);
        row.appendChild(topRow);

        var bottomRow=document.createElement('div');bottomRow.className='track-elective-inner-row';
        var elecWrap=document.createElement('div');elecWrap.className='evt-field';
        var elecLbl=document.createElement('label');elecLbl.innerHTML='Elective <span class="required">*</span>';
        var elecSel=document.createElement('select');elecSel.className='form-select elective-select';elecSel.required=true;
        var defOpt3=document.createElement('option');defOpt3.value='';defOpt3.textContent='Select';elecSel.appendChild(defOpt3);
        elecWrap.appendChild(elecLbl);elecWrap.appendChild(elecSel);bottomRow.appendChild(elecWrap);

        var subjWrap=document.createElement('div');subjWrap.className='evt-field';
        var subjLbl=document.createElement('label');subjLbl.innerHTML='Subject <span class="required">*</span>';
        var subjSel=document.createElement('select');subjSel.className='form-select elective-subject-select';subjSel.required=true;
        var defOpt4=document.createElement('option');defOpt4.value='';defOpt4.textContent='Select';subjSel.appendChild(defOpt4);
        subjWrap.appendChild(subjLbl);subjWrap.appendChild(subjSel);bottomRow.appendChild(subjWrap);
        row.appendChild(bottomRow);

        var rm=document.createElement('button');rm.type='button';rm.className='rm-row-btn';rm.title='Remove';rm.innerHTML='<i class="bi bi-x-lg"></i>';
        rm.addEventListener('click',function(){if(row.parentNode&&row.parentNode.children.length>1)row.remove();});
        row.appendChild(rm);

        function updateElectives(){
            var tid=trackSel.value;
            elecSel.innerHTML='';
            var defE=document.createElement('option');defE.value='';defE.textContent='Select';elecSel.appendChild(defE);
            (electiveOptions||[]).forEach(function(o){if(String(o.track_id)===String(tid)){var opt=document.createElement('option');opt.value=o.value;opt.textContent=o.text;elecSel.appendChild(opt);}});
            updateSubjects();
        }
        function updateSubjects(){
            var eid=elecSel.value;
            subjSel.innerHTML='';
            var defS=document.createElement('option');defS.value='';defS.textContent='Select';subjSel.appendChild(defS);
            (electiveSubjectOptions||[]).forEach(function(o){if(String(o.elective_id)===String(eid)){var opt=document.createElement('option');opt.value=o.value;opt.textContent=o.text;subjSel.appendChild(opt);}});
        }
        trackSel.addEventListener('change', updateElectives);
        elecSel.addEventListener('change', updateSubjects);
        updateElectives();

        if(data && data.section_id) gsSel.value = String(data.section_id);
        if(data && data.track_id) trackSel.value = String(data.track_id);
        if(data && data.track_id) updateElectives();
        if(data && data.elective_id) elecSel.value = String(data.elective_id);
        if(data && data.elective_id) updateSubjects();
        if(data && data.subject_id) subjSel.value = String(data.subject_id);

        return row;
    }

    function getTrackElectiveData(container){
        var rows=container?container.querySelectorAll('.track-elective-row'):[];
        var data=[];
        rows.forEach(function(row){
            var gs=row.querySelector('.shs-grade-section-select-te');
            var track=row.querySelector('.track-select');
            var elec=row.querySelector('.elective-select');
            var subj=row.querySelector('.elective-subject-select');
            if(gs && track && elec && subj && gs.value && track.value && elec.value && subj.value){
                data.push({section_id:gs.value, track_id:track.value, elective_id:elec.value, subject_id:subj.value});
            }
        });
        return data;
    }

    function setTrackElectiveData(container, items){
        if(!container)return;
        container.innerHTML='';
        if(!items||!items.length){container.appendChild(buildTrackElectiveRow({}));return;}
        items.forEach(function(item){container.appendChild(buildTrackElectiveRow(item));});
    }

    function updateTrackElectiveHidden(container, hiddenInput){
        var data = getTrackElectiveData(container);
        if(hiddenInput) hiddenInput.value = JSON.stringify(data);
    }

    /* ADD TEACHER */
    var addO=document.getElementById('addTeacherOverlay'),addF=document.getElementById('addTeacherForm'),addB=document.getElementById('addTeacherSave');
    var addGradeContainer = document.getElementById('gradeHandledRepeatable');
    var addGradeHidden = document.getElementById('grade_section_handled');
    var addCoreSubjectContainer = document.getElementById('coreSubjectRepeatable');
    var addCoreSubjectHidden = document.getElementById('core_subjects_handled');
    var addTrackElectiveContainer = document.getElementById('trackElectiveRepeatable');
    var addTrackElectiveHidden = document.getElementById('track_elective_handled');
    function getCsrf(){var m=document.querySelector('meta[name="csrf-token"]');return m?m.getAttribute('content') : '';}
    if(addO&&addF&&addB){
        function openAddTeacher(){addF.reset();if(addGradeContainer) addGradeContainer.innerHTML='';if(addGradeHidden) addGradeHidden.value='[]';if(addCoreSubjectContainer) addCoreSubjectContainer.innerHTML='';if(addCoreSubjectHidden) addCoreSubjectHidden.value='[]';if(addTrackElectiveContainer) addTrackElectiveContainer.innerHTML='';if(addTrackElectiveHidden) addTrackElectiveHidden.value='[]';openModal(addO);}
        var openBtn=document.getElementById('openAddTeacher');if(openBtn)openBtn.addEventListener('click',openAddTeacher);
        var openBtnM=document.getElementById('openAddTeacherMobile');if(openBtnM)openBtnM.addEventListener('click',openAddTeacher);
        document.getElementById('addTeacherClose').addEventListener('click',function(){closeModal(addO);});
        document.getElementById('addTeacherCancel').addEventListener('click',function(){closeModal(addO);});
        var addGradeBtn=document.getElementById('addGradeBtn');
        if(addGradeBtn){
            addGradeBtn.addEventListener('click',function(){
                if(addGradeContainer) addGradeContainer.appendChild(buildGradeHandledRow({}));
            });
        }
        var addCoreSubjectBtn=document.getElementById('addCoreSubjectBtn');
        if(addCoreSubjectBtn){
            addCoreSubjectBtn.addEventListener('click',function(){
                if(addCoreSubjectContainer) addCoreSubjectContainer.appendChild(buildCoreSubjectRow({}));
            });
        }
        var addTrackElectiveBtn=document.getElementById('addTrackElectiveBtn');
        if(addTrackElectiveBtn){
            addTrackElectiveBtn.addEventListener('click',function(){
                if(addTrackElectiveContainer) addTrackElectiveContainer.appendChild(buildTrackElectiveRow({}));
            });
        }
        function escapeHtml(str) { return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
        function getSubjectNameById(id) {
            var s = subjectOptions.find(function(o) { return String(o.value) === String(id); });
            return s ? s.text : '';
        }
        addF.addEventListener('submit',function(e){
            e.preventDefault();
            var fn=addF.querySelector('[name="first_name"]').value.trim(),mn=addF.querySelector('[name="middle_name"]').value.trim(),ln=addF.querySelector('[name="last_name"]').value.trim(),em=addF.querySelector('[name="email"]').value.trim();
            if(!fn||!ln||!em){showToast('Please fill in all required fields.','error');return;}
            updateGradeHandledHidden(addGradeContainer, addGradeHidden);
            updateCoreSubjectHidden(addCoreSubjectContainer, addCoreSubjectHidden);
            updateTrackElectiveHidden(addTrackElectiveContainer, addTrackElectiveHidden);
            addB.classList.add('loading');addB.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Adding...';
            var fd=new FormData(addF);fd.append('action','add');fd.append('csrf_token',getCsrf());
            fetch('<?= BASE_URL ?>/api/teachers.php',{method:'POST',body:fd}).then(function(r){
                if(!r.ok) { return r.json().then(function(d){ throw new Error(d.message || ('Error ' + r.status)); }); }
                return r.json();
            }).then(function(d){
                if(d.success && d.teacher) {
                    var t = d.teacher;
                    var cardBody = document.querySelector('.teacher-table-wrapper .card-body');
                    if(cardBody) {
                        var emptyEl = cardBody.querySelector('.empty-state');
                        if(emptyEl) {
                            cardBody.innerHTML = '<div class="table-scroll-wrapper"><table class="table table-hover teacher-table mb-0"><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Subjects</th><th>Advisory</th><th>Department</th><th style="width:120px">Actions</th></tr></thead><tbody></tbody></table></div>';
                        }
                    }
                    var desktopTbody = document.querySelector('.teacher-table-wrapper .teacher-table tbody');
                    
                    var coreSubjectNames = [];
                    try {
                        var cs = typeof t.core_subjects_handled === 'string' ? JSON.parse(t.core_subjects_handled) : (t.core_subjects_handled || []);
                        if(Array.isArray(cs)) {
                            cs.forEach(function(item) {
                                var name = getSubjectNameById(item.subject_id);
                                if(name) coreSubjectNames.push(name);
                            });
                        }
                    } catch(ex) {}
                    
                    var trackText = '';
                    try {
                        var te = typeof t.track_elective_handled === 'string' ? JSON.parse(t.track_elective_handled) : (t.track_elective_handled || []);
                        if(Array.isArray(te)) {
                            var sMap = {}, trMap = {}, eMap = {}, sjMap = {};
                            shsGradeSectionOptions.forEach(function(s) { sMap[s.value] = s.text; });
                            trackOptions.forEach(function(tr) { trMap[tr.value] = tr.text; });
                            electiveOptions.forEach(function(e) { eMap[e.value] = e.text; });
                            subjectOptions.forEach(function(s) { sjMap[s.value] = s.text; });
                            var parts = [];
                            te.forEach(function(item) {
                                var sec = sMap[item.section_id] || '';
                                var trk = trMap[item.track_id] || '';
                                var elc = eMap[item.elective_id] || '';
                                var sj = sjMap[item.subject_id] || '';
                                var line = (sec ? sec + ' — ' : '') + (trk ? trk : '') + (elc ? ' / ' + elc : '') + (sj ? ' — ' + sj : '');
                                if(line) parts.push(line);
                            });
                            trackText = parts.join('; ');
                        }
                    } catch(ex) {}
                    
                    var teacherData = {
                        id: t.id,
                        first_name: t.first_name,
                        middle_name: t.middle_name || '',
                        last_name: t.last_name,
                        email: t.email,
                        phone: t.phone,
                        department: t.department,
                        advisory_class: t.advisory_class,
                        subjects_handled: t.subjects_handled,
                        grade_section_handled: t.grade_section_handled,
                        core_subjects_handled: t.core_subjects_handled,
                        track_elective_handled: t.track_elective_handled
                    };
                    var teacherJson = JSON.stringify(teacherData).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
                    
                    var row = document.createElement('tr');
                    
                    var jhDetails = [], shsDetails = [], teDetails = [];
                    function buildJhShsRows(details) {
                        var html = '';
                        details.forEach(function(d) {
                            var line = (d.section ? d.section + ' - ' : '') + (d.subject ? d.subject : '');
                            if (line) html += '<div style="font-size:11px;color:#000;line-height:1.35;margin-top:1px">' + escapeHtml(line) + '</div>';
                        });
                        return html;
                    }
                    function buildTeRows(details) {
                        var html = '';
                        details.forEach(function(d) {
                            var line = (d.section ? d.section + ' - ' : '') + (d.track ? d.track + ' - ' : '') + (d.elective ? d.elective + ' - ' : '') + (d.subject ? d.subject : '');
                            if (line) html += '<div style="font-size:11px;color:#000;line-height:1.35;margin-top:1px">' + escapeHtml(line) + '</div>';
                        });
                        return html;
                    }
                    try {
                        var gsMap={}, subjMap={};
                        subjectOptions.forEach(function(o){subjMap[o.value]=o.text;});
                        gradeSectionOptions.forEach(function(o){gsMap[o.value]=o.text;});
                        var shsGsMap={};
                        shsGradeSectionOptions.forEach(function(o){shsGsMap[o.value]=o.text;});
                        var jh = typeof t.grade_section_handled === 'string' ? JSON.parse(t.grade_section_handled) : (t.grade_section_handled||[]);
                        if(Array.isArray(jh)) jh.forEach(function(item){
                            var sec = item.section_id && gsMap[item.section_id] ? gsMap[item.section_id] : '';
                            var subj = item.subject_id && subjMap[item.subject_id] ? subjMap[item.subject_id] : '';
                            jhDetails.push({section: sec, subject: subj});
                        });
                    } catch(e) { jhDetails = []; }
                    try {
                        var shs = typeof t.core_subjects_handled === 'string' ? JSON.parse(t.core_subjects_handled) : (t.core_subjects_handled||[]);
                        if(Array.isArray(shs)) shs.forEach(function(item){
                            var sec = item.section_id && shsGsMap[item.section_id] ? shsGsMap[item.section_id] : '';
                            var subj = item.subject_id && subjMap[item.subject_id] ? subjMap[item.subject_id] : '';
                            shsDetails.push({section: sec, subject: subj});
                        });
                    } catch(e) { shsDetails = []; }
                    try {
                        var te = typeof t.track_elective_handled === 'string' ? JSON.parse(t.track_elective_handled) : (t.track_elective_handled||[]);
                        if(Array.isArray(te)) te.forEach(function(item){
                            var sec = item.section_id && shsGsMap[item.section_id] ? shsGsMap[item.section_id] : '';
                            var trk = item.track_id && (trackOptions.find(function(o){return o.value==item.track_id;}) || {}).text || '';
                            var elc = item.elective_id && (electiveOptions.find(function(o){return o.value==item.elective_id;}) || {}).text || '';
                            var subj = item.subject_id && subjMap[item.subject_id] ? subjMap[item.subject_id] : '';
                            teDetails.push({section: sec, track: trk, elective: elc, subject: subj});
                        });
                    } catch(e) { teDetails = []; }
                    
                    var subjectsCellHtml = '';
                    if (jhDetails.length) {
                        subjectsCellHtml += '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#000;margin-bottom:2px">Junior High</div>';
                        subjectsCellHtml += buildJhShsRows(jhDetails);
                    }
                    if (shsDetails.length) {
                        if (jhDetails.length) subjectsCellHtml += '<div style="border-top:1px solid rgba(255,255,255,0.06);margin:6px 0"></div>';
                        subjectsCellHtml += '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#000;margin-bottom:2px">Senior High</div>';
                        subjectsCellHtml += buildJhShsRows(shsDetails);
                    }
                    if (teDetails.length) {
                        if (jhDetails.length || shsDetails.length) subjectsCellHtml += '<div style="border-top:1px solid rgba(255,255,255,0.06);margin:6px 0"></div>';
                        subjectsCellHtml += '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#000;margin-bottom:2px">Track & Elective</div>';
                        subjectsCellHtml += buildTeRows(teDetails);
                    }
                    if (!subjectsCellHtml) subjectsCellHtml = '<span class="text-muted" style="font-size:12px">None</span>';
                    
var teacherName = escapeHtml(t.first_name);
                     if (t.middle_name) {
                         teacherName += ' ' + escapeHtml(t.middle_name.charAt(0).toUpperCase() + '.');
                     }
                     teacherName += ' ' + escapeHtml(t.last_name);
                     var deleteName = t.first_name;
                     if (t.middle_name) {
                         deleteName += ' ' + t.middle_name.charAt(0).toUpperCase() + '.';
                     }
                     deleteName += ' ' + t.last_name;
                     row.innerHTML = '<td class="teacher-name">' + teacherName + '</td>' +
                         '<td class="teacher-email"><a href="mailto:' + escapeHtml(t.email) + '">' + escapeHtml(t.email) + '</a></td>' +
                         '<td style="font-size:13px">' + escapeHtml(t.phone || '-') + '</td>' +
                         '<td class="teacher-subjects">' + subjectsCellHtml + '</td>' +
                         '<td class="teacher-advisory">' + (t.advisory_class ? '<span class="badge bg-primary-soft text-primary">' + escapeHtml(t.advisory_class) + '</span>' : '<span class="text-muted" style="font-size:12px">-</span>') + '</td>' +
                         '<td class="teacher-department">' + (t.department ? '<span class="badge bg-success-soft text-success">' + escapeHtml(t.department) + '</span>' : '<span class="text-muted" style="font-size:12px">-</span>') + '</td>' +
                         '<td><div class="action-btns">' +
                             '<button class="btn btn-icon btn-sm btn-outline-primary tm-edit-trigger" data-teacher=\'' + teacherJson + '\' title="Edit"><i class="bi bi-pencil"></i></button>' +
                             '<button class="btn btn-icon btn-sm btn-outline-danger tm-delete-trigger" data-delete-id="' + t.id + '" data-delete-name="' + deleteName + '" title="Delete"><i class="bi bi-trash3"></i></button>' +
                         '</div></td>';
                    if(desktopTbody) desktopTbody.insertBefore(row, desktopTbody.firstChild);
                    
                    var showingEl = document.querySelector('.teacher-table-wrapper .card-header .text-muted');
                    if(showingEl) {
                        var txt = showingEl.textContent;
                        var m = txt.match(/Showing (\d+)–(\d+) of (\d+)/);
                        if(m) {
                            var ns = parseInt(m[1]) + 1;
                            var ne = parseInt(m[2]) + 1;
                            var nt = parseInt(m[3]) + 1;
                            showingEl.textContent = 'Showing ' + ns + '–' + ne + ' of ' + nt;
                        }
                    }
                    
                    showToast(d.message || 'Teacher added!','success');
                    closeModal(addO);
                } else {
                    showToast(d.message || 'Failed.','error');
                }
            })
            .catch(function(err){showToast((err && err.message ? err.message : 'Something went wrong. Please try again.'),'error');console.error(err);})
            .finally(function(){addB.classList.remove('loading');addB.innerHTML='<i class="bi bi-person-plus me-1"></i> Add Teacher';});
        });
    }

/* EDIT TEACHER */
    var editO=document.getElementById('editTeacherOverlay'),editF=document.getElementById('editTeacherForm'),editB=document.getElementById('editTeacherSave');
    var editGradeContainer = document.getElementById('editGradeHandledRepeatable');
    var editGradeHidden = document.getElementById('edit_grade_section_handled');
    var editCoreSubjectContainer = document.getElementById('editCoreSubjectRepeatable');
    var editCoreSubjectHidden = document.getElementById('edit_core_subjects_handled');
    var editTrackElectiveContainer = document.getElementById('editTrackElectiveRepeatable');
    var editTrackElectiveHidden = document.getElementById('edit_track_elective_handled');
    
    function handleEditClick(btn) {
        var t; try { t = JSON.parse(btn.getAttribute('data-teacher')); } catch(er) { return; }
        document.getElementById('edit-id').value = t.id || '';
        document.getElementById('edit-first_name').value = t.first_name || '';
        document.getElementById('edit-middle_name').value = t.middle_name || '';
        document.getElementById('edit-last_name').value = t.last_name || '';
        document.getElementById('edit-email').value = t.email || '';
        document.getElementById('edit-phone').value = t.phone || '';
        document.getElementById('edit-advisory_class').value = t.advisory_class || '';
        document.getElementById('edit-department').value = t.department || '';
        
        var gradeItems = [];
        try { gradeItems = (t.grade_section_handled && typeof t.grade_section_handled === 'string') ? JSON.parse(t.grade_section_handled) : (Array.isArray(t.grade_section_handled) ? t.grade_section_handled : []); } catch(e) { gradeItems = []; }
        if (gradeItems && gradeItems.length) { setGradeHandledData(editGradeContainer, gradeItems); } else { if(editGradeContainer) { editGradeContainer.innerHTML = ''; editGradeContainer.appendChild(buildGradeHandledRow({})); } }
        if(editGradeHidden) editGradeHidden.value = JSON.stringify(gradeItems||[]);
        
        var coreItems = [];
        try { coreItems = (t.core_subjects_handled && typeof t.core_subjects_handled === 'string') ? JSON.parse(t.core_subjects_handled) : (Array.isArray(t.core_subjects_handled) ? t.core_subjects_handled : []); } catch(e) { coreItems = []; }
        if (coreItems && coreItems.length) { setCoreSubjectData(editCoreSubjectContainer, coreItems); } else { if(editCoreSubjectContainer) { editCoreSubjectContainer.innerHTML = ''; editCoreSubjectContainer.appendChild(buildCoreSubjectRow({})); } }
        if(editCoreSubjectHidden) editCoreSubjectHidden.value = JSON.stringify(coreItems||[]);
        
        var trackItems = [];
        try { trackItems = (t.track_elective_handled && typeof t.track_elective_handled === 'string') ? JSON.parse(t.track_elective_handled) : (Array.isArray(t.track_elective_handled) ? t.track_elective_handled : []); } catch(e) { trackItems = []; }
        if (trackItems && trackItems.length) { setTrackElectiveData(editTrackElectiveContainer, trackItems); } else { if(editTrackElectiveContainer) { editTrackElectiveContainer.innerHTML = ''; editTrackElectiveContainer.appendChild(buildTrackElectiveRow({})); } }
        if(editTrackElectiveHidden) editTrackElectiveHidden.value = JSON.stringify(trackItems||[]);
        
        openModal(editO);
    }
    
    var desktopTableBody = document.querySelector('.teacher-table-wrapper .card-body');
    if (desktopTableBody) {
        desktopTableBody.addEventListener('click', function(e) {
            var editBtn = e.target.closest('.tm-edit-trigger');
            if (editBtn) { handleEditClick(editBtn); return; }
            var delBtn = e.target.closest('.tm-delete-trigger');
            if (delBtn) { handleDeleteClick(delBtn); }
        });
    }
    
    document.getElementById('editTeacherClose').addEventListener('click',function(){closeModal(editO);});
    document.getElementById('editTeacherCancel').addEventListener('click',function(){closeModal(editO);});
var editAddGradeBtn=document.getElementById('editAddGradeBtn');
    if(editAddGradeBtn){
        editAddGradeBtn.addEventListener('click',function(){
            if(editGradeContainer) editGradeContainer.appendChild(buildGradeHandledRow({}));
        });
    }
    var editAddCoreSubjectBtn=document.getElementById('editAddCoreSubjectBtn');
    if(editAddCoreSubjectBtn){
        editAddCoreSubjectBtn.addEventListener('click',function(){
            if(editCoreSubjectContainer) editCoreSubjectContainer.appendChild(buildCoreSubjectRow({}));
        });
    }
    var editAddTrackElectiveBtn=document.getElementById('editAddTrackElectiveBtn');
    if(editAddTrackElectiveBtn){
        editAddTrackElectiveBtn.addEventListener('click',function(){
            if(editTrackElectiveContainer) editTrackElectiveContainer.appendChild(buildTrackElectiveRow({}));
        });
    }
    editF.addEventListener('submit',function(e){
        e.preventDefault();editB.classList.add('loading');editB.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
        updateGradeHandledHidden(editGradeContainer, editGradeHidden);
        updateCoreSubjectHidden(editCoreSubjectContainer, editCoreSubjectHidden);
        updateTrackElectiveHidden(editTrackElectiveContainer, editTrackElectiveHidden);
        var fd=new FormData(editF);fd.append('action','update');fd.append('csrf_token',getCsrf());
        fetch('<?= BASE_URL ?>/api/teachers.php',{method:'POST',body:fd}).then(function(r){
            if(!r.ok) { return r.json().then(function(d){ throw new Error(d.message || ('Error ' + r.status)); }); }
            return r.json();
        }).then(function(d){
            if(d.success){showToast('Teacher updated!','success');closeModal(editO);setTimeout(function(){location.reload();},600);}else{showToast(d.message||'Failed.','error');}
        }).catch(function(err){showToast((err && err.message ? err.message : 'Something went wrong. Please try again.'),'error');})
        .finally(function(){editB.classList.remove('loading');editB.innerHTML='<i class="bi bi-check-lg me-1"></i> Save Changes';});
    });

    /* DELETE */
    var delO=document.getElementById('deleteTeacherOverlay'),delN=document.getElementById('deleteTeacherNameDisplay'),delB=document.getElementById('confirmDeleteBtn');
    
    function handleDeleteClick(btn) {
        var id=btn.getAttribute('data-delete-id'),name=btn.getAttribute('data-delete-name')||'this teacher';
        if(delN)delN.textContent=name;if(delB)delB.href='<?= BASE_URL ?>/admin/teachers.php?delete='+(id||'0');
        openModal(delO);
    }
    
    document.getElementById('deleteTeacherClose').addEventListener('click',function(){closeModal(delO);});
    document.getElementById('deleteTeacherCancel').addEventListener('click',function(){closeModal(delO);});
    delB.addEventListener('click',function(e){e.preventDefault();var h=delB.href;if(!h||h.endsWith('delete=0'))return;delB.classList.add('disabled');delB.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Deleting...';location.href=h;});

    /* TOAST */
    window.showToast=function(msg,type){type=type||'success';var c=document.getElementById('toastContainer');if(!c)return;var t=document.createElement('div');t.className='toast-notification toast-'+type;var icons={success:'check-circle-fill',info:'info-circle-fill',warning:'exclamation-triangle-fill',error:'exclamation-circle-fill'};var d=document.createElement('div');d.appendChild(document.createTextNode(msg));t.innerHTML='<i class="bi bi-'+(icons[type]||'info-circle-fill')+'"></i><span>'+d.innerHTML+'</span>';c.appendChild(t);setTimeout(function(){if(t.parentNode)t.remove();},4200);};

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>