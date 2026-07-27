<?php

require_once __DIR__ . '/../config.php';
requireRole(['admin']);

// ============================================================
// MIGRATIONS
// ============================================================
try {
    $col = $db->query("SHOW COLUMNS FROM subjects LIKE 'grade_level_end'")->fetchAll();
    if (empty($col)) $db->exec("ALTER TABLE subjects ADD COLUMN grade_level_end VARCHAR(10) DEFAULT NULL AFTER grade_level");
} catch (Exception $e) { error_log('mig: ' . $e->getMessage()); }

try {
    $col = $db->query("SHOW COLUMNS FROM subjects LIKE 'strand_id'")->fetchAll();
    if (empty($col)) $db->exec("ALTER TABLE subjects ADD COLUMN strand_id INT DEFAULT NULL AFTER grade_level_end");
} catch (Exception $e) { error_log('mig: ' . $e->getMessage()); }

try {
    $db->exec("CREATE TABLE IF NOT EXISTS sections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        section_name VARCHAR(100) NOT NULL,
        grade_level VARCHAR(10) NOT NULL,
        strand_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_grade_level (grade_level)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { error_log('sections: ' . $e->getMessage()); }

try {
    $col = $db->query("SHOW COLUMNS FROM sections LIKE 'strand_id'")->fetchAll();
    if (empty($col)) $db->exec("ALTER TABLE sections ADD COLUMN strand_id INT DEFAULT NULL AFTER grade_level");
} catch (Exception $e) { error_log('mig sec strand: ' . $e->getMessage()); }

try {
    $db->exec("CREATE TABLE IF NOT EXISTS strands (
        id INT AUTO_INCREMENT PRIMARY KEY,
        strand_name VARCHAR(255) NOT NULL,
        strand_code VARCHAR(50) NOT NULL,
        description TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY idx_strand_code (strand_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { error_log('strands: ' . $e->getMessage()); }

// ============================================================
// AJAX HANDLERS
// ============================================================
if (isset($_POST['ajax_action']) || isset($_GET['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['ajax_action'] ?? $_GET['ajax_action'] ?? '';

    switch ($action) {

        case 'add_subject':
            $code = trim($_POST['subject_code'] ?? '');
            $name = trim($_POST['subject_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $gf   = trim($_POST['grade_from'] ?? '');
            $gt   = trim($_POST['grade_to'] ?? '');
            $sid  = !empty($_POST['strand_id']) ? (int)$_POST['strand_id'] : null;
            if (empty($code) || empty($name) || empty($gf) || empty($gt)) { echo json_encode(['success'=>false,'message'=>'Subject code, name, and grade range are required.']); exit; }
            $v = ['7','8','9','10','11','12'];
            if (!in_array($gf,$v)||!in_array($gt,$v)) { echo json_encode(['success'=>false,'message'=>'Invalid grade.']); exit; }
            if ((int)$gf > (int)$gt) [$gf,$gt] = [$gt,$gf];
            if ((int)$gf < 11 && (int)$gt < 11) $sid = null;
            try {
                $dup = $db->prepare("SELECT id FROM subjects WHERE subject_code=?"); $dup->execute([$code]);
                if ($dup->fetch()) { echo json_encode(['success'=>false,'message'=>'Subject code already exists.']); exit; }
                $db->prepare("INSERT INTO subjects (subject_code,subject_name,description,grade_level,grade_level_end,strand_id) VALUES (?,?,?,?,?,?)")
                   ->execute([$code,$name,$desc,$gf,$gt,$sid]);
                echo json_encode(['success'=>true,'message'=>'Subject added.']);
            } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>'Database error.']); }
            exit;

        case 'update_subject':
            $id   = (int)($_POST['id'] ?? 0);
            $code = trim($_POST['subject_code'] ?? '');
            $name = trim($_POST['subject_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $gf   = trim($_POST['grade_from'] ?? '');
            $gt   = trim($_POST['grade_to'] ?? '');
            $sid  = !empty($_POST['strand_id']) ? (int)$_POST['strand_id'] : null;
            if ($id<=0||empty($code)||empty($name)||empty($gf)||empty($gt)) { echo json_encode(['success'=>false,'message'=>'All required fields must be filled.']); exit; }
            $v = ['7','8','9','10','11','12'];
            if (!in_array($gf,$v)||!in_array($gt,$v)) { echo json_encode(['success'=>false,'message'=>'Invalid grade.']); exit; }
            if ((int)$gf > (int)$gt) [$gf,$gt] = [$gt,$gf];
            if ((int)$gf < 11 && (int)$gt < 11) $sid = null;
            try {
                $db->prepare("UPDATE subjects SET subject_code=?,subject_name=?,description=?,grade_level=?,grade_level_end=?,strand_id=? WHERE id=?")
                   ->execute([$code,$name,$desc,$gf,$gt,$sid,$id]);
                echo json_encode(['success'=>true,'message'=>'Subject updated.']);
            } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>'Database error.']); }
            exit;

        case 'delete_subject':
            $id = (int)($_POST['subject_id'] ?? 0);
            if ($id<=0) { 
                echo json_encode(['success'=>false,'message'=>'Invalid ID.']); 
                exit; 
            }
            try {
                // Check if subject exists first
                $check = $db->prepare("SELECT id FROM subjects WHERE id=?");
                $check->execute([$id]);
                if (!$check->fetch()) {
                    echo json_encode(['success'=>false,'message'=>'Subject not found.']);
                    exit;
                }
                
                $db->prepare("DELETE FROM subjects WHERE id=?")->execute([$id]);
                echo json_encode(['success'=>true,'message'=>'Subject deleted.']);
            } catch (Exception $e) {
                error_log('Delete subject error: ' . $e->getMessage());
                echo json_encode(['success'=>false,'message'=>'Cannot delete subject. It may be in use.']);
            }
            exit;

        case 'add_section':
            $name  = trim($_POST['section_name'] ?? '');
            $grade = trim($_POST['grade_level'] ?? '');
            $sid   = !empty($_POST['strand_id']) ? (int)$_POST['strand_id'] : null;
            if (empty($name) || empty($grade)) { echo json_encode(['success'=>false,'message'=>'Section name and grade required.']); exit; }
            if (!in_array($grade,['7','8','9','10','11','12'])) { echo json_encode(['success'=>false,'message'=>'Invalid grade.']); exit; }
            if ((int)$grade < 11) $sid = null;
            try {
                $db->prepare("INSERT INTO sections (section_name,grade_level,strand_id) VALUES (?,?,?)")
                   ->execute([$name,$grade,$sid]);
                echo json_encode(['success'=>true,'message'=>'Section added.','section'=>['id'=>(int)$db->lastInsertId(),'section_name'=>$name,'grade_level'=>$grade]]);
            } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>'Database error.']); }
            exit;

        case 'update_section':
            $id    = (int)($_POST['section_id'] ?? 0);
            $name  = trim($_POST['section_name'] ?? '');
            $grade = trim($_POST['grade_level'] ?? '');
            $sid   = !empty($_POST['strand_id']) ? (int)$_POST['strand_id'] : null;
            if ($id<=0||empty($name)||empty($grade)) { echo json_encode(['success'=>false,'message'=>'All fields required.']); exit; }
            if (!in_array($grade,['7','8','9','10','11','12'])) { echo json_encode(['success'=>false,'message'=>'Invalid grade.']); exit; }
            if ((int)$grade < 11) $sid = null;
            try {
                $db->prepare("UPDATE sections SET section_name=?,grade_level=?,strand_id=? WHERE id=?")
                   ->execute([$name,$grade,$sid,$id]);
                echo json_encode(['success'=>true,'message'=>'Section updated.']);
            } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>'Database error.']); }
            exit;

        case 'delete_section':
            $id = (int)($_POST['section_id'] ?? 0);
            if ($id<=0) { 
                echo json_encode(['success'=>false,'message'=>'Invalid ID.']); 
                exit; 
            }
            try {
                // Check if section exists
                $check = $db->prepare("SELECT id FROM sections WHERE id=?");
                $check->execute([$id]);
                if (!$check->fetch()) {
                    echo json_encode(['success'=>false,'message'=>'Section not found.']);
                    exit;
                }
                
                $db->prepare("DELETE FROM sections WHERE id=?")->execute([$id]);
                echo json_encode(['success'=>true,'message'=>'Section deleted.']);
            } catch (Exception $e) {
                error_log('Delete section error: ' . $e->getMessage());
                echo json_encode(['success'=>false,'message'=>'Cannot delete section. It may be in use.']);
            }
            exit;

        case 'delete_strand':
            $id = (int)($_POST['id'] ?? 0);
            if ($id<=0) { 
                echo json_encode(['success'=>false,'message'=>'Invalid ID.']); 
                exit; 
            }
            try {
                // Check if track exists
                $check = $db->prepare("SELECT id FROM strands WHERE id=?");
                $check->execute([$id]);
                if (!$check->fetch()) {
                    echo json_encode(['success'=>false,'message'=>'Track not found.']);
                    exit;
                }
                
                // Check if track is being used by subjects or sections
                $used = $db->prepare("SELECT COUNT(*) FROM subjects WHERE strand_id=?");
                $used->execute([$id]);
                $subjectCount = $used->fetchColumn();
                
                $used = $db->prepare("SELECT COUNT(*) FROM sections WHERE strand_id=?");
                $used->execute([$id]);
                $sectionCount = $used->fetchColumn();
                
                if ($subjectCount > 0 || $sectionCount > 0) {
                    $message = 'Cannot delete track: It is being used by ';
                    if ($subjectCount > 0) $message .= $subjectCount . ' subject(s) ';
                    if ($sectionCount > 0) $message .= $sectionCount . ' section(s)';
                    echo json_encode(['success'=>false,'message'=>$message]);
                    exit;
                }
                
                // Update subjects and sections to remove strand_id reference
                $db->prepare("UPDATE subjects SET strand_id=NULL WHERE strand_id=?")->execute([$id]);
                $db->prepare("UPDATE sections SET strand_id=NULL WHERE strand_id=?")->execute([$id]);
                $db->prepare("DELETE FROM strands WHERE id=?")->execute([$id]);
                
                echo json_encode(['success'=>true,'message'=>'Track deleted.']);
            } catch (Exception $e) {
                error_log('Delete track error: ' . $e->getMessage());
                echo json_encode(['success'=>false,'message'=>'Cannot delete track. It may be in use.']);
            }
            exit;

        case 'add_strand':
            $code = trim($_POST['strand_code'] ?? '');
            $name = trim($_POST['strand_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if (empty($code)||empty($name)) { echo json_encode(['success'=>false,'message'=>'Code and name required.']); exit; }
            try {
                $dup = $db->prepare("SELECT id FROM strands WHERE strand_code=?"); $dup->execute([$code]);
                if ($dup->fetch()) { echo json_encode(['success'=>false,'message'=>'Track code already exists.']); exit; }
                $db->prepare("INSERT INTO strands (strand_code,strand_name,description) VALUES (?,?,?)")->execute([$code,$name,$desc]);
                echo json_encode(['success'=>true,'message'=>'Track added.']);
            } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>'Database error.']); }
            exit;

        case 'update_strand':
            $id   = (int)($_POST['strand_id'] ?? 0);
            $code = trim($_POST['strand_code'] ?? '');
            $name = trim($_POST['strand_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if ($id<=0||empty($code)||empty($name)) { echo json_encode(['success'=>false,'message'=>'Code and name required.']); exit; }
            try {
                $dup = $db->prepare("SELECT id FROM strands WHERE strand_code=? AND id!=?"); $dup->execute([$code,$id]);
                if ($dup->fetch()) { echo json_encode(['success'=>false,'message'=>'Track code already exists.']); exit; }
                $db->prepare("UPDATE strands SET strand_code=?,strand_name=?,description=? WHERE id=?")->execute([$code,$name,$desc,$id]);
                echo json_encode(['success'=>true,'message'=>'Track updated.']);
            } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>'Database error.']); }
            exit;

        default:
            echo json_encode(['success'=>false,'message'=>'Unknown action.']); exit;
    }
}

