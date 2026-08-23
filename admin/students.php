<?php
require_once __DIR__ . '/../config.php';
requireRole(['admin']);

if (isset($_GET['delete'])) {
    $deleteId = intval($_GET['delete']);
    if ($deleteId) {
        deleteStudent($db, $deleteId);
        redirect('/admin/students.php', 'Student deleted successfully.', 'success');
    }
}

$pageTitle = 'Student Management';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$search     = sanitize($_GET['search'] ?? '');
$gradeLevel = sanitize($_GET['grade_level'] ?? '');
$section    = sanitize($_GET['section'] ?? '');
$page       = max(1, intval($_GET['page'] ?? 1));
$perPage    = PER_PAGE;
$offset     = ($page - 1) * $perPage;

$filters = [];
if ($search)     $filters['search']      = $search;
if ($gradeLevel) $filters['grade_level'] = $gradeLevel;
if ($section)    $filters['section']     = $section;

$totalStudents = count(getStudents($db, $filters));
$totalPages    = max(1, ceil($totalStudents / $perPage));

$sql = "SELECT s.*, g.guardian_name, g.relationship, g.phone as guardian_phone, g.email as guardian_email FROM students s LEFT JOIN guardians g ON s.id = g.student_id";
$conditions = [];
$params = [];
if ($search) {
    $conditions[] = "(s.first_name LIKE :search OR s.last_name LIKE :search2 OR s.student_id LIKE :search3 OR s.name_extension LIKE :search4)";
    $params[':search']  = "%$search%";
    $params[':search2'] = "%$search%";
    $params[':search3'] = "%$search%";
    $params[':search4'] = "%$search%";
}
if ($gradeLevel) { $conditions[] = "s.grade_level = :grade_level"; $params[':grade_level'] = $gradeLevel; }
if ($section)    { $conditions[] = "s.section = :section";         $params[':section'] = $section; }
if ($conditions) $sql .= " WHERE " . implode(" AND ", $conditions);
$sql .= " ORDER BY s.last_name ASC, s.first_name ASC LIMIT $perPage OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

$sections = [];
try { $stmt = $db->query("SELECT DISTINCT section FROM students WHERE section != '' ORDER BY section"); $sections = $stmt->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}

$gradeSectionsMap = [];
try {
    $stmt = $db->query("SELECT grade_level, section FROM students WHERE section != '' AND grade_level != '' GROUP BY grade_level, section ORDER BY CAST(grade_level AS UNSIGNED), section");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $gradeSectionsMap[$row['grade_level']][] = $row['section'];
    }
} catch (Exception $e) {}

$faceCount = 0;
try { $faceCount = $db->query("SELECT COUNT(*) FROM student_faces WHERE face_encoding IS NOT NULL")->fetchColumn(); } catch (Exception $e) {}

$gradeCount = 0;
try { $gradeCount = $db->query("SELECT COUNT(DISTINCT grade_level) FROM students")->fetchColumn(); } catch (Exception $e) {}

