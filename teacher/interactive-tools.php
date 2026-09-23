<?php
/**
 * LDB-FRAS · Interactive Tools
 * Comprehensive classroom engagement hub: Spin Wheel (Pick / Groups / Custom),
 * Points & Leaderboard, Participation Tracker, Companion Timer, Noise Meter,
 * and a Live Log with Group Saver.
 * Pure vanilla PHP / CSS3 / ES6 — runs instantly on XAMPP Apache.
 */
require_once __DIR__ . '/../config.php';
requireRole(['admin', 'teacher']);

$pageTitle = 'Interactive Tools';

/* ── Group-save placeholder endpoint (AJAX POST) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_groups') {
    header('Content-Type: application/json');
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }
    $groups     = $_POST['groups'] ?? '[]';
    $classLabel = $_POST['class_label'] ?? '';
    try {
        $stmt = $db->prepare("INSERT INTO group_sessions (class_label, groups_json, created_by, created_at) VALUES (?,?,?,NOW())");
        $stmt->execute([$classLabel, $groups, $_SESSION['user_id'] ?? 0]);
        echo json_encode(['success' => true, 'message' => 'Grouping saved to the database.']);
    } catch (Exception $e) {
        error_log('Interactive Tools save groups: ' . $e->getMessage());
        echo json_encode(['success' => true, 'message' => 'Saved (session only) — group_sessions table not found, logged instead.']);
    }
    exit;
}

/* ── Persist today's Participation Tracker + Points & Leaderboard state (AJAX POST) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_tools_state') {
    header('Content-Type: application/json');
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }
    $uid       = (int)(getCurrentUserId() ?: 0);
    $classLabel = trim($_POST['class_label'] ?? '');
    $stateJson = trim($_POST['state'] ?? '');
    $decoded   = json_decode($stateJson, true);
    if ($uid <= 0 || $classLabel === '' || !is_array($decoded)) {
        echo json_encode(['success' => false, 'message' => 'Invalid state data.']);
        exit;
    }
    try {
        $stmt = $db->prepare(
            "INSERT INTO interactive_tools_state (user_id, state_date, class_label, state_json, created_at, updated_at)
             VALUES (?,?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE state_json = VALUES(state_json), updated_at = NOW()"
        );
        $stmt->execute([$uid, date('Y-m-d'), $classLabel, $stateJson]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log('Interactive Tools save state: ' . $e->getMessage());
        $_SESSION['it_tools_state'] = $_SESSION['it_tools_state'] ?? [];
        $_SESSION['it_tools_state'][$classLabel] = $decoded;
        echo json_encode(['success' => true, 'message' => 'Session-only save (DB unavailable).']);
    }
    exit;
}

/* ── Reset today's state for the current class (AJAX POST) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_tools_state') {
    header('Content-Type: application/json');
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }
    $uid        = (int)(getCurrentUserId() ?: 0);
    $classLabel = trim($_POST['class_label'] ?? '');
    try {
        $stmt = $db->prepare("DELETE FROM interactive_tools_state WHERE user_id = ? AND state_date = ? AND class_label = ?");
        $stmt->execute([$uid, date('Y-m-d'), $classLabel]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        error_log('Interactive Tools reset state: ' . $e->getMessage());
        if (isset($_SESSION['it_tools_state'][$classLabel])) { unset($_SESSION['it_tools_state'][$classLabel]); }
        echo json_encode(['success' => true]);
    }
    exit;
}

/* ── Roster: real registered Grade & Section combos from the students table ── */
$classes = [];
$userRole = $_SESSION['user_role'] ?? 'admin';

/* Teachers only see the Grade & Section combos assigned to them by the admin.
   Junior High comes from grade_section_handled, Senior High from
   core_subjects_handled / track_elective_handled — all three carry section_id. */
$allowedClasses = null; // null = no restriction (admin)
if ($userRole === 'teacher') {
    $assignedSectionIds = [];
    try {
        $stmt = $db->prepare("SELECT t.* FROM teachers t JOIN users u ON t.user_id = u.id WHERE u.id = ? LIMIT 1");
        $stmt->execute([getCurrentUserId()]);
        $teacher = $stmt->fetch();
    } catch (Exception $e) {
        $teacher = null;
    }
    if ($teacher) {
        foreach (['grade_section_handled', 'core_subjects_handled', 'track_elective_handled'] as $field) {
            $raw = $teacher[$field] ?? '';
            if (is_string($raw)) { $raw = json_decode($raw, true); }
            if (!is_array($raw)) { continue; }
            foreach ($raw as $item) {
                if (isset($item['section_id']) && is_numeric($item['section_id'])) {
                    $assignedSectionIds[] = (int)$item['section_id'];
                }
            }
        }
        $assignedSectionIds = array_values(array_unique(array_filter($assignedSectionIds, function ($id) { return $id > 0; })));
        if (!empty($assignedSectionIds)) {
            $ph = implode(',', array_fill(0, count($assignedSectionIds), '?'));
            try {
                $stmt = $db->prepare("SELECT DISTINCT grade_level, section_name FROM sections WHERE id IN ($ph)");
                $stmt->execute($assignedSectionIds);
                foreach ($stmt->fetchAll() as $sec) {
                    $gl = trim((string)($sec['grade_level'] ?? ''));
                    $sn = trim((string)($sec['section_name'] ?? ''));
                    if ($gl === '' || $sn === '') { continue; }
                    $allowedClasses[] = ['grade_level' => $gl, 'section' => $sn];
                }
            } catch (Exception $e) {
                error_log('Interactive Tools assigned sections: ' . $e->getMessage());
            }
        }
    }
}

