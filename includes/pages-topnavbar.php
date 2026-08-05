<?php
/**
 * Dynamic notification state for the navbar bell.
 * Surfaces only today's notifications, split into Pending Actions, New, and Earlier,
 * using a modern card-based social feed layout (Tailwind utilities).
 */
if (!function_exists('relTime')) {
    function relTime($dt) {
        $ts = strtotime($dt);
        $diff = time() - $ts;
        if ($diff < 60) return 'now';
        if ($diff < 3600) return floor($diff / 60) . 'm';
        if ($diff < 86400) return floor($diff / 3600) . 'h';
        if ($diff < 604800) return floor($diff / 86400) . 'd';
        return floor($diff / 604800) . 'w';
    }
    function initials($name) {
        $parts = explode(' ', trim($name));
        $out = '';
        foreach ($parts as $p) {
            if ($p !== '') { $out .= strtoupper($p[0]); }
            if (strlen($out) >= 2) break;
        }
        return $out ?: '?';
    }
    function render_ntf_card($n, $is_earlier) {
        $unread = empty($n['is_read']) ? '1' : '0';
        $cat = $n['category'] ?? 'system';
        $icon = $cat === 'calendar' ? 'bi-calendar-event' : ($cat === 'attendance' ? 'bi-clipboard-check' : ($cat === 'security' ? 'bi-shield-lock' : ($cat === 'automated' ? 'bi-robot' : 'bi-info-circle')));
        $color = $cat === 'calendar' ? 'bg-blue-100 text-blue-600' : ($cat === 'attendance' ? 'bg-emerald-100 text-emerald-600' : ($cat === 'security' ? 'bg-red-100 text-red-600' : ($cat === 'automated' ? 'bg-purple-100 text-purple-600' : 'bg-gray-100 text-gray-600')));
        $rel = relTime($n['created_at']);
        $role = $_SESSION['user_role'] ?? 'guest';
        $destMap = [
            'calendar' => (match($role) {
                'teacher' => '/teacher/MyCalendar.php',
                'gate'    => '/gate/notifications.php',
                default   => '/admin/MyCalendar.php',
            }),
            'attendance' => (match($role) {
                'teacher' => '/teacher/attendance.php',
                'gate'    => '/gate/logs.php',
                default   => '/admin/attendance.php',
            }),
            'security' => (match($role) {
                'teacher' => '/teacher/notifications.php',
                'gate'    => '/gate/logs.php',
                'admin'   => '/admin/audit-logs.php',
                default   => '/admin/notifications.php',
            }),
            'system' => (match($role) {
                'teacher' => '/teacher/notifications.php',
                'gate'    => '/gate/notifications.php',
                default   => '/admin/notifications.php',
            }),
            'automated' => (match($role) {
                'teacher' => '/teacher/notifications.php',
                'gate'    => '/gate/notifications.php',
                default   => '/admin/notifications.php',
            }),
            'announcement' => (match($role) {
                'teacher' => '/teacher/advisory.php',
                'gate'    => '/gate/notifications.php',
                default   => '/admin/announcements.php',
            }),
        ];
        $dest = $n['destination_url'] ?? $destMap[$cat] ?? '/admin/notifications.php';
        ?>
        <div class="ntf-card flex items-start gap-3 p-3 rounded-xl transition cursor-pointer <?= $is_earlier ? 'bg-gray-50/50 opacity-75 hover:bg-gray-50' : 'bg-white hover:bg-gray-50' ?>" data-unread="<?= (int)$unread ?>" data-type="regular" data-id="<?= (int)($n['id'] ?? 0) ?>" data-destination="<?= htmlspecialchars($dest, ENT_QUOTES) ?>">
            <div class="w-9 h-9 rounded-full <?= $color ?> flex items-center justify-center text-sm flex-shrink-0">
                <i class="bi bi-<?= $icon ?>"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="text-[13px] font-semibold text-gray-800 leading-snug"><?= htmlspecialchars($n['title'] ?? 'Notification') ?></div>
                <?php if (!empty($n['message'])): ?>
                <div class="text-[11px] text-gray-500 mt-0.5 line-clamp-2"><?= htmlspecialchars($n['message']) ?></div>
                <?php endif; ?>
                <div class="text-[10px] text-gray-400 mt-1"><?= htmlspecialchars($rel) ?></div>
            </div>
            <?php if (!$is_earlier && $unread): ?>
            <span class="ntf-dot w-2 h-2 bg-blue-600 rounded-full flex-shrink-0 mt-1.5 transition-opacity"></span>
            <?php endif; ?>
        </div>
        <?php
    }
}