// ============================================================
// PAGE DATA
// ============================================================
$pageTitle = 'Subject Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

$gradeFilter = sanitize($_GET['grade_level'] ?? '');
$subjects = [];
try {
    $sql = "SELECT s.*, st.strand_name, st.strand_code FROM subjects s LEFT JOIN strands st ON s.strand_id = st.id";
    if ($gradeFilter) $sql .= " WHERE s.grade_level <= :g AND COALESCE(s.grade_level_end,s.grade_level) >= :g";
    $sql .= " ORDER BY s.grade_level ASC, s.subject_name ASC";
    $stmt = $db->prepare($sql);
    if ($gradeFilter) $stmt->bindValue(':g',$gradeFilter);
    $stmt->execute(); $subjects = $stmt->fetchAll();
} catch (Exception $e) { $subjects = []; }

$totalSubjects=$totalTracks=$totalSections=$jhsCount=$shsCount=0;
try {
    $totalSubjects=$db->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
    $totalTracks=$db->query("SELECT COUNT(*) FROM strands")->fetchColumn();
    $totalSections=$db->query("SELECT COUNT(*) FROM sections")->fetchColumn();

    $subjectLevelRows = $db->query("SELECT grade_level, grade_level_end FROM subjects")->fetchAll();
    foreach ($subjectLevelRows as $subjectRow) {
        $bucket = getSubjectLevelBucket($subjectRow['grade_level'], $subjectRow['grade_level_end']);
        if ($bucket === 'jhs') {
            $jhsCount++;
        } elseif ($bucket === 'shs') {
            $shsCount++;
        }
    }
} catch (Exception $e) {}

$strands = [];
try { $strands = $db->query("SELECT * FROM strands ORDER BY strand_name")->fetchAll(); } catch (Exception $e) {}

$allSections = [];
try {
    $sql = "SELECT s.*, st.strand_name, st.strand_code FROM sections s LEFT JOIN strands st ON s.strand_id = st.id WHERE 1=1";
    $params = [];
    if ($gradeFilter) {
        $sql .= " AND CAST(s.grade_level AS UNSIGNED) = :g";
        $params[':g'] = (int)$gradeFilter;
    }
    $sql .= " ORDER BY CAST(s.grade_level AS UNSIGNED) ASC, s.section_name ASC";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_INT);
    $stmt->execute(); $allSections = $stmt->fetchAll();
} catch (Exception $e) {}

function gradeRangeLabel($r) {
    $f=(int)$r['grade_level']; $t=(int)($r['grade_level_end']??$f);
    $sn=$r['strand_name']??null; $sc=$r['strand_code']??null;
    $l='Grade '.$f; if($t!==$f) $l.='–'.$t;
    if($sn) $l.=' · '.$sn; elseif($sc) $l.=' · '.$sc;
    return $l;
}

function sectionGradeLabel($r) {
    $sn=$r['strand_name']??null; $sc=$r['strand_code']??null;
    $l='Grade '.$r['grade_level'];
    if($sn) $l.=' — '.$sn; elseif($sc) $l.=' — '.$sc;
    return $l;
}

function buildGradeOptions($strands, $includePlaceholder=true, $placeholderText='Select Grade', $plainShs = false) {
    $h='';
    if($includePlaceholder) $h.='<option value="">'.htmlspecialchars($placeholderText).'</option>';
    $h.='<optgroup label="Junior High School">';
    foreach(['7','8','9','10'] as $g) $h.='<option value="'.$g.'">Grade '.$g.'</option>';
    $h.='</optgroup>';
    if($plainShs){
        $h.='<optgroup label="Senior High">';
        $h.='<option value="11">Grade 11</option>';
        $h.='<option value="12">Grade 12</option>';
        $h.='</optgroup>';
    } else {
        $h.='<optgroup label="Senior High — Grade 11">';
        $h.='<option value="11:0">Grade 11 — All Tracks</option>';
        foreach($strands as $st) $h.='<option value="11:'.$st['id'].'">Grade 11 — '.sanitize($st['strand_code']).'</option>';
        $h.='</optgroup>';
        $h.='<optgroup label="Senior High — Grade 12">';
        $h.='<option value="12:0">Grade 12 — All Tracks</option>';
        foreach($strands as $st) $h.='<option value="12:'.$st['id'].'">Grade 12 — '.sanitize($st['strand_code']).'</option>';
        $h.='</optgroup>';
    }
    return $h;
}
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">