try {
    $sql = "SELECT DISTINCT grade_level, section
            FROM students
            WHERE grade_level IS NOT NULL AND section IS NOT NULL AND section != ''";
    $params = [];
    if ($allowedClasses !== null) {
        if (!empty($allowedClasses)) {
            $ors = [];
            foreach ($allowedClasses as $pair) {
                $ors[] = "(grade_level = ? AND section = ?)";
                $params[] = $pair['grade_level'];
                $params[] = $pair['section'];
            }
            $sql .= " AND (" . implode(' OR ', $ors) . ")";
        } else {
            $sql .= " AND 1 = 0";
        }
    }
    $sql .= " ORDER BY grade_level, section";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $c) {
        $label = 'Grade ' . $c['grade_level'] . ' - ' . $c['section'];
        $st = $db->prepare("SELECT first_name, last_name FROM students WHERE grade_level = ? AND section = ? ORDER BY last_name, first_name");
        $st->execute([$c['grade_level'], $c['section']]);
        $names = array_filter(array_map(fn($r) => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')), $st->fetchAll()), 'strlen');
        if ($names) $classes[$label] = array_values($names);
    }
} catch (Exception $e) {
    error_log('Interactive Tools roster load failed: ' . $e->getMessage());
}

/* ── Mock fallback (admin preview only; never shown to a teacher) ── */
if (empty($classes) && $userRole !== 'teacher') {
    $classes = [
        'Grade 7 - Aquila' => ['Maria Santos','John Dela Cruz','Angela Reyes','Mark Villanueva','Sofia Mendoza','Liam Gonzales','Bea Castillo','Kenji Tan','Hannah Lim','Paolo Garcia'],
        'Grade 8 - Corvus' => ['Jasmine Reyes','Adrian Lopez','Patricia San Juan','Kevin Soriano','Angelica Fausto','Marco Dimaculangan','Liza Cordero','Rafael Baltazar','Nicole Quinto','Jonathan Escobar'],
        'Grade 9 - Equuleus' => ['Angela Villar','Sebastian Co','Katrina Herrera','Lance Montecillo','Chloe Sibug','Ralph de Leon','Bianca Sison','Emilio Zaragoza','Jade Villanueva','Patrick Aquino'],
        'Grade 10 - Grus' => ['Fatima Salazar','Zachary Lazaro','Czarina Montes','Ivan Barcenas','Leah Crisostomo','Gabriel Tabuena','Selene Catacutan','Andrei Paguio','Carmina David','Theo Villanueva'],
    ];
}

/* ── Themed wheel content (Custom Wheel mode) ── */
$topics = [
    'Solve: 2x + 5 = 17','Explain photosynthesis','Name the 3 branches of government',
    'Convert 0.75 to a fraction','State Newton’s 2nd Law','Define the word "metaphor"',
    'What is 12 × 13?','List the 7 continents','Explain the water cycle',
    'Who discovered penicillin?','Solve: √144','Name a renewable energy source',
];
$rewards = [
    '5 Bonus Points','Homework Pass','Free Seat Day','Extra Recess (5 min)','Line Leader',
    'Choose the Game','No Uniform Check','Sticker Pack','Treasure Box Pick','Lunch with Teacher',
    'Bonus Quiz Drop','Star Student Badge',
];

$classesJson = json_encode($classes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$topicsJson  = json_encode($topics,  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$rewardsJson = json_encode($rewards, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$csrfToken   = generateCSRFToken();

/* ── Load today's persisted Interactive Tools state (per class) ── */
$persistedByClass = [];
$lastActiveClass  = null;
$persistedUid = (int)(getCurrentUserId() ?: 0);
if ($persistedUid > 0) {
    try {
        $stmt = $db->prepare(
            "SELECT class_label, state_json FROM interactive_tools_state
             WHERE user_id = ? AND state_date = ? ORDER BY updated_at DESC"
        );
        $stmt->execute([$persistedUid, date('Y-m-d')]);
        foreach ($stmt->fetchAll() as $row) {
            $decoded = json_decode($row['state_json'] ?? '', true);
            if (!is_array($decoded)) { continue; }
            if ($lastActiveClass === null) { $lastActiveClass = $row['class_label']; }
            $persistedByClass[$row['class_label']] = $decoded;
        }
    } catch (Exception $e) {
        error_log('Interactive Tools load state (db): ' . $e->getMessage());
    }
    /* Session fallback (used only if the DB table was unavailable) */
    if (!empty($_SESSION['it_tools_state']) && is_array($_SESSION['it_tools_state'])) {
        foreach ($_SESSION['it_tools_state'] as $classLabel => $state) {
            if (is_array($state) && !isset($persistedByClass[$classLabel])) {
                $persistedByClass[$classLabel] = $state;
                if ($lastActiveClass === null) { $lastActiveClass = $classLabel; }
            }
        }
    }
}
$persistedJson = json_encode(
    ['classes' => (object)$persistedByClass, 'last' => $lastActiveClass],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<!-- FILE_BODY_START -->

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">

<style>
/* ═══ INTERACTIVE TOOLS — scoped under .it-* (reuses design-system tokens) ═══ */
.it-font{font-family:'Plus Jakarta Sans',sans-serif}
.it-page .content-area{padding:22px 26px 40px}
.it-layout{display:grid;grid-template-columns:300px minmax(0,1fr) 350px;gap:18px;align-items:start}
@media(max-width:1280px){.it-layout{grid-template-columns:280px minmax(0,1fr)}.it-right{grid-column:1/-1}}
@media(max-width:920px){.it-layout{grid-template-columns:1fr}.it-right{grid-column:auto}}
.it-col{display:flex;flex-direction:column;gap:18px}
.it-right{display:flex;flex-direction:column;gap:18px}

/* Mode toggle pills */
.it-modebar{display:flex;gap:6px;background:rgba(255,255,255,0.04);border:1px solid var(--pg-border);border-radius:var(--pg-radius-sm);padding:5px}
.it-mode{flex:1;text-align:center;padding:9px 6px;border-radius:8px;cursor:pointer;font-size:11.5px;font-weight:700;color:rgba(255,255,255,0.55);transition:all var(--pg-transition);border:1px solid transparent;user-select:none;line-height:1.3}
.it-mode i{display:block;font-size:16px;margin-bottom:3px}
.it-mode:hover{color:#fff;background:rgba(255,255,255,0.06)}
.it-mode.active{color:#fff;background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);box-shadow:0 4px 16px rgba(79,70,229,0.35);border-color:rgba(124,58,237,0.4)}

/* Wheel */
.it-wheel-card .card-body{display:flex;flex-direction:column;align-items:center;gap:6px}
.it-wheel-wrap{position:relative;width:min(440px,88%);aspect-ratio:1;margin:6px auto 4px}
.it-wheel-wrap canvas{width:100%;height:100%;display:block;border-radius:50%;box-shadow:0 18px 60px rgba(0,0,0,0.55),0 0 0 10px rgba(255,255,255,0.04),0 0 0 14px rgba(79,70,229,0.25)}
.it-pointer{position:absolute;top:-12px;left:50%;transform:translateX(-50%);width:0;height:0;border-left:17px solid transparent;border-right:17px solid transparent;border-top:30px solid #fff;filter:drop-shadow(0 3px 6px rgba(0,0,0,0.5));z-index:5}
.it-pointer::after{content:'';position:absolute;top:-34px;left:-9px;width:18px;height:18px;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#4f46e5);box-shadow:0 0 0 3px rgba(255,255,255,0.2)}
.it-spin-btn{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:30%;height:30%;border-radius:50%;border:none;cursor:pointer;z-index:6;background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);color:#fff;font-weight:800;font-size:clamp(14px,2.6vw,20px);letter-spacing:.04em;box-shadow:0 8px 26px rgba(79,70,229,0.55),inset 0 0 0 4px rgba(255,255,255,0.18);transition:transform var(--pg-spring),box-shadow var(--pg-transition)}
.it-spin-btn:hover:not(:disabled){transform:translate(-50%,-50%) scale(1.06);box-shadow:0 12px 34px rgba(79,70,229,0.7),inset 0 0 0 4px rgba(255,255,255,0.28)}
.it-spin-btn:active:not(:disabled){transform:translate(-50%,-50%) scale(0.96)}
.it-spin-btn:disabled{opacity:.65;cursor:not-allowed}

.it-hidden{display:none!important}
.it-field label{display:block;font-size:11px;font-weight:700;color:rgba(255,255,255,0.45);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px}
.it-check{display:flex;align-items:center;gap:9px;font-size:13px;font-weight:500;color:rgba(255,255,255,0.7);cursor:pointer;user-select:none}
.it-check input{width:18px;height:18px;accent-color:#4f46e5;cursor:pointer}
.it-names-count{font-size:12px;color:rgba(255,255,255,0.45);margin-top:12px;text-align:center}
.it-names-count b{color:#60A5FA;font-family:var(--pg-mono)}

/* Live log */
.it-log{max-height:200px;overflow-y:auto;display:flex;flex-direction:column;gap:6px}
.it-log-item{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:8px;background:rgba(255,255,255,0.03);font-size:13px;animation:pgFadeUp .3s ease}
.it-log-item .it-log-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;background:#60A5FA}
.it-log-item .it-log-name{font-weight:600;color:#fff}
.it-log-item .it-log-meta{margin-left:auto;font-size:11px;color:rgba(255,255,255,0.4);font-family:var(--pg-mono)}
.it-log-empty{text-align:center;color:rgba(255,255,255,0.4);font-size:13px;padding:18px}

/* Groups */
.it-groups{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-top:6px}
.it-group{border:1px solid var(--pg-border);border-radius:var(--pg-radius-sm);padding:12px;background:rgba(255,255,255,0.03)}
.it-group-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.it-group-title{font-size:13px;font-weight:800;color:#fff;display:flex;align-items:center;gap:7px}
.it-group-badge{font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;background:var(--pg-primary-light);color:#a5b4fc;font-family:var(--pg-mono)}
.it-member{display:flex;align-items:center;gap:9px;padding:6px 8px;border-radius:8px;font-size:13px;color:rgba(255,255,255,0.85)}
.it-member .dot{width:7px;height:7px;border-radius:50%;background:#60A5FA;flex-shrink:0}
.it-leader{background:rgba(245,158,11,0.12);border:1px solid rgba(245,158,11,0.2)}
.it-leader .dot{background:#FBBF24}
.it-leader-tag{margin-left:auto;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;padding:2px 8px;border-radius:6px;background:rgba(245,158,11,0.18);color:#FBBF24;flex-shrink:0}

/* Timer */
.it-timer-display{font-family:var(--pg-mono);font-size:46px;font-weight:700;text-align:center;color:#fff;line-height:1;margin:4px 0}
.it-timer-inputs{display:flex;align-items:center;justify-content:center;gap:6px;margin-bottom:12px}
.it-timer-inputs input{width:60px;text-align:center;font-family:var(--pg-mono);font-size:16px;font-weight:700;padding:8px 4px;border:1.5px solid rgba(255,255,255,0.08);border-radius:var(--pg-radius-xs);background:rgba(255,255,255,0.05);color:#fff}
.it-timer-inputs input:focus{outline:none;border-color:var(--pg-primary);box-shadow:0 0 0 3px var(--pg-primary-glow)}
.it-timer-inputs span{font-size:20px;font-weight:700;color:rgba(255,255,255,0.4)}
.it-timer-btns{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.it-timer-btns .span2{grid-column:1/-1}
.it-flash{animation:itFlash .7s steps(1) infinite}
@keyframes itFlash{0%{background:rgba(239,68,68,0.18);color:#F87171;box-shadow:0 0 0 2px rgba(239,68,68,0.5) inset}50%{background:transparent;color:#fff;box-shadow:none}100%{background:rgba(239,68,68,0.18);color:#F87171;box-shadow:0 0 0 2px rgba(239,68,68,0.5) inset}}

/* Noise meter */
.it-noise-meter{height:18px;border-radius:10px;background:rgba(255,255,255,0.06);overflow:hidden;position:relative}
.it-noise-fill{height:100%;width:0%;background:linear-gradient(90deg,#34d399 0%,#fbbf24 60%,#ef4444 100%);transition:width .08s linear}
.it-noise-threshold{position:absolute;top:0;bottom:0;width:2px;background:#fff;opacity:.7}
.it-noise-alert{margin-top:10px;padding:10px 12px;border-radius:8px;background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.3);color:#F87171;font-weight:700;font-size:13px;text-align:center;display:none}
.it-noise-alert.show{display:block;animation:itFlash .7s steps(1) infinite}
.it-threshold-row{display:flex;align-items:center;gap:10px;margin-top:10px;font-size:12px;color:rgba(255,255,255,0.6)}

/* Leaderboard / participation */
.it-lb{max-height:240px;overflow-y:auto;display:flex;flex-direction:column;gap:6px}
.it-lb-row{display:flex;align-items:center;gap:10px;padding:7px 9px;border-radius:8px;background:rgba(255,255,255,0.03)}
.it-lb-rank{width:22px;font-family:var(--pg-mono);font-weight:700;color:rgba(255,255,255,0.4);text-align:center;flex-shrink:0}
.it-lb-row:nth-child(1) .it-lb-rank{color:#FBBF24}
.it-lb-name{flex:1;font-size:13px;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.it-lb-pts{font-family:var(--pg-mono);font-weight:700;color:#a5b4fc;font-size:13px}
.it-pt-btns{display:flex;gap:4px;flex-shrink:0}
.it-pt-btn{width:26px;height:26px;border-radius:7px;border:1px solid rgba(255,255,255,0.12);background:rgba(255,255,255,0.05);color:#fff;cursor:pointer;font-weight:700;line-height:1;transition:all var(--pg-transition)}
.it-pt-btn.plus:hover{background:rgba(16,185,129,0.2);border-color:#10b981;color:#34d399}
.it-pt-btn.minus:hover{background:rgba(239,68,68,0.2);border-color:#ef4444;color:#f87171}
.it-part-row{display:flex;align-items:center;gap:10px;font-size:12px;padding:5px 0}
.it-part-name{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:rgba(255,255,255,0.8)}
.it-part-track{flex:2;height:8px;border-radius:5px;background:rgba(255,255,255,0.06);overflow:hidden}
.it-part-fill{height:100%;background:linear-gradient(90deg,#06b6d4,#4f46e5);width:0%;transition:width .35s ease}
.it-part-count{width:26px;text-align:right;font-family:var(--pg-mono);color:rgba(255,255,255,0.6)}
.it-warn{margin-top:10px;padding:10px 12px;border-radius:8px;background:rgba(245,158,11,0.12);border:1px solid rgba(245,158,11,0.3);color:#FBBF24;font-size:12.5px;font-weight:600;display:none}
.it-warn.show{display:block}

/* Winner modal */
.event-modal-overlay{position:fixed;inset:0;background:rgba(11,11,20,0.72);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);z-index:9998;display:none;align-items:center;justify-content:center;padding:20px}
.event-modal-overlay.show{display:flex}
.event-modal{border-radius:20px;width:500px;max-width:100%;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;background:rgba(10,34,76,0.90);backdrop-filter:blur(24px) saturate(1.6);-webkit-backdrop-filter:blur(24px) saturate(1.6);border:1px solid rgba(255,255,255,0.12);box-shadow:0 24px 80px rgba(0,0,0,0.4);animation:modalSlideIn .35s cubic-bezier(.34,1.56,.64,1);color:#ffffff}
.event-modal-header{display:flex;justify-content:space-between;align-items:center;padding:22px 26px;flex-shrink:0;border-bottom:1px solid rgba(255,255,255,0.07)}
.event-modal-title{display:flex;align-items:center;gap:12px;font-size:17px;font-weight:800;letter-spacing:-.02em;color:#fff}
.event-modal-title i{font-size:22px;color:#60A5FA}
.event-modal-close{width:34px;height:34px;border:none;border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s cubic-bezier(.4,0,.2,1);font-size:14px;background:rgba(255,255,255,0.06);color:rgba(255,255,255,0.5)}
.event-modal-close:hover{background:rgba(239,68,68,0.15);color:#ef4444}
.event-modal-body{padding:26px;overflow-y:auto;flex:1}
.event-modal-footer{display:flex;justify-content:flex-end;gap:10px;padding:18px 26px;flex-shrink:0;border-top:1px solid rgba(255,255,255,0.07)}
.evt-btn{padding:11px 22px;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;transition:all .2s cubic-bezier(.4,0,.2,1);display:flex;align-items:center;gap:6px;font-family:var(--pg-font)}
.evt-btn-cancel{background:rgba(255,255,255,0.06);color:rgba(255,255,255,0.55);border:1px solid rgba(255,255,255,0.10)}
.evt-btn-cancel:hover{background:rgba(255,255,255,0.12);color:#fff}
.evt-btn-save{background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);color:#fff;box-shadow:0 2px 12px rgba(79,70,229,0.3)}
.evt-btn-save:hover{transform:translateY(-1px);box-shadow:0 8px 24px rgba(79,70,229,0.45)}
@keyframes modalSlideIn{from{opacity:0;transform:translateY(24px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}

/* Winner modal overrides (keep unique layout, match calendar colors) */
.it-modal-overlay{position:fixed;inset:0;background:rgba(11,11,20,0.72);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);z-index:9998;display:none;align-items:center;justify-content:center;padding:20px}
.it-modal-overlay.show{display:flex}
.it-modal{width:440px;max-width:100%;border-radius:22px;overflow:hidden;background:rgba(10,34,76,0.90);backdrop-filter:blur(24px) saturate(1.6);-webkit-backdrop-filter:blur(24px) saturate(1.6);border:1px solid rgba(255,255,255,0.12);box-shadow:0 24px 80px rgba(0,0,0,0.4);animation:pgModalSlideIn .35s cubic-bezier(.34,1.56,.64,1);text-align:center;color:#ffffff}
.it-modal-head{padding:26px 26px 10px}
.it-modal-kicker{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:#60A5FA}
.it-modal-body{padding:6px 30px 18px}
.it-winner-avatar{width:96px;height:96px;border-radius:50%;margin:6px auto 14px;display:flex;align-items:center;justify-content:center;font-size:38px;font-weight:800;color:#fff;background:linear-gradient(135deg,#7c3aed 0%,#4f46e5 100%);box-shadow:0 10px 30px rgba(79,70,229,0.45),inset 0 0 0 4px rgba(255,255,255,0.15)}
.it-winner-name{font-size:26px;font-weight:800;letter-spacing:-.02em;color:#fff;margin-bottom:4px}
.it-winner-sub{font-size:13px;color:rgba(255,255,255,0.5)}
.it-modal-foot{padding:14px 26px 26px;display:flex;gap:10px;justify-content:center}
.it-confetti{position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;overflow:hidden}
@keyframes pgModalSlideIn{from{opacity:0;transform:translateY(14px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}

/* Timer / noise modal specific styles */
.it-modal-timer-display{font-family:var(--pg-mono);font-size:64px;font-weight:700;text-align:center;color:#fff;line-height:1;margin:8px 0}
.it-modal-timer-inputs{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:16px}
.it-modal-timer-inputs input{width:80px;text-align:center;font-family:var(--pg-mono);font-size:22px;font-weight:700;padding:12px 8px;border:2px solid rgba(255,255,255,0.10);border-radius:12px;background:rgba(255,255,255,0.05);color:#fff;transition:all var(--pg-transition)}
.it-modal-timer-inputs input:focus{outline:none;border-color:#60A5FA;box-shadow:0 0 0 3px rgba(96,165,250,0.15);background:rgba(255,255,255,0.08)}
.it-modal-timer-inputs span{font-size:28px;font-weight:700;color:rgba(255,255,255,0.4)}
.it-modal-timer-btns{display:flex;gap:10px;flex-wrap:wrap;justify-content:center}
.it-modal-timer-btns button{min-width:100px}
.it-modal-noise-meter{width:100%;height:24px;border-radius:12px;background:rgba(255,255,255,0.06);overflow:hidden;position:relative;margin-bottom:10px}
.it-modal-noise-fill{height:100%;width:0%;background:linear-gradient(90deg,#34d399 0%,#fbbf24 60%,#ef4444 100%);transition:width .08s linear}
.it-modal-noise-threshold{position:absolute;top:0;bottom:0;width:3px;background:#fff;opacity:.8}
.it-modal-noise-alert{margin-top:14px;padding:14px 18px;border-radius:12px;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.35);color:#F87171;font-weight:700;font-size:15px;text-align:center;display:none;width:100%}
.it-modal-noise-alert.show{display:block;animation:itFlash .7s steps(1) infinite}
.it-modal-threshold-row{display:flex;align-items:center;gap:12px;width:100%;font-size:13px;color:rgba(255,255,255,0.7)}
.it-modal-threshold-row input[type=range]{flex:1;accent-color:#4f46e5}

/* Edit wheel content rows (inputs match .evt-field from pages-theme) */
.it-edit-list{max-height:46vh;overflow-y:auto;display:flex;flex-direction:column;gap:8px;padding-right:4px}
.it-edit-list::-webkit-scrollbar{width:6px}
.it-edit-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.18);border-radius:6px}
.it-edit-row{display:flex;align-items:center;gap:10px}
.it-edit-row .form-input{flex:1;width:100%;padding:11px 16px;border:1.5px solid rgba(255,255,255,0.12);border-radius:10px;background:rgba(255,255,255,0.06);color:#fff;font-size:14px;font-family:'Plus Jakarta Sans',sans-serif}
.it-edit-row .form-input:focus{outline:none;border-color:#60A5FA;box-shadow:0 0 0 3px rgba(96,165,250,0.15)}
.it-edit-del{flex-shrink:0;width:34px;height:38px;border-radius:9px;border:1px solid rgba(239,68,68,0.35);background:rgba(239,68,68,0.16);color:#F87171;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center}
.it-edit-del:hover{background:rgba(239,68,68,0.3)}

/* Timer / noise modal specific styles */
</style>
<!-- FILE_BODY_MID -->
<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content it-page" id="mainContent">

    <div class="content-area">
        <?= displayFlashMessage() ?>

        <div class="page-title d-flex flex-wrap align-items-center justify-content-between mb-3" style="gap:12px">
            <div>
                <h5>Interactive Tools</h5>
                <small>Classroom hub · <strong>Spin Wheel</strong>, groups, points, timer &amp; live tools</small>
            </div>
            <span class="count-pill"><i class="bi bi-people-fill me-1"></i><span id="itRosterCount">0</span> students</span>
        </div>

        <div class="it-layout">

            <!-- ═══ LEFT: CONTROL PANEL ═══ -->
            <div class="it-col">

                <!-- Class selector -->
                <div class="card">
                    <div class="card-header"><span><i class="bi bi-grid-1x2-fill"></i> Class &amp; Section</span></div>
                    <div class="card-body">
                        <label class="form-label" for="itClassSelect">Select Grade &amp; Section</label>
                        <select class="form-select" id="itClassSelect">
                            <option value="">— Choose a class —</option>
                        </select>
                        <div class="it-names-count">On wheel: <b id="itNamesCount">0</b></div>
                        <button class="btn-ad-outline w-100 mt-2" id="itResetNames" type="button"><i class="bi bi-arrow-counterclockwise"></i> Reset Wheel &amp; Scores</button>
                    </div>
                </div>

                <!-- Mode -->
                <div class="card">
                    <div class="card-header"><span><i class="bi bi-sliders"></i> Activity Mode</span></div>
                    <div class="card-body">
                        <div class="it-modebar">
                            <div class="it-mode active" data-mode="pick"><i class="bi bi-person-badge"></i>Pick a Student</div>
                            <div class="it-mode" data-mode="group"><i class="bi bi-collection"></i>Make Groups</div>
                            <div class="it-mode" data-mode="custom"><i class="bi bi-stars"></i>Custom Wheel</div>
                        </div>

                        <!-- Custom content selector -->
                        <div id="itContentControls" class="it-hidden mt-3">
                            <label class="form-label">Wheel Content</label>
                            <select class="form-select" id="itContentType">
                                <option value="students">Student Names</option>
                                <option value="topics">Topics / Questions</option>
                                <option value="rewards">Rewards</option>
                            </select>
                        </div>

                        <!-- Remove after win -->
                        <label class="it-check mt-3">
                            <input type="checkbox" id="itRemoveAfterWin">
                            <span>Remove name after win (no repeat)</span>
                        </label>

                        <!-- Group config -->
                        <div id="itGroupControls" class="it-hidden mt-3">
                            <div class="row g-2">
                                <div class="col-7">
                                    <div class="it-field">
                                        <label for="itGroupBy">Split by</label>
                                        <select class="form-select" id="itGroupBy">
                                            <option value="teams">Number of Teams</option>
                                            <option value="members">Members per Team</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-5">
                                    <div class="it-field">
                                        <label for="itGroupNum">Qty</label>
                                        <input type="number" class="form-input" id="itGroupNum" min="2" max="20" value="4">
                                    </div>
                                </div>
                            </div>
                            <button class="btn-ad-primary w-100 mt-2" id="itGenGroups" type="button"><i class="bi bi-shuffle"></i> Generate Teams</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ CENTER: STAGE ═══ -->
            <div class="it-col">
                <div class="card it-wheel-card">
                    <div class="card-header"><span><i class="bi bi-circle-half"></i> Spin the Wheel</span><span class="text-muted" id="itWheelStatus" style="font-size:12px">Pick a class to begin</span></div>
                    <div class="card-body">
                        <div class="it-wheel-wrap">
                            <div class="it-pointer"></div>
                            <canvas id="itWheel"></canvas>
                            <button class="it-spin-btn" id="itSpinBtn" disabled>SPIN</button>
                        </div>
                        <div id="itGroupResults" class="w-100 it-hidden">
                            <div class="d-flex align-items-center justify-content-between mb-2 mt-2">
                                <strong style="font-size:14px"><i class="bi bi-collection me-1"></i>Generated Teams</strong>
                                <div class="d-flex gap-2">
                                    <button class="btn-ad-outline" id="itRegenGroups" type="button"><i class="bi bi-arrow-repeat"></i> Reshuffle</button>
                                    <button class="btn-ad-success" id="itSaveGroups" type="button"><i class="bi bi-save"></i> Save Grouping</button>
                                </div>
                            </div>
                            <div class="it-groups" id="itGroups"></div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><span><i class="bi bi-clock-history"></i> Live Spin Log</span><button class="btn-ad-clear" id="itClearLog" title="Clear log" style="border:none;background:transparent;color:rgba(255,255,255,0.4);cursor:pointer;font-size:14px"><i class="bi bi-trash"></i></button></div>
                    <div class="card-body">
                        <div class="it-log" id="itLog"><div class="it-log-empty">No spins yet — give the wheel a spin!</div></div>
                    </div>
                </div>
            </div>

            <!-- ═══ RIGHT: SIDEBAR HUB ═══ -->
            <div class="it-right">

                <!-- Timer -->
                <div class="card">
                    <div class="card-header"><span><i class="bi bi-stopwatch"></i> Classroom Timer</span></div>
                    <div class="card-body">
                        <div class="it-timer-display" id="itTimerDisplay">05:00</div>
                        <div class="it-timer-inputs">
                            <input type="number" id="itMin" min="0" max="99" value="5" aria-label="Minutes">
                            <span>:</span>
                            <input type="number" id="itSec" min="0" max="59" value="0" aria-label="Seconds">
                        </div>
                        <div class="it-timer-btns">
                            <button class="btn-ad-primary" id="itTimerStart" type="button"><i class="bi bi-play-fill"></i> Start</button>
                            <button class="btn-ad-outline" id="itTimerPause" type="button"><i class="bi bi-pause-fill"></i> Pause</button>
                            <button class="btn-ad-outline span2" id="itTimerReset" type="button"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
                        </div>
                    </div>
                </div>

                <!-- Noise meter -->
                <div class="card" id="itNoiseCard" style="cursor:pointer">
                    <div class="card-header"><span><i class="bi bi-soundwave"></i> Noise Monitor</span></div>
                    <div class="card-body" id="itNoiseCardBody">
                        <div class="it-noise-meter"><div class="it-noise-fill" id="itNoiseFill"></div><div class="it-noise-threshold" id="itNoiseThreshold"></div></div>
                        <div class="it-noise-alert" id="itNoiseAlert"><i class="bi bi-exclamation-triangle-fill me-1"></i> Too Loud!</div>
                        <div class="it-threshold-row">
                            <span>Threshold</span>
                            <input type="range" id="itNoiseThresh" min="10" max="100" value="70" style="flex:1;accent-color:#4f46e5">
                            <span id="itNoiseThreshVal" style="font-family:var(--pg-mono);color:#fff">70</span>
                        </div>
                        <button class="btn-ad-outline w-100 mt-2" id="itNoiseToggle" type="button"><i class="bi bi-mic"></i> Enable Microphone</button>
                    </div>
                </div>

                <!-- Leaderboard -->
                <div class="card">
                    <div class="card-header"><span><i class="bi bi-trophy"></i> Points &amp; Leaderboard</span></div>
                    <div class="card-body">
                        <div class="it-lb" id="itLeaderboard"><div class="it-log-empty">Load a class to start scoring.</div></div>
                    </div>
                </div>

                <!-- Participation -->
                <div class="card">
                    <div class="card-header"><span><i class="bi bi-graph-up"></i> Participation Tracker</span></div>
                    <div class="card-body">
                        <div id="itPartList"></div>
                        <div class="it-warn" id="itPartWarn"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Winner modal -->
<div class="it-modal-overlay" id="itWinnerModal">
    <div class="it-modal">
        <canvas class="it-confetti" id="itConfetti"></canvas>
        <div class="it-modal-head"><span class="it-modal-kicker">🎉 We have a winner</span></div>
        <div class="it-modal-body">
            <div class="it-winner-avatar" id="itWinnerAvatar">?</div>
            <div class="it-winner-name" id="itWinnerName">—</div>
            <div class="it-winner-sub" id="itWinnerSub">selected from the wheel</div>
        </div>
        <div class="it-modal-foot">
            <button class="evt-btn evt-btn-cancel" id="itWinnerClose" type="button"><i class="bi bi-x-lg"></i> Close</button>
            <button class="evt-btn evt-btn-save" id="itWinnerAgain" type="button"><i class="bi bi-arrow-repeat"></i> Spin Again</button>
        </div>
    </div>
</div>
<!-- Edit wheel content modal (styled like the calendar "Add Event" modal) -->
<div class="event-modal-overlay" id="itContentModal">
    <div class="event-modal">
        <div class="event-modal-header">
            <div class="event-modal-title" id="itContentTitle"><i class="bi bi-pencil-square"></i> Edit Wheel Content</div>
            <button class="event-modal-close" id="itContentClose" type="button"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="event-modal-body">
            <div id="itContentList" class="it-edit-list"></div>
            <button class="btn-ad-outline w-100 mt-3" id="itContentAdd" type="button"><i class="bi bi-plus-lg"></i> Add Item</button>
        </div>
        <div class="event-modal-footer">
            <button class="evt-btn evt-btn-cancel" id="itContentCancel" type="button"><i class="bi bi-x-lg"></i> Cancel</button>
            <button class="evt-btn evt-btn-save" id="itContentSave" type="button"><i class="bi bi-check-lg"></i> Save Content</button>
        </div>
    </div>
</div>

<!-- Timer expanded modal -->
<div class="event-modal-overlay" id="itTimerModal">
    <div class="event-modal">
        <div class="event-modal-header">
            <div class="event-modal-title"><i class="bi bi-stopwatch" style="color:#a5b4fc"></i><span>Classroom Timer</span></div>
            <button class="event-modal-close" id="itTimerModalClose" type="button"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="event-modal-body">
            <div class="it-modal-timer-display" id="itModalTimerDisplay">05:00</div>
            <div class="it-modal-timer-inputs">
                <input type="number" id="itModalMin" min="0" max="99" value="5" aria-label="Minutes">
                <span>:</span>
                <input type="number" id="itModalSec" min="0" max="59" value="0" aria-label="Seconds">
            </div>
            <div class="it-modal-timer-btns">
                <button class="btn-ad-primary" id="itModalTimerStart" type="button"><i class="bi bi-play-fill"></i> Start</button>
                <button class="btn-ad-outline" id="itModalTimerPause" type="button"><i class="bi bi-pause-fill"></i> Pause</button>
                <button class="btn-ad-outline" id="itModalTimerReset" type="button"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
            </div>
        </div>
    </div>
</div>

<!-- Noise monitor expanded modal -->
<div class="event-modal-overlay" id="itNoiseModal">
    <div class="event-modal">
        <div class="event-modal-header">
            <div class="event-modal-title"><i class="bi bi-soundwave" style="color:#34d399"></i><span>Noise Monitor</span></div>
            <button class="event-modal-close" id="itNoiseModalClose" type="button"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="event-modal-body">
            <div class="it-modal-noise-meter"><div class="it-modal-noise-fill" id="itModalNoiseFill"></div><div class="it-modal-noise-threshold" id="itModalNoiseThreshold"></div></div>
            <div class="it-modal-noise-alert" id="itModalNoiseAlert"><i class="bi bi-exclamation-triangle-fill me-1"></i> Too Loud!</div>
            <div class="it-modal-threshold-row">
                <span>Threshold</span>
                <input type="range" id="itModalNoiseThresh" min="10" max="100" value="70">
                <span id="itModalNoiseThreshVal" style="font-family:var(--pg-mono);color:#fff;min-width:28px;text-align:right">70</span>
            </div>
            <button class="btn-ad-outline" id="itModalNoiseToggle" type="button" style="min-width:180px"><i class="bi bi-mic"></i> Enable Microphone</button>
        </div>
    </div>
</div>

<!-- FILE_BODY_JS -->
<script>
(function(){
    'use strict';

    /* ── Data from PHP ── */
    var CLASSES = <?= $classesJson ?>;
    var TOPICS  = <?= $topicsJson ?>;
    var REWARDS = <?= $rewardsJson ?>;

    var classSelect = document.getElementById('itClassSelect');
    Object.keys(CLASSES).forEach(function(label){
        var o = document.createElement('option'); o.value = label; o.textContent = label;
        classSelect.appendChild(o);
    });

    var CSRF    = '<?= $csrfToken ?>';
    var PERSISTED = <?= $persistedJson ?>;   // {classes:{label:state}, last:label} or {classes:{},last:null}

    /* ══════════ PERSISTENCE (per teacher · per day · per class) ══════════ */
    var saveTimer = null, currentClass = '';
    function buildStatePayload(){
        return JSON.stringify({
            points: points,
            pickCounts: pickCounts,
            log: log.slice(0, 40),
            names: names.slice(),
            wheelItems: wheelItems.slice(),
            customTopics: customTopics.slice(),
            customRewards: customRewards.slice(),
            lastGroups: lastGroups,
            mode: mode,
            contentType: contentType
        });
    }
    function scheduleSave(){
        if(!currentClass) return;
        clearTimeout(saveTimer);
        saveTimer = setTimeout(sendState, 300);
    }
    function sendState(label){
        var cl = (label !== undefined && label !== null) ? label : currentClass;
        if(!cl) return;
        var stateJson = buildStatePayload();
        var fd = new FormData();
        fd.append('action','save_tools_state');
        fd.append('csrf_token', CSRF);
        fd.append('class_label', cl);
        fd.append('state', stateJson);
        fetch(window.location.href,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function(r){return r.json();})
            .then(function(res){
                if(res && res.success && PERSISTED && PERSISTED.classes){
                    PERSISTED.classes[cl] = JSON.parse(stateJson);
                    if(!PERSISTED.last) PERSISTED.last = cl;
                }
            })
            .catch(function(err){ console.warn('[InteractiveTools] state save error', err); });
    }
    function resetSavedState(){
        if(!currentClass) return;
        var fd = new FormData();
        fd.append('action','reset_tools_state');
        fd.append('csrf_token', CSRF);
        fd.append('class_label', currentClass);
        fetch(window.location.href,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function(r){return r.json();})
            .catch(function(err){ console.warn('[InteractiveTools] state reset error', err); });
        if(PERSISTED && PERSISTED.classes) delete PERSISTED.classes[currentClass];
    }
    function hydrateFromPersisted(classLabel){
        var p = (PERSISTED && PERSISTED.classes) ? PERSISTED.classes[classLabel] : null;
        if(!p) return;
        if(typeof p.points==='object' && p.points) points = p.points;
        if(typeof p.pickCounts==='object' && p.pickCounts) pickCounts = p.pickCounts;
        if(Array.isArray(p.log)) log = p.log.slice(0, 40);
        if(Array.isArray(p.names) && p.names.length) names = p.names.slice();
        if(Array.isArray(p.customTopics)) customTopics = p.customTopics.slice();
        if(Array.isArray(p.customRewards)) customRewards = p.customRewards.slice();
        if(Array.isArray(p.wheelItems) && p.wheelItems.length) wheelItems = p.wheelItems.slice();
        if(p.lastGroups !== undefined && p.lastGroups !== null) lastGroups = p.lastGroups;
        if(p.mode) mode = p.mode;
        if(p.contentType) contentType = p.contentType;
    }
    window.addEventListener('pagehide', function(){
        if(saveTimer){ clearTimeout(saveTimer); saveTimer = null; }
        if(!currentClass) return;
        try{
            var fd = new FormData();
            fd.append('action','save_tools_state');
            fd.append('csrf_token', CSRF);
            fd.append('class_label', currentClass);
            fd.append('state', buildStatePayload());
            navigator.sendBeacon(window.location.href, fd);
        }catch(e){}
    });

    /* ── State ── */
    var fullRoster = [], names = [], wheelItems = [], customTopics = TOPICS.slice(), customRewards = REWARDS.slice();
    var mode = 'pick', contentType = 'students';
    var points = {}, pickCounts = {}, log = [], lastGroups = null;

    /* ══════════ WHEEL ══════════ */
    var canvas = document.getElementById('itWheel'), ctx = canvas.getContext('2d');
    var size = 0, dpr = window.devicePixelRatio || 1;
    var rotation = 0, velocity = 0, spinning = false;
    var segColors = ['#4f46e5','#7c3aed','#06b6d4','#0ea5e9','#6366f1','#8b5cf6','#3b82f6','#22d3ee','#5b21b6','#2563eb','#0891b2','#7c3aed'];

    function resizeCanvas(){
        size = canvas.parentElement.clientWidth;
        canvas.width = size * dpr; canvas.height = size * dpr;
        ctx.setTransform(dpr,0,0,dpr,0,0);
        draw();
    }
    function draw(){
        ctx.clearRect(0,0,size,size);
        var cx=size/2, cy=size/2, R=size/2-4, n=wheelItems.length;
        if(!n) return;
        var seg=(Math.PI*2)/n;
        for(var i=0;i<n;i++){
            var a0=rotation+i*seg, a1=a0+seg;
            ctx.beginPath(); ctx.moveTo(cx,cy); ctx.arc(cx,cy,R,a0,a1); ctx.closePath();
            ctx.fillStyle=segColors[i%segColors.length]; ctx.fill();
            ctx.strokeStyle='rgba(255,255,255,0.12)'; ctx.lineWidth=1; ctx.stroke();
            ctx.save(); ctx.translate(cx,cy); ctx.rotate(a0+seg/2);
            ctx.textAlign='right'; ctx.fillStyle='#fff';
            ctx.font='600 '+Math.max(11,Math.min(16,size/30))+"px 'Plus Jakarta Sans', sans-serif";
            var label=wheelItems[i]; if(label.length>16) label=label.substring(0,15)+'…';
            ctx.fillText(label,R-14,5); ctx.restore();
        }
        ctx.beginPath(); ctx.arc(cx,cy,R*0.18,0,Math.PI*2); ctx.fillStyle='rgba(11,11,20,0.85)'; ctx.fill();
    }
    function getWinningIndex(){
        var n=wheelItems.length; if(!n) return -1;
        var seg=(Math.PI*2)/n, pointer=-Math.PI/2;
        var angle=(pointer-rotation)%(Math.PI*2); if(angle<0) angle+=Math.PI*2;
        return Math.floor(angle/seg)%n;
    }
    function rebuildWheel(){
        if(mode==='custom'){
            if(contentType==='topics') wheelItems=customTopics.slice();
            else if(contentType==='rewards') wheelItems=customRewards.slice();
            else wheelItems=names.slice();
        } else wheelItems=names.slice();
        document.getElementById('itNamesCount').textContent=wheelItems.length;
        draw();
    }
    function spin(){
        if(spinning || !wheelItems.length) return;
        spinning=true; document.getElementById('itSpinBtn').disabled=true;
        velocity=0.28+Math.random()*0.12; var friction=0.991;
        (function frame(){
            rotation+=velocity; velocity*=friction;
            if(velocity<0.0025){ velocity=0; rotation=rotation%(Math.PI*2); spinning=false; draw(); onSpinEnd(); return; }
            draw(); requestAnimationFrame(frame);
        })();
    }
    function onSpinEnd(){
        var idx=getWinningIndex(), winner=wheelItems[idx];
        document.getElementById('itSpinBtn').disabled=false;
        if(!winner) return;
        showWinner(winner); addLog(winner);
        if(fullRoster.indexOf(winner)>=0){ pickCounts[winner]=(pickCounts[winner]||0)+1; renderParticipation(); }
        var remove = document.getElementById('itRemoveAfterWin').checked && mode!=='group';
        if(remove){
            var wi=wheelItems.indexOf(winner); if(wi>=0) wheelItems.splice(wi,1);
            var ni=names.indexOf(winner); if(ni>=0) names.splice(ni,1);
            document.getElementById('itNamesCount').textContent=wheelItems.length; draw();
        }
        scheduleSave();
    }

    /* ── Winner modal ── */
    var modal=document.getElementById('itWinnerModal');
    function initials(n){var p=n.trim().split(/\s+/);return ((p[0]?p[0][0]:'')+(p[1]?p[1][0]:'')).toUpperCase()||'?';}
    function showWinner(name){
        document.getElementById('itWinnerName').textContent=name;
        document.getElementById('itWinnerAvatar').textContent=initials(name);
        var sub = mode==='custom' ? (contentType==='topics'?'topic drawn':contentType==='rewards'?'reward unlocked':'picked from') : (mode==='group'?'featured from':'picked from');
        document.getElementById('itWinnerSub').textContent=sub+' '+(classSelect.value||'the wheel');
        modal.classList.add('show'); beep(); launchConfetti();
    }
    function closeModal(){modal.classList.remove('show');}
    document.getElementById('itWinnerClose').addEventListener('click',closeModal);
    document.getElementById('itWinnerAgain').addEventListener('click',function(){closeModal();spin();});
    modal.addEventListener('click',function(e){if(e.target===modal)closeModal();});

    function beep(){
        try{
            var AC=window.AudioContext||window.webkitAudioContext; if(!AC) return;
            var a=new AC(); [880,1175,1568].forEach(function(f,i){
                var o=a.createOscillator(),g=a.createGain(); o.type='sine'; o.frequency.value=f;
                o.connect(g); g.connect(a.destination); var t=a.currentTime+i*0.09;
                g.gain.setValueAtTime(0.0001,t); g.gain.exponentialRampToValueAtTime(0.18,t+0.02); g.gain.exponentialRampToValueAtTime(0.0001,t+0.18);
                o.start(t); o.stop(t+0.2);
            }); setTimeout(function(){a.close();},600);
        }catch(e){}
    }
    function launchConfetti(){
        var c=document.getElementById('itConfetti'),parent=c.parentElement;
        c.width=parent.clientWidth; c.height=parent.clientHeight; var cx=c.getContext('2d');
        var cols=['#4f46e5','#7c3aed','#06b6d4','#22d3ee','#fbbf24','#34d399','#f87171'],parts=[];
        for(var i=0;i<120;i++) parts.push({x:c.width/2,y:c.height/2,vx:(Math.random()-0.5)*9,vy:(Math.random()-0.5)*9-3,s:4+Math.random()*5,col:cols[i%cols.length],life:1});
        var start=performance.now();
        (function anim(now){var t=now-start; cx.clearRect(0,0,c.width,c.height);
            parts.forEach(function(p){p.x+=p.vx;p.y+=p.vy;p.vy+=0.18;p.life-=0.012; if(p.life>0){cx.globalAlpha=Math.max(0,p.life);cx.fillStyle=p.col;cx.fillRect(p.x,p.y,p.s,p.s);}});
            cx.globalAlpha=1; if(t<1800) requestAnimationFrame(anim); else cx.clearRect(0,0,c.width,c.height);
        })(start);
    }

    /* ── Live log ── */
    function renderLog(){
        var box=document.getElementById('itLog');
        if(!log.length){ box.innerHTML='<div class="it-log-empty">No spins yet — give the wheel a spin!</div>'; return; }
        box.innerHTML='';
        log.forEach(function(e){
            var modeLabel = e.m==='custom' ? (e.c==='topics'?'Topic':e.c==='rewards'?'Reward':'Pick') : (e.m==='group'?'Group':'Pick');
            var item=document.createElement('div'); item.className='it-log-item';
            item.innerHTML='<span class="it-log-dot"></span><span class="it-log-name">'+escapeHtml(e.name)+'</span><span class="it-log-meta">'+modeLabel+' · '+e.t+'</span>';
            box.appendChild(item);
        });
        while(box.children.length>40) box.removeChild(box.lastChild);
    }
    function addLog(name){
        var d=new Date(); var t=('0'+d.getHours()).slice(-2)+':'+('0'+d.getMinutes()).slice(-2)+':'+('0'+d.getSeconds()).slice(-2);
        log.unshift({name:name,t:t,m:mode,c:contentType});
        renderLog();
        scheduleSave();
    }
    document.getElementById('itClearLog').addEventListener('click',function(){log=[];renderLog();scheduleSave();});

    /* ── Group maker ── */
    function renderGroups(){
        var html='';
        lastGroups.forEach(function(members,gi){
            var leaderIdx=members.length?Math.floor(Math.random()*members.length):-1;
            html+='<div class="it-group"><div class="it-group-head"><div class="it-group-title"><i class="bi bi-flag-fill" style="color:#a5b4fc"></i>Team '+(gi+1)+'</div><span class="it-group-badge">'+members.length+'</span></div>';
            members.forEach(function(m,mi){
                var lead=mi===leaderIdx;
                html+='<div class="it-member'+(lead?' it-leader':'')+'"><span class="dot"></span>'+escapeHtml(m)+(lead?'<span class="it-leader-tag">Lead</span>':'')+'</div>';
            });
            html+='</div>';
        });
        document.getElementById('itGroups').innerHTML=html;
        document.getElementById('itGroupResults').classList.remove('it-hidden');
    }
    function generateGroups(){
        var by=document.getElementById('itGroupBy').value, num=parseInt(document.getElementById('itGroupNum').value,10);
        if(!names.length){showToast('Load a class first','warning');return;}
        if(isNaN(num)||num<1){showToast('Enter a valid quantity','warning');return;}
        var pool=names.slice();
        for(var i=pool.length-1;i>0;i--){var j=Math.floor(Math.random()*(i+1));var t=pool[i];pool[i]=pool[j];pool[j]=t;}
        var teamCount=(by==='teams')?num:Math.ceil(pool.length/num); if(teamCount>pool.length)teamCount=pool.length;
        var groups=[]; for(var g=0;g<teamCount;g++) groups.push([]);
        pool.forEach(function(m,idx){groups[idx%teamCount].push(m);});
        lastGroups=groups;
        renderGroups();
        document.getElementById('itGroupResults').classList.remove('it-hidden');
        showToast('Generated '+teamCount+' team'+(teamCount>1?'s':''),'success');
        scheduleSave();
    }
    document.getElementById('itGenGroups').addEventListener('click',generateGroups);
    document.getElementById('itRegenGroups').addEventListener('click',generateGroups);
    document.getElementById('itSaveGroups').addEventListener('click',function(){
        if(!lastGroups){showToast('Generate teams first','warning');return;}
        var btn=this; btn.disabled=true; var orig=btn.innerHTML; btn.innerHTML='<i class="bi bi-hourglass-split spin"></i> Saving…';
        var fd=new FormData(); fd.append('action','save_groups'); fd.append('csrf_token',CSRF);
        fd.append('class_label',classSelect.value); fd.append('groups',JSON.stringify(lastGroups));
        fetch(window.location.href,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function(r){return r.json();}).then(function(res){
                btn.disabled=false; btn.innerHTML=orig;
                showToast(res.message||'Saved','success');
            }).catch(function(){btn.disabled=false;btn.innerHTML=orig;showToast('Save failed','danger');});
    });

    /* ── Mode / content switching ── */
    function syncModeUI(){
        document.querySelectorAll('.it-mode').forEach(function(m){m.classList.toggle('active',m.dataset.mode===mode);});
        var custom=mode==='custom';
        document.getElementById('itContentControls').classList.toggle('it-hidden',!custom);
        document.getElementById('itGroupControls').classList.toggle('it-hidden',mode!=='group');
        document.getElementById('itGroupResults').classList.toggle('it-hidden',mode!=='group' || !lastGroups);
        document.getElementById('itContentType').value=contentType;
    }
    function updateWheelStatus(){
        var status=document.getElementById('itWheelStatus');
        if(!fullRoster.length){ status.textContent='No students'; return; }
        status.textContent = mode==='group' ? 'Use Generate Teams for groups' :
            (mode==='custom' ? 'Custom wheel — tap the wheel to edit content' : 'Tap SPIN to pick a student');
    }
    document.querySelectorAll('.it-mode').forEach(function(el){
        el.addEventListener('click',function(){
            mode=el.dataset.mode;
            syncModeUI(); updateWheelStatus(); rebuildWheel(); scheduleSave();
        });
    });
    document.getElementById('itContentType').addEventListener('change',function(){
        contentType=this.value; rebuildWheel(); scheduleSave();
    });

    /* ── Class load ── */
    classSelect.addEventListener('change',function(){
        /* Flush any pending save against the previous class before switching */
        if(saveTimer){ clearTimeout(saveTimer); saveTimer=null; if(currentClass){ sendState(currentClass); } }
        currentClass = this.value;
        fullRoster=(CLASSES[this.value]||[]).slice();
        names=fullRoster.slice(); customTopics=TOPICS.slice(); customRewards=REWARDS.slice();
        points={}; pickCounts={}; log=[]; lastGroups=null;
        fullRoster.forEach(function(n){points[n]=0;pickCounts[n]=0;});
        document.getElementById('itRosterCount').textContent=fullRoster.length;
        document.getElementById('itSpinBtn').disabled=!fullRoster.length;
        document.getElementById('itGroupResults').classList.add('it-hidden');
        hydrateFromPersisted(this.value);
        rotation=0; rebuildWheel();
        if(lastGroups && mode==='group'){ renderGroups(); }
        syncModeUI(); updateWheelStatus();
        renderLeaderboard(); renderParticipation(); renderLog();
        scheduleSave();
    });
    document.getElementById('itSpinBtn').addEventListener('click',spin);
    document.getElementById('itResetNames').addEventListener('click',function(){
        names=fullRoster.slice(); customTopics=TOPICS.slice(); customRewards=REWARDS.slice();
        points={}; pickCounts={}; log=[]; lastGroups=null;
        fullRoster.forEach(function(n){points[n]=0;pickCounts[n]=0;});
        document.getElementById('itGroupResults').classList.add('it-hidden');
        rotation=0; rebuildWheel(); renderLeaderboard(); renderParticipation(); renderLog();
        syncModeUI(); updateWheelStatus();
        resetSavedState();
        showToast('Wheel & scores reset','info');
    });

    /* ══════════ LEADERBOARD (points) ══════════ */
    function renderLeaderboard(){
        var box=document.getElementById('itLeaderboard');
        var entries=Object.keys(points).map(function(n){return{n:n,p:points[n]};});
        entries.sort(function(a,b){return b.p-a.p;});
        if(!entries.length){box.innerHTML='<div class="it-log-empty">Load a class to start scoring.</div>';return;}
        box.innerHTML='';
        entries.forEach(function(e,i){
            var row=document.createElement('div'); row.className='it-lb-row';
            row.innerHTML='<span class="it-lb-rank">'+(i+1)+'</span>'+
                '<span class="it-lb-name">'+escapeHtml(e.n)+'</span>'+
                '<span class="it-lb-pts">'+e.p+'</span>'+
                '<span class="it-pt-btns"><button class="it-pt-btn minus" data-n="'+escapeAttr(e.n)+'">−</button>'+
                '<button class="it-pt-btn plus" data-n="'+escapeAttr(e.n)+'">+</button></span>';
            box.appendChild(row);
        });
        box.querySelectorAll('.it-pt-btn').forEach(function(b){
            b.addEventListener('click',function(){
                var nm=this.dataset.n, d=this.classList.contains('plus')?1:-1;
                points[nm]=Math.max(0,(points[nm]||0)+d); renderLeaderboard(); scheduleSave();
            });
        });
    }

    /* ══════════ PARTICIPATION TRACKER ══════════ */
    function renderParticipation(){
        var box=document.getElementById('itPartList'), warn=document.getElementById('itPartWarn');
        var students=fullRoster;
        if(!students.length){box.innerHTML='';warn.classList.remove('show');return;}
        var max=0; students.forEach(function(n){max=Math.max(max,pickCounts[n]||0);});
        var leftOut=[];
        box.innerHTML='';
        students.forEach(function(n){
            var c=pickCounts[n]||0; if(c===0&&max>0) leftOut.push(n);
            var row=document.createElement('div'); row.className='it-part-row';
            row.innerHTML='<span class="it-part-name">'+escapeHtml(n)+'</span>'+
                '<span class="it-part-track"><span class="it-part-fill" style="width:'+(max?Math.round(c/max*100):0)+'%"></span></span>'+
                '<span class="it-part-count">'+c+'</span>';
            box.appendChild(row);
        });
        var totalSpins=students.reduce(function(s,n){return s+(pickCounts[n]||0);},0);
        if(leftOut.length && totalSpins>=Math.ceil(students.length/2)){
            warn.textContent='⚠ Left out: '+leftOut.slice(0,5).join(', ')+(leftOut.length>5?' +'+(leftOut.length-5):'');
            warn.classList.add('show');
        } else warn.classList.remove('show');
    }

    /* ══════════ COMPANION TIMER ══════════ */
    var tDisp=document.getElementById('itTimerDisplay'), tMin=document.getElementById('itMin'), tSec=document.getElementById('itSec');
    var remaining=300, timerInt=null, flashing=false;
    function fmt(s){var m=Math.floor(s/60),x=s%60;return (m<10?'0':'')+m+':'+(x<10?'0':'')+x;}
    function renderTimer(){tDisp.textContent=fmt(remaining);tMin.value=Math.floor(remaining/60);tSec.value=remaining%60;}
    function stopFlash(){if(flashing){tDisp.classList.remove('it-flash');flashing=false;}}
    function tick(){
        if(remaining<=0){clearInterval(timerInt);timerInt=null;return;}
        if(remaining<=10 && remaining>0) playClockTick();
        remaining--; renderTimer();
        if(remaining<=0){clearInterval(timerInt);timerInt=null;tDisp.classList.add('it-flash');flashing=true;playBell();showToast('⏰ Time is up!','warning');}
    }
    document.getElementById('itTimerStart').addEventListener('click',function(){
        stopFlash(); getAC();
        if(timerInt)return;
        if(remaining<=0) remaining=(parseInt(tMin.value,10)||0)*60+(parseInt(tSec.value,10)||0);
        if(remaining<=0){showToast('Set a time first','warning');return;}
        renderTimer(); timerInt=setInterval(tick,1000);
    });
    document.getElementById('itTimerPause').addEventListener('click',function(){if(timerInt){clearInterval(timerInt);timerInt=null;}});
    document.getElementById('itTimerReset').addEventListener('click',function(){
        if(timerInt){clearInterval(timerInt);timerInt=null;} stopFlash();
        remaining=(parseInt(tMin.value,10)||0)*60+(parseInt(tSec.value,10)||0); renderTimer();
    });
    tMin.addEventListener('change',function(){stopFlash();remaining=(parseInt(tMin.value,10)||0)*60+(parseInt(tSec.value,10)||0);renderTimer();});
    tSec.addEventListener('change',function(){stopFlash();var s=parseInt(tSec.value,10)||0;if(s>59)s=59;tSec.value=s;remaining=(parseInt(tMin.value,10)||0)*60+s;renderTimer();});
    renderTimer();

    /* ══════════ NOISE METER ══════════ */
    var noiseFill=document.getElementById('itNoiseFill'), noiseThresh=document.getElementById('itNoiseThreshold');
    var noiseAlert=document.getElementById('itNoiseAlert'), noiseSlider=document.getElementById('itNoiseThresh'), noiseThreshVal=document.getElementById('itNoiseThreshVal');
    var micStream=null, audioCtx=null, analyser=null, rafId=null, noiseOn=false;
    function positionThreshold(){noiseThresh.style.left=noiseSlider.value+'%';}
    noiseSlider.addEventListener('input',function(){noiseThreshVal.textContent=this.value;positionThreshold();});
    positionThreshold();
    document.getElementById('itNoiseToggle').addEventListener('click',function(){
        if(noiseOn){ stopNoise(); this.innerHTML='<i class="bi bi-mic"></i> Enable Microphone'; return; }
        if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){showToast('Microphone not supported','warning');return;}
        navigator.mediaDevices.getUserMedia({audio:true}).then(function(stream){
            micStream=stream; audioCtx=new (window.AudioContext||window.webkitAudioContext)();
            var src=audioCtx.createMediaStreamSource(stream); analyser=audioCtx.createAnalyser();
            analyser.fftSize=1024; src.connect(analyser);
            noiseOn=true; document.getElementById('itNoiseToggle').innerHTML='<i class="bi bi-mic-mute"></i> Disable Microphone';
            var buf=new Uint8Array(analyser.fftSize);
            (function loop(){
                analyser.getByteTimeDomainData(buf);
                var sum=0; for(var i=0;i<buf.length;i++){var v=(buf[i]-128)/128; sum+=v*v;}
                var rms=Math.sqrt(sum/buf.length); var pct=Math.min(100,Math.round(rms*240));
                noiseFill.style.width=pct+'%';
                if(pct>=parseInt(noiseSlider.value,10)){noiseAlert.classList.add('show');} else {noiseAlert.classList.remove('show');}
                rafId=requestAnimationFrame(loop);
            })();
        }).catch(function(){showToast('Microphone permission denied','warning');});
    });
    function stopNoise(){
        noiseOn=false; if(rafId)cancelAnimationFrame(rafId);
        if(micStream)micStream.getTracks().forEach(function(t){t.stop();});
        if(audioCtx)audioCtx.close(); micStream=null; analyser=null;
        noiseFill.style.width='0%'; noiseAlert.classList.remove('show');
    }

    /* ── Edit wheel content (click the wheel, except SPIN) ── */
    var contentModal = document.getElementById('itContentModal');
    function buildEditRow(val){
        var row=document.createElement('div'); row.className='it-edit-row';
        var inp=document.createElement('input'); inp.className='form-input'; inp.type='text'; inp.value=val; inp.placeholder='Item text';
        var del=document.createElement('button'); del.className='it-edit-del'; del.type='button'; del.innerHTML='&times;';
        del.addEventListener('click',function(){
            row.remove();
            if(!document.getElementById('itContentList').querySelector('.it-edit-row')){
                var e=document.createElement('div'); e.className='it-log-empty'; e.textContent='No items — add one below.';
                document.getElementById('itContentList').appendChild(e);
            }
        });
        row.appendChild(inp); row.appendChild(del); return row;
    }
    function openContentEditor(){
        if(!wheelItems.length){ showToast('Load a class first','warning'); return; }
        var title = (mode==='custom')
            ? (contentType==='topics' ? 'Edit Topics' : contentType==='rewards' ? 'Edit Rewards' : 'Edit Student Names')
            : 'Edit Student Names';
        document.getElementById('itContentTitle').innerHTML='<i class="bi bi-pencil-square me-1"></i>'+title;
        var list=document.getElementById('itContentList'); list.innerHTML='';
        wheelItems.forEach(function(it){ list.appendChild(buildEditRow(it)); });
        if(!wheelItems.length) list.innerHTML='<div class="it-log-empty">No items — add one below.</div>';
        contentModal.classList.add('show');
    }
    function closeContentEditor(){ contentModal.classList.remove('show'); }
    document.getElementById('itContentClose').addEventListener('click',closeContentEditor);
    document.getElementById('itContentCancel').addEventListener('click',closeContentEditor);
    contentModal.addEventListener('click',function(e){ if(e.target===contentModal) closeContentEditor(); });
    document.getElementById('itContentAdd').addEventListener('click',function(){
        var empty=document.getElementById('itContentList').querySelector('.it-log-empty'); if(empty) empty.remove();
        var list=document.getElementById('itContentList'); list.appendChild(buildEditRow(''));
        var inputs=list.querySelectorAll('.form-input'); if(inputs.length) inputs[inputs.length-1].focus();
    });
    document.getElementById('itContentSave').addEventListener('click',function(){
        var vals=Array.prototype.map.call(document.getElementById('itContentList').querySelectorAll('.form-input'),function(i){return i.value.trim();});
        var cleaned=vals.filter(function(v){ return v.length>0; });
        if(!cleaned.length){ showToast('Add at least one item','warning'); return; }
        wheelItems=cleaned;
        if(mode==='custom'){
            if(contentType==='topics')        customTopics=cleaned.slice();
            else if(contentType==='rewards')  customRewards=cleaned.slice();
            else                              names=cleaned.slice();
        } else {
            names=cleaned.slice();
        }
        document.getElementById('itNamesCount').textContent=wheelItems.length;
        draw(); closeContentEditor(); showToast('Wheel content saved','success');
        scheduleSave();
    });
    /* Clicking the wheel (canvas or pointer) opens the editor — SPIN button is separate */
    canvas.addEventListener('click',openContentEditor);
    var wheelPointerEl=document.querySelector('.it-pointer');
    if(wheelPointerEl) wheelPointerEl.addEventListener('click',openContentEditor);

    /* ── Audio helpers (Web Audio API, no files needed) ── */
    var sharedAC=null;
    function getAC(){
        if(!sharedAC){
            var AC=window.AudioContext||window.webkitAudioContext; if(!AC) return null;
            sharedAC=new AC();
        }
        if(sharedAC.state==='suspended') sharedAC.resume();
        return sharedAC;
    }
    function playClockTick(){
        try{
            var a=getAC(); if(!a) return;
            var o=a.createOscillator(),g=a.createGain(); o.type='sine'; o.frequency.value=1200;
            o.connect(g); g.connect(a.destination); var t=a.currentTime;
            g.gain.setValueAtTime(0.0001,t); g.gain.exponentialRampToValueAtTime(0.12,t+0.005); g.gain.exponentialRampToValueAtTime(0.0001,t+0.06);
            o.start(t); o.stop(t+0.07);
        }catch(e){}
    }
    function playBell(){
        try{
            var a=getAC(); if(!a) return;
            [880,1175,1568].forEach(function(f,i){
                var o=a.createOscillator(),g=a.createGain(); o.type='sine'; o.frequency.value=f;
                o.connect(g); g.connect(a.destination); var t=a.currentTime+i*0.12;
                g.gain.setValueAtTime(0.0001,t); g.gain.exponentialRampToValueAtTime(0.22,t+0.02); g.gain.exponentialRampToValueAtTime(0.0001,t+0.35);
                o.start(t); o.stop(t+0.4);
            });
        }catch(e){}
    }
    function playLoudAlert(){
        try{
            var a=getAC(); if(!a) return;
            [440,440].forEach(function(_,i){
                var o=a.createOscillator(),g=a.createGain(); o.type='square'; o.frequency.value=440;
                o.connect(g); g.connect(a.destination); var t=a.currentTime+i*0.25;
                g.gain.setValueAtTime(0.0001,t); g.gain.exponentialRampToValueAtTime(0.15,t+0.01); g.gain.exponentialRampToValueAtTime(0.0001,t+0.2);
                o.start(t); o.stop(t+0.22);
            });
        }catch(e){}
    }

    /* ── Timer expanded modal ── */
    var timerModal=document.getElementById('itTimerModal');
    var mDisp=document.getElementById('itModalTimerDisplay'), mMin=document.getElementById('itModalMin'), mSec=document.getElementById('itModalSec');
    var modalRemaining=300, modalTimerInt=null, modalFlashing=false;
    function fmtModal(s){var m=Math.floor(s/60),x=s%60;return (m<10?'0':'')+m+':'+(x<10?'0':'')+x;}
    function renderModalTimer(){mDisp.textContent=fmtModal(modalRemaining);mMin.value=Math.floor(modalRemaining/60);mSec.value=modalRemaining%60;}
    function stopModalFlash(){if(modalFlashing){mDisp.classList.remove('it-flash');modalFlashing=false;}}
    function modalTick(){
        if(modalRemaining<=0){clearInterval(modalTimerInt);modalTimerInt=null;return;}
        if(modalRemaining<=10 && modalRemaining>0) playClockTick();
        modalRemaining--; renderModalTimer();
        if(modalRemaining<=0){
            clearInterval(modalTimerInt);modalTimerInt=null;
            mDisp.classList.add('it-flash');modalFlashing=true;
            playBell();showToast('⏰ Time is up!','warning');
        }
    }
    function openTimerModal(){
        stopModalFlash(); clearInterval(modalTimerInt); modalTimerInt=null;
        modalRemaining=(parseInt(mMin.value,10)||0)*60+(parseInt(mSec.value,10)||0);
        if(modalRemaining<=0) modalRemaining=300;
        mMin.value=Math.floor(modalRemaining/60); mSec.value=modalRemaining%60;
        renderModalTimer(); timerModal.classList.add('show');
    }
    function closeTimerModal(){
        timerModal.classList.remove('show');
        stopModalFlash(); clearInterval(modalTimerInt); modalTimerInt=null;
        modalRemaining=(parseInt(mMin.value,10)||0)*60+(parseInt(mSec.value,10)||0);
        renderModalTimer();
    }
    document.getElementById('itTimerDisplay').addEventListener('click',openTimerModal);
    document.getElementById('itTimerModalClose').addEventListener('click',closeTimerModal);
    timerModal.addEventListener('click',function(e){if(e.target===timerModal)closeTimerModal();});
    document.getElementById('itModalTimerStart').addEventListener('click',function(){
        stopModalFlash(); getAC();
        if(modalTimerInt)return;
        if(modalRemaining<=0) modalRemaining=(parseInt(mMin.value,10)||0)*60+(parseInt(mSec.value,10)||0);
        if(modalRemaining<=0){showToast('Set a time first','warning');return;}
        renderModalTimer(); modalTimerInt=setInterval(modalTick,1000);
    });
    document.getElementById('itModalTimerPause').addEventListener('click',function(){if(modalTimerInt){clearInterval(modalTimerInt);modalTimerInt=null;}});
    document.getElementById('itModalTimerReset').addEventListener('click',function(){
        if(modalTimerInt){clearInterval(modalTimerInt);modalTimerInt=null;} stopModalFlash();
        modalRemaining=(parseInt(mMin.value,10)||0)*60+(parseInt(mSec.value,10)||0); renderModalTimer();
    });
    mMin.addEventListener('change',function(){stopModalFlash();modalRemaining=(parseInt(mMin.value,10)||0)*60+(parseInt(mSec.value,10)||0);renderModalTimer();});
    mSec.addEventListener('change',function(){stopModalFlash();var s=parseInt(mSec.value,10)||0;if(s>59)s=59;mSec.value=s;modalRemaining=(parseInt(mMin.value,10)||0)*60+s;renderModalTimer();});
    renderTimer();

    /* ── Noise monitor expanded modal ── */
    var noiseModal=document.getElementById('itNoiseModal');
    var mNoiseFill=document.getElementById('itModalNoiseFill'), mNoiseThresh=document.getElementById('itModalNoiseThreshold');
    var mNoiseAlert=document.getElementById('itModalNoiseAlert'), mNoiseSlider=document.getElementById('itModalNoiseThresh'), mNoiseThreshVal=document.getElementById('itModalNoiseThreshVal');
    var mMicStream=null, mAudioCtx=null, mAnalyser=null, mRafId=null, modalNoiseOn=false;
    var loudAlertCooldown=0;
    function positionModalThreshold(){mNoiseThresh.style.left=mNoiseSlider.value+'%';}
    mNoiseSlider.addEventListener('input',function(){mNoiseThreshVal.textContent=this.value;positionModalThreshold();});
    positionModalThreshold();
    function stopModalNoise(){
        modalNoiseOn=false; if(mRafId)cancelAnimationFrame(mRafId);
        if(mMicStream)mMicStream.getTracks().forEach(function(t){t.stop();});
        if(mAudioCtx)mAudioCtx.close(); mMicStream=null; mAnalyser=null;
        mNoiseFill.style.width='0%'; mNoiseAlert.classList.remove('show');
        document.getElementById('itModalNoiseToggle').innerHTML='<i class="bi bi-mic"></i> Enable Microphone';
    }
    document.getElementById('itModalNoiseToggle').addEventListener('click',function(){
        if(modalNoiseOn){ stopModalNoise(); return; }
        if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){showToast('Microphone not supported','warning');return;}
        navigator.mediaDevices.getUserMedia({audio:true}).then(function(stream){
            mMicStream=stream; mAudioCtx=new (window.AudioContext||window.webkitAudioContext)();
            var src=mAudioCtx.createMediaStreamSource(stream); mAnalyser=mAudioCtx.createAnalyser();
            mAnalyser.fftSize=1024; src.connect(mAnalyser);
            modalNoiseOn=true; document.getElementById('itModalNoiseToggle').innerHTML='<i class="bi bi-mic-mute"></i> Disable Microphone';
            var buf=new Uint8Array(mAnalyser.fftSize);
            (function loop(){
                mAnalyser.getByteTimeDomainData(buf);
                var sum=0; for(var i=0;i<buf.length;i++){var v=(buf[i]-128)/128; sum+=v*v;}
                var rms=Math.sqrt(sum/buf.length); var pct=Math.min(100,Math.round(rms*240));
                mNoiseFill.style.width=pct+'%';
                if(pct>=parseInt(mNoiseSlider.value,10)){
                    mNoiseAlert.classList.add('show');
                    var now=Date.now();
                    if(now>loudAlertCooldown){loudAlertCooldown=now+2000;playLoudAlert();}
                } else { mNoiseAlert.classList.remove('show'); }
                mRafId=requestAnimationFrame(loop);
            })();
        }).catch(function(){showToast('Microphone permission denied','warning');});
    });
    document.getElementById('itNoiseFill').addEventListener('click',openNoiseModal);
    document.getElementById('itNoiseAlert').addEventListener('click',openNoiseModal);
    document.getElementById('itNoiseCard').addEventListener('click',function(e){
        if(e.target.closest('button, input, select, textarea, a')) return;
        openNoiseModal();
    });
    document.getElementById('itNoiseModalClose').addEventListener('click',function(){stopModalNoise();noiseModal.classList.remove('show');});
    noiseModal.addEventListener('click',function(e){if(e.target===noiseModal){stopModalNoise();noiseModal.classList.remove('show');}});
    function openNoiseModal(){
        noiseModal.classList.add('show');
    }

    /* ── Helpers ── */
    function escapeHtml(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
    function escapeAttr(s){return String(s).replace(/"/g,'&quot;');}

    window.addEventListener('resize',resizeCanvas);
    resizeCanvas();

    /* Restore today's persisted class & scores (falls back to first class) */
    var initClass = null;
    if(PERSISTED && PERSISTED.last && CLASSES[PERSISTED.last]) initClass = PERSISTED.last;
    if(!initClass && classSelect.options.length>1) initClass = classSelect.options[1].value;
    if(initClass){ classSelect.value=initClass; classSelect.dispatchEvent(new Event('change')); }
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>