$__unread = 0;
$__feed = [];
$__pending = [];
$__new = [];
$__earlier = [];
$__failedAnns = [];
$__avatarUrl = null;
$__userName = null;
if (isset($db)) {
    $__role = $_SESSION['user_role'] ?? 'guest';
    $__userId = $_SESSION['user_id'] ?? 0;
    if ($__userId) {
        try {
            if ($__role === 'teacher') {
                $stmt = $db->prepare("SELECT first_name FROM teachers WHERE user_id = ? LIMIT 1");
                $stmt->execute([$__userId]);
                $__userName = $stmt->fetchColumn();
            }
        } catch (Exception $e) {}
    }
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM user_notifications WHERE (user_role = ? OR user_role = 'all') AND is_read = 0");
        $stmt->execute([$__role]);
        $__unread = (int)$stmt->fetchColumn();
    } catch (Exception $e) { /* table may not exist yet */ }
    try {
        $stmt = $db->prepare("SELECT * FROM user_notifications WHERE (user_role = ? OR user_role = 'all') ORDER BY created_at DESC, id DESC LIMIT 20");
        $stmt->execute([$__role]);
        $__feed = $stmt->fetchAll() ?: [];
    } catch (Exception $e) { /* table may not exist yet */ }
    foreach ($__feed as $__n) {
        if (($__n['delivery_status'] ?? 'sent') === 'pending') {
            $__pending[] = $__n;
        } elseif (empty($__n['is_read'])) {
            $__new[] = $__n;
        } else {
            $__earlier[] = $__n;
        }
    }
    if (($_SESSION['user_role'] ?? '') === 'admin') {
        try {
            $stmt = $db->prepare("SELECT id, subject, body, recipients, channels, created_at FROM announcements WHERE status = 'failed' ORDER BY created_at DESC LIMIT 5");
            $stmt->execute();
            $__failedAnns = $stmt->fetchAll() ?: [];
        } catch (Exception $e) { /* announcements table may not exist */ }
    }
    if ($__userId) {
        $exts = ['jpg', 'jpeg', 'png', 'webp'];
        foreach ($exts as $ext) {
            $path = ROOT_PATH . '/uploads/avatars/' . $__userId . '.' . $ext;
            if (file_exists($path)) { $__avatarUrl = BASE_URL . '/uploads/avatars/' . $__userId . '.' . $ext; break; }
        }
    }
}
$__userInitial = $__userName ? strtoupper(substr($__userName, 0, 1)) : strtoupper(substr(($_SESSION['user_role'] ?? 'admin'), 0, 1));
?>
<!-- ═══ TOP NAVBAR ═══ -->
<style>
@media(max-width:767px){.navbar-center{display:none!important}}
.ntf-dot{transition:opacity .15s ease}
.ntf-dot.hidden{opacity:0}
.line-clamp-2{display:-webkit-box;-webkit-line-clamp:2;overflow:hidden}
@keyframes ntfSpin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
.spin{animation:ntfSpin 1s linear infinite}
#searchResultsDropdown .search-results-title{color:rgba(255,255,255,0.5)}
#searchResultsDropdown .search-result-item{color:rgba(255,255,255,0.8)}
#searchResultsDropdown .search-result-item:hover{background:rgba(255,255,255,0.08);color:#fff}
#searchResultsDropdown .search-result-meta{color:rgba(255,255,255,0.4)}
#searchResultsDropdown .search-result-empty{color:rgba(255,255,255,0.4)}
#searchResultsDropdown{background:rgba(10,34,76,0.85);backdrop-filter:blur(24px) saturate(1.6);-webkit-backdrop-filter:blur(24px) saturate(1.6);border:1px solid rgba(255,255,255,0.12);box-shadow:0 12px 48px rgba(0,0,0,0.3)}
</style>
<nav class="top-navbar">
    <div class="navbar-left">
        <button class="sidebar-toggle" id="sidebarToggle" title="Toggle sidebar"><i class="bi bi-list"></i></button>
        <a class="navbar-brand" href="<?= BASE_URL ?>/<?= $_SESSION['user_role'] ?? 'admin' ?>/">
            <img src="<?= BASE_URL ?>/assets/images/ldb_logo.webp" alt="Logo" class="brand-logo">
            <div class="brand-info">
                <span class="brand-name">LICEO DE BALENO</span>
                <span class="brand-subtitle">Facial Recognition Attendance System</span>
            </div>
        </a>
    </div>
    <div class="navbar-center">
        <div class="search-box">
            <i class="bi bi-search search-icon"></i>
            <input type="text" placeholder="Search students, teachers, sessions..." id="globalSearchInput" autocomplete="off">
            <kbd class="search-shortcut">/</kbd>
            <div class="search-results-dropdown" id="searchResultsDropdown"></div>
        </div>
    </div>
    <div class="navbar-right">
        <div class="notification-wrapper">
            <button class="nav-icon-btn" id="notificationBell" title="Notifications">
                <i class="bi bi-bell"></i>
                <span class="notification-badge" id="notificationBadge" data-unread="<?= (int)$__unread ?>" style="<?= $__unread > 0 ? '' : 'display:none;' ?>"><?= (int)$__unread ?></span>
            </button>
            <div class="notification-dropdown" id="notificationDropdown" style="max-height:none;background:#ffffff;color:#111827;border:1px solid #e5e7eb;box-shadow:0 10px 40px rgba(0,0,0,.15);">
                <div class="p-4 pb-2">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-base font-bold text-white">Notifications</h3>
                        <div class="flex bg-gray-100 rounded-lg p-0.5">
                            <button class="ntf-tab active px-3 py-1 text-xs font-semibold rounded-md bg-white text-gray-900 shadow-sm" data-tab="all">All</button>
                            <button class="ntf-tab px-3 py-1 text-xs font-semibold rounded-md text-gray-500 hover:text-gray-700" data-tab="unread">Unread</button>
                        </div>
                    </div>
                </div>
                <div class="px-4 pb-2 space-y-3 max-h-[70vh] overflow-y-auto" id="ntfFeed">
                    <?php if (!empty($__pending) && ($_SESSION['user_role'] ?? '') === 'admin'): ?>
                    <div>
                        <div class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-1.5 px-1">Pending Actions</div>
                        <div class="space-y-2">
                            <?php foreach ($__pending as $__pn):
                                $__init = initials($__pn['title'] ?? 'P');
                                $__rel = relTime($__pn['created_at']);
                            ?>
                            <div class="ntf-card ntf-pending flex items-start gap-3 p-3 rounded-xl border border-gray-100 bg-gray-50/70" data-unread="1" data-type="pending">
                                <div class="w-9 h-9 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-xs font-bold flex-shrink-0"><?= htmlspecialchars($__init) ?></div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-[13px] font-semibold text-gray-800 leading-snug"><?= htmlspecialchars($__pn['title'] ?? 'Pending Action') ?></div>
                                    <div class="text-[11px] text-gray-500 mt-0.5 line-clamp-2"><?= htmlspecialchars($__pn['message'] ?? '') ?></div>
                                    <div class="text-[10px] text-gray-400 mt-1"><?= htmlspecialchars($__rel) ?></div>
                                    <div class="flex gap-2 mt-2">
                                        <button class="ntf-approve px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-[11px] font-bold rounded-lg transition" data-id="<?= (int)$__pn['id'] ?>">Confirm</button>
                                        <button class="ntf-decline px-3 py-1.5 bg-gray-200 hover:bg-gray-300 text-gray-700 text-[11px] font-bold rounded-lg transition" data-id="<?= (int)$__pn['id'] ?>">Decline</button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($__failedAnns) && ($_SESSION['user_role'] ?? '') === 'admin'): ?>
                    <div class="ntf-section">
                        <div class="text-[11px] font-bold text-red-400 uppercase tracking-wider mb-1.5 px-1">Delivery Failed</div>
                        <div class="space-y-1">
                            <?php foreach ($__failedAnns as $__fa): ?>
                            <div class="ntf-card ntf-failed flex items-start gap-3 p-3 rounded-xl border border-red-100 bg-red-50/70" data-unread="1" data-type="failed-ann" data-ann-id="<?= (int)$__fa['id'] ?>">
                                <div class="w-9 h-9 rounded-full bg-red-100 text-red-600 flex items-center justify-center text-sm flex-shrink-0">
                                    <i class="bi bi-megaphone"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-[13px] font-semibold text-red-800 leading-snug"><?= htmlspecialchars($__fa['subject'] ?? 'Announcement') ?></div>
                                    <div class="text-[11px] text-red-500 mt-0.5 line-clamp-2">Notification failed to send — recipients may not have received this announcement.</div>
                                    <div class="text-[10px] text-red-400 mt-1"><?= htmlspecialchars(relTime($__fa['created_at'])) ?></div>
                                    <button class="ntf-resend-ann mt-2 px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-[11px] font-bold rounded-lg transition" data-id="<?= (int)$__fa['id'] ?>">
                                        <i class="bi bi-arrow-clockwise me-1"></i>Resend
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($__new) || !empty($__earlier)): ?>
                        <?php if (!empty($__new)): ?>
                        <div class="ntf-section">
                            <div class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-1.5 px-1">New</div>
                            <div class="space-y-1">
                                <?php foreach ($__new as $__n): render_ntf_card($__n, false); endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($__earlier)): ?>
                        <div class="ntf-section">
                            <div class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-1.5 px-1">Earlier</div>
                            <div class="space-y-1">
                                <?php foreach ($__earlier as $__n): render_ntf_card($__n, true); endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center text-gray-400 text-xs py-6">No notifications yet.</div>
                    <?php endif; ?>
                </div>
                <div class="p-3 border-t border-gray-100">
                    <a href="<?= BASE_URL ?>/<?= $_SESSION['user_role'] ?? 'admin' ?>/notifications.php" class="block w-full text-center px-3 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold rounded-xl transition">See previous notifications</a>
                </div>
            </div>
        </div>
        <button class="navbar-profile-compact" id="navbarProfileToggle" title="User menu">
            <div class="navbar-profile-avatar" id="navbarProfileAvatar">
                <img id="navbarProfileAvatarImg" src="<?= $__avatarUrl ? htmlspecialchars($__avatarUrl) : 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'%3E%3Ccircle cx=\'50\' cy=\'50\' r=\'50\' fill=\'url(%23grad)\'/%3E%3Cdefs%3E%3ClinearGradient id=\'grad\' x1=\'0%25\' y1=\'0%25\' x2=\'100%25\' y2=\'100%25\'%3E%3Cstop offset=\'0%25\' style=\'stop-color:%237c3aed;stop-opacity:1\' /%3E%3Cstop offset=\'100%25\' style=\'stop-color:%234f46e5;stop-opacity:1\' /%3E%3C/linearGradient%3E%3C/defs%3E%3Ctext x=\'50\' y=\'60\' text-anchor=\'middle\' font-size=\'45\' font-weight=\'bold\' fill=\'white\' font-family=\'Arial\'%3E<?= $__userInitial ?>%3C/text%3E%3C/svg%3E' ?>" alt="Profile" style="<?= $__avatarUrl ? 'display:block;' : 'display:none;' ?>">
                <span id="navbarProfileAvatarText" style="font-weight:700;<?= $__avatarUrl ? 'display:none;' : '' ?>"><?= htmlspecialchars($__userInitial) ?></span>
                <input type="file" id="navbarAvatarInput" class="navbar-profile-avatar-input" accept="image/*">
            </div>
            <div class="navbar-profile-info">
                <div class="navbar-profile-email"><?= htmlspecialchars(substr($_SESSION['user_email'] ?? 'user@liceodelbal...', 0, 25)) ?></div>
                <div class="navbar-profile-role"><?= ucfirst($_SESSION['user_role'] ?? 'User') ?></div>
            </div>
            <div class="navbar-profile-toggle"><i class="bi bi-chevron-down"></i></div>
        </button>
        <div class="navbar-profile-dropdown" id="navbarProfileDropdown">
            <a href="<?= BASE_URL ?>/<?= $_SESSION['user_role'] ?? 'admin' ?>/profile.php" class="navbar-profile-menu-item"><i class="bi bi-person"></i>My Profile</a>
            <?php $role = $_SESSION['user_role'] ?? 'admin'; ?>
            <?php if ($role === 'admin'): ?>
            <a href="javascript:void(0)" class="navbar-profile-menu-item" id="navbarChangeAvatar"><i class="bi bi-camera"></i>Change Profile Picture</a>
            <?php else: ?>
            <a href="<?= BASE_URL ?>/settings.php" class="navbar-profile-menu-item"><i class="bi bi-gear"></i>Settings</a>
            <?php endif; ?>
            <?php if (file_exists(__DIR__ . '/../' . $role . '/settings.php')): ?>
            <a href="<?= BASE_URL ?>/<?= $role ?>/settings.php" class="navbar-profile-menu-item"><i class="bi bi-gear"></i>Settings</a>
            <?php endif; ?>
            <div class="navbar-profile-menu-divider"></div>
            <a href="<?= BASE_URL ?>/logout.php" class="navbar-profile-menu-item"><i class="bi bi-box-arrow-right"></i>Logout</a>
        </div>
    </div>
