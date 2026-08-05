<?php
/**
 * LDB-FRAS - Global Search Handler
 * Returns HTML fragments for navbar search dropdown
 * Role-scoped comprehensive search across all system entities
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/session.php';

header('Content-Type: text/html; charset=UTF-8');

if (!isLoggedIn()) {
    http_response_code(401);
    exit('Unauthorized');
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if ($q === '' || mb_strlen($q) < 3) {
    exit;
}

$role = $_SESSION['user_role'] ?? 'guest';
$userId = $_SESSION['user_id'] ?? 0;
$searchPattern = '%' . $q . '%';
$baseUrl = BASE_URL;
$encodedQ = urlencode($q);

$students = [];
$teachers = [];
$subjects = [];
$sessions = [];
$announcements = [];
$auditLogs = [];
$strands = [];

// ─── HELPER: esc ─────────────────────────────────────────────────────────────
function esc($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function sectionLabel($s) {
    $parts = array_filter([$s['grade_level'] ?? '', $s['section'] ?? '']);
    return implode(' - ', $parts) ?: 'N/A';
}

// ═══════════════════════════════════════════════════════════════════════════════
// STUDENTS — role-scoped
// ═══════════════════════════════════════════════════════════════════════════════

if ($role === 'admin') {
    // Admin: all students, no restriction
    try {
        $stmt = $db->prepare("
            SELECT s.id, s.student_id, s.first_name, s.last_name, s.grade_level, s.section
            FROM students s
            WHERE s.first_name LIKE :q1
               OR s.last_name LIKE :q2
               OR s.student_id LIKE :q3
            ORDER BY s.last_name ASC, s.first_name ASC
            LIMIT 6
        ");
        $stmt->execute([':q1' => $searchPattern, ':q2' => $searchPattern, ':q3' => $searchPattern]);
        $students = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('Global search students error: ' . $e->getMessage());
    }

} elseif ($role === 'teacher') {
    // Teacher: only students matching handled grade/section or advisory class
    try {
        $teacherRec = null;
        $stmt = $db->prepare("SELECT id, grade_section_handled, advisory_class FROM teachers WHERE user_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$userId]);
        $teacherRec = $stmt->fetch();

        if ($teacherRec) {
            $gradeSections = json_decode($teacherRec['grade_section_handled'] ?? '[]', true) ?: [];
            $advisory = trim($teacherRec['advisory_class'] ?? '');

            $gradeConditions = [];
            $gradeParams = [];

            foreach ($gradeSections as $gs) {
                $gl = $gs['grade_level'] ?? $gs['grade'] ?? '';
                $sec = $gs['section'] ?? '';
                if ($gl !== '' && $sec !== '') {
                    $gradeConditions[] = "(s.grade_level = :gl_{$gl}_{$sec} AND s.section = :sec_{$gl}_{$sec})";
                    $gradeParams[":gl_{$gl}_{$sec}"] = $gl;
                    $gradeParams[":sec_{$gl}_{$sec}"] = $sec;
                } elseif ($gl !== '') {
                    $gradeConditions[] = "s.grade_level = :gl_{$gl}";
                    $gradeParams[":gl_{$gl}"] = $gl;
                }
            }

            $sql = "SELECT s.id, s.student_id, s.first_name, s.last_name, s.grade_level, s.section
                    FROM students s
                    WHERE (s.first_name LIKE :q1 OR s.last_name LIKE :q2 OR s.student_id LIKE :q3)";

            $sqlParams = [':q1' => $searchPattern, ':q2' => $searchPattern, ':q3' => $searchPattern];

            if (!empty($gradeConditions)) {
                $sql .= " AND (" . implode(' OR ', $gradeConditions) . ")";
                $sqlParams = array_merge($sqlParams, $gradeParams);
            } elseif ($advisory !== '') {
                $sql .= " AND CONCAT(s.grade_level, s.section) LIKE :advisory";
                $sqlParams[':advisory'] = '%' . $advisory . '%';
            }

            $sql .= " ORDER BY s.last_name ASC, s.first_name ASC LIMIT 6";
            $stmt = $db->prepare($sql);
            $stmt->execute($sqlParams);
            $students = $stmt->fetchAll();
        }
    } catch (Exception $e) {
        error_log('Global search teacher students error: ' . $e->getMessage());
    }

} elseif ($role === 'gate') {
    // Gate: scan active students for security validation
    try {
        $stmt = $db->prepare("
            SELECT s.id, s.student_id, s.first_name, s.last_name, s.grade_level, s.section
            FROM students s
            WHERE s.first_name LIKE :q1
               OR s.last_name LIKE :q2
               OR s.student_id LIKE :q3
            ORDER BY s.last_name ASC, s.first_name ASC
            LIMIT 6
        ");
        $stmt->execute([':q1' => $searchPattern, ':q2' => $searchPattern, ':q3' => $searchPattern]);
        $students = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('Global search gate students error: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// TEACHERS / FACULTY — admin sees all, gate scans for security validation
// ═══════════════════════════════════════════════════════════════════════════════

if ($role === 'admin') {
    try {
        $stmt = $db->prepare("
            SELECT t.id, t.first_name, t.last_name, t.department, t.employee_id
            FROM teachers t
            WHERE t.first_name LIKE :q1
               OR t.last_name LIKE :q2
               OR t.employee_id LIKE :q3
            ORDER BY t.last_name ASC, t.first_name ASC
            LIMIT 5
        ");
        $stmt->execute([':q1' => $searchPattern, ':q2' => $searchPattern, ':q3' => $searchPattern]);
        $teachers = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('Global search teachers error: ' . $e->getMessage());
    }

} elseif ($role === 'gate') {
    // Gate: scan faculty for quick security validation
    try {
        $stmt = $db->prepare("
            SELECT t.id, t.first_name, t.last_name, t.department, t.employee_id
            FROM teachers t
            WHERE t.first_name LIKE :q1
               OR t.last_name LIKE :q2
               OR t.employee_id LIKE :q3
            ORDER BY t.last_name ASC, t.first_name ASC
            LIMIT 4
        ");
        $stmt->execute([':q1' => $searchPattern, ':q2' => $searchPattern, ':q3' => $searchPattern]);
        $teachers = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('Global search gate faculty error: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// SUBJECTS (admin only)
// ═══════════════════════════════════════════════════════════════════════════════

if ($role === 'admin') {
    try {
        $stmt = $db->prepare("\
            SELECT sub.id, sub.subject_name, sub.grade_level\
            FROM subjects sub\
            WHERE sub.subject_name LIKE :q1\
            ORDER BY sub.subject_name ASC\
            LIMIT 5\
        ");
        $stmt->execute([':q1' => $searchPattern]);
        $subjects = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('Global search subjects error: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ATTENDANCE SESSIONS (role-specific)
// ═══════════════════════════════════════════════════════════════════════════════

if (in_array($role, ['admin', 'teacher'])) {
    try {
        $stmt = $db->prepare("
            SELECT ses.id, ses.session_type, ses.status, sub.subject_name, ses.start_time
            FROM attendance_sessions ses
            LEFT JOIN subjects sub ON ses.subject_id = sub.id
            WHERE sub.subject_name LIKE :q1
               OR ses.status LIKE :q2
            ORDER BY ses.start_time DESC
            LIMIT 5
        ");
        $stmt->execute([':q1' => $searchPattern, ':q2' => $searchPattern]);
        $sessions = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('Global search sessions error: ' . $e->getMessage());
    }
} elseif ($role === 'gate') {
    try {
        $stmt = $db->prepare("
            SELECT gs.id, gs.session_type, gs.status, gs.start_time
            FROM gate_sessions gs
            WHERE gs.session_type LIKE :q1
               OR gs.status LIKE :q2
            ORDER BY gs.start_time DESC
            LIMIT 5
        ");
        $stmt->execute([':q1' => $searchPattern, ':q2' => $searchPattern]);
        $sessions = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('Global search gate sessions error: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ANNOUNCEMENTS (admin only)
// ═══════════════════════════════════════════════════════════════════════════════

if ($role === 'admin') {
    try {
        $stmt = $db->prepare("
            SELECT a.id, a.subject, a.status, a.created_at
            FROM announcements a
            WHERE a.subject LIKE :q1
               OR a.body LIKE :q2
            ORDER BY a.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([':q1' => $searchPattern, ':q2' => $searchPattern]);
        $announcements = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('Global search announcements error: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// AUDIT LOGS (admin only)
// ═══════════════════════════════════════════════════════════════════════════════

if ($role === 'admin') {
    try {
        $stmt = $db->prepare("
            SELECT al.id, al.action, al.description, al.created_at, u.email
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.description LIKE :q1
               OR al.action LIKE :q2
               OR u.email LIKE :q3
            ORDER BY al.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([':q1' => $searchPattern, ':q2' => $searchPattern, ':q3' => $searchPattern]);
        $auditLogs = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('Global search audit logs error: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// STRANDS / ELECTIVES (admin only)
// ═══════════════════════════════════════════════════════════════════════════════

if ($role === 'admin') {
    try {
        $stmt = $db->prepare("
            SELECT s.id, s.strand_name, s.strand_code, s.description
            FROM strands s
            WHERE s.strand_name LIKE :q1
               OR s.strand_code LIKE :q2
               OR s.description LIKE :q3
            ORDER BY s.strand_name ASC
            LIMIT 5
        ");
        $stmt->execute([':q1' => $searchPattern, ':q2' => $searchPattern, ':q3' => $searchPattern]);
        $strands = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('Global search strands error: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// RENDER HTML
// ═══════════════════════════════════════════════════════════════════════════════

$hasResults = !empty($students) || !empty($teachers) || !empty($subjects)
           || !empty($sessions) || !empty($announcements) || !empty($auditLogs)
           || !empty($strands);
?>

<?php if ($hasResults): ?>

    <?php if (!empty($students)): ?>
    <div class="search-results-header">
        <span class="search-results-title">Students</span>
    </div>
    <?php foreach ($students as $s):
        if ($role === 'admin') {
            $studentLink = $baseUrl . '/admin/student-edit.php?id=' . (int)$s['id'] . '&q=' . $encodedQ;
        } elseif ($role === 'teacher') {
            $studentLink = $baseUrl . '/teacher/records.php?student_id=' . (int)$s['id'];
        } else {
            $studentLink = $baseUrl . '/gate/logs.php?verify_lrn=' . urlencode($s['student_id']);
        }
    ?>
    <a href="<?= $studentLink ?>" class="search-result-item">
        <div class="search-result-icon student">
            <i class="bi bi-people"></i>
        </div>
        <div class="search-result-body">
            <div class="search-result-title"><?= esc($s['last_name'] . ', ' . $s['first_name']) ?></div>
            <div class="search-result-meta"><?= esc($s['student_id']) ?> · <?= esc(sectionLabel($s)) ?></div>
        </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($teachers)): ?>
    <div class="search-results-header">
        <span class="search-results-title">Faculty</span>
    </div>
    <?php foreach ($teachers as $t):
        if ($role === 'admin') {
            $teacherLink = $baseUrl . '/admin/teachers.php?q=' . $encodedQ;
        } else {
            $teacherLink = $baseUrl . '/gate/logs.php?verify_lrn=' . urlencode($t['employee_id'] ?? '');
        }
    ?>
    <a href="<?= $teacherLink ?>" class="search-result-item">
        <div class="search-result-icon teacher">
            <i class="bi bi-person-badge"></i>
        </div>
        <div class="search-result-body">
            <div class="search-result-title"><?= esc($t['last_name'] . ', ' . $t['first_name']) ?></div>
            <div class="search-result-meta"><?= esc($t['department'] ?? 'Faculty') ?> <?= !empty($t['employee_id']) ? '· ' . esc($t['employee_id']) : '' ?></div>
        </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($subjects)): ?>
    <div class="search-results-header">
        <span class="search-results-title">Subjects</span>
    </div>
    <?php foreach ($subjects as $sub): ?>
    <a href="<?= $baseUrl ?>/admin/subjects.php?q=<?= $encodedQ ?>" class="search-result-item">
        <div class="search-result-icon subject">
            <i class="bi bi-book"></i>
        </div>
        <div class="search-result-body">
            <div class="search-result-title"><?= esc($sub['subject_name']) ?></div>
            <div class="search-result-meta">Grade <?= esc($sub['grade_level']) ?></div>
        </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($sessions)): ?>
    <div class="search-results-header">
        <span class="search-results-title">Sessions</span>
    </div>
    <?php foreach ($sessions as $ses):
        if ($role === 'gate') {
            $sessionLink = $baseUrl . '/gate/logs.php?search=' . $encodedQ;
        } elseif ($role === 'teacher') {
            $sessionLink = $baseUrl . '/teacher/records.php?q=' . $encodedQ;
        } else {
            $sessionLink = $baseUrl . '/admin/attendance.php?q=' . $encodedQ;
        }
    ?>
    <a href="<?= $sessionLink ?>" class="search-result-item">
        <div class="search-result-icon session">
            <i class="bi bi-calendar-check"></i>
        </div>
        <div class="search-result-body">
            <div class="search-result-title"><?= esc($ses['subject_name'] ?? ($ses['session_type'] ?? 'Session')) ?></div>
            <div class="search-result-meta"><?= esc(ucfirst($ses['status'] ?? '')) ?> · <?= esc(ucfirst($ses['session_type'] ?? '')) ?></div>
        </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($announcements)): ?>
    <div class="search-results-header">
        <span class="search-results-title">Announcements</span>
    </div>
    <?php foreach ($announcements as $a): ?>
    <a href="<?= $baseUrl ?>/admin/announcements.php?q=<?= $encodedQ ?>" class="search-result-item">
        <div class="search-result-icon announcement">
            <i class="bi bi-megaphone"></i>
        </div>
        <div class="search-result-body">
            <div class="search-result-title"><?= esc($a['subject']) ?></div>
            <div class="search-result-meta"><?= esc(ucfirst($a['status'] ?? '')) ?> · <?= esc(date('M j, Y', strtotime($a['created_at']))) ?></div>
        </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($auditLogs)): ?>
    <div class="search-results-header">
        <span class="search-results-title">Audit Logs</span>
    </div>
    <?php foreach ($auditLogs as $log): ?>
    <a href="<?= $baseUrl ?>/admin/audit-logs.php?search=<?= urlencode($log['action'] ?? $q) ?>" class="search-result-item">
        <div class="search-result-icon audit">
            <i class="bi bi-shield-lock"></i>
        </div>
        <div class="search-result-body">
            <div class="search-result-title"><?= esc($log['action'] ?? 'Log') ?></div>
            <div class="search-result-meta"><?= esc($log['description'] ?? '') ?></div>
        </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($strands)): ?>
    <div class="search-results-header">
        <span class="search-results-title">Electives</span>
    </div>
    <?php foreach ($strands as $str): ?>
    <a href="<?= $baseUrl ?>/admin/strands.php?q=<?= $encodedQ ?>" class="search-result-item">
        <div class="search-result-icon strand">
            <i class="bi bi-grid-1x2"></i>
        </div>
        <div class="search-result-body">
            <div class="search-result-title"><?= esc($str['strand_name']) ?></div>
            <div class="search-result-meta"><?= esc($str['strand_code']) ?></div>
        </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

<?php else: ?>
    <div class="search-results-header">
        <span class="search-results-title">Results</span>
    </div>
    <div class="search-result-empty">
        <i class="bi bi-search" style="font-size:20px;opacity:0.6"></i>
        <span>No results found</span>
    </div>
<?php endif; ?>
