<?php
/**
 * LDB-FRAS — Shared In-App Notification Center (role-scoped)
 *
 * This partial renders the advanced notification dashboard used by
 * admin/notifications.php, gate/notifications.php and teacher/notifications.php.
 * It bootstraps the `user_notifications` table, seeds demonstration data on
 * first run, and renders the dual-column, role-filtered dashboard using the
 * same dark "frosted glass" design system as the rest of the pages.
 *
 * It depends on $db (PDO) and the active session being available.
 */

if (!isset($db)) {
    return;
}

$currentRole = getCurrentUserRole() ?: 'guest';
$userId      = getCurrentUserId();

/* ───────────────────────────────────────────────────────────────────────────
 * 1. Ensure the notification tracking table exists
 * ─────────────────────────────────────────────────────────────────────────── */
$db->exec("
    CREATE TABLE IF NOT EXISTS user_notifications (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        user_role        VARCHAR(20)  NOT NULL DEFAULT 'all',
        category         VARCHAR(30)  NOT NULL DEFAULT 'system',
        title            VARCHAR(255) NOT NULL,
        message          TEXT,
        delivery_status  ENUM('pending','sent','failed') NOT NULL DEFAULT 'sent',
        retry_count      INT NOT NULL DEFAULT 0,
        calendar_event_id INT NULL DEFAULT NULL,
        reference_id     INT NULL DEFAULT NULL,
        destination_url  VARCHAR(500) NULL DEFAULT NULL,
        is_read          TINYINT(1) NOT NULL DEFAULT 0,
        created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_role    (user_role),
        INDEX idx_category(category),
        INDEX idx_status  (delivery_status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
try { $db->exec("ALTER TABLE user_notifications ADD COLUMN IF NOT EXISTS destination_url VARCHAR(500) NULL DEFAULT NULL"); } catch (Exception $e) {}

/* ───────────────────────────────────────────────────────────────────────────
 * 2. Seed demonstration notifications (only when the table is empty)
 * ─────────────────────────────────────────────────────────────────────────── */
if (!function_exists('seedNotifications')) {
    function seedNotifications($db) {
        $seed = [
            'admin' => [
                ['calendar',   'Faculty General Assembly',            'Monthly faculty meeting in the Audio-Visual Room.',                  'sent',     null],
                ['calendar',   'Quarterly Parents\' Conference',       'Automated reminder broadcast for parents.',                         'failed',   1],
                ['attendance', 'Attendance deadline approaching',      '3 sections are below the 80% compliance threshold.',               'sent',     null],
                ['attendance', 'Registration: pending approvals',      '2 new student registrations await admin verification.',            'sent',     null],
                ['security',   'Audit log requires review',            '5 new privileged actions were recorded in the audit trail.',       'sent',     null],
                ['security',   'New sign-in from unknown device',      'An admin session originated from an unrecognized IP address.',     'sent',     null],
                ['automated',  'SMS Quota Monitor',                    'System automatically monitors SMS quota levels and alerts when low.', 'sent', null],
            ],
            'teacher' => [
                ['calendar',   'Advisory Period Rescheduled',          'Automated advisory reminder broadcast.',                            'failed',   2],
                ['calendar',   'Department Meeting',                   'Science department sync after classes.',                            'sent',     null],
                ['attendance', '3-Day Absence Flag: Juan Dela Cruz',   'Advisory student marked absent for 3 consecutive days.',            'sent',     null],
                ['attendance', 'Milestone: Section Apollo @ 95%',      'Your advisory reached the monthly attendance milestone.',          'sent',     null],
                ['security',   'Grade sheet export downloaded',        'A report export was triggered from your account.',                 'sent',     null],
                ['security',   'Pending Manual Override — Section B',  'Manual attendance override request for Section B pending approval.', 'pending', null],
            ],
            'gate' => [
                ['calendar',   'Gate Duty Rotation Updated',           'New weekly gate duty assignment published.',                       'sent',     null],
                ['security',   'Kiosk Device #2 Disconnected',         'Registration kiosk lost network connectivity.',                     'failed',   null],
                ['security',   'Unauthorized Scanning Attempt',        'Multiple rejected scans detected at the Main Gate.',               'sent',     null],
                ['attendance', 'Low Attendance Detected',              'Only 12 students scanned in the current window.',                  'sent',     null],
                ['security',   'Gate Session Expiring',                'Your active gate session expires in 30 minutes.',                  'sent',     null],
                ['security',   'Pending Manual Override — Gate Session','Gate session extension request pending supervisor approval.',      'pending', null],
            ],
        ];

        $stmt = $db->prepare(
            "INSERT INTO user_notifications
             (user_role, category, title, message, delivery_status, calendar_event_id, is_read, destination_url)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?)"
        );

        $seedDestMap = [
            'calendar' => '/admin/MyCalendar.php',
            'attendance' => '/admin/attendance.php',
            'security' => '/admin/audit-logs.php',
            'automated' => '/admin/notifications.php',
        ];

        foreach ($seed as $role => $rows) {
            foreach ($rows as $r) {
                $dest = $seedDestMap[$r[0]] ?? '/admin/notifications.php';
                $stmt->execute([$role, $r[0], $r[1], $r[2], $r[3], $r[4], $dest]);
            }
        }
    }
}

try {
    $countStmt = $db->query("SELECT COUNT(*) FROM user_notifications");
    if ((int)$countStmt->fetchColumn() === 0) {
        seedNotifications($db);
    }
} catch (Exception $e) {
    error_log('notification_center seed: ' . $e->getMessage());
}

/* ───────────────────────────────────────────────────────────────────────────
 * 3. Fetch notifications scoped to the logged-in role (+ global "all")
 * ─────────────────────────────────────────────────────────────────────────── */
$allNotifs = [];
try {
    $stmt = $db->prepare(
        "SELECT * FROM user_notifications
         WHERE user_role = ? OR user_role = 'all'
         ORDER BY created_at DESC, id DESC"
    );
    $stmt->execute([$currentRole]);
    $allNotifs = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('notification_center fetch: ' . $e->getMessage());
}

// Per-category counts for the filter badges.
$countAll = count($allNotifs);
$countCal = 0; $countAtt = 0; $countSec = 0; $countAuto = 0;
foreach ($allNotifs as $n) {
    $c = $n['category'] ?? 'system';
    if ($c === 'calendar') $countCal++;
    elseif ($c === 'attendance') $countAtt++;
    elseif ($c === 'security' || $c === 'system') $countSec++;
    elseif ($c === 'automated') $countAuto++;
}

/* ───────────────────────────────────────────────────────────────────────────
 * 4. Fetch notification dispatch history (moved from SMS settings)
 * ─────────────────────────────────────────────────────────────────────────── */
$notifLogs = [];
$notifLogTotal = 0;
$notifLogPage = max(1, (int)($_GET['log_page'] ?? 1));
$notifLogPerPage = 10;
try {
    $countStmt = $db->query("SELECT COUNT(*) FROM notification_logs");
    $notifLogTotal = (int)$countStmt->fetchColumn();
    $offset = ($notifLogPage - 1) * $notifLogPerPage;
    $logStmt = $db->prepare("SELECT nl.*, s.first_name, s.last_name FROM notification_logs nl LEFT JOIN students s ON nl.student_id = s.id ORDER BY nl.created_at DESC LIMIT :limit OFFSET :offset");
    $logStmt->bindValue(':limit', $notifLogPerPage, PDO::PARAM_INT);
    $logStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $logStmt->execute();
    $notifLogs = $logStmt->fetchAll();
} catch (Exception $e) {}

/* ───────────────────────────────────────────────────────────────────────────
 * 5. SMS Quota Low Warning — auto-insert admin notification when ≤ 10 left
 * ─────────────────────────────────────────────────────────────────────────── */
try {
    $qStmt = $db->query("SELECT sms_subscription_tier, sms_daily_count FROM system_notification_config ORDER BY id ASC LIMIT 1");
    $qRow = $qStmt->fetch();
    if ($qRow && ($qRow['sms_subscription_tier'] ?? 'free_50') === 'free_50') {
        $remaining = 50 - (int)($qRow['sms_daily_count'] ?? 0);
        if ($remaining <= 10 && $remaining >= 0) {
            $warnTitle = 'SMS Quota Low — ' . $remaining . ' message' . ($remaining !== 1 ? 's' : '') . ' remaining';
            $warnMsg = 'Your daily SMS quota is almost exhausted (' . (int)($qRow['sms_daily_count'] ?? 0) . '/50 used). SMS notifications will stop once the quota is reached. Consider upgrading your subscription plan.';
            $checkStmt = $db->prepare("SELECT id FROM user_notifications WHERE category = 'automated' AND title = ? AND DATE(created_at) = CURDATE() LIMIT 1");
            $checkStmt->execute([$warnTitle]);
            if (!$checkStmt->fetch()) {
                $insStmt = $db->prepare("INSERT INTO user_notifications (user_role, category, title, message, delivery_status, is_read, destination_url) VALUES ('admin', 'automated', ?, ?, 'sent', 0, '/admin/notifications.php')");
                $insStmt->execute([$warnTitle, $warnMsg]);
            }
        }
    }
} catch (Exception $e) {}

/* ───────────────────────────────────────────────────────────────────────────
 * 6. Destination URL mapping for notification categories
 * ─────────────────────────────────────────────────────────────────────────── */
$destinationMap = [
    'calendar'   => '/admin/MyCalendar.php',
    'attendance' => '/admin/attendance.php',
    'security'   => '/admin/audit-logs.php',
    'system'     => '/admin/notifications.php',
    'automated'  => '/admin/notifications.php',
];

$catMeta = [
    'calendar'            => ['label' => 'Calendar Event',      'icon' => 'bi-calendar-event'],
    'attendance'          => ['label' => 'Attendance Highlight', 'icon' => 'bi-clipboard-check'],
    'security'            => ['label' => 'System Security',     'icon' => 'bi-shield-lock'],
    'system'              => ['label' => 'System',              'icon' => 'bi-gear'],
    'automated'           => ['label' => 'Automated Notification', 'icon' => 'bi-robot'],
];
$statusMeta = [
    'sent'     => 'db-sent',
    'pending'  => 'db-pending',
    'failed'   => 'db-failed',
];
$failedMessage = '🔴 Delivery Failed: Automated calendar reminder failed to send to targeted users due to a network or gateway connection timeout.';
?>
<!-- ═══ NOTIFICATION CENTER (theme-matched to the rest of the pages) ═══ -->
<style>
    .notif-dash { display: flex; flex-direction: column; gap: 20px; }
    @media (min-width: 768px) { .notif-dash { flex-direction: row; align-items: flex-start; } }

    .notif-filters { flex-shrink: 0; width: 100%; }
    @media (min-width: 768px) { .notif-filters { width: 240px; position: sticky; top: 88px; } }

    .notif-filter-list { display: flex; flex-direction: column; gap: 8px; }
    .notif-tab {
        display: flex; align-items: center; gap: 10px; width: 100%; text-align: left;
        padding: 12px 16px; border-radius: 10px;
        background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08);
        color: rgba(255,255,255,0.7); font-size: 13px; font-weight: 600;
        cursor: pointer; font-family: var(--pg-font); transition: all .2s ease;
    }
    .notif-tab i { font-size: 15px; opacity: .7; }
    .notif-tab:hover { background: rgba(255,255,255,0.09); color: #fff; }
    .notif-tab.active {
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        border-color: transparent; color: #fff;
        box-shadow: 0 2px 12px rgba(79,70,229,.3);
    }
    .notif-tab.active i { opacity: 1; }
    .notif-tab .count {
        margin-left: auto; font-size: 11px; font-weight: 700;
        background: rgba(255,255,255,.1); padding: 2px 9px; border-radius: 20px;
        font-family: var(--pg-mono);
    }
    .notif-tab.active .count { background: rgba(255,255,255,.2); }

    .notif-main { flex: 1; min-width: 0; width: 100%; }
    .notif-main-head {
        display: flex; flex-wrap: wrap; gap: 12px;
        align-items: center; justify-content: space-between; margin-bottom: 16px;
    }
    .notif-main-head h1 { font-size: 20px; font-weight: 800; letter-spacing: -.03em; margin: 0; color: #fff; }
    .notif-main-head .sub { font-size: 12px; color: rgba(255,255,255,.5); margin-top: 2px; }
    .notif-tools { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .notif-search { position: relative; }
    .notif-search i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 13px; color: rgba(255,255,255,.4); }
    .notif-search input {
        height: 38px; padding: 0 12px 0 34px; width: 200px;
        background: rgba(255,255,255,.06); border: 1.5px solid rgba(255,255,255,.08);
        border-radius: 10px; color: #fff; font-size: 13px; font-family: var(--pg-font);
    }
    .notif-search input:focus { outline: none; border-color: var(--pg-primary); box-shadow: 0 0 0 3px var(--pg-primary-glow); background: rgba(255,255,255,.09); }
    .notif-search input::placeholder { color: rgba(255,255,255,.35); }

    .notif-cards { display: flex; flex-direction: column; gap: 10px; }
    .notif-card {
        display: flex; gap: 14px; padding: 16px; border-radius: 12px;
        background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.07);
        transition: all .2s ease; position: relative; overflow: hidden;
    }
    .notif-card:hover { background: rgba(255,255,255,.08); border-color: rgba(255,255,255,.14); }
    .notif-card.unread { border-color: rgba(79,70,229,.4); box-shadow: 0 0 0 1px rgba(79,70,229,.25); }
    .notif-card.failed { background: rgba(239,68,68,.08); border-color: rgba(239,68,68,.35); border-left: 4px solid var(--pg-danger); }

    .nic { width: 42px; height: 42px; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
    .nic.calendar   { background: rgba(59,130,246,.14);  color: #60A5FA; }
    .nic.attendance { background: rgba(16,185,129,.14);  color: #34D399; }
    .nic.security   { background: rgba(239,68,68,.14);   color: #F87171; }
    .nic.system     { background: rgba(245,158,11,.14);  color: #FBBF24; }
    .nic.automated  { background: rgba(168,85,247,.14);  color: #A855F7; }

    .nic-body { flex: 1; min-width: 0; }
    .nic-title { font-size: 14px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .nic-msg { font-size: 13px; color: rgba(255,255,255,.6); margin-top: 6px; line-height: 1.5; }
    .notif-card.failed .nic-msg { color: #FCA5A5; }
    .nic-meta { font-size: 11px; color: rgba(255,255,255,.4); margin-top: 8px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .nic-actions { margin-top: 10px; }

    .delivery-badge { font-size: 10px; font-weight: 700; padding: 3px 10px; border-radius: 20px; text-transform: uppercase; letter-spacing: .04em; }
    .db-sent    { background: rgba(16,185,129,.15);  color: #34D399; }
    .db-pending { background: rgba(245,158,11,.15);  color: #FBBF24; }
    .db-failed  { background: rgba(239,68,68,.18);   color: #F87171; }

    .sent-ok { color: #34D399; font-weight: 700; font-size: 12px; margin-top: 8px; }

    .btn-resend {
        display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;
        border: none; border-radius: 10px; font-size: 12px; font-weight: 700; color: #fff;
        background: linear-gradient(135deg, #ef4444, #dc2626);
        box-shadow: 0 2px 12px rgba(239,68,68,.3); cursor: pointer;
        font-family: var(--pg-font); transition: all .2s ease;
    }
    .btn-resend:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(239,68,68,.4); }
    .btn-resend:disabled { opacity: .7; cursor: default; transform: none; }

    .spin { animation: notifSpin 1s linear infinite; }
    @keyframes notifSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

    /* ── Dispatch History container ── */
    #dispatchHistorySection { display: none; }
    #dispatchHistorySection.dispatch-visible { display: block; }
    #dispatchHistorySection .dispatch-table-wrap {
        overflow-x: auto; -webkit-overflow-scrolling: touch;
        scrollbar-width: thin; scrollbar-color: rgba(255,255,255,.12) transparent;
    }
    #dispatchHistorySection .dispatch-table-wrap::-webkit-scrollbar { height: 6px; }
    #dispatchHistorySection .dispatch-table-wrap::-webkit-scrollbar-track { background: transparent; }
    #dispatchHistorySection .dispatch-table-wrap::-webkit-scrollbar-thumb { background: rgba(255,255,255,.12); border-radius: 10px; }
    #dispatchHistorySection table { min-width: 760px; }
    #dispatchHistorySection th, #dispatchHistorySection td { white-space: nowrap; }
</style>

<div class="page-title d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
    <div>
        <h5 class="mb-0">Notifications</h5>
        <small>Role-based system alerts &amp; reminders</small>
    </div>
</div>

<div class="notif-dash">
    <!-- ── LEFT: Category filter navigation ── -->
    <aside class="notif-filters">
        <div class="card">
            <div class="card-header"><span><i class="bi bi-funnel"></i> Filters</span></div>
            <div class="card-body">
                <nav class="notif-filter-list">
                    <button class="notif-tab active" data-filter="all"><i class="bi bi-bell"></i> All Notifications <span class="count"><?= $countAll ?></span></button>
                    <button class="notif-tab" data-filter="calendar"><i class="bi bi-calendar-event"></i> Calendar Events <span class="count"><?= $countCal ?></span></button>
                    <button class="notif-tab" data-filter="attendance"><i class="bi bi-clipboard-check"></i> Attendance Highlights <span class="count"><?= $countAtt ?></span></button>
                    <button class="notif-tab" data-filter="security"><i class="bi bi-shield-lock"></i> System Security <span class="count"><?= $countSec ?></span></button>
                    <button class="notif-tab" data-filter="automated"><i class="bi bi-robot"></i> Automated Notification <span class="count"><?= $countAuto ?></span></button>
                </nav>
            </div>
        </div>
    </aside>

    <!-- ── RIGHT: Header + notification cards ── -->
    <section class="notif-main">
        <div class="card">
            <div class="card-header d-block">
                <div class="notif-main-head">
                    <div>
                        <h1>System Notifications</h1>
                        <div class="sub" id="notifSubtitle">Viewing as <span class="fw-semibold text-capitalize"><?= htmlspecialchars($currentRole) ?></span> &middot; <?= $countAll ?> total</div>
                    </div>
                    <div class="notif-tools">
                        <div class="notif-search">
                            <i class="bi bi-search"></i>
                            <input id="notifSearch" type="text" placeholder="Search notifications...">
                        </div>
                        <button id="markAllRead" class="btn-ad-primary"><i class="bi bi-check2-all"></i> Mark All as Read</button>
                        <button id="exportLogs" class="btn-ad-outline"><i class="bi bi-download"></i> Export Logs</button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div id="notifCards" class="notif-cards">
                    <?php if (empty($allNotifs)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="bi bi-bell-slash"></i></div>
                            <h6>No Notifications</h6>
                            <p>You have no notifications yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($allNotifs as $n):
                            $cat    = $n['category'] ?? 'system';
                            $status = $n['delivery_status'] ?? 'sent';
                            $isFail = ($status === 'failed');
                            $meta   = $catMeta[$cat] ?? $catMeta['system'];
                            $sClass = $statusMeta[$status] ?? $statusMeta['sent'];
                            $title  = $n['title'] ?? 'Notification';
                            $date   = function_exists('formatDateTime') ? formatDateTime($n['created_at'] ?? '') : ($n['created_at'] ?? '');
                            $msg    = $isFail ? $failedMessage : ($n['message'] ?? '');
                            $search = strtolower($title . ' ' . strip_tags($msg) . ' ' . $meta['label']);
                            $unread = empty($n['is_read']) ? ' unread' : '';
                            $cardClass = 'notif-card' . $unread . ($isFail ? ' failed' : '');
                        ?>
                            <div class="<?= $cardClass ?>"
                                 data-id="<?= (int)$n['id'] ?>" data-category="<?= htmlspecialchars($cat) ?>"
                                 data-status="<?= htmlspecialchars($status) ?>" data-title="<?= htmlspecialchars($title, ENT_QUOTES) ?>"
                                 data-search="<?= htmlspecialchars($search, ENT_QUOTES) ?>" data-date="<?= htmlspecialchars($n['created_at'] ?? '', ENT_QUOTES) ?>"
                                 data-reference-id="<?= (int)($n['reference_id'] ?? 0) ?>"
                                 data-destination="<?= htmlspecialchars($destinationMap[$cat] ?? '/admin/notifications.php', ENT_QUOTES) ?>">
                                <div class="nic <?= $cat === 'automated' ? 'automated' : ($cat === 'attendance' ? 'attendance' : ($cat === 'security' ? 'security' : ($cat === 'calendar' ? 'calendar' : 'system'))) ?>">
                                    <i class="bi <?= $meta['icon'] ?>"></i>
                                </div>
                                <div class="nic-body">
                                    <div class="nic-title">
                                        <?= htmlspecialchars($title) ?>
                                        <span class="delivery-badge <?= $sClass ?>"><?= htmlspecialchars($status) ?></span>
                                    </div>
                                    <div class="nic-msg notif-msg"><?= htmlspecialchars($msg) ?></div>
                                    <div class="nic-meta">
                                        <span><i class="bi bi-tag me-1"></i><?= htmlspecialchars($meta['label']) ?></span>
                                        <span><i class="bi bi-clock me-1"></i><?= htmlspecialchars($date) ?></span>
                                        <?php if (!empty($n['retry_count'])): ?><span><i class="bi bi-arrow-repeat me-1"></i>Retries: <?= (int)$n['retry_count'] ?></span><?php endif; ?>
                                    </div>
                                    <?php if ($isFail && $cat !== 'calendar'): ?>
                                        <div class="nic-actions">
                                            <button class="resend-btn btn-resend" data-id="<?= (int)$n['id'] ?>"><i class="bi bi-arrow-clockwise"></i> Resend Notification</button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ═══ DISPATCH HISTORY (only visible under Automated filter) ═══ -->
        <div id="dispatchHistorySection" class="card" style="margin-top:16px;">
            <div class="card-header"><span style="display:inline-flex;align-items:center;gap:6px;"><i class="bi bi-clock-history"></i> Notification Dispatch History</span></div>
            <div class="card-body" style="padding:0;">
                <div class="dispatch-table-wrap">
                    <table style="width:100%;border-collapse:collapse;color:var(--set-text);font-size:13px;">
                        <thead>
                            <tr style="border-bottom:1px solid var(--set-border);background:rgba(255,255,255,0.02);">
                                <th style="padding:14px 20px;font-weight:700;color:var(--set-text-secondary);font-size:11px;text-transform:uppercase;letter-spacing:0.06em;text-align:left;">Timestamp</th>
                                <th style="padding:14px 20px;font-weight:700;color:var(--set-text-secondary);font-size:11px;text-transform:uppercase;letter-spacing:0.06em;text-align:left;">Student</th>
                                <th style="padding:14px 20px;font-weight:700;color:var(--set-text-secondary);font-size:11px;text-transform:uppercase;letter-spacing:0.06em;text-align:left;">Event</th>
                                <th style="padding:14px 20px;font-weight:700;color:var(--set-text-secondary);font-size:11px;text-transform:uppercase;letter-spacing:0.06em;text-align:left;">Channel</th>
                                <th style="padding:14px 20px;font-weight:700;color:var(--set-text-secondary);font-size:11px;text-transform:uppercase;letter-spacing:0.06em;text-align:left;">Recipient</th>
                                <th style="padding:14px 20px;font-weight:700;color:var(--set-text-secondary);font-size:11px;text-transform:uppercase;letter-spacing:0.06em;text-align:left;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($notifLogs)): ?>
                            <tr><td colspan="6" style="padding:32px 20px;text-align:center;color:var(--set-text-muted);font-size:13px;font-weight:500;">No dispatch logs yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($notifLogs as $log): ?>
                                <tr style="border-bottom:1px solid rgba(255,255,255,0.03);transition:all 0.15s ease;cursor:default;">
                                    <td style="padding:12px 20px;font-family:var(--set-mono);font-size:12px;color:var(--set-text-secondary);"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($log['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td style="padding:12px 20px;color:var(--set-text);font-weight:600;"><?= htmlspecialchars(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td style="padding:12px 20px;color:var(--set-text);font-weight:600;"><?= htmlspecialchars($log['trigger_event'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td style="padding:12px 20px;">
                                        <span style="padding:5px 12px;border-radius:8px;font-size:11px;font-weight:700;background:rgba(6,182,212,0.15);color:#22d3ee;border:1px solid rgba(6,182,212,0.2);display:inline-block;"><?= htmlspecialchars($log['channel'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td style="padding:12px 20px;font-family:var(--set-mono);font-size:12px;color:var(--set-text-secondary);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($log['recipient_contact'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($log['recipient_contact'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td style="padding:12px 20px;">
                                        <?php
                                            $statusColor = match($log['delivery_status']) {
                                                'SUCCESS' => 'rgba(16,185,129,0.15);color:#34d399;border:1px solid rgba(16,185,129,0.25);',
                                                'FAILED' => 'rgba(239,68,68,0.15);color:#f87171;border:1px solid rgba(239,68,68,0.25);',
                                                'QUOTA_EXCEEDED' => 'rgba(245,158,11,0.15);color:#fbbf24;border:1px solid rgba(245,158,11,0.25);',
                                                default => 'rgba(255,255,255,0.06);color:var(--set-text-muted);border:1px solid rgba(255,255,255,0.06);'
                                            };
                                        ?>
                                        <span style="padding:5px 12px;border-radius:8px;font-size:11px;font-weight:700;background:<?= $statusColor ?>display:inline-block;"><?= htmlspecialchars($log['delivery_status'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($notifLogTotal > $notifLogPerPage): ?>
                <div style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--set-border);background:rgba(255,255,255,0.01);">
                    <span style="font-size:12px;color:var(--set-text-muted);font-weight:500;">Showing <?= ($notifLogPage - 1) * $notifLogPerPage + 1 ?>-<?= min($notifLogPage * $notifLogPerPage, $notifLogTotal) ?> of <?= $notifLogTotal ?></span>
                    <div style="display:flex;gap:8px;">
                        <?php if ($notifLogPage > 1): ?>
                            <a href="?log_page=<?= $notifLogPage - 1 ?>" style="padding:8px 16px;border-radius:8px;font-size:12px;font-weight:700;background:var(--set-surface);color:var(--set-text-secondary);text-decoration:none;border:1px solid var(--set-border);transition:all 0.2s;display:inline-flex;align-items:center;gap:6px;">Previous</a>
                        <?php endif; ?>
                        <?php if ($notifLogPage * $notifLogPerPage < $notifLogTotal): ?>
                            <a href="?log_page=<?= $notifLogPage + 1 ?>" style="padding:8px 16px;border-radius:8px;font-size:12px;font-weight:700;background:var(--set-primary);color:#fff;text-decoration:none;border:1px solid var(--set-primary);transition:all 0.2s;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 8px rgba(79,70,229,0.3);">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </section>
</div>

<script>
(function () {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var base = window.BASE_URL || '';

    var tabs  = Array.prototype.slice.call(document.querySelectorAll('.notif-tab'));
    var cards = Array.prototype.slice.call(document.querySelectorAll('.notif-card'));
    var searchInput = document.getElementById('notifSearch');
    var dispatchSection = document.getElementById('dispatchHistorySection');
    var subtitle = document.getElementById('notifSubtitle');
    var roleLabel = '<?= htmlspecialchars($currentRole) ?>';

    function applyFilter() {
        var active = document.querySelector('.notif-tab.active');
        var f = active ? active.dataset.filter : 'all';
        var q = (searchInput.value || '').toLowerCase();
        var visibleCount = 0;
        cards.forEach(function (c) {
            var cat = c.dataset.category;
            var matchCat = f === 'all' ||
                (f === 'security' ? (cat === 'security' || cat === 'system') : (f === 'automated' ? cat === 'automated' : cat === f));
            var hay = (c.dataset.search || '').toLowerCase();
            var matchQ = !q || hay.indexOf(q) !== -1;
            var show = matchCat && matchQ;
            c.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });
        if (dispatchSection) {
            if (f === 'automated') { dispatchSection.classList.add('dispatch-visible'); }
            else { dispatchSection.classList.remove('dispatch-visible'); }
        }
        if (subtitle) {
            var label = active ? active.textContent.replace(/\d+/g, '').trim() : 'All';
            subtitle.innerHTML = 'Viewing as <span class="fw-semibold text-capitalize">' + roleLabel + '</span> &middot; ' + visibleCount + ' notification' + (visibleCount !== 1 ? 's' : '');
        }
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            tabs.forEach(function (x) { x.classList.remove('active'); });
            t.classList.add('active');
            applyFilter();
        });
    });
    if (searchInput) searchInput.addEventListener('input', applyFilter);

    /* ── Click notification card → navigate to destination ── */
    cards.forEach(function (c) {
        c.addEventListener('click', function (e) {
            if (e.target.closest('.resend-btn')) return;
            var dest = c.dataset.destination;
            if (dest) {
                var notifId = c.dataset.id;
                if (notifId) {
                    fetch(base + '/notification_handler.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                        body: JSON.stringify({ action: 'mark_read_one', id: notifId })
                    }).then(function () { window.location.href = base + dest; }).catch(function () { window.location.href = base + dest; });
                } else {
                    window.location.href = base + dest;
                }
            }
        });
    });

    function updateBadge(n) {
        var b = document.getElementById('notificationBadge');
        if (!b) return;
        n = parseInt(n, 10) || 0;
        if (n > 0) { b.textContent = n; b.style.display = ''; }
        else { b.style.display = 'none'; }
    }

    /* ── Resend (Failed Delivery Recovery) ── */
    document.querySelectorAll('.resend-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var card = this.closest('.notif-card');
            var refId = card ? card.dataset.referenceId || card.dataset.reference_id : null;
            var notifId = this.dataset.id;
            if (!refId) {
                showAlertModal('Unable to locate the original announcement. Please resend from the Announcements page.', { title: 'Cannot Resend', icon: 'exclamation-circle-fill', type: 'warning' });
                return;
            }
            window.location.href = base + '/admin/announcements.php?resend=' + encodeURIComponent(refId);
        });
    });

    /* ── Mark All as Read ── */
    var markBtn = document.getElementById('markAllRead');
    if (markBtn) {
        markBtn.addEventListener('click', function () {
            this.disabled = true;
            fetch(base + '/notification_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ action: 'mark_all_read' })
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success) {
                    document.querySelectorAll('.notif-card.unread').forEach(function (c) { c.classList.remove('unread'); });
                    updateBadge(0);
                }
                markBtn.disabled = false;
            })
            .catch(function () { markBtn.disabled = false; });
        });
    }

    /* ── Export Logs (CSV) ── */
    var exportBtn = document.getElementById('exportLogs');
    if (exportBtn) {
        exportBtn.addEventListener('click', function () {
            var csv = 'Category,Title,Message,Status,Date\n';
            cards.forEach(function (c) {
                if (c.style.display === 'none') return;
                var cat = (c.dataset.category || '').replace(/"/g, '""');
                var title = (c.dataset.title || '').replace(/"/g, '""');
                var msg = ((c.querySelector('.notif-msg') || {}).textContent || '').replace(/"/g, '""').replace(/\s+/g, ' ');
                var status = (c.dataset.status || '').replace(/"/g, '""');
                var date = (c.dataset.date || '').replace(/"/g, '""');
                csv += '"' + cat + '","' + title + '","' + msg + '","' + status + '","' + date + '"\n';
            });
            var blob = new Blob([csv], { type: 'text/csv' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url; a.download = 'notification_logs.csv';
            document.body.appendChild(a); a.click(); a.remove();
            URL.revokeObjectURL(url);
        });
    }
})();
</script>