<style>
    :root {
        --sm-font:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,sans-serif;
        --sm-mono:'JetBrains Mono',monospace;
        --sm-primary:#4f46e5;--sm-primary-light:rgba(79,70,229,0.15);--sm-primary-dark:#3730a3;--sm-primary-glow:rgba(79,70,229,0.2);
        --sm-success:#10b981;--sm-success-light:rgba(16,185,129,0.15);--sm-success-dark:#059669;--sm-success-glow:rgba(16,185,129,0.25);
        --sm-danger:#ef4444;--sm-danger-light:rgba(239,68,68,0.15);
        --sm-warning:#f59e0b;--sm-warning-light:rgba(245,158,11,0.15);
        --sm-info:#06b6d4;--sm-info-light:rgba(6,182,212,0.15);
        --sm-radius:14px;--sm-radius-sm:10px;--sm-radius-xs:8px;
        --sm-shadow:0 4px 16px rgba(0,0,0,0.25);
        --sm-transition:0.2s cubic-bezier(0.4,0,0.2,1);
        --select-bg:#1e2a4a;--select-bg-hover:#2563eb;--select-bg-placeholder:#151d33;
        --select-text:#fff;--select-text-muted:rgba(255,255,255,0.45);
    }

    /* GLOBAL SELECT */
    select,.form-select{appearance:none;-webkit-appearance:none;-moz-appearance:none;background-color:rgba(255,255,255,0.05);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='rgba(255,255,255,0.5)' viewBox='0 0 16 16'%3E%3Cpath d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;background-size:12px;padding-right:40px;cursor:pointer;border:1.5px solid rgba(255,255,255,0.12);border-radius:var(--sm-radius-sm);color:inherit;font-size:14px;font-family:var(--sm-font);transition:all var(--sm-transition)}
    select:focus,.form-select:focus{outline:none;border-color:var(--sm-primary);box-shadow:0 0 0 3px var(--sm-primary-glow)}
    select option,.form-select option{background:var(--select-bg);color:var(--select-text);padding:10px 14px;font-size:14px;line-height:1.6}
    select option:hover,select option:checked,select option:active{background:var(--select-bg-hover);color:var(--select-text)}
    select option[value=""]{color:var(--select-text-muted);background:var(--select-bg-placeholder)}
    select optgroup,.form-select optgroup{background:#1a2540;color:rgba(255,255,255,0.55);font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:0.08em;padding:6px 0}
    select option:disabled{opacity:0.3;cursor:not-allowed}

    /* NAVBAR */
    .page-title h5
    .mobile-title-left h5 { font-size: 18px; font-weight: 800; letter-spacing: -0.03em; margin: 0; }
    .mobile-title-left small { font-size: 12px; font-weight: 500; }
    .stat-card{transition:all var(--sm-transition);position:relative;overflow:hidden}
    .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--sm-radius) var(--sm-radius) 0 0;opacity:0;transition:opacity var(--sm-transition)}
    .stat-card:hover{transform:translateY(-3px)}.stat-card:hover::before{opacity:1}
    .stat-card:nth-child(1)::before{background:var(--sm-primary)}.stat-card:nth-child(2)::before{background:var(--sm-success)}
    .stat-card:nth-child(3)::before{background:var(--sm-info)}.stat-card:nth-child(4)::before{background:var(--sm-warning)}
    .stat-card:nth-child(5)::before{background:var(--sm-danger)}
    @keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
    .stat-card{animation:fadeUp .5s ease forwards;opacity:0}
    .stat-card:nth-child(1){animation-delay:.05s}.stat-card:nth-child(2){animation-delay:.1s}
    .stat-card:nth-child(3){animation-delay:.15s}.stat-card:nth-child(4){animation-delay:.2s}
    .stat-card:nth-child(5){animation-delay:.25s}

    /* CARDS */
    .card{overflow:hidden}.card-header{font-size:14px;font-weight:700;letter-spacing:-0.01em}
    .track-row,.section-row,.subject-card-item{transition:all var(--sm-transition)}.track-row:hover,.section-row:hover,.subject-card-item:hover{background:rgba(255,255,255,0.03)}
    .grade-range-badge{font-size:11px;font-weight:700;padding:4px 12px;border-radius:20px;white-space:nowrap;display:inline-flex;align-items:center;gap:5px}
    .grade-range-badge i{font-size:10px}

    /* FILTER */
    .filter-chips{display:flex;gap:8px;flex-wrap:wrap}
    .filter-chip{padding:7px 18px;border-radius:20px;font-size:12px;font-weight:600;border:1.5px solid rgba(255,255,255,0.12);background:transparent;cursor:pointer;transition:all var(--sm-transition);text-decoration:none;display:inline-flex;align-items:center}
    .filter-chip:hover{transform:translateY(-1px);border-color:var(--sm-primary)}.filter-chip.active{background:var(--sm-primary);border-color:var(--sm-primary);color:#fff}

    /* ACTION BUTTONS */
    .btn-add-action{padding:9px 18px;border:none;border-radius:var(--sm-radius-sm);font-size:13px;font-weight:700;cursor:pointer;transition:all var(--sm-transition);display:inline-flex;align-items:center;gap:7px;white-space:nowrap}
    .btn-add-action:hover{transform:translateY(-1px)}
    .btn-add-subject{background:var(--sm-primary);color:#fff}.btn-add-subject:hover{background:var(--sm-primary-dark);box-shadow:0 4px 16px var(--sm-primary-glow)}
    .btn-add-section{background:var(--sm-success);color:#fff}.btn-add-section:hover{background:var(--sm-success-dark);box-shadow:0 4px 16px var(--sm-success-glow)}
    .btn-add-track-sm{background:var(--sm-info);color:#fff}.btn-add-track-sm:hover{background:#0891b2}

    /* MODAL */
    .event-modal-overlay{position:fixed;inset:0;background:rgba(26,29,46,0.5);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);z-index:9998;display:none;align-items:center;justify-content:center;padding:20px}
    .event-modal-overlay.show{display:flex}
    .event-modal{border-radius:20px;width:500px;max-width:100%;max-height:95vh;display:flex;flex-direction:column;animation:modalSlideIn .35s cubic-bezier(.34,1.56,.64,1);overflow:hidden}
    @keyframes modalSlideIn{from{opacity:0;transform:translateY(24px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}
    .event-modal-header{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;flex-shrink:0}
    .event-modal-title{display:flex;align-items:center;gap:10px;font-size:16px;font-weight:800;letter-spacing:-0.02em}
    .event-modal-title i{font-size:20px}
    .event-modal-close{width:30px;height:30px;border:none;border-radius:var(--sm-radius-xs);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all var(--sm-transition);font-size:13px;background:rgba(255,255,255,0.06);color:inherit}
    .event-modal-close:hover{background:rgba(255,255,255,0.14)}
    .event-modal-body{padding:6px 18px 14px;overflow-y:auto;flex:1;min-height:0}
    .event-modal-body::-webkit-scrollbar{width:5px}.event-modal-body::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.12);border-radius:10px}
    .evt-field{margin-bottom:8px}.evt-field:last-child{margin-bottom:0}
    .evt-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:3px}
    .evt-field label .required{color:var(--sm-danger)}
    .evt-field input[type="text"],.evt-field textarea,.evt-field select{width:100%;padding:9px 12px;border:1.5px solid rgba(255,255,255,0.12);border-radius:var(--sm-radius-sm);font-size:13px;transition:all var(--sm-transition);font-family:var(--sm-font);background:rgba(255,255,255,0.05);color:inherit}
    .evt-field textarea{resize:vertical;min-height:54px}
    .evt-field input:focus,.evt-field textarea:focus,.evt-field select:focus{outline:none;border-color:var(--sm-primary);box-shadow:0 0 0 3px var(--sm-primary-glow)}
    .evt-field .form-hint{font-size:10px;margin-top:4px;opacity:0.5}
    .evt-field select{background-color:rgba(255,255,255,0.05);padding-right:36px}
    .evt-row{display:flex;gap:10px}.evt-flex-1{flex:1;min-width:0}
    .evt-separator{border:none;border-top:1px solid rgba(255,255,255,0.06);margin:8px 0}
    .evt-section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:8px;display:flex;align-items:center;gap:6px;opacity:0.5}
    .event-modal-footer{display:flex;justify-content:flex-end;gap:8px;padding:10px 18px;flex-shrink:0}
    .evt-btn{padding:9px 16px;border:none;border-radius:var(--sm-radius-sm);font-size:12px;font-weight:700;cursor:pointer;transition:all var(--sm-transition);display:flex;align-items:center;gap:5px}
    .evt-btn-cancel{background:rgba(255,255,255,0.08);color:inherit}.evt-btn-cancel:hover{background:rgba(255,255,255,0.14)}
    .evt-btn-save{background:var(--sm-primary);color:#fff}.evt-btn-save:hover{background:var(--sm-primary-dark);box-shadow:0 4px 16px var(--sm-primary-glow)}
    .evt-btn-save.loading{opacity:0.7;pointer-events:none}
    .evt-btn-danger{background:var(--sm-danger);color:#fff}.evt-btn-danger:hover{background:#dc2626;box-shadow:0 4px 14px rgba(239,68,68,0.3)}

    /* Delete modal */
    .delete-modal{width:400px;height:auto;max-height:90vh}
    .delete-modal-icon{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:24px}
    .delete-modal-text{text-align:center}
    .delete-modal-text h6{font-weight:700;font-size:16px;letter-spacing:-0.02em;margin-bottom:6px}
    .delete-modal-text p{font-size:13px;max-width:280px;margin:0 auto;line-height:1.5}

    /* TOAST */
    .toast-container{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none}
    .toast-notification{padding:14px 22px;border-radius:var(--sm-radius-sm);color:#fff;font-size:13px;font-weight:600;display:flex;align-items:center;gap:12px;max-width:400px;pointer-events:auto;animation:toastIn .4s cubic-bezier(.34,1.56,.64,1),toastOut .4s ease 3.6s forwards;font-family:var(--sm-font)}
    .toast-success{background:#059669}.toast-error{background:#dc2626}.toast-info{background:#0284c7}
    @keyframes toastIn{from{opacity:0;transform:translateX(40px) scale(.95)}to{opacity:1;transform:translateX(0) scale(1)}}
    @keyframes toastOut{from{opacity:1;transform:translateX(0)}to{opacity:0;transform:translateX(40px)}}
    .toast-notification i{font-size:18px;flex-shrink:0;opacity:0.9}

    .empty-state{text-align:center;padding:30px 20px}
    .empty-icon{width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:22px}
    .empty-state h6{font-weight:600;margin-bottom:4px}.empty-state p{font-size:13px}

    @media(max-width:991px){.content-area{padding:20px}.event-modal{width:460px}}
    @media(max-width:767px){
        .top-navbar{padding:12px 14px;flex-wrap:wrap;gap:0}.navbar-left{flex:1;gap:10px}
        #sidebarToggle{width:38px;height:38px;font-size:20px;flex-shrink:0}
        .navbar-brand{display:flex}.navbar-brand-logo{width:44px;height:44px}
        .navbar-brand-name{font-size:12px}.navbar-brand-sub{font-size:9px;opacity:0.45}
        .desktop-title{display:none!important}.mobile-title{display:block}
        .navbar-actions{gap:6px}.nav-icon-btn{width:38px;height:38px;font-size:15px}

        .content-area{padding:10px 12px 28px}
        .stat-card{padding:14px 12px}.stat-value{font-size:22px}.stat-label{font-size:10px;margin-top:3px}
        .stat-icon{width:36px;height:36px;font-size:14px;border-radius:10px}
        .card-header{font-size:13px}.filter-chips{gap:6px}.filter-chip{padding:6px 14px;font-size:11px}
        .btn-add-action{padding:8px 14px;font-size:12px;gap:5px}
        .subject-card .card-body{padding:14px}.subject-card h6{font-size:14px}
        .subject-card .card-footer{padding:0 14px 14px}
        .empty-state{padding:24px 16px}.empty-icon{width:44px;height:44px;font-size:18px}
        .empty-state h6{font-size:13px}.empty-state p{font-size:12px}
        .event-modal-overlay{align-items:flex-end;justify-content:center;padding:0}
        .event-modal{width:100%;max-width:100vw;max-height:92vh;border-radius:16px 16px 0 0;animation:modalSheetUp .3s ease-out}
        @keyframes modalSheetUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
        .event-modal-header{padding:18px 20px}.event-modal-title{font-size:15px;gap:10px}.event-modal-title i{font-size:18px}
        .event-modal-body{padding:4px 20px 20px}.evt-field{margin-bottom:16px}
        .evt-field input[type="text"],.evt-field textarea,.evt-field select{padding:10px 14px;font-size:13px}
        .evt-row{flex-direction:column;gap:0}
        .event-modal-footer{padding:14px 20px;padding-bottom:calc(14px + env(safe-area-inset-bottom, 0px))}
        .evt-btn{padding:10px 18px;font-size:12px}
        select,.form-select{background-position:right 12px center;padding-right:36px}
        select option,.form-select option{padding:8px 12px;font-size:13px}
        .event-modal::before{content:'';display:block;width:36px;height:4px;border-radius:4px;background:rgba(255,255,255,0.2);margin:10px auto 0;flex-shrink:0}
        .toast-container{bottom:24px;right:12px;left:12px}.toast-notification{max-width:100%;font-size:12px;padding:12px 16px}
        .stat-card{animation:none;opacity:1}
        .mobile-title-left h5 { font-size: 17px; }
        .mobile-title-left small { font-size: 12px; }
    }
    @media(max-width:576px){
        .top-navbar{padding:10px 10px}.navbar-brand-logo{width:38px;height:38px}
        .navbar-brand-name{font-size:11px}.navbar-brand-sub{font-size:8px}
        .navbar-actions{gap:4px;flex-wrap:wrap}.nav-icon-btn{width:34px;height:34px;font-size:14px}
        #sidebarToggle{width:34px;height:34px;font-size:18px}
        .mobile-title-left h5{font-size:15px}.mobile-title-left small{font-size:11px}
        .content-area{padding:8px 8px 24px}.stat-card{padding:12px 10px}
        .stat-value{font-size:18px}.stat-label{font-size:9px}
        .stat-icon{width:32px;height:32px;font-size:13px}
        .btn-add-action{padding:7px 12px;font-size:11px}
        .filter-chip{padding:5px 10px;font-size:10px}.sidebar{width:260px}
        .event-modal{max-height:95vh}.event-modal-body{padding:4px 16px 16px}
        .event-modal-header{padding:16px}
        .event-modal-footer{padding:12px 16px;padding-bottom:calc(12px + env(safe-area-inset-bottom, 0px))}
    }
    @media(min-width:768px){.mobile-title{display:none!important}}
</style>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">

<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">

    <div class="content-area">
        <?= displayFlashMessage() ?>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
            <div class="page-title mb-0">
                <h5 class="mb-0">Subject & Track Management</h5>
                <small>Configure academic subjects, SHS tracks, and sections</small>
            </div>
            <div class="d-flex gap-2">
                <button class="btn-add-action btn-add-section" id="btnAddSection"><i class="bi bi-window-stack"></i> Add Section</button>
                <button class="btn-add-action btn-add-subject" id="btnAddSubject"><i class="bi bi-plus-lg"></i> Add Subject</button>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg col-md-4"><div class="stat-card"><div class="d-flex justify-content-between align-items-start"><div><div class="stat-value"><?= $totalSubjects ?></div><div class="stat-label">Total Subjects</div></div><div class="stat-icon bg-primary-soft"><i class="bi bi-book"></i></div></div></div></div>
            <div class="col-6 col-lg col-md-4"><div class="stat-card"><div class="d-flex justify-content-between align-items-start"><div><div class="stat-value"><?= $totalTracks ?></div><div class="stat-label">Total Tracks</div></div><div class="stat-icon bg-success-soft"><i class="bi bi-diagram-3"></i></div></div></div></div>
            <div class="col-6 col-lg col-md-4"><div class="stat-card"><div class="d-flex justify-content-between align-items-start"><div><div class="stat-value"><?= $totalSections ?></div><div class="stat-label">Total Sections</div></div><div class="stat-icon bg-info-soft"><i class="bi bi-window-stack"></i></div></div></div></div>
            <div class="col-6 col-lg col-md-6"><div class="stat-card"><div class="d-flex justify-content-between align-items-start"><div><div class="stat-value"><?= $jhsCount ?></div><div class="stat-label">JHS Subjects</div></div><div class="stat-icon bg-warning-soft"><i class="bi bi-journal-text"></i></div></div></div></div>
            <div class="col-12 col-lg col-md-6"><div class="stat-card"><div class="d-flex justify-content-between align-items-start"><div><div class="stat-value"><?= $shsCount ?></div><div class="stat-label">SHS Subjects</div></div><div class="stat-icon bg-danger-soft"><i class="bi bi-mortarboard"></i></div></div></div></div>
        </div>

        <div class="card mb-4"><div class="card-body py-3"><div class="d-flex flex-wrap align-items-center gap-2">
            <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;opacity:.6;margin-right:4px">JHS Grade</span>
            <a href="<?= BASE_URL ?>/admin/subjects.php" class="filter-chip <?= !$gradeFilter?'active':'' ?>" data-grade="">All</a>
            <?php foreach(['7','8','9','10'] as $g): ?><a href="<?= BASE_URL ?>/admin/subjects.php?grade_level=<?= $g ?>" class="filter-chip <?= $gradeFilter===$g?'active':'' ?>" data-grade="<?= $g ?>">Grade <?= $g ?></a><?php endforeach; ?>
            <span style="width:1px;height:18px;background:rgba(255,255,255,0.12);margin:0 4px"></span>
            <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;opacity:.6;margin-right:4px">SHS</span>
            <?php foreach(['11','12'] as $g): ?><a href="<?= BASE_URL ?>/admin/subjects.php?grade_level=<?= $g ?>" class="filter-chip <?= $gradeFilter===$g?'active':'' ?>" data-grade="<?= $g ?>">Grade <?= $g ?></a><?php endforeach; ?>
        </div></div></div>

        <!-- SUBJECTS -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-grid me-2"></i>Subjects</span><span class="badge bg-secondary-soft subjects-header-badge"><?= count($subjects) ?></span></div>
            <div class="card-body">
                <?php if(empty($subjects)): ?><div class="empty-state"><div class="empty-icon bg-primary-soft"><i class="bi bi-book"></i></div><h6>No Subjects Found</h6><p>Add your first subject to get started.</p></div>
                <?php else: ?><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em">Subject Code</th>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em">Subject Name</th>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em">Grade Level</th>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em">Description</th>
                    <th style="width:100px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em">Actions</th>
                </tr></thead><tbody><?php foreach($subjects as $s): ?><tr class="subject-card-item" data-grade-start="<?= (int)$s['grade_level'] ?>" data-grade-end="<?= (int)($s['grade_level_end'] ?? $s['grade_level']) ?>">
                    <td><code style="font-size:12px"><?= sanitize($s['subject_code']) ?></code></td>
                    <td class="fw-600"><?= sanitize($s['subject_name']) ?></td>
                    <td><span class="grade-range-badge bg-secondary-soft text-primary"><?= sanitize(gradeRangeLabel($s)) ?></span></td>
                    <td style="font-size:12px"><?= !empty($s['description']) ? sanitize($s['description']) : '-' ?></td>
                    <td><div class="d-flex gap-1">
                        <button class="btn btn-sm btn-outline-primary" style="padding:4px 10px" onclick='openEditSubject(<?= json_encode(['id'=>(int)$s['id'],'subject_code'=>$s['subject_code'],'subject_name'=>$s['subject_name'],'description'=>$s['description']??'','grade_level'=>$s['grade_level'],'grade_level_end'=>$s['grade_level_end']??'','strand_id'=>$s['strand_id']??''],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil" style="font-size:12px"></i></button>
                        <button class="btn btn-sm btn-outline-danger" style="padding:4px 10px" onclick="openDeleteModal('subject',<?= $s['id'] ?>, '<?= addslashes($s['subject_name']) ?>')"><i class="bi bi-trash" style="font-size:12px"></i></button>
                    </div></td>
                </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
            </div>
        </div>

        <!-- SECTIONS -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-window-stack me-2"></i>Sections</span><div class="d-flex align-items-center gap-2">                <span class="badge bg-secondary-soft" id="sectionsHeaderBadge"><?= count($allSections) ?></span><button class="btn-add-action btn-add-section" id="btnAddSectionInline" style="padding:6px 14px;font-size:11px"><i class="bi bi-plus-lg"></i> Add</button></div></div>
            <div class="card-body">
                <?php if(empty($allSections)): ?><div class="empty-state"><div class="empty-icon bg-info-soft"><i class="bi bi-window-stack"></i></div><h6>No Sections Yet</h6><p>Click "Add Section" to create your first section.</p></div>
                <?php else: ?><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em">Section Name</th>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em">Grade</th>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em">Created</th>
                    <th style="width:100px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em">Actions</th>
                </tr></thead><tbody><?php foreach($allSections as $sec): ?><tr class="section-row" data-grade="<?= (int)$sec['grade_level'] ?>">
                    <td class="fw-600"><?= sanitize($sec['section_name']) ?></td>
                    <td><span class="badge bg-secondary-soft text-primary"><?= sanitize(sectionGradeLabel($sec)) ?></span></td>
                    <td style="font-size:12px"><?= formatDate($sec['created_at'],'M j, Y') ?></td>
                    <td><div class="d-flex gap-1">
                        <button class="btn btn-sm btn-outline-primary" style="padding:4px 10px" onclick='openEditSection(<?= json_encode(['id'=>(int)$sec['id'],'section_name'=>$sec['section_name'],'grade_level'=>$sec['grade_level'],'strand_id'=>$sec['strand_id']??''],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil" style="font-size:12px"></i></button>
                        <button class="btn btn-sm btn-outline-danger" style="padding:4px 10px" onclick="openDeleteModal('section',<?= $sec['id'] ?>, '<?= addslashes($sec['section_name']) ?>')"><i class="bi bi-trash" style="font-size:12px"></i></button>
                    </div></td>
                </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
            </div>
        </div>

        <!-- TRACKS -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-diagram-3 me-2"></i>SHS Tracks</span><button class="btn-add-action btn-add-track-sm" id="btnAddTrack" style="padding:6px 14px;font-size:11px"><i class="bi bi-plus-lg"></i> Add Track</button></div>
            <div class="card-body">
                <?php if(empty($strands)): ?><div class="empty-state"><div class="empty-icon bg-success-soft"><i class="bi bi-diagram-3"></i></div><h6>No Tracks Added Yet</h6><p>Click "Add Track" to create SHS tracks.</p></div>
                <?php else: ?><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em">Code</th>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em">Name</th>
                    <th style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em">Description</th>
                    <th style="width:100px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em">Actions</th>
                </tr></thead><tbody><?php foreach($strands as $st): ?><tr class="track-row">
                    <td><code style="font-size:12px"><?= sanitize($st['strand_code']) ?></code></td>
                    <td class="fw-600"><?= sanitize($st['strand_name']) ?></td>
                    <td style="font-size:12px"><?= sanitize($st['description']??'-') ?></td>
                    <td><div class="d-flex gap-1">
                        <button class="btn btn-sm btn-outline-primary" style="padding:4px 10px" onclick='openEditTrack(<?= json_encode(['id'=>(int)$st['id'],'strand_code'=>$st['strand_code'],'strand_name'=>$st['strand_name'],'description'=>$st['description']??''],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil" style="font-size:12px"></i></button>
                        <button class="btn btn-sm btn-outline-danger" style="padding:4px 10px" onclick="openDeleteModal('track',<?= $st['id'] ?>, '<?= addslashes($st['strand_name']) ?>')"><i class="bi bi-trash" style="font-size:12px"></i></button>
                    </div></td>
                </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ADD SUBJECT -->
<div class="event-modal-overlay" id="addSubjectOverlay"><div class="event-modal"><div class="event-modal-header"><div class="event-modal-title"><i class="bi bi-book-half"></i><span>Add New Subject</span></div><button class="event-modal-close" onclick="closeModal('addSubjectOverlay')"><i class="bi bi-x-lg"></i></button></div>
<form id="addSubjectForm" autocomplete="off"><input type="hidden" name="ajax_action" value="add_subject"><div class="event-modal-body">
    <div class="evt-field"><label>Subject Code <span class="required">*</span></label><input type="text" name="subject_code" placeholder="e.g. MATH101" required maxlength="50"></div>
    <div class="evt-field"><label>Subject Name <span class="required">*</span></label><input type="text" name="subject_name" placeholder="e.g. Mathematics" required maxlength="150"></div>
    <div class="evt-separator"></div><div class="evt-section-label"><i class="bi bi-mortarboard"></i> Grade Level Range</div>
     <div class="evt-row"><div class="evt-field evt-flex-1"><label>From <span class="required">*</span></label><select name="grade_from" id="add-from" required><?= buildGradeOptions($strands,true,'From Grade',true) ?></select></div>
     <div class="evt-field evt-flex-1"><label>To <span class="required">*</span></label><select name="grade_to" id="add-to" required><?= buildGradeOptions($strands,true,'To Grade',true) ?></select></div></div>
    <div class="evt-separator"></div>
    <div class="evt-field"><label>Description</label><textarea name="description" rows="2" placeholder="Brief description..." maxlength="500"></textarea></div>
</div><div class="event-modal-footer"><button type="button" class="evt-btn evt-btn-cancel" onclick="closeModal('addSubjectOverlay')">Cancel</button><button type="submit" class="evt-btn evt-btn-save" id="addSubjectSave"><i class="bi bi-check-lg me-1"></i> Add Subject</button></div></form></div></div>

<!-- EDIT SUBJECT -->
<div class="event-modal-overlay" id="editSubjectOverlay"><div class="event-modal"><div class="event-modal-header"><div class="event-modal-title"><i class="bi bi-pencil-square"></i><span>Edit Subject</span></div><button class="event-modal-close" onclick="closeModal('editSubjectOverlay')"><i class="bi bi-x-lg"></i></button></div>
<form id="editSubjectForm" autocomplete="off"><input type="hidden" name="ajax_action" value="update_subject"><input type="hidden" name="id" id="edit-id"><div class="event-modal-body">
    <div class="evt-field"><label>Subject Code <span class="required">*</span></label><input type="text" name="subject_code" id="edit-code" required maxlength="50"></div>
    <div class="evt-field"><label>Subject Name <span class="required">*</span></label><input type="text" name="subject_name" id="edit-name" required maxlength="150"></div>
    <div class="evt-separator"></div><div class="evt-section-label"><i class="bi bi-mortarboard"></i> Grade Level Range</div>
    <div class="evt-row"><div class="evt-field evt-flex-1"><label>From <span class="required">*</span></label><select name="grade_from" id="edit-from" required><?= buildGradeOptions($strands,false) ?></select></div>
    <div class="evt-field evt-flex-1"><label>To <span class="required">*</span></label><select name="grade_to" id="edit-to" required><?= buildGradeOptions($strands,false) ?></select></div></div>
    <div class="evt-separator"></div>
    <div class="evt-field"><label>Description</label><textarea name="description" id="edit-desc" rows="2" maxlength="500"></textarea></div>
</div><div class="event-modal-footer"><button type="button" class="evt-btn evt-btn-cancel" onclick="closeModal('editSubjectOverlay')">Cancel</button><button type="submit" class="evt-btn evt-btn-save" id="editSubjectSave"><i class="bi bi-check-lg me-1"></i> Save Changes</button></div></form></div></div>

<!-- ADD SECTION -->
<div class="event-modal-overlay" id="addSectionOverlay"><div class="event-modal"><div class="event-modal-header"><div class="event-modal-title"><i class="bi bi-window-stack"></i><span>Add New Section</span></div><button class="event-modal-close" onclick="closeModal('addSectionOverlay')"><i class="bi bi-x-lg"></i></button></div>
<form id="addSectionForm" autocomplete="off"><input type="hidden" name="ajax_action" value="add_section"><div class="event-modal-body">
    <div class="evt-field"><label>Section Name <span class="required">*</span></label><input type="text" name="section_name" id="add-sec-name" placeholder="e.g. A - St. Paul" required maxlength="100"><div class="form-hint">Format: Section Letter/Number - Adviser/Saint Name</div></div>
     <div class="evt-field"><label>Grade Level <span class="required">*</span></label><select name="grade_level" id="add-sec-grade" required><?= buildGradeOptions($strands,true,'Select Grade Level',true) ?></select></div>
</div><div class="event-modal-footer"><button type="button" class="evt-btn evt-btn-cancel" onclick="closeModal('addSectionOverlay')">Cancel</button><button type="submit" class="evt-btn evt-btn-save" id="addSectionSave"><i class="bi bi-check-lg me-1"></i> Add Section</button></div></form></div></div>

<!-- EDIT SECTION -->
<div class="event-modal-overlay" id="editSectionOverlay"><div class="event-modal"><div class="event-modal-header"><div class="event-modal-title"><i class="bi bi-pencil-square"></i><span>Edit Section</span></div><button class="event-modal-close" onclick="closeModal('editSectionOverlay')"><i class="bi bi-x-lg"></i></button></div>
<form id="editSectionForm" autocomplete="off"><input type="hidden" name="ajax_action" value="update_section"><input type="hidden" name="section_id" id="edit-sec-id"><div class="event-modal-body">
    <div class="evt-field"><label>Section Name <span class="required">*</span></label><input type="text" name="section_name" id="edit-sec-name" required maxlength="100"></div>
     <div class="evt-field"><label>Grade Level <span class="required">*</span></label><select name="grade_level" id="edit-sec-grade" required><?= buildGradeOptions($strands,false,'',true) ?></select></div>
</div><div class="event-modal-footer"><button type="button" class="evt-btn evt-btn-cancel" onclick="closeModal('editSectionOverlay')">Cancel</button><button type="submit" class="evt-btn evt-btn-save" id="editSectionSave"><i class="bi bi-check-lg me-1"></i> Save Changes</button></div></form></div></div>

<!-- ADD TRACK -->
<div class="event-modal-overlay" id="addTrackOverlay"><div class="event-modal"><div class="event-modal-header"><div class="event-modal-title"><i class="bi bi-diagram-3"></i><span>Add New Track</span></div><button class="event-modal-close" onclick="closeModal('addTrackOverlay')"><i class="bi bi-x-lg"></i></button></div>
<form id="addTrackForm" autocomplete="off"><input type="hidden" name="ajax_action" value="add_strand"><div class="event-modal-body">
    <div class="evt-field"><label>Track Code <span class="required">*</span></label><input type="text" name="strand_code" placeholder="e.g. STEM" required maxlength="20"></div>
    <div class="evt-field"><label>Track Name <span class="required">*</span></label><input type="text" name="strand_name" placeholder="e.g. Science, Technology, Engineering & Math" required maxlength="150"></div>
</div><div class="event-modal-footer"><button type="button" class="evt-btn evt-btn-cancel" onclick="closeModal('addTrackOverlay')">Cancel</button><button type="submit" class="evt-btn evt-btn-save" id="addTrackSave"><i class="bi bi-check-lg me-1"></i> Add Track</button></div></form></div></div>

<!-- EDIT TRACK -->
<div class="event-modal-overlay" id="editTrackOverlay"><div class="event-modal"><div class="event-modal-header"><div class="event-modal-title"><i class="bi bi-pencil-square"></i><span>Edit Track</span></div><button class="event-modal-close" onclick="closeModal('editTrackOverlay')"><i class="bi bi-x-lg"></i></button></div>
<form id="editTrackForm" autocomplete="off"><input type="hidden" name="ajax_action" value="update_strand"><input type="hidden" name="strand_id" id="edit-track-id"><div class="event-modal-body">
    <div class="evt-field"><label>Track Code <span class="required">*</span></label><input type="text" name="strand_code" id="edit-track-code" required maxlength="20"></div>
    <div class="evt-field"><label>Track Name <span class="required">*</span></label><input type="text" name="strand_name" id="edit-track-name" required maxlength="150"></div>
    <div class="evt-field"><label>Description</label><textarea name="description" id="edit-track-desc" rows="2" maxlength="500"></textarea></div>
</div><div class="event-modal-footer"><button type="button" class="evt-btn evt-btn-cancel" onclick="closeModal('editTrackOverlay')">Cancel</button><button type="submit" class="evt-btn evt-btn-save" id="editTrackSave"><i class="bi bi-check-lg me-1"></i> Save Changes</button></div></form></div></div>

<!-- DELETE SUBJECT MODAL -->
<div class="event-modal-overlay" id="deleteSubjectOverlay">
    <div class="event-modal delete-modal">
        <div class="event-modal-header" style="padding-bottom:0"><div></div><button class="event-modal-close" id="deleteSubjectClose"><i class="bi bi-x-lg"></i></button></div>
        <div class="event-modal-body" style="padding-top:4px">
            <div class="delete-modal-icon" style="background:var(--sm-danger-light);color:var(--sm-danger)"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div class="delete-modal-text"><h6>Delete Subject?</h6><p>This will permanently remove <strong id="deleteSubjectNameDisplay"></strong>. This action cannot be undone.</p></div>
        </div>
        <div class="event-modal-footer" style="justify-content:center">
            <button type="button" class="evt-btn evt-btn-cancel" id="deleteSubjectCancel">Cancel</button>
            <button type="button" class="evt-btn evt-btn-danger" id="confirmDeleteSubjectBtn"><i class="bi bi-trash3 me-1"></i> Delete</button>
        </div>
    </div>
</div>

<!-- DELETE SECTION MODAL -->
<div class="event-modal-overlay" id="deleteSectionOverlay">
    <div class="event-modal delete-modal">
        <div class="event-modal-header" style="padding-bottom:0"><div></div><button class="event-modal-close" id="deleteSectionClose"><i class="bi bi-x-lg"></i></button></div>
        <div class="event-modal-body" style="padding-top:4px">
            <div class="delete-modal-icon" style="background:var(--sm-danger-light);color:var(--sm-danger)"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div class="delete-modal-text"><h6>Delete Section?</h6><p>This will permanently remove <strong id="deleteSectionNameDisplay"></strong>. This action cannot be undone.</p></div>
        </div>
        <div class="event-modal-footer" style="justify-content:center">
            <button type="button" class="evt-btn evt-btn-cancel" id="deleteSectionCancel">Cancel</button>
            <button type="button" class="evt-btn evt-btn-danger" id="confirmDeleteSectionBtn"><i class="bi bi-trash3 me-1"></i> Delete</button>
        </div>
    </div>
</div>

<!-- DELETE TRACK MODAL -->
<div class="event-modal-overlay" id="deleteTrackOverlay">
    <div class="event-modal delete-modal">
        <div class="event-modal-header" style="padding-bottom:0"><div></div><button class="event-modal-close" id="deleteTrackClose"><i class="bi bi-x-lg"></i></button></div>
        <div class="event-modal-body" style="padding-top:4px">
            <div class="delete-modal-icon" style="background:var(--sm-danger-light);color:var(--sm-danger)"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div class="delete-modal-text"><h6>Delete Track?</h6><p>This will permanently remove <strong id="deleteTrackNameDisplay"></strong>. This action cannot be undone.</p></div>
        </div>
        <div class="event-modal-footer" style="justify-content:center">
            <button type="button" class="evt-btn evt-btn-cancel" id="deleteTrackCancel">Cancel</button>
            <button type="button" class="evt-btn evt-btn-danger" id="confirmDeleteTrackBtn"><i class="bi bi-trash3 me-1"></i> Delete</button>
        </div>
    </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>
(function(){
'use strict';

var cur=null,
    AJAX=window.location.pathname,
    currentDelete = { type: null, id: null, name: null };

function openModal(id){
    var el=document.getElementById(id);
    if(!el)return;
    el.classList.add('show');
    document.body.style.overflow='hidden';
    cur=id;
    var f=el.querySelector('input[type="text"],textarea,select');
    if(f)setTimeout(function(){f.focus();},200);
}

function closeModalFn(id){
    var el=document.getElementById(id);
    if(!el)return;
    el.classList.remove('show');
    document.body.style.overflow='';
    cur=null;
}

window.closeModal=closeModalFn;

document.querySelectorAll('.event-modal-overlay').forEach(function(o){
    o.addEventListener('click',function(e){
        if(e.target===o)closeModalFn(o.id);
    });
});

document.addEventListener('keydown',function(e){
    if(e.key==='Escape'&&cur)closeModalFn(cur);
});

// GRADE VALUE PARSING
function parseGradeVal(v){
    if(!v)return{grade:0,trackId:null};
    if(v.indexOf(':')!==-1){
        var p=v.split(':'),
            sid=parseInt(p[1]);
        return{grade:parseInt(p[0]),trackId:sid>0?sid:null};
    }
    return{grade:parseInt(v),trackId:null};
}

function gradeValToStr(g,sid){
    if(!sid||sid===''||sid==='0'||sid===0){
        return g>=11?g+':0':String(g);
    }
    return g>=11?g+':'+sid:String(g);
}

function updateToOptions(fId,tId){
    var fp=parseGradeVal(document.getElementById(fId).value),
        toSel=document.getElementById(tId);
    Array.from(toSel.options).forEach(function(o){
        if(!o.value)return;
        if(!fp.grade){o.disabled=false;return;}
        var tp=parseGradeVal(o.value);
        if(fp.grade<=10&&tp.grade<=10){
            o.disabled=tp.grade<fp.grade;
        } else if(fp.grade>=11&&tp.grade>=11){
            o.disabled=(fp.trackId!==tp.trackId)||(tp.grade<fp.grade);
        } else {
            o.disabled=true;
        }
    });
    if(toSel.value){
        var c=toSel.options[toSel.selectedIndex];
        if(c&&c.disabled)toSel.value=document.getElementById(fId).value;
    }
}

document.getElementById('add-from').addEventListener('change',function(){
    updateToOptions('add-from','add-to');
});
document.getElementById('edit-from').addEventListener('change',function(){
    updateToOptions('edit-from','edit-to');
});

// BUTTONS
document.getElementById('btnAddSubject').addEventListener('click',function(){
    document.getElementById('addSubjectForm').reset();
    updateToOptions('add-from','add-to');
    openModal('addSubjectOverlay');
});

document.querySelectorAll('#btnAddSection,#btnAddSectionInline').forEach(function(b){
    b.addEventListener('click',function(){
        document.getElementById('addSectionForm').reset();
        openModal('addSectionOverlay');
    });
});

document.getElementById('btnAddTrack').addEventListener('click',function(){
    document.getElementById('addTrackForm').reset();
    openModal('addTrackOverlay');
});

// EDIT SUBJECT
window.openEditSubject=function(s){
    document.getElementById('edit-id').value=s.id;
    document.getElementById('edit-code').value=s.subject_code||'';
    document.getElementById('edit-name').value=s.subject_name||'';
    document.getElementById('edit-desc').value=s.description||'';
    document.getElementById('edit-from').value=gradeValToStr(parseInt(s.grade_level),s.strand_id);
    document.getElementById('edit-to').value=gradeValToStr(parseInt(s.grade_level_end||s.grade_level),s.strand_id);
    updateToOptions('edit-from','edit-to');
    openModal('editSubjectOverlay');
};

// EDIT SECTION
window.openEditSection=function(sec){
    document.getElementById('edit-sec-id').value=sec.id;
    document.getElementById('edit-sec-name').value=sec.section_name||'';
    var g=parseInt(sec.grade_level),
        sid=sec.strand_id?parseInt(sec.strand_id):0;
    document.getElementById('edit-sec-grade').value=gradeValToStr(g,sid);
    openModal('editSectionOverlay');
};

// EDIT TRACK
window.openEditTrack=function(st){
    document.getElementById('edit-track-id').value=st.id;
    document.getElementById('edit-track-code').value=st.strand_code||'';
    document.getElementById('edit-track-name').value=st.strand_name||'';
    document.getElementById('edit-track-desc').value=st.description||'';
    openModal('editTrackOverlay');
};

// FORM HANDLER
function handleForm(fId,sId,oId,before){
    var form=document.getElementById(fId),
        btn=document.getElementById(sId);
    form.addEventListener('submit',function(e){
        e.preventDefault();
        btn.classList.add('loading');
        var orig=btn.innerHTML;
        btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
        var fd=new FormData(form);
        if(before)before(fd);
        fetch(AJAX,{method:'POST',body:fd})
            .then(function(r){
                if(!r.ok)throw new Error('Network response was not ok');
                return r.json();
            })
            .then(function(d){
                if(d.success){
                    showToast(d.message,'success');
                    form.reset();
                    closeModalFn(oId);
                    setTimeout(function(){location.reload();},600);
                } else {
                    showToast(d.message||'Failed.','error');
                }
            })
            .catch(function(){
                showToast('Something went wrong.','error');
            })
            .finally(function(){
                btn.classList.remove('loading');
                btn.innerHTML=orig;
            });
    });
}

function parseCompoundGrade(fd){
    var fp=parseGradeVal(fd.get('grade_from')),
        tp=parseGradeVal(fd.get('grade_to'));
    fd.set('grade_from',String(fp.grade));
    fd.set('grade_to',String(tp.grade));
    fd.set('strand_id',fp.trackId?String(fp.trackId):'');
}

function parseSectionGrade(fd){
    var p=parseGradeVal(fd.get('grade_level'));
    fd.set('grade_level',String(p.grade));
    fd.set('strand_id',p.trackId?String(p.trackId):'');
}

handleForm('addSubjectForm','addSubjectSave','addSubjectOverlay',parseCompoundGrade);
handleForm('editSubjectForm','editSubjectSave','editSubjectOverlay',parseCompoundGrade);
handleForm('addSectionForm','addSectionSave','addSectionOverlay',parseSectionGrade);
handleForm('editSectionForm','editSectionSave','editSectionOverlay',parseSectionGrade);
handleForm('addTrackForm','addTrackSave','addTrackOverlay');
handleForm('editTrackForm','editTrackSave','editTrackOverlay');

// FILTER FUNCTIONALITY
function applyGradeFilter(grade) {
    var subjItems = document.querySelectorAll('.subject-card-item');
    var subjCount = 0;
    subjItems.forEach(function(item) {
        var start = parseInt(item.getAttribute('data-grade-start') || '0', 10);
        var end = parseInt(item.getAttribute('data-grade-end') || '0', 10);
        var show = !grade || (start <= grade && end >= grade);
        item.style.display = show ? '' : 'none';
        if (show) subjCount++;
    });
    var subjBadge = document.querySelector('.subjects-header-badge');
    if (subjBadge) subjBadge.textContent = subjCount;

    var secRows = document.querySelectorAll('.section-row');
    var secCount = 0;
    secRows.forEach(function(row) {
        var rowGrade = parseInt(row.getAttribute('data-grade') || '0', 10);
        var show = !grade || rowGrade === grade;
        row.style.display = show ? '' : 'none';
        if (show) secCount++;
    });
    var secBadge = document.getElementById('sectionsHeaderBadge');
    if (secBadge) secBadge.textContent = secCount;
}

document.querySelectorAll('.filter-chip').forEach(function(chip) {
    chip.addEventListener('click', function(e) {
        e.preventDefault();
        var gradeAttr = this.getAttribute('data-grade');
        var grade = gradeAttr === null || gradeAttr === '' ? '' : parseInt(gradeAttr, 10);
        document.querySelectorAll('.filter-chip').forEach(function(c) { c.classList.remove('active'); });
        this.classList.add('active');
        applyGradeFilter(grade);
    });
});

var iniGrade = '';
if (window.location.search) {
    var m = window.location.search.match(/grade_level=(\d+)/);
    if (m) iniGrade = parseInt(m[1], 10);
}
if (iniGrade) {
    var activeChip = document.querySelector('.filter-chip[data-grade="' + iniGrade + '"]');
    if (activeChip) {
        document.querySelectorAll('.filter-chip').forEach(function(c) { c.classList.remove('active'); });
        activeChip.classList.add('active');
        applyGradeFilter(iniGrade);
    }
}

// ============================================================
// FIXED DELETE FUNCTIONALITY
// ============================================================

// Universal delete modal opener
window.openDeleteModal = function(type, id, name) {
    // Validate inputs
    if (!type || !id) {
        showToast('Invalid delete request.', 'error');
        return;
    }
    
    currentDelete = { type: type, id: id, name: name || 'this item' };
    
    var overlayId = 'delete' + type.charAt(0).toUpperCase() + type.slice(1) + 'Overlay';
    var nameDisplayId = 'delete' + type.charAt(0).toUpperCase() + type.slice(1) + 'NameDisplay';
    
    var overlay = document.getElementById(overlayId);
    var nameDisplay = document.getElementById(nameDisplayId);
    
    if (!overlay) {
        showToast('Error: Delete modal not found.', 'error');
        return;
    }
    
    if (nameDisplay) {
        nameDisplay.textContent = name || 'this item';
    }
    
    openModal(overlayId);
};

// Helper function for delete confirmation
function setupDeleteHandler(overlayId, actionName, idFieldName, successMessage) {
    var overlay = document.getElementById(overlayId);
    if (!overlay) return;
    
    var closeBtn = overlay.querySelector('.event-modal-close');
    var cancelBtn = overlay.querySelector('.evt-btn-cancel');
    var confirmBtn = overlay.querySelector('.evt-btn-danger');
    
    if (closeBtn) {
        closeBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            closeModalFn(overlayId);
        });
    }
    
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            closeModalFn(overlayId);
        });
    }
    
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            // Prevent double submission
            if (confirmBtn.disabled) return;
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Deleting...';
            
            // Validate we have something to delete
            if (!currentDelete.id || currentDelete.type !== actionName.replace('delete_', '')) {
                showToast('Error: Invalid delete request.', 'error');
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="bi bi-trash3 me-1"></i> Delete';
                return;
            }
            
            var fd = new FormData();
            fd.append('ajax_action', actionName);
            fd.append(idFieldName, currentDelete.id);
            
            fetch(AJAX, {
                method: 'POST',
                body: fd
            })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.json();
            })
            .then(function(data) {
                if (data.success) {
                    showToast(successMessage || 'Item deleted successfully.', 'success');
                    closeModalFn(overlayId);
                    // Reload after a short delay to show the toast
                    setTimeout(function() {
                        location.reload();
                    }, 500);
                } else {
                    showToast(data.message || 'Failed to delete item.', 'error');
                }
            })
            .catch(function(error) {
                console.error('Delete error:', error);
                showToast('Error: Could not delete item. Please try again.', 'error');
            })
            .finally(function() {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="bi bi-trash3 me-1"></i> Delete';
            });
        });
    }
}

// Setup all delete handlers
setupDeleteHandler('deleteSubjectOverlay', 'delete_subject', 'subject_id', 'Subject deleted successfully.');
setupDeleteHandler('deleteSectionOverlay', 'delete_section', 'section_id', 'Section deleted successfully.');
setupDeleteHandler('deleteTrackOverlay', 'delete_strand', 'id', 'Track deleted successfully.');

// TOAST NOTIFICATION SYSTEM
window.showToast = function(msg, type) {
    type = type || 'success';
    var container = document.getElementById('toastContainer');
    if (!container) return;
    
    var toast = document.createElement('div');
    toast.className = 'toast-notification toast-' + type;
    
    var icons = {
        success: 'check-circle-fill',
        info: 'info-circle-fill',
        warning: 'exclamation-triangle-fill',
        error: 'exclamation-circle-fill'
    };
    
    var iconName = icons[type] || 'info-circle-fill';
    toast.innerHTML = '<i class="bi bi-' + iconName + '"></i><span>' + msg + '</span>';
    container.appendChild(toast);
    
    // Auto remove after animation
    setTimeout(function() {
        if (toast.parentNode) {
            toast.remove();
        }
    }, 4200);
};

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>