$studentIds = array_column($students, 'id');
$faceMap = [];
if (!empty($studentIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $faceStmt = $db->prepare("SELECT student_id FROM student_faces WHERE student_id IN ($placeholders) AND face_encoding IS NOT NULL");
        $faceStmt->execute($studentIds);
        $faceMap = array_flip($faceStmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {}
}

$upcomingEvents = [];
try { $stmt = $db->prepare("SELECT * FROM calendar_events WHERE created_by=? AND event_date >= CURDATE() AND event_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND is_completed = 0 ORDER BY event_date ASC, event_time IS NULL, event_time ASC LIMIT 20"); $stmt->execute([getCurrentUserId()]); $upcomingEvents = $stmt->fetchAll(); } catch (Exception $e) {}
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">

<style>
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
.page-header-mobile-actions .btn-bar-primary{padding:8px 14px;font-size:12px}

/* ═══════════════════════════════════════════════════════════════════
   FILTER BAR — COMPLETE RESTYLE
   ═══════════════════════════════════════════════════════════════════ */
.filter-card{
    border-radius:var(--pg-radius);
    background:var(--pg-surface-card);
    border:1px solid var(--pg-border);
}
.filter-card .card-header{
    background:transparent;
    border-bottom:1px solid var(--pg-border);
    padding:14px 20px;
    color:#fff;
}
.filter-card .card-body{
    padding:22px 24px;
}

.filter-label{
    display:block;
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:0.08em;
    margin-bottom:8px;
    color:rgba(255,255,255,0.5);
}

/* Search wrapper */
.search-wrapper{position:relative}
.search-wrapper .search-icon{
    position:absolute;left:14px;top:50%;transform:translateY(-50%);
    font-size:15px;color:rgba(255,255,255,0.35);pointer-events:none;z-index:1;
}

/* Input fields */
.filter-input{
    display:block;
    width:100%;
    height:44px;
    padding:0 14px 0 14px;
    background:rgba(255,255,255,0.05);
    border:1.5px solid rgba(255,255,255,0.10);
    border-radius:var(--pg-radius-sm);
    font-size:13px;
    font-weight:500;
    font-family:var(--pg-font);
    color:#fff;
    transition:all var(--pg-transition);
    -webkit-appearance:none;
    -moz-appearance:none;
    appearance:none;
}
.filter-input::placeholder{color:rgba(255,255,255,0.3)}
.filter-input:focus{
    outline:none;
    border-color:var(--pg-primary);
    box-shadow:0 0 0 3px var(--pg-primary-glow);
    background:rgba(255,255,255,0.07);
}
.filter-input:hover:not(:focus){
    border-color:rgba(255,255,255,0.18);
    background:rgba(255,255,255,0.07);
}

/* Search input with icon */
.search-wrapper .filter-input{padding-left:42px}

/* Select dropdowns */
select.filter-input{
    cursor:pointer;
    padding-right:36px;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='rgba(255,255,255,0.45)' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
    background-repeat:no-repeat;
    background-position:right 14px center;
    background-color:rgba(255,255,255,0.05);
}
select.filter-input option{
    background:#1a1a2e;
    color:#fff;
    padding:8px;
}

/* Buttons */
.filter-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    width:100%;
    height:44px;
    padding:0 18px;
    border:none;
    border-radius:var(--pg-radius-sm);
    font-size:13px;
    font-weight:700;
    font-family:var(--pg-font);
    cursor:pointer;
    transition:all var(--pg-spring);
    text-decoration:none;
}
.filter-btn i{font-size:14px}

.filter-btn-primary{
    background:linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    color:#fff;
    box-shadow:0 2px 12px rgba(79,70,229,0.3);
}
.filter-btn-primary:hover{
    transform:translateY(-2px);
    box-shadow:0 6px 24px rgba(79,70,229,0.45);
    color:#fff;
}

.filter-btn-outline{
    background:rgba(255,255,255,0.05);
    border:1.5px solid rgba(255,255,255,0.14);
    color:rgba(255,255,255,0.7);
}
.filter-btn-outline:hover{
    border-color:rgba(255,255,255,0.3);
    color:#fff;
    background:rgba(255,255,255,0.10);
    transform:translateY(-1px);
}

/* Active filter tags */
.active-filters{
    display:flex;gap:6px;flex-wrap:wrap;
    margin-top:16px;padding-top:16px;
    border-top:1px solid rgba(255,255,255,0.06);
    align-items:center;
}
.active-filters-label{
    font-size:10px;font-weight:700;
    text-transform:uppercase;letter-spacing:0.08em;
    color:rgba(255,255,255,0.35);margin-right:4px;
}
.filter-tag{
    display:inline-flex;align-items:center;gap:5px;
    padding:5px 12px;border-radius:20px;
    font-size:11px;font-weight:600;
    background:var(--pg-primary-light);color:var(--pg-primary);
}
.filter-tag i{font-size:9px}
.filter-tag-remove{
    width:16px;height:16px;border-radius:50%;
    display:inline-flex;align-items:center;justify-content:center;
    font-size:11px;cursor:pointer;opacity:0.6;
    transition:opacity var(--pg-transition);
    text-decoration:none;color:inherit;
}
.filter-tag-remove:hover{opacity:1}

/* ═══════════════════════════════════════════════════════════════════
   STUDENT TABLE
   ═══════════════════════════════════════════════════════════════════ */
.student-table-wrapper{overflow:hidden}
.table-scroll-wrapper{overflow-x:auto;-webkit-overflow-scrolling:touch}
.table-scroll-wrapper::-webkit-scrollbar{height:6px}
.table-scroll-wrapper::-webkit-scrollbar-track{background:#f4f5f7}
.table-scroll-wrapper::-webkit-scrollbar-thumb{background:#d1d5db;border-radius:6px}
.table-scroll-wrapper::-webkit-scrollbar-thumb:hover{background:#9ca3af}
.student-table{width:100%;border-collapse:collapse;min-width:780px;font-size:13px;color:#1e1e2a;background:#fff}
.student-table thead th{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;padding:14px 16px;white-space:nowrap;border-bottom:2px solid #e8e9ed;text-align:left;color:#9b9bb0;background:#f4f5f7}
.student-table tbody td{padding:14px 16px;font-size:13px;vertical-align:middle;border-bottom:1px solid #f0f1f4;white-space:nowrap;color:#5a5a72;background:#fff;transition:background var(--pg-transition)}
.student-table tbody tr:hover td{background:#f8f9fb}
.student-table tbody tr:last-child td{border-bottom:none}
.student-name-cell{min-width:160px}
.student-name{font-weight:600;font-size:14px;letter-spacing:-0.01em;color:#1e1e2a}
.student-id-code{font-family:var(--pg-mono);font-size:12px;font-weight:500;padding:3px 8px;border-radius:6px;display:inline-block;background:var(--pg-primary-light);color:var(--pg-primary)}
.student-middle{font-size:12px;margin-top:2px;color:#9b9bb0}
.guardian-info{font-size:13px;color:#5a5a72}
.guardian-phone{font-size:11px;margin-top:2px;color:#9b9bb0}
.face-registered{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;letter-spacing:0.03em;background:var(--pg-success-light);color:var(--pg-success)}
.face-pending{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;letter-spacing:0.03em;background:var(--pg-warning-light);color:var(--pg-warning)}
.btn-action-icon{width:34px;height:34px;border-radius:var(--pg-radius-xs);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px;transition:all var(--pg-transition);border:1.5px solid transparent;background:transparent;color:inherit}
.btn-action-icon:hover{transform:translateY(-1px)}
.btn-action-icon.edit{border-color:rgba(79,70,229,0.3);color:var(--pg-primary)}
.btn-action-icon.edit:hover{background:var(--pg-primary);color:#fff}
.btn-action-icon.face{border-color:rgba(16,185,129,0.3);color:var(--pg-success)}
.btn-action-icon.face:hover{background:var(--pg-success);color:#fff}
.btn-action-icon.delete{border-color:rgba(239,68,68,0.3);color:var(--pg-danger)}
.btn-action-icon.delete:hover{background:var(--pg-danger);color:#fff}

.empty-state{text-align:center;padding:48px 20px}
.empty-icon{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:26px}
.empty-state h6{font-weight:700;font-size:16px;margin-bottom:6px;color:#fff}
.empty-state p{font-size:13px;max-width:300px;margin:0 auto;line-height:1.6;color:rgba(255,255,255,0.4)}

.pagination-wrapper{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;flex-wrap:wrap;gap:12px;border-top:1px solid #e8e9ed;background:#fff}
.pagination-info{font-size:12px;font-weight:600;color:#9b9bb0}
.pagination-controls{display:flex;align-items:center;gap:4px}
.page-btn{min-width:36px;height:36px;border-radius:var(--pg-radius-xs);display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;cursor:pointer;transition:all var(--pg-transition);text-decoration:none;border:1px solid #e8e9ed;color:#5a5a72;background:#fff;padding:0 8px}
.page-btn:hover{transform:translateY(-1px);border-color:var(--pg-primary);color:var(--pg-primary);background:rgba(79,70,229,0.06);text-decoration:none}
.page-btn.active{background:var(--pg-primary);border-color:var(--pg-primary);color:#fff;box-shadow:0 2px 8px rgba(79,70,229,0.3)}
.page-btn.disabled{opacity:0.3;pointer-events:none}

/* ═══════════════════════════════════════════════════════════════════
   MODALS
   ═══════════════════════════════════════════════════════════════════ */
/* Delete Confirmation Modal (matches MyCalendar.php) */
.delete-modal{width:400px}
.delete-modal-icon{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:24px}
.delete-modal-text{text-align:center}
.delete-modal-text h6{font-weight:700;font-size:16px;letter-spacing:-0.02em;margin-bottom:6px;color:#fff}
.delete-modal-text p{font-size:13px;max-width:280px;margin:0 auto;line-height:1.5}
.evt-btn-danger{background:#ef4444;color:#fff}.evt-btn-danger:hover{background:#dc2626;box-shadow:0 4px 14px rgba(239,68,68,0.3)}

.import-modal-overlay{position:fixed;inset:0;background:rgba(11,11,20,0.55);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);z-index:9998;display:none;align-items:center;justify-content:center;padding:20px}
.import-modal-overlay.show{display:flex}
.import-modal{border-radius:20px;width:580px;max-width:100%;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;background:rgba(22,22,40,0.98);border:1px solid rgba(255,255,255,0.10);box-shadow:0 24px 60px rgba(0,0,0,0.5);animation:pgModalSlideIn 0.35s cubic-bezier(0.34,1.56,0.64,1)}
.import-modal-header{display:flex;justify-content:space-between;align-items:center;padding:22px 26px;flex-shrink:0;border-bottom:1px solid rgba(255,255,255,0.07)}
.import-modal-title{display:flex;align-items:center;gap:12px;font-size:17px;font-weight:800;letter-spacing:-0.02em;color:#fff}
.import-modal-title i{font-size:22px}
.import-modal-close{width:34px;height:34px;border:none;border-radius:var(--pg-radius-xs);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all var(--pg-transition);font-size:14px;background:rgba(255,255,255,0.06);color:rgba(255,255,255,0.5)}
.import-modal-close:hover{background:rgba(239,68,68,0.15);color:#ef4444}
.import-modal-body{padding:26px;overflow-y:auto;flex:1}
.import-info-box{padding:16px 18px;border-radius:var(--pg-radius-sm);margin-bottom:20px;font-size:13px;line-height:1.6}
.import-info-box h6{font-size:13px;font-weight:700;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.import-info-box code{display:block;padding:8px 12px;border-radius:8px;font-family:var(--pg-mono);font-size:12px;margin:8px 0;word-break:break-all;background:rgba(0,0,0,0.2)}
.import-info-box pre{padding:10px 14px;border-radius:8px;font-family:var(--pg-mono);font-size:11px;line-height:1.5;overflow-x:auto;margin:8px 0 0;background:rgba(0,0,0,0.2)}
.import-field{margin-bottom:18px}
.import-field label{display:block;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:8px;color:rgba(255,255,255,0.5)}
.import-field label .required{color:var(--pg-danger)}
.import-field input[type="file"]{width:100%;padding:11px 16px;border-radius:var(--pg-radius-sm);border:1.5px solid rgba(255,255,255,0.10);font-size:14px;font-family:var(--pg-font);background:rgba(255,255,255,0.05);color:inherit}
.import-check-row{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:var(--pg-radius-xs);margin-bottom:8px;cursor:pointer;transition:all var(--pg-transition)}
.import-check-row:hover{background:rgba(255,255,255,0.04)}
.import-check-input{width:18px;height:18px;accent-color:var(--pg-primary);cursor:pointer;flex-shrink:0}
.import-check-label{font-size:13px;font-weight:500;cursor:pointer;color:rgba(255,255,255,0.7)}
.import-preview{margin-top:18px}
.import-preview-header{font-size:13px;font-weight:700;margin-bottom:10px;display:flex;align-items:center;gap:7px;color:#fff}
.import-preview-scroll{max-height:200px;overflow-y:auto;border-radius:10px;border:1px solid rgba(255,255,255,0.06)}
.import-preview-table{width:100%;border-collapse:collapse;font-size:12px}
.import-preview-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;padding:10px 12px;white-space:nowrap;text-align:left;color:rgba(255,255,255,0.5);background:rgba(255,255,255,0.04)}
.import-preview-table td{padding:8px 12px;font-size:12px;border-bottom:1px solid rgba(255,255,255,0.04);color:rgba(255,255,255,0.7)}
.import-results{margin-top:18px}
.import-results-box{padding:18px;border-radius:var(--pg-radius-sm);font-size:13px}
.import-results-box h6{font-weight:700;margin-bottom:10px}
.import-results-box p{margin-bottom:6px}
.import-modal-footer{display:flex;justify-content:flex-end;gap:10px;padding:18px 26px;flex-shrink:0;border-top:1px solid rgba(255,255,255,0.07)}
.import-btn{padding:11px 22px;border:none;border-radius:var(--pg-radius-sm);font-size:13px;font-weight:700;cursor:pointer;transition:all var(--pg-transition);display:inline-flex;align-items:center;gap:6px;font-family:var(--pg-font);color:#fff}
.import-btn:hover{transform:translateY(-1px)}
.import-btn.loading{opacity:0.7;pointer-events:none}

/* ═══════════════════════════════════════════════════════════════════
   RESPONSIVE
   ═══════════════════════════════════════════════════════════════════ */
@media(max-width:991px){
    .page-header-row{gap:12px}
    .page-header-left h5{font-size:20px}
}

@media(max-width:767px){
    .page-header-row{display:none !important}
    .page-header-mobile{display:block !important}
    .page-header-mobile h5{font-size:17px}
    .page-header-mobile small{font-size:12px}
    .content-area{padding:8px 10px 28px}

    .filter-card .card-body{padding:14px}
    .filter-label{font-size:10px;margin-bottom:6px}
    .filter-input{height:40px;font-size:12px}
    .search-wrapper .search-icon{left:12px;font-size:13px}
    .search-wrapper .filter-input{padding-left:36px}
    .filter-btn{height:40px;font-size:12px}

    .student-table{min-width:700px}
    .student-table thead th{padding:10px 12px;font-size:10px}
    .student-table tbody td{padding:10px 12px;font-size:12px}
    .student-name{font-size:13px}
    .student-id-code{font-size:11px;padding:2px 6px}
    .student-middle{font-size:11px}
    .guardian-info{font-size:12px}
    .guardian-phone{font-size:10px}
    .btn-action-icon{width:30px;height:30px;font-size:12px}
    .pagination-wrapper{flex-direction:column;gap:10px;padding:12px 14px;text-align:center}
    .page-btn{min-width:32px;height:32px;font-size:12px}
    .empty-state{padding:36px 16px}
    .empty-icon{width:52px;height:52px;font-size:22px}
    .empty-state h6{font-size:14px}
    .empty-state p{font-size:12px}

    .delete-modal{width:100%;max-width:100vw;height:auto;max-height:92vh}
    .delete-modal-text p{font-size:12px}

    .event-modal-overlay{position:fixed;inset:0;background:rgba(26,29,46,0.55);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:9998;display:none;align-items:center;justify-content:center;padding:20px}
    .event-modal-overlay.show{display:flex}
    .event-modal{border-radius:20px;width:100%;max-width:1200px;max-height:95vh;display:flex;flex-direction:column;overflow:hidden;animation:modalSlideIn .35s cubic-bezier(.34,1.56,.64,1)}
    @keyframes modalSlideIn{from{opacity:0;transform:translateY(24px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}
    .event-modal-header{display:flex;justify-content:space-between;align-items:center;padding:18px 22px;flex-shrink:0;border-bottom:1px solid rgba(255,255,255,0.06)}
    .event-modal-title{display:flex;align-items:center;gap:10px;font-size:16px;font-weight:800;letter-spacing:-0.02em;color:#fff}
    .event-modal-title i{font-size:20px;color:#60A5FA}
    .event-modal-close{width:32px;height:32px;border:none;border-radius:var(--pg-radius-xs);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all var(--pg-transition);font-size:13px;background:rgba(255,255,255,0.06);color:rgba(255,255,255,0.5)}
    .event-modal-close:hover{background:rgba(255,255,255,0.14)}
    .event-modal-body{padding:0 32px 22px;overflow-y:auto;overflow-x:hidden;flex:1 1 auto;min-height:0;-webkit-overflow-scrolling:touch;color:rgba(255,255,255,0.65);font-size:14px;line-height:1.7}
    .event-modal-body::-webkit-scrollbar{width:5px}
    .event-modal-body::-webkit-scrollbar-track{background:transparent;margin:4px 0}
    .event-modal-body::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.15);border-radius:10px}
    .event-modal-body::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,0.25)}
    .event-modal-body{scrollbar-width:thin;scrollbar-color:rgba(255,255,255,0.15) transparent}
    .event-modal-footer{display:flex;justify-content:flex-end;gap:10px;padding:12px 22px;flex-shrink:0;border-top:1px solid rgba(255,255,255,0.06);background:rgba(255,255,255,0.02)}
    .evt-btn-cancel{background:rgba(255,255,255,0.08);color:rgba(255,255,255,0.7)}.evt-btn-cancel:hover{background:rgba(255,255,255,0.14)}
    .evt-btn-save{background:#4f46e5;color:#fff}.evt-btn-save:hover{background:#4338ca;box-shadow:0 4px 16px rgba(79,70,229,0.3)}
    .evt-btn-save.loading{opacity:0.7;pointer-events:none}

    .import-modal-overlay{align-items:flex-end;padding:0}
    .import-modal{width:100%;max-width:100vw;max-height:92vh;border-radius:16px 16px 0 0;animation:pgModalSheetUp 0.3s ease-out}
    .import-modal::before{content:'';display:block;width:36px;height:4px;border-radius:4px;background:rgba(255,255,255,0.2);margin:10px auto 0;flex-shrink:0}
    .import-modal-header{padding:16px 18px}
    .import-modal-title{font-size:15px;gap:10px}
    .import-modal-title i{font-size:18px}
    .import-modal-body{padding:4px 18px 18px}
    .import-info-box{padding:12px 14px;font-size:12px;margin-bottom:14px}
    .import-info-box code{font-size:11px}
    .import-info-box pre{font-size:10px}
    .import-field{margin-bottom:14px}
    .import-field input[type="file"]{padding:10px 14px;font-size:13px}
    .import-check-row{padding:8px 12px}
    .import-preview-table th{font-size:9px;padding:8px 10px}
    .import-preview-table td{padding:6px 10px;font-size:11px}
    .import-modal-footer{padding:14px 18px;padding-bottom:calc(14px + env(safe-area-inset-bottom, 0px))}
    .import-btn{padding:10px 16px;font-size:12px}
    .stat-card{animation:none;opacity:1}
}

@media(max-width:576px){
    .page-header-mobile h5{font-size:15px}
    .page-header-mobile small{font-size:11px}
    .page-header-mobile-actions .btn-bar-primary{padding:7px 12px;font-size:11px}
    .page-header-mobile-actions .btn-bar-outline{padding:7px 12px;font-size:11px}
    .content-area{padding:6px 6px 24px}
    .filter-card .card-body{padding:10px}
    .filter-input{height:38px;font-size:11px}
    .search-wrapper .filter-input{padding-left:34px}
    .search-wrapper .search-icon{left:10px;font-size:12px}
    .student-table{min-width:650px}
    .student-table thead th{padding:8px 10px;font-size:9px}
    .student-table tbody td{padding:8px 10px;font-size:11px}
    .student-name{font-size:12px}
    .btn-action-icon{width:28px;height:28px;font-size:11px}
    .page-btn{min-width:30px;height:30px;font-size:11px}
    .import-modal{max-height:95vh}
    .import-modal-body{padding:4px 14px 14px}
    .import-modal-header{padding:14px}
    .import-modal-footer{padding:10px 14px;padding-bottom:calc(10px + env(safe-area-inset-bottom, 0px))}
}

@media(min-width:768px){
    .page-header-mobile{display:none !important}
}
</style>

<!-- ═══ TOP NAVBAR ═══ -->
<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">

    <!-- ═══ CONTENT ═══ -->
    <div class="content-area">
        <?= displayFlashMessage() ?>

        <!-- T&Cs GATEKEEPER MODAL -->
        <div class="event-modal-overlay" id="tcs-gatekeeper-modal">
            <div class="event-modal">
                <div class="event-modal-header">
                    <div class="event-modal-title">
                        <i class="bi bi-shield-lock-fill" style="color:rgba(96,165,250,0.9);"></i>
                        <span>Data Privacy Agreement</span>
                    </div>
                    <button class="event-modal-close" id="btn-cancel-x"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="event-modal-body">
                    <div style="color:rgba(255,255,255,0.65);font-size:13px;line-height:1.6;">
                        <div style="margin-bottom:14px;">
                            <div style="font-weight:700;color:#fff;font-size:13px;margin-bottom:4px;">1. Introduction</div>
                            <p>This Data Privacy Agreement ("Agreement") governs the collection, processing, storage, and protection of personal and sensitive personal information within the Web-Based Facial Recognition Attendance System ("System") of Liceo de Baleno. By accessing, registering, or interacting with this System, you explicitly acknowledge that you have read, understood, and consented to the processing of your data in accordance with the Republic Act No. 10173, otherwise known as the Data Privacy Act of 2012 (DPA).</p>
                        </div>
                        <div style="margin-bottom:14px;">
                            <div style="font-weight:700;color:#fff;font-size:13px;margin-bottom:4px;">2. Scope of Data Collection</div>
                            <p style="margin-bottom:6px;">To fulfill its functions, the System processes the following information:</p>
                            <ul style="margin:0;padding-left:20px;display:flex;flex-direction:column;gap:4px;">
                                <li><strong>Biometric Data:</strong> Multi-angle facial images (Front, Left, and Right profiles) converted into encrypted, mathematical biometric templates.</li>
                                <li><strong>Student Personal Information:</strong> Full name, Learner Reference Number (LRN), grade level, section, and official school email.</li>
                                <li><strong>Guardian Personal Information:</strong> Full name, relationship to the student, active mobile number, and contact details.</li>
                                <li><strong>Logistical Data:</strong> Automated attendance timestamps, kiosk interaction logs, and historical tracking metrics.</li>
                            </ul>
                        </div>
                        <div style="margin-bottom:14px;">
                            <div style="font-weight:700;color:#fff;font-size:13px;margin-bottom:4px;">3. Purpose of Data Processing</div>
                            <p style="margin-bottom:6px;">All collected information is processed strictly under the principles of transparency, legitimate purpose, and proportionality for the following objectives:</p>
                            <ul style="margin:0;padding-left:20px;display:flex;flex-direction:column;gap:4px;">
                                <li>Automating, verifying, and securing daily student attendance records.</li>
                                <li>Triggering real-time SMS/system notifications to registered guardians regarding student arrival and departure.</li>
                                <li>Academic research, system evaluation, and technical validation within the scope of institutional optimization and authorized research development.</li>
                            </ul>
                        </div>
                        <div style="margin-bottom:14px;">
                            <div style="font-weight:700;color:#fff;font-size:13px;margin-bottom:4px;">4. Data Storage and Security</div>
                            <p style="margin-bottom:6px;">Liceo de Baleno implements rigorous organizational, physical, and technical security measures:</p>
                            <ul style="margin:0;padding-left:20px;display:flex;flex-direction:column;gap:4px;">
                                <li>Data is hosted on secure, encrypted servers with strict role-based access control lists (ACLs).</li>
                                <li>Facial photographs are processed into one-way, non-reversible digital hashes to prevent reverse engineering of facial images.</li>
                                <li>Access is tightly restricted to authorized system administrators, institutional authorities, and designated researchers.</li>
                            </ul>
                        </div>
                        <div style="margin-bottom:14px;">
                            <div style="font-weight:700;color:#fff;font-size:13px;margin-bottom:4px;">5. Data Retention and Disposal</div>
                            <ul style="margin:0;padding-left:20px;display:flex;flex-direction:column;gap:4px;">
                                <li>Personal and biometric data will be retained only for the duration of the student's enrollment or the active lifecycle evaluation of this research system.</li>
                                <li>Upon graduation, transfer, withdrawal of consent, or formal system decommissioning, all biometric vectors and personal identifiers will be permanently deleted, overwritten, or anonymized beyond recovery.</li>
                            </ul>
                        </div>
                        <div style="margin-bottom:14px;">
                            <div style="font-weight:700;color:#fff;font-size:13px;margin-bottom:4px;">6. Data Subject Rights</div>
                            <p style="margin-bottom:6px;">Under the DPA of 2012, students (and their legal guardians) are afforded the following rights:</p>
                            <ul style="margin:0;padding-left:20px;display:flex;flex-direction:column;gap:4px;">
                                <li><strong>Right to be Informed:</strong> Knowing how, why, and when their biometric data is processed.</li>
                                <li><strong>Right to Object/Opt-out:</strong> The right to withhold or withdraw consent to biometric tracking without academic penalty (alternative manual attendance mechanisms will be provided).</li>
                                <li><strong>Right to Access and Rectification:</strong> Requesting a copy of stored records or correcting clerical errors in guardian contact details.</li>
                            </ul>
                        </div>
                        <div style="margin-bottom:14px;">
                            <div style="font-weight:700;color:#fff;font-size:13px;margin-bottom:4px;">7. Third-Party Disclosures</div>
                            <p>Biometric templates and personal data will never be shared, rented, or sold to third-party commercial entities. Data transmission is limited exclusively to authorized school personnel and targeted SMS gateways used solely for transmitting automated guardian attendance updates.</p>
                        </div>
                        <div style="margin-bottom:14px;">
                            <div style="font-weight:700;color:#fff;font-size:13px;margin-bottom:4px;">8. Limitation of Liability</div>
                            <p>While the development team and Liceo de Baleno implement industry-standard encryption and safety protocols, no digital system is entirely immune to malicious breaches. The institution shall not be held liable for unforeseen system disruptions or unauthorized access occurring outside reasonable technical control, provided all statutory security updates and best practices have been strictly maintained.</p>
                        </div>
                        <div style="margin-bottom:0;">
                            <div style="font-weight:700;color:#fff;font-size:13px;margin-bottom:4px;">9. Governing Law</div>
                            <p>This Agreement shall be governed, interpreted, and enforced in absolute accordance with the laws of the Republic of the Philippines, under the regulatory oversight of the National Privacy Commission (NPC).</p>
                        </div>
                    </div>
                </div>
                <div class="event-modal-footer">
                    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;user-select:none;margin-right:auto;padding-right:12px;">
                        <input type="checkbox" id="tcs-agreement-checkbox" style="margin-top:3px;width:16px;height:16px;border-radius:4px;border:1px solid rgba(255,255,255,0.2);accent-color:#4f46e5;cursor:pointer;flex-shrink:0;background:rgba(255,255,255,0.05);">
                        <span style="font-size:13px;color:rgba(255,255,255,0.75);line-height:1.5;">I agree to the Data Privacy Terms and Conditions and verify that parental/guardian consent has been secured for this student.</span>
                    </label>
                    <button id="btn-cancel" class="evt-btn evt-btn-cancel">Cancel</button>
                    <button id="btn-next" disabled class="evt-btn evt-btn-save" style="opacity:0.5;">Next</button>
                </div>
            </div>
        </div>
        
        <!-- PAGE HEADER — DESKTOP -->
        <div class="page-header-row">
            <div class="page-header-left">
                <h5>Student Management</h5>
                <small>Manage student records, guardian info & face registration</small>
            </div>
            <div class="page-header-right">
                <button type="button" class="btn-bar-primary" id="btnOpenAddStudentModal" style="background:var(--pg-primary);"><i class="bi bi-plus-lg"></i> Add Student</button>
                <a href="<?= BASE_URL ?>/api/students.php?action=download_template" class="btn-bar-primary" style="background:#10b981;"><i class="bi bi-file-earmark-excel"></i> Download Template</a>
                <button type="button" class="btn-bar-primary" id="openImportBtn" style="background:var(--pg-success);"><i class="bi bi-file-earmark-spreadsheet"></i> Import CSV</button>
                <a href="<?= BASE_URL ?>/api/students.php?action=export_csv" class="btn-bar-primary" style="background:#f59e0b;"><i class="bi bi-download"></i> Export</a>
            </div>
        </div>

        <!-- PAGE HEADER — MOBILE -->
        <div class="page-header-mobile">
            <h5>Student Management</h5>
            <small>Manage student records, guardian info & face registration</small>
            <div class="page-header-mobile-actions">
                <button type="button" class="btn-bar-primary" id="btnOpenAddStudentModalMobile" style="background:var(--pg-primary);"><i class="bi bi-plus-lg"></i> Add</button>
                <a href="<?= BASE_URL ?>/api/students.php?action=download_template" class="btn-bar-primary" style="background:#10b981;"><i class="bi bi-file-earmark-excel"></i> Template</a>
                <button type="button" class="btn-bar-primary" id="openImportBtnMobile" style="background:var(--pg-success);"><i class="bi bi-file-earmark-spreadsheet"></i> Import</button>
                <a href="<?= BASE_URL ?>/api/students.php?action=export_csv" class="btn-bar-primary" style="background:#f59e0b;"><i class="bi bi-download"></i> Export</a>
            </div>
        </div>

        <!-- STATISTICS -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3"><div class="stat-card"><div class="d-flex justify-content-between align-items-start"><div><div class="stat-value"><?= $totalStudents ?></div><div class="stat-label">Total Students</div></div><div class="stat-icon bg-primary-soft"><i class="bi bi-people-fill"></i></div></div></div></div>
            <div class="col-6 col-lg-3"><div class="stat-card"><div class="d-flex justify-content-between align-items-start"><div><div class="stat-value"><?= $faceCount ?></div><div class="stat-label">Faces Registered</div></div><div class="stat-icon bg-success-soft"><i class="bi bi-camera-fill"></i></div></div></div></div>
            <div class="col-6 col-lg-3"><div class="stat-card"><div class="d-flex justify-content-between align-items-start"><div><div class="stat-value"><?= $gradeCount ?></div><div class="stat-label">Grade Levels</div></div><div class="stat-icon bg-warning-soft"><i class="bi bi-layers-fill"></i></div></div></div></div>
            <div class="col-6 col-lg-3"><div class="stat-card"><div class="d-flex justify-content-between align-items-start"><div><div class="stat-value"><?= max(0, $totalStudents - $faceCount) ?></div><div class="stat-label">Pending Face Reg.</div></div><div class="stat-icon bg-danger-soft"><i class="bi bi-exclamation-circle-fill"></i></div></div></div></div>
        </div>

        <!-- FILTER BAR -->
        <div class="card filter-card mb-4">
            <div class="card-body">
                <form method="GET" action="" id="filterForm">
                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-md-4">
                            <label class="filter-label">Search</label>
                            <div class="search-wrapper">
                                <i class="bi bi-search search-icon"></i>
                                <input type="text" class="filter-input" name="search" placeholder="Name or student LRN..." value="<?= sanitize($search) ?>">
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="filter-label">Grade</label>
                            <select name="grade_level" class="filter-input">
                                <option value="">All Grades</option>
                                <?php foreach (['7','8','9','10','11','12'] as $g): ?>
                                    <option value="<?= $g ?>" <?= $gradeLevel === $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="filter-label">Section</label>
                            <select name="section" class="filter-input" id="sectionFilterSelect">
                                <option value="">All Sections</option>
                                <?php foreach ($sections as $s): ?>
                                    <option value="<?= sanitize($s) ?>" <?= $section === $s ? 'selected' : '' ?>><?= sanitize($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <button type="submit" class="filter-btn filter-btn-primary"><i class="bi bi-funnel"></i> Filter</button>
                        </div>
                        <div class="col-6 col-md-2">
                            <a href="<?= BASE_URL ?>/admin/students.php" class="filter-btn filter-btn-outline"><i class="bi bi-x-lg"></i> Clear</a>
                        </div>
                    </div>

                    <?php if ($search || $gradeLevel || $section): ?>
                    <div class="active-filters">
                        <span class="active-filters-label">Active:</span>
                        <?php if ($search): ?>
                            <span class="filter-tag"><i class="bi bi-search"></i> "<?= sanitize($search) ?>"<a href="?<?= http_build_query(array_diff_key($_GET, ['search' => ''])) ?>" class="filter-tag-remove">&times;</a></span>
                        <?php endif; ?>
                        <?php if ($gradeLevel): ?>
                            <span class="filter-tag"><i class="bi bi-layers"></i> Grade <?= $gradeLevel ?><a href="?<?= http_build_query(array_diff_key($_GET, ['grade_level' => ''])) ?>" class="filter-tag-remove">&times;</a></span>
                        <?php endif; ?>
                        <?php if ($section): ?>
                            <span class="filter-tag"><i class="bi bi-grid"></i> <?= sanitize($section) ?><a href="?<?= http_build_query(array_diff_key($_GET, ['section' => ''])) ?>" class="filter-tag-remove">&times;</a></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- STUDENT TABLE -->
        <div class="card student-table-wrapper">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-table me-2"></i>Student Records (<?= $totalStudents ?>)</span>
                <button type="button" id="btnOpenAddStudentModalRecords" class="btn btn-sm" style="background:var(--pg-primary);color:#fff;padding:6px 14px;border-radius:8px;font-size:12px;font-weight:700;border:none;text-decoration:none;cursor:pointer;"><i class="bi bi-plus-lg"></i> Add New</button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($students)): ?>
                    <div class="empty-state">
                        <div class="empty-icon" style="background:var(--pg-primary-light);color:var(--pg-primary);"><i class="bi bi-people"></i></div>
                        <h6>No Students Found</h6>
                        <p>Add your first student or adjust your filters to see results.</p>
                        <a href="<?= BASE_URL ?>/admin/student-add.php" class="btn-bar-primary mt-3" style="background:var(--pg-primary);font-size:13px;"><i class="bi bi-plus-lg"></i> Add Student</a>
                    </div>
                <?php else: ?>
                <div class="table-scroll-wrapper">
                    <table class="student-table">
                        <thead><tr><th>Student ID</th><th>Name</th><th>Age</th><th>Grade</th><th>Section</th><th>Gender</th><th>Guardian</th><th>Face</th><th style="width:130px;">Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                            <tr>
                                <td><span class="student-id-code"><?= sanitize($student['student_id']) ?></span></td>
                                <td class="student-name-cell">
                                    <span class="student-name"><?= sanitize($student['last_name']) ?>, <?= sanitize($student['first_name']) ?><?= $student['name_extension'] ? ' ' . sanitize($student['name_extension']) : '' ?></span>
                                    <?php if (!empty($student['middle_name'])): ?><div class="student-middle"><?= sanitize($student['middle_name']) ?></div><?php endif; ?>
                                    <?php if (!empty($student['email'])): ?><div class="student-middle"><?= sanitize($student['email']) ?></div><?php endif; ?>
                                </td>
                                <td><span style="font-size:10px;font-weight:700;padding:3px 10px;border-radius:6px;background:var(--pg-primary-light);color:var(--pg-primary);letter-spacing:0.04em;"><?= !empty($student['age']) ? $student['age'] : '-' ?></span></td>
                                <td><span style="font-size:10px;font-weight:700;padding:3px 10px;border-radius:6px;background:var(--pg-primary-light);color:var(--pg-primary);letter-spacing:0.04em;">Grade <?= $student['grade_level'] ?></span></td>
                                <td><?= sanitize($student['section'] ?? '-') ?></td>
                                <td><?= sanitize($student['gender'] ?? '-') ?></td>
                                <td>
                                    <?php if (!empty($student['guardian_name'])): ?>
                                        <div class="guardian-info"><?= sanitize($student['guardian_name']) ?></div>
                                        <?php if (!empty($student['relationship'])): ?><div class="guardian-phone"><?= sanitize($student['relationship']) ?></div><?php endif; ?>
                                        <?php if (!empty($student['guardian_phone'])): ?><div class="guardian-phone"><?= sanitize($student['guardian_phone']) ?></div><?php endif; ?>
                                        <?php if (!empty($student['guardian_email'])): ?><div class="guardian-phone"><?= sanitize($student['guardian_email']) ?></div><?php endif; ?>
                                    <?php else: ?><span style="opacity:0.4;font-size:12px;">Not set</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if (isset($faceMap[$student['id']])): ?>
                                        <span class="face-registered"><i class="bi bi-check-circle-fill"></i> Registered</span>
                                    <?php else: ?>
                                        <span class="face-pending"><i class="bi bi-clock-fill"></i> Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex;gap:4px;">
                                        <a href="<?= BASE_URL ?>/admin/student-edit.php?id=<?= $student['id'] ?>" class="btn-action-icon edit" title="Edit"><i class="bi bi-pencil"></i></a>
                                        <a href="<?= BASE_URL ?>/admin/student-edit.php?id=<?= $student['id'] ?>&tab=face" class="btn-action-icon face" title="Face Registration"><i class="bi bi-camera"></i></a>
                                        <button class="btn-action-icon delete" onclick="openDeleteConfirm(<?= $student['id'] ?>, '<?= sanitize(addslashes($student['first_name'] . ' ' . $student['name_extension'] . ' ' . $student['last_name'])) ?>')" title="Delete"><i class="bi bi-trash3"></i></button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                <div class="pagination-wrapper">
                    <div class="pagination-info">Showing <strong><?= $offset + 1 ?>–<?= min($offset + $perPage, $totalStudents) ?></strong> of <strong><?= $totalStudents ?></strong></div>
                    <div class="pagination-controls">
                        <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"><i class="bi bi-chevron-left"></i></a>
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <a class="page-btn <?= $i === $page ? 'active' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"><i class="bi bi-chevron-right"></i></a>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══ DELETE STUDENT CONFIRMATION MODAL ═══ -->
<div class="event-modal-overlay" id="deleteConfirmOverlay">
    <div class="event-modal delete-modal">
        <div class="event-modal-header">
            <div class="event-modal-title"><i class="bi bi-trash3" style="color:#ef4444;"></i><span>Delete Student</span></div>
            <button type="button" class="event-modal-close" id="deleteModalClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="event-modal-body" style="display:flex;align-items:center;justify-content:center;">
            <div class="delete-modal-text" style="width:100%;padding-top:10px;">
                <div class="delete-modal-icon" style="background:rgba(239,68,68,0.15);color:#ef4444;"><i class="bi bi-trash3-fill"></i></div>
                <h6>Delete this student?</h6>
                <p id="deleteStudentName">This student will be permanently removed along with all associated records including attendance history and face data.</p>
                <p style="color:#ef4444;font-size:12px;font-weight:600;margin-top:10px;"><i class="bi bi-exclamation-triangle-fill"></i> This action cannot be undone.</p>
            </div>
        </div>
        <div class="event-modal-footer">
            <button type="button" class="evt-btn evt-btn-cancel" id="deleteCancelBtn">Cancel</button>
            <a href="#" class="evt-btn evt-btn-danger" id="deleteConfirmBtn"><i class="bi bi-trash-fill"></i> Yes, Delete</a>
        </div>
    </div>
</div>

<!-- CSV / EXCEL IMPORT -->
<div class="event-modal-overlay" id="importModalOverlay">
    <div class="event-modal">
        <div class="event-modal-header">
            <div class="event-modal-title">
                <i class="bi bi-file-earmark-spreadsheet" style="color:#10b981;"></i>
                <span>Bulk Import Students</span>
            </div>
            <button class="event-modal-close" id="importModalClose"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="event-modal-body">
            <div style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:14px 16px;margin-bottom:14px;">
                <div style="font-size:13px;font-weight:700;color:#fff;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                    <i class="bi bi-info-circle" style="color:#60A5FA;"></i>
                    Supported Formats
                </div>
                <p style="font-size:12px;color:rgba(255,255,255,0.55);margin-bottom:6px;">You can import a CSV or Excel (.xlsx) file. Columns can be in any order.</p>
                <code style="display:block;padding:8px 10px;border-radius:6px;background:rgba(0,0,0,0.25);font-family:var(--pg-mono);font-size:11px;color:rgba(255,255,255,0.75);word-break:break-all;line-height:1.5;">LRN,first_name,middle_name,last_name,name_extension,age,gender,email,grade_level,section,address,guardian_name,relationship,guardian_phone,guardian_email,guardian_address</code>
                <p style="font-size:12px;color:rgba(255,255,255,0.55);margin-top:8px;margin-bottom:4px;"><strong style="color:rgba(255,255,255,0.7);">Example (first data row):</strong></p>
                <pre style="padding:8px 10px;border-radius:6px;background:rgba(0,0,0,0.25);font-family:var(--pg-mono);font-size:10px;color:rgba(255,255,255,0.65);line-height:1.5;overflow-x:auto;margin:0;">113400000001,Juan,Santos,Dela Cruz,Jr.,16,Male,juan@example.com,11,St. Luke,Malolos Bulacan,Jose Dela Cruz,parents,+639171234567,jose@example.com,Malolos Bulacan</pre>
            </div>
            <form id="importCSVForm" autocomplete="off" enctype="multipart/form-data">
                <div class="evt-field">
                    <label>Select File <span class="required">*</span></label>
                    <input type="file" id="csvFile" name="csv_file" accept=".csv,.xlsx" required style="width:100%;padding:9px 12px;border:1.5px solid rgba(255,255,255,0.12);border-radius:var(--pg-radius-sm);font-size:13px;background:rgba(255,255,255,0.05);color:inherit;">
                    <small style="font-size:10px;opacity:0.4;line-height:1.4;margin-top:3px;display:block;">Accepts .csv or .xlsx files, Max 10MB, up to 1000 students</small>
                </div>
                <div class="import-check-row" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:var(--pg-radius-xs);margin-bottom:8px;cursor:pointer;transition:all var(--pg-transition);background:rgba(255,255,255,0.03);">
                    <input class="import-check-input" type="checkbox" id="skipDuplicates" checked style="width:16px;height:16px;accent-color:#4f46e5;cursor:pointer;flex-shrink:0;">
                    <label class="import-check-label" for="skipDuplicates" style="font-size:13px;font-weight:500;cursor:pointer;color:rgba(255,255,255,0.7);">Skip duplicate Student IDs (recommended)</label>
                </div>
                <div class="import-check-row" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:var(--pg-radius-xs);margin-bottom:8px;cursor:pointer;transition:all var(--pg-transition);background:rgba(255,255,255,0.03);">
                    <input class="import-check-input" type="checkbox" id="updateExisting" style="width:16px;height:16px;accent-color:#4f46e5;cursor:pointer;flex-shrink:0;">
                    <label class="import-check-label" for="updateExisting" style="font-size:13px;font-weight:500;cursor:pointer;color:rgba(255,255,255,0.7);">Update existing records if Student ID exists</label>
                </div>
            </form>
            <div class="import-preview d-none" id="importPreview">
                <div class="import-preview-header" style="font-size:13px;font-weight:700;margin-bottom:10px;display:flex;align-items:center;gap:7px;color:#fff;"><i class="bi bi-eye"></i> Preview (first 5 rows)</div>
                <div class="import-preview-scroll" style="max-height:200px;overflow-y:auto;border-radius:10px;border:1px solid rgba(255,255,255,0.06);"><table class="import-preview-table" id="previewTable"><thead></thead><tbody></tbody></table></div>
            </div>
            <div class="import-results d-none" id="importResults">
                <div class="import-results-box" id="importResultsBox"><h6 id="importResultsTitle"></h6><div id="importResultsContent"></div></div>
            </div>
        </div>
        <div class="event-modal-footer">
            <button type="button" class="evt-btn evt-btn-cancel" id="importCancelBtn">Cancel</button>
            <button type="button" class="evt-btn evt-btn-save" id="importBtn" onclick="processImport()"><i class="bi bi-upload"></i> Import Students</button>
        </div>
    </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>
(function(){'use strict';
// Delete
var dO=document.getElementById('deleteConfirmOverlay'),dN=document.getElementById('deleteStudentName'),dB=document.getElementById('deleteConfirmBtn'),dC=document.getElementById('deleteCancelBtn'),dX=document.getElementById('deleteModalClose'),BU=window.BASE_URL||'';window.openDeleteConfirm=function(id,name){dN.textContent='"'+name+'" will be permanently removed along with all associated records including attendance history and face data.';dB.href=BU+'/admin/students.php?delete='+id;dO.classList.add('show');document.body.style.overflow='hidden'};function cD(){dO.classList.remove('show');document.body.style.overflow=''}dC.addEventListener('click',cD);if(dX)dX.addEventListener('click',cD);dO.addEventListener('click',function(e){if(e.target===dO)cD()});
// Import
var iO=document.getElementById('importModalOverlay'),iCl=document.getElementById('importModalClose'),iCa=document.getElementById('importCancelBtn');function oI(){iO.classList.add('show');document.body.style.overflow='hidden'}function cI(){iO.classList.remove('show');document.body.style.overflow=''}var iB1=document.getElementById('openImportBtn'),iB2=document.getElementById('openImportBtnMobile');if(iB1)iB1.addEventListener('click',oI);if(iB2)iB2.addEventListener('click',oI);iCl.addEventListener('click',cI);iCa.addEventListener('click',cI);iO.addEventListener('click',function(e){if(e.target===iO)cI()});iO.addEventListener('transitionend',function(){if(!iO.classList.contains('show')){document.getElementById('csvFile').value='';document.getElementById('importPreview').classList.add('d-none');document.getElementById('importResults').classList.add('d-none');csvData=null;excelFile=null}});
// Escape
document.addEventListener('keydown',function(e){if(e.key==='Escape'){if(dO.classList.contains('show'))cD();if(iO.classList.contains('show'))cI();if(dd&&dd.classList.contains('show')){nO=false;dd.classList.remove('show')}}});
// Toast
window.showToast=function(msg,type){type=type||'success';var c=document.getElementById('toastContainer'),t=document.createElement('div');t.className='toast-notification';t.style.background=type==='success'?'#059669':type==='error'?'#dc2626':type==='warning'?'#d97706':'#2563eb';var ic={success:'check-circle-fill',info:'info-circle-fill',warning:'exclamation-triangle-fill',error:'exclamation-circle-fill'};t.innerHTML='<i class="bi bi-'+(ic[type]||'info-circle-fill')+'"></i><span>'+esc(msg)+'</span>';c.appendChild(t);setTimeout(function(){if(t.parentNode)t.remove()},4200)};function esc(s){if(!s)return '';var d=document.createElement('div');d.appendChild(document.createTextNode(s));return d.innerHTML}
})();
// ═══ T&Cs GATEKEEPER (students.php) ═══
(function(){
    var modal = document.getElementById('tcs-gatekeeper-modal');
    var checkbox = document.getElementById('tcs-agreement-checkbox');
    var btnNext = document.getElementById('btn-next');
    var btnCancel = document.getElementById('btn-cancel');
    var btnOpen1 = document.getElementById('btnOpenAddStudentModal');
    var btnOpen2 = document.getElementById('btnOpenAddStudentModalMobile');
    var btnOpen3 = document.getElementById('btnOpenAddStudentModalRecords');

    function updateNextButton() {
        if (!btnNext || !checkbox) return;
        if (checkbox.checked) {
            btnNext.disabled = false;
            btnNext.style.background = '#4f46e5';
            btnNext.style.opacity = '1';
        } else {
            btnNext.disabled = true;
            btnNext.style.background = 'rgba(255,255,255,0.08)';
            btnNext.style.opacity = '0.5';
        }
    }

    function openTcsModal() {
        if (!modal) return;
        checkbox.checked = false;
        updateNextButton();
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function resetTcsGatekeeper() {
        if (!modal) return;
        modal.classList.remove('show');
        document.body.style.overflow = '';
        if (checkbox) checkbox.checked = false;
        updateNextButton();
    }

    if (btnOpen1) btnOpen1.addEventListener('click', function(e) {
        e.preventDefault();
        openTcsModal();
    });
    if (btnOpen2) btnOpen2.addEventListener('click', function(e) {
        e.preventDefault();
        openTcsModal();
    });
    if (btnOpen3) btnOpen3.addEventListener('click', function(e) {
        e.preventDefault();
        openTcsModal();
    });

    if (btnNext) {
        btnNext.addEventListener('click', function() {
            if (!checkbox || !checkbox.checked) return;
            resetTcsGatekeeper();
            window.location.href = '<?= BASE_URL ?>/admin/student-add.php';
        });
    }

    if (checkbox) {
        checkbox.addEventListener('change', function() {
            if (this.checked) {
                btnNext.disabled = false;
                btnNext.style.background = '#4f46e5';
                btnNext.style.opacity = '1';
            } else {
                btnNext.disabled = true;
                btnNext.style.background = 'rgba(255,255,255,0.08)';
                btnNext.style.opacity = '0.5';
            }
        });
    }

    if (btnCancel) {
        btnCancel.addEventListener('click', function() {
            resetTcsGatekeeper();
        });
    }

    var btnCloseX = document.getElementById('btn-cancel-x');
    if (btnCloseX) {
        btnCloseX.addEventListener('click', function() {
            resetTcsGatekeeper();
        });
    }

    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                resetTcsGatekeeper();
            }
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal && modal.classList.contains('show')) {
            resetTcsGatekeeper();
        }
    });
})();
// ═══ NAVBAR PROFILE AVATAR UPLOAD ═══
(function(){
    var avatarUpload = document.getElementById('profileAvatarUpload');
    var avatarInput = document.getElementById('profileAvatarInput');
    var avatarImg = document.getElementById('profileAvatarImg');
    var avatarText = document.getElementById('profileAvatarText');
    if(!avatarUpload||!avatarInput)return;
    
    avatarUpload.addEventListener('click',function(e){
        if(e.target.tagName!=='IMG'&&e.target.tagName!=='SPAN'&&!e.target.closest('.navbar-profile-avatar-upload-hint'))return;
        e.preventDefault();
        avatarInput.click();
    });
    
    avatarInput.addEventListener('change',function(e){
        var file = e.target.files[0];
        if(!file)return;
        if(!file.type.startsWith('image/')){
            showToast('Please select a valid image file','warning');
            return;
        }
        if(file.size>5*1024*1024){
            showToast('Image size must be less than 5MB','warning');
            return;
        }
        var reader = new FileReader();
        reader.onload = function(event){
            var dataUrl = event.target.result;
            avatarImg.src = dataUrl;
            avatarImg.style.display = 'block';
            avatarText.style.display = 'none';
            // Upload to server
            var formData = new FormData();
            formData.append('action','upload_avatar');
            formData.append('image',file);
            var csrf = document.querySelector('meta[name="csrf-token"]');
            if(csrf)formData.append('csrf_token',csrf.getAttribute('content'));
            fetch(window.BASE_URL+'/api/settings.php',{method:'POST',credentials:'same-origin',body:formData})
            .then(function(res){return res.json()})
            .catch(function(err){console.error('Avatar upload error:',err)});
        };
        reader.readAsDataURL(file);
    });
})();
var csvData=null;
function parseCSVLine(line){
    var result=[],current='',inQuotes=false;
    for(var i=0;i<line.length;i++){
        var ch=line[i];
        if(inQuotes){
            if(ch==='"'){
                if(i+1<line.length&&line[i+1]==='"'){current+='"';i++}
                else{inQuotes=false}
            }else{current+=ch}
        }else{
            if(ch==='"'){inQuotes=true}
            else if(ch===','){result.push(current.trim());current=''}
            else{current+=ch}
        }
    }
    result.push(current.trim());
    return result
}

function readCSVFile(file){
    var r=new FileReader();
    r.onload=function(e){
        var t=e.target.result,li=t.split('\n').filter(function(l){return l.trim()});
        if(li.length<2){showToast('CSV must have a header row and at least one data row.','warning');return}
        if(li[0]&&li[0].charCodeAt(0)===0xFEFF)li[0]=li[0].slice(1);
        var h=parseCSVLine(li[0]).map(function(x){return x.trim().toLowerCase()});
        var req=['lrn','first_name','last_name'],mis=req.filter(function(x){return h.indexOf(x)===-1});
        if(mis.length>0){showToast('Missing required columns: '+mis.join(', '),'error');document.getElementById('csvFile').value='';return}
        csvData=[];
        for(var i=1;i<li.length;i++){
            var v=parseCSVLine(li[i]);
            var row={};
            h.forEach(function(x,idx){row[x]=v[idx]||''});
            csvData.push(row)
        }
        showPreview(h,csvData.slice(0,5));
        document.getElementById('importPreview').classList.remove('d-none');
        document.getElementById('importResults').classList.add('d-none');
        showToast('CSV loaded: '+csvData.length+' rows detected.','info')
    };
    r.readAsText(file)
}

async function uploadExcelFile(file){
    var fd=new FormData();
    fd.append('excel_file',file);
    fd.append('action','import_excel');
    fd.append('skip_duplicates',document.getElementById('skipDuplicates').checked?'1':'0');
    fd.append('update_existing',document.getElementById('updateExisting').checked?'1':'0');
    var cm=document.querySelector('meta[name="csrf-token"]');
    if(cm)fd.append('csrf_token',cm.getAttribute('content'));
    
    var b=document.getElementById('importBtn');
    b.classList.add('loading');
    b.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Importing...';
    
    try{
        var res=await fetch(window.BASE_URL+'/api/students.php',{method:'POST',credentials:'same-origin',body:fd});
        var ct=res.headers.get('content-type')||'';
        var text=await res.text();
        if(!ct.includes('application/json')){
            var preview=text.length>200?text.substring(0,200)+'...':text;
            throw new Error('Server returned non-JSON (HTTP '+res.status+'): '+preview)
        }
        var d=JSON.parse(text);
        var rd=document.getElementById('importResults'),bd=document.getElementById('importResultsBox'),td=document.getElementById('importResultsTitle'),cd=document.getElementById('importResultsContent');
        rd.classList.remove('d-none');
        if(d.success){
            bd.style.background='rgba(16,185,129,0.15)';bd.style.color='#10b981';
            td.innerHTML='<i class="bi bi-check-circle-fill me-1"></i> Import Successful';
            cd.innerHTML='<p><strong>'+(d.imported||0)+'</strong> students imported</p>'+(d.skipped?'<p><strong>'+d.skipped+'</strong> duplicates skipped</p>':'')+(d.updated?'<p><strong>'+d.updated+'</strong> records updated</p>':'')+(d.errors?'<p><strong>'+d.errors+'</strong> rows had errors</p>':'')+'<small style="opacity:0.7;display:block;margin-top:8px;">Refreshing in 3 seconds...</small>';
            setTimeout(function(){location.reload()},3000)
        }else{
            bd.style.background='rgba(239,68,68,0.15)';bd.style.color='#ef4444';
            td.innerHTML='<i class="bi bi-exclamation-circle me-1"></i> Import Failed';
            cd.innerHTML='<p>'+(d.error||'Unknown error')+'</p>'
        }
    }catch(err){
        console.error('Import error:',err);
        var rd=document.getElementById('importResults'),bd=document.getElementById('importResultsBox'),td=document.getElementById('importResultsTitle'),cd=document.getElementById('importResultsContent');
        rd.classList.remove('d-none');
        bd.style.background='rgba(239,68,68,0.15)';bd.style.color='#ef4444';
        td.innerHTML='<i class="bi bi-exclamation-circle me-1"></i> Error';
        cd.innerHTML='<p style="white-space:pre-wrap;font-size:11px;opacity:0.9;">'+(err.message||'Network error')+'</p>'
    }finally{
        b.classList.remove('loading');
        b.innerHTML='<i class="bi bi-upload"></i> Import Students'
    }
}

function showPreview(h,rows){
    var t=document.getElementById('previewTable');
    t.innerHTML='';
    var th=document.createElement('thead'),hr=document.createElement('tr');
    h.forEach(function(x){
        var c=document.createElement('th');
        c.textContent=x;
        hr.appendChild(c)
    });
    th.appendChild(hr);
    t.appendChild(th);
    var tb=document.createElement('tbody');
    rows.forEach(function(row){
        var tr=document.createElement('tr');
        h.forEach(function(x){
            var td=document.createElement('td');
            td.textContent=row[x]||'-';
            tr.appendChild(td)
        });
        tb.appendChild(tr)
    });
    t.appendChild(tb)
}

document.getElementById('csvFile').addEventListener('change',function(e){
    var f=e.target.files[0];
    if(!f)return;
    var ext=f.name.split('.').pop().toLowerCase();
    if(ext==='xlsx'){
        csvData=null;
        document.getElementById('importPreview').classList.add('d-none');
        uploadExcelFile(f)
    }else{
        readCSVFile(f)
    }
});

async function processImport(){
    if(!csvData||csvData.length===0){showToast('Please select a valid CSV file first.','warning');return}
    var b=document.getElementById('importBtn');
    b.classList.add('loading');
    b.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Importing...';
    var fd=new FormData();
    var cm=document.querySelector('meta[name="csrf-token"]');
    fd.append('csrf_token',cm?cm.getAttribute('content'):'');
    fd.append('action','import_csv');
    fd.append('students',JSON.stringify(csvData));
    fd.append('skip_duplicates',document.getElementById('skipDuplicates').checked?'1':'0');
    fd.append('update_existing',document.getElementById('updateExisting').checked?'1':'0');
    var BU=window.BASE_URL||'';
    try{
        var res=await fetch(BU+'/api/students.php',{method:'POST',credentials:'same-origin',body:fd});
        var ct=res.headers.get('content-type')||'';
        var text=await res.text();
        if(!ct.includes('application/json')){
            var preview=text.length>200?text.substring(0,200)+'...':text;
            throw new Error('Server returned non-JSON (HTTP '+res.status+'): '+preview)
        }
        var d=JSON.parse(text);
        var rd=document.getElementById('importResults'),bd=document.getElementById('importResultsBox'),td=document.getElementById('importResultsTitle'),cd=document.getElementById('importResultsContent');
        rd.classList.remove('d-none');
        if(d.success){
            bd.style.background='rgba(16,185,129,0.15)';bd.style.color='#10b981';
            td.innerHTML='<i class="bi bi-check-circle-fill me-1"></i> Import Successful';
            cd.innerHTML='<p><strong>'+(d.imported||0)+'</strong> students imported</p>'+(d.skipped?'<p><strong>'+d.skipped+'</strong> duplicates skipped</p>':'')+(d.updated?'<p><strong>'+d.updated+'</strong> records updated</p>':'')+(d.errors?'<p><strong>'+d.errors+'</strong> rows had errors</p>':'')+'<small style="opacity:0.7;display:block;margin-top:8px;">Refreshing in 3 seconds...</small>';
            setTimeout(function(){location.reload()},3000)
        }else{
            bd.style.background='rgba(239,68,68,0.15)';bd.style.color='#ef4444';
            td.innerHTML='<i class="bi bi-exclamation-circle me-1"></i> Import Failed';
            cd.innerHTML='<p>'+(d.error||'Unknown error')+'</p>'
        }
    }catch(err){
        console.error('Import error:',err);
        var rd=document.getElementById('importResults'),bd=document.getElementById('importResultsBox'),td=document.getElementById('importResultsTitle'),cd=document.getElementById('importResultsContent');
        rd.classList.remove('d-none');
        bd.style.background='rgba(239,68,68,0.15)';bd.style.color='#ef4444';
        td.innerHTML='<i class="bi bi-exclamation-circle me-1"></i> Error';
        cd.innerHTML='<p style="white-space:pre-wrap;font-size:11px;opacity:0.9;">'+(err.message||'Network error')+'</p>'
    }finally{
        b.classList.remove('loading');
        b.innerHTML='<i class="bi bi-upload"></i> Import Students'
    }
}

// ============================================================
// GRADE -> SECTION FILTER DEPENDENCY
// ============================================================
(function() {
    var gradeSel = document.querySelector('select[name="grade_level"]');
    var sectionSel = document.getElementById('sectionFilterSelect');
    if (!gradeSel || !sectionSel) return;
    var gradeSectionsMap = <?= json_encode($gradeSectionsMap) ?>;

    function sectionListForGrade(grade) {
        var list = grade ? (gradeSectionsMap[grade] || []) : [];
        if (!grade) {
            var seen = {};
            list = [];
            Object.keys(gradeSectionsMap).forEach(function(g) {
                (gradeSectionsMap[g] || []).forEach(function(s) {
                    if (!seen[s]) { seen[s] = true; list.push(s); }
                });
            });
            list.sort();
        }
        return list;
    }

    function updateSectionOptions() {
        var current = sectionSel.value;
        var list = sectionListForGrade(gradeSel.value);
        sectionSel.innerHTML = '<option value="">All Sections</option>';
        list.forEach(function(s) {
            var opt = document.createElement('option');
            opt.value = s;
            opt.textContent = s;
            sectionSel.appendChild(opt);
        });
        if (list.indexOf(current) !== -1) {
            sectionSel.value = current;
        }
    }

    gradeSel.addEventListener('change', updateSectionOptions);
    updateSectionOptions();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>