</nav>
<script>
(function(){
    var nav = document.querySelector('.top-navbar');
    if(!nav) return;
    function syncNavHeight(){
        var h = nav.offsetHeight;
        document.documentElement.style.setProperty('--actual-navbar-height', h + 'px');
    }
    syncNavHeight();
    window.addEventListener('resize', syncNavHeight);
})();
</script>
<!-- Tailwind Play (preflight disabled so the existing dark theme isn't reset) -->
<script>
if (!document.getElementById('ntf-tailwind-script')) {
    var s = document.createElement('script');
    s.id = 'ntf-tailwind-script';
    s.src = '<?= BASE_URL ?>/assets/vendor/js/tailwind.js';
    s.onload = function () {
        if (window.tailwind) { tailwind.config = { corePlugins: { preflight: false } }; }
    };
    document.head.appendChild(s);
}
(function () {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var base = window.BASE_URL || '';
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.ntf-tab'));
    var cards = Array.prototype.slice.call(document.querySelectorAll('.ntf-card'));
    function applyTab() {
        var active = document.querySelector('.ntf-tab.active');
        var mode = active ? active.dataset.tab : 'all';
        document.querySelectorAll('.ntf-section').forEach(function (s) { s.style.display = ''; });
        document.querySelectorAll('.ntf-card').forEach(function (c) {
            if (mode === 'unread') { c.style.display = (c.dataset.unread === '1') ? '' : 'none'; }
            else { c.style.display = ''; }
        });
        if (mode === 'unread') {
            var any = Array.prototype.slice.call(document.querySelectorAll('.ntf-card')).some(function (c) { return c.style.display !== 'none'; });
            if (!any) {
                var empty = document.querySelector('#ntfFeed .text-center');
                if (!empty) {
                    var wrap = document.getElementById('ntfFeed');
                    var msg = document.createElement('div');
                    msg.className = 'text-center text-gray-400 text-xs py-6';
                    msg.textContent = 'No unread notifications.';
                    if (wrap) wrap.appendChild(msg);
                }
            }
        }
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            tabs.forEach(function (x) { x.classList.remove('active', 'bg-white', 'text-gray-900', 'shadow-sm'); x.classList.add('text-gray-500'); });
            t.classList.add('active', 'bg-white', 'text-gray-900', 'shadow-sm'); t.classList.remove('text-gray-500');
            applyTab();
        });
    });
    // Dot removal on hover/click for unread regular cards
    document.querySelectorAll('.ntf-card').forEach(function (card) {
        card.addEventListener('mouseenter', function () {
            var dot = card.querySelector('.ntf-dot');
            if (dot) dot.classList.add('hidden');
        });
        card.addEventListener('mouseleave', function () {
            var dot = card.querySelector('.ntf-dot');
            if (dot && card.dataset.unread === '1') dot.classList.remove('hidden');
        });
        card.addEventListener('click', function (e) {
            if (e.target.closest('.ntf-approve') || e.target.closest('.ntf-decline')) return;
            var id = card.dataset.id;
            var dest = card.dataset.destination;
            function navigateToDest() {
                if (dest) window.location.href = base + dest;
            }
            if (id) {
                fetch(base + '/notification_handler.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ action: 'mark_read_one', id: id })
                }).then(function () { navigateToDest(); }).catch(function () { navigateToDest(); });
            } else {
                navigateToDest();
            }
        });
    });
    // Approve / Decline
    document.querySelectorAll('.ntf-approve, .ntf-decline').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var id = this.dataset.id;
            var action = this.classList.contains('ntf-approve') ? 'approve_notif' : 'decline_notif';
            this.disabled = true; this.textContent = '...';
            fetch(base + '/notification_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ action: action, id: id })
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success) {
                    var card = btn.closest('.ntf-card');
                    if (card) { card.style.opacity = '0'; card.style.transform = 'translateX(8px)'; setTimeout(function () { card.remove(); }, 180); }
                } else {
                    btn.disabled = false;
                    btn.textContent = btn.classList.contains('ntf-approve') ? 'Confirm' : 'Decline';
                }
            })
            .catch(function () { btn.disabled = false; btn.textContent = btn.classList.contains('ntf-approve') ? 'Confirm' : 'Decline'; });
        });
    });

    // Resend Failed Announcement (navbar bell)
    document.querySelectorAll('.ntf-resend-ann').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var id = this.dataset.id;
            var card = this.closest('.ntf-card');
            this.disabled = true;
            this.innerHTML = '<i class="bi bi-arrow-clockwise spin me-1"></i>Sending...';
            var fd = new FormData();
            fd.append('action', 'resend');
            fd.append('id', id);
            if (csrf) fd.append('csrf_token', csrf);
            fetch(base + '/api/announcements.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success) {
                    if (card) { card.style.opacity = '0'; card.style.transform = 'translateX(8px)'; setTimeout(function () { card.remove(); }, 200); }
                    var badge = document.getElementById('notificationBadge');
                    if (badge) {
                        var cur = parseInt(badge.dataset.unread || '0') - 1;
                        badge.dataset.unread = cur;
                        badge.textContent = cur;
                        if (cur <= 0) badge.style.display = 'none';
                    }
                } else {
                    btn.disabled = false;
                    showAlertModal((res && res.error) ? res.error : 'Resend failed. Please try again.', { title: 'Resend Failed', icon: 'exclamation-circle-fill', type: 'danger' });
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Resend';
            });
        });
    });
})();
</script>
<script>
(function(){
    var navAvatar = document.getElementById('navbarProfileAvatar');
    var navInput = document.getElementById('navbarAvatarInput');
    var navImg = document.getElementById('navbarProfileAvatarImg');
    var navText = document.getElementById('navbarProfileAvatarText');
    var changeAvatarBtn = document.getElementById('navbarChangeAvatar');
    if(!navAvatar||!navInput)return;

    navAvatar.addEventListener('click',function(e){
        e.preventDefault();
        e.stopPropagation();
        navInput.click();
    });

    if(changeAvatarBtn){
        changeAvatarBtn.addEventListener('click',function(e){
            e.preventDefault();
            e.stopPropagation();
            navInput.click();
        });
    }

    navInput.addEventListener('change',function(e){
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
            if(navImg){ navImg.src = dataUrl; navImg.style.display = 'block'; }
            if(navText){ navText.style.display = 'none'; }
            var formData = new FormData();
            formData.append('action','upload');
            formData.append('image',file);
            var csrf = document.querySelector('meta[name="csrf-token"]');
            if(csrf) formData.append('csrf_token', csrf.getAttribute('content'));
            fetch(window.BASE_URL+'/api/avatar.php',{method:'POST',credentials:'same-origin',body:formData})
            .then(function(res){return res.json()})
            .then(function(data){
                if(data && data.success){
                    showToast(data.message || 'Profile picture updated','success');
                    if(navImg){ navImg.src = data.avatar_url; navImg.style.display = 'block'; }
                    if(navText){ navText.style.display = 'none'; }
                    var pageImg = document.getElementById('profilePageAvatarImg');
                    var pageText = document.getElementById('profilePageAvatarText');
                    if(pageImg){ pageImg.src = data.avatar_url; pageImg.style.display = 'block'; }
                    if(pageText){ pageText.style.display = 'none'; }
                } else {
                    showToast(data && data.error ? data.error : 'Upload failed','danger');
                }
            })
            .catch(function(err){ console.error('Avatar upload error:',err); showToast('Upload failed','danger'); });
        };
        reader.readAsDataURL(file);
    });
})();
</script>
<script>
(function(){
    var input = document.getElementById('globalSearchInput');
    var dropdown = document.getElementById('searchResultsDropdown');
    if (!input || !dropdown) return;

    var base = window.BASE_URL || '';
    var debounceTimer = null;
    var activeRequest = null;

    function showDropdown() { dropdown.classList.add('show'); }
    function hideDropdown() { dropdown.classList.remove('show'); dropdown.innerHTML = ''; }

    input.addEventListener('input', function(){
        var q = input.value.trim();
        if (debounceTimer) clearTimeout(debounceTimer);
        if (q.length < 3) { hideDropdown(); return; }
        debounceTimer = setTimeout(function(){
            if (activeRequest) activeRequest.abort();
            var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            activeRequest = controller;
            fetch(base + '/global_search_handler.php?q=' + encodeURIComponent(q), {
                credentials: 'same-origin',
                signal: controller ? controller.signal : undefined
            })
            .then(function(r){ return r.text(); })
            .then(function(html){
                if (html.trim() === '') {
                    dropdown.innerHTML = '<div class="search-result-empty"><i class="bi bi-search"></i><span>No results found</span></div>';
                } else {
                    dropdown.innerHTML = html;
                }
                showDropdown();
            })
            .catch(function(err){
                if (err.name !== 'AbortError') {
                    dropdown.innerHTML = '<div class="search-result-empty"><i class="bi bi-exclamation-triangle"></i><span>Search failed. Try again.</span></div>';
                    showDropdown();
                }
            });
        }, 280);
    });

    input.addEventListener('keydown', function(e){
        if (e.key === 'Escape') { hideDropdown(); input.blur(); }
    });

    input.addEventListener('focus', function(){
        if (input.value.trim().length >= 3 && dropdown.innerHTML.trim() !== '') {
            showDropdown();
        }
    });

    document.addEventListener('click', function(e){
        if (!e.target.closest('.search-box')) hideDropdown();
    });
})();
</script>
