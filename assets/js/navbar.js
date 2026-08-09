(function() {
    'use strict';

    window.LDB = window.LDB || {};

    LDB.navbar = {
        role: '<?= $_SESSION["user_role"] ?? "guest" ?>',
        baseUrl: window.BASE_URL || '',

        reminders: {
            admin: [
                { icon: 'bi-people', title: 'New student registrations pending', meta: '2 awaiting approval', type: 'reminder', url: '<?= BASE_URL ?>/admin/students.php' },
                { icon: 'bi-shield-check', title: 'System audit log requires review', meta: '5 new entries', type: 'meeting', url: '<?= BASE_URL ?>/admin/audit-logs.php' },
                { icon: 'bi-calendar-x', title: 'Attendance deadline approaching', meta: 'Due in 2 days', type: 'deadline', url: '<?= BASE_URL ?>/admin/attendance.php' },
                { icon: 'bi-person-badge', title: 'Teacher documents incomplete', meta: '3 teachers need update', type: 'reminder', url: '<?= BASE_URL ?>/admin/teachers.php' }
            ],
            teacher: [
                { icon: 'bi-calendar-check', title: 'Attendance session scheduled today', meta: 'Grade 10 - A', type: 'meeting', url: '<?= BASE_URL ?>/teacher/attendance.php' },
                { icon: 'bi-file-earmark-text', title: 'Reports due this week', meta: 'Generate attendance report', type: 'deadline', url: '<?= BASE_URL ?>/teacher/reports.php' },
                { icon: 'bi-book', title: 'New subject assigned', meta: 'Check your subjects', type: 'reminder', url: '<?= BASE_URL ?>/teacher/index.php' },
                { icon: 'bi-bell', title: 'School announcement posted', meta: 'Read latest updates', type: 'general', url: '<?= BASE_URL ?>/teacher/index.php' }
            ],
            gate: [
                { icon: 'bi-box-arrow-in-right', title: 'Morning time-in session active', meta: 'Start scanning students', type: 'meeting', url: '<?= BASE_URL ?>/gate/timein.php' },
                { icon: 'bi-box-arrow-left', title: 'Afternoon time-out pending', meta: 'Schedule at 3:00 PM', type: 'reminder', url: '<?= BASE_URL ?>/gate/timeout.php' },
                { icon: 'bi-people', title: 'Low attendance detected today', meta: 'Only 12 students scanned', type: 'deadline', url: '<?= BASE_URL ?>/gate/logs.php' },
                { icon: 'bi-shield-lock', title: 'Gate session expiring soon', meta: 'Renew within 30 mins', type: 'general', url: '<?= BASE_URL ?>/gate/register.php' }
            ]
        },

        init: function() {
            this.sidebarToggle();
            this.searchBox();
            this.notificationBell();
            this.profileDropdown();
            this.refNotif();
            this.soundToggle();
            this.pollNotifs();
        },

        sidebarToggle: function() {
            var toggle = document.getElementById('sidebarToggle');
            var sidebar = document.querySelector('.sidebar');
            var overlay = document.querySelector('.sidebar-overlay');
            if (!toggle || !sidebar) return;
            if (!overlay) { overlay = document.createElement('div'); overlay.className = 'sidebar-overlay'; document.body.appendChild(overlay); }
            function isMobile() { return window.innerWidth <= 767; }
            function openSidebar() { sidebar.classList.add('mobile-open'); overlay.classList.add('mobile-active'); document.body.style.overflow = 'hidden'; }
            function closeSidebar() { sidebar.classList.remove('mobile-open'); overlay.classList.remove('mobile-active'); document.body.style.overflow = ''; }
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (isMobile()) {
                    sidebar.classList.contains('mobile-open') ? closeSidebar() : openSidebar();
                } else {
                    document.body.classList.toggle('sidebar-collapsed');
                }
            });
            overlay.addEventListener('click', closeSidebar);
            sidebar.addEventListener('click', function(e) { if (isMobile() && (e.target.tagName === 'A' || e.target.closest('a'))) setTimeout(closeSidebar, 150); });
            document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && sidebar.classList.contains('mobile-open')) closeSidebar(); });
            window.addEventListener('resize', function() { if (!isMobile()) closeSidebar(); });
        },

        searchBox: function() {
            var input = document.getElementById('globalSearchInput');
            var dropdown = document.getElementById('searchResultsDropdown');
            if (!input || !dropdown) return;

            var debounceTimer;
            input.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                var query = this.value.trim();
                if (query.length < 3) { dropdown.innerHTML = ''; dropdown.classList.remove('show'); return; }
                debounceTimer = setTimeout(function() { LDB.navbar.performSearch(query); }, 250);
            });
            input.addEventListener('focus', function() {
                var q = input.value.trim();
                if (q.length >= 3) dropdown.classList.add('show');
            });
            document.addEventListener('click', function(e) {
                if (!input.contains(e.target) && !dropdown.contains(e.target)) dropdown.classList.remove('show');
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === '/' && !e.ctrlKey && !e.metaKey && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
                    e.preventDefault(); input.focus();
                }
                if (e.key === 'Escape') { dropdown.classList.remove('show'); input.blur(); }
            });
        },

        performSearch: function(query) {
            var dropdown = document.getElementById('searchResultsDropdown');
            if (!dropdown) return;
            dropdown.innerHTML = '<div class="search-results-header"><span class="search-results-title">Searching...</span></div><div class="search-result-empty"><i class="bi bi-arrow-repeat spin"></i><span>Searching...</span></div>';
            dropdown.classList.add('show');

            fetch(LDB.navbar.baseUrl + '/global_search_handler.php?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    dropdown.innerHTML = html;
                    dropdown.classList.add('show');
                })
                .catch(function() {
                    dropdown.classList.remove('show');
                });
        },

        notificationBell: function() {
            var bell = document.getElementById('notificationBell');
            var dropdown = document.getElementById('notificationDropdown');
            if (!bell || !dropdown) return;

            // The badge count and recent items are rendered server-side
            // (pages-topnavbar.php) from the user_notifications table, so we
            // only handle the open/close toggle here.
            bell.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdown.classList.toggle('show');
            });
            document.addEventListener('click', function(e) {
                if (!bell.contains(e.target) && !dropdown.contains(e.target)) dropdown.classList.remove('show');
            });
        },

        profileDropdown: function() {
            var toggle = document.getElementById('navbarProfileToggle');
            var dropdown = document.getElementById('navbarProfileDropdown');
            if (!toggle || !dropdown) return;
            toggle.addEventListener('click', function(e) {
                e.stopPropagation();
                dropdown.classList.toggle('active');
            });
            var menuItems = dropdown.querySelectorAll('.navbar-profile-menu-item');
            menuItems.forEach(function(item) {
                item.addEventListener('click', function(e) {
                    e.stopPropagation();
                    dropdown.classList.remove('active');
                });
            });
            document.addEventListener('click', function(e) {
                if (!toggle.contains(e.target) && !dropdown.contains(e.target)) {
                    dropdown.classList.remove('active');
                }
            });
        },

        escHtml: function(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        },

        // Fetch upcoming calendar events and append them to the bell dropdown
        // feed on EVERY page (not just MyCalendar). Role scoping is handled
        // server-side by notification_handler.php?ajax_action=get_upcoming.
        refNotif: function() {
            var feed = document.getElementById('ntfFeed');
            if (!feed) return;
            fetch(LDB.navbar.baseUrl + '/notification_handler.php?ajax_action=get_upcoming', { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (!d || !d.success || !d.events) return;
                    var existing = feed.querySelector('.ntf-cal-section');
                    if (existing) existing.remove();
                    var events = d.events;
                    if (!events.length) return;

                    var todayStr = new Date().toISOString().slice(0, 10);
                    var tmrStr = new Date(Date.now() + 86400000).toISOString().slice(0, 10);

                    var wrap = document.createElement('div');
                    wrap.className = 'ntf-cal-section';
                    var title = document.createElement('div');
                    title.className = 'text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-1.5 px-1 mt-1';
                    title.innerHTML = '<i class="bi bi-calendar-event me-1"></i>Upcoming Events';
                    wrap.appendChild(title);

                    var list = document.createElement('div');
                    list.className = 'space-y-1';

                    events.forEach(function(e) {
                        var ed = new Date(e.event_date + 'T00:00:00');
                        var dateStr = e.event_date === todayStr ? 'Today' : (e.event_date === tmrStr ? 'Tomorrow' : ed.toLocaleDateString('en', { month: 'short', day: 'numeric' }));
                        var timeStr = 'All day';
                        if (e.event_time) {
                            var p = String(e.event_time).split(':'), h = parseInt(p[0], 10), m = p[1], ap = h >= 12 ? 'PM' : 'AM';
                            if (h > 12) h -= 12; if (h === 0) h = 12;
                            timeStr = h + ':' + m + ' ' + ap;
                        }
                        var card = document.createElement('div');
                        card.className = 'ntf-card flex items-start gap-3 p-3 rounded-xl transition bg-white hover:bg-gray-50 border border-gray-100';
                        card.setAttribute('data-unread', '0');
                        card.setAttribute('data-type', 'regular');
                        card.innerHTML =
                            '<div class="w-9 h-9 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-sm flex-shrink-0"><i class="bi bi-calendar-event"></i></div>' +
                            '<div class="flex-1 min-w-0">' +
                                '<div class="text-[13px] font-semibold text-gray-800 leading-snug">' + LDB.navbar.escHtml(e.title) + '</div>' +
                                (e.description ? '<div class="text-[11px] text-gray-500 mt-0.5 line-clamp-2">' + LDB.navbar.escHtml(e.description) + '</div>' : '') +
                                '<div class="text-[10px] text-gray-400 mt-1"><i class="bi bi-calendar-event me-1"></i>' + dateStr + ' <i class="bi bi-clock ms-2 me-1"></i>' + timeStr + '</div>' +
                            '</div>';
                        list.appendChild(card);
                    });
                    wrap.appendChild(list);
                    feed.appendChild(wrap);

                    // Remove any "No notifications yet." placeholder now that we have events
                    var empty = feed.querySelector('.text-center');
                    if (empty && feed.querySelectorAll('.ntf-card').length) empty.remove();
                })
                .catch(function() {});
        },

        soundEnabled: function() {
            try { return localStorage.getItem('ldb_notif_sound') !== 'off'; } catch (e) { return true; }
        },

        toggleSound: function() {
            var on = !this.soundEnabled();
            try { localStorage.setItem('ldb_notif_sound', on ? 'on' : 'off'); } catch (e) {}
            this.updateSoundIcon();
            return on;
        },

        updateSoundIcon: function() {
            var btn = document.getElementById('notifSoundToggle');
            if (!btn) return;
            var on = this.soundEnabled();
            btn.innerHTML = on
                ? '<i class="bi bi-volume-up-fill text-sm"></i>'
                : '<i class="bi bi-volume-mute-fill text-sm"></i>';
            btn.classList.toggle('text-gray-500', on);
            btn.classList.toggle('text-gray-400', !on);
        },

        soundToggle: function() {
            var btn = document.getElementById('notifSoundToggle');
            if (!btn) return;
            var self = this;
            this.updateSoundIcon();
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var on = self.toggleSound();
                showToast('Notification sound ' + (on ? 'enabled' : 'muted'), on ? 'success' : 'warning', 1800);
            });
        },

        playNotificationSound: function() {
            if (!this.soundEnabled()) return;
            try {
                var Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                var ctx = new Ctx();
                var now = ctx.currentTime;
                [0, 0.18, 0.36].forEach(function(offset, i) {
                    var osc = ctx.createOscillator();
                    var gain = ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.value = i === 1 ? 880 : 660;
                    gain.gain.setValueAtTime(0.0001, now + offset);
                    gain.gain.exponentialRampToValueAtTime(0.3, now + offset + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.0001, now + offset + 0.25);
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.start(now + offset);
                    osc.stop(now + offset + 0.28);
                });
                setTimeout(function() { ctx.close(); }, 1500);
            } catch (e) {}
        },

        showNotifToast: function(n) {
            if (!n) return;
            var dest = n.destination_url || '';
            var icon = n.category === 'calendar' ? 'bi-calendar-event' : 'bi-bell-fill';
            var toast = document.createElement('div');
            toast.setAttribute('role', 'alert');
            toast.style.cssText = 'position:fixed;top:14px;right:14px;z-index:99999;max-width:340px;background:rgba(10,34,76,0.95);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,0.15);box-shadow:0 12px 40px rgba(0,0,0,0.35);color:#fff;border-radius:14px;padding:14px 16px;cursor:pointer;opacity:0;transform:translateX(24px);transition:opacity .25s ease,transform .25s ease;';
            toast.innerHTML =
                '<div class="flex items-start gap-3">' +
                '<div class="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center text-sm flex-shrink-0"><i class="bi ' + icon + '"></i></div>' +
                '<div class="flex-1 min-w-0">' +
                '<div class="text-[13px] font-bold leading-snug">' + this.escHtml(n.title || 'Notification') + '</div>' +
                (n.message ? '<div class="text-[11px] opacity-80 mt-0.5 line-clamp-2">' + this.escHtml(n.message) + '</div>' : '') +
                '</div>' +
                '</div>';
            toast.addEventListener('click', function() {
                if (dest) window.location.href = LDB.navbar.baseUrl + dest;
            });
            document.body.appendChild(toast);
            requestAnimationFrame(function() {
                toast.style.opacity = '1';
                toast.style.transform = 'translateX(0)';
            });
            setTimeout(function() {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(24px)';
                setTimeout(function() { toast.remove(); }, 300);
            }, 6000);
        },

        // Poll for new notifications on every page; when a calendar event
        // reaches its set time, the server materializes it and it arrives here
        // as the latest notification (sound + toast + badge update).
        pollNotifs: function() {
            var feed = document.getElementById('ntfFeed');
            if (!feed) return;
            var lastId = parseInt(feed.getAttribute('data-last-notif-id') || '0', 10) || 0;
            var self = this;
            function tick() {
                fetch(self.baseUrl + '/notification_handler.php?ajax_action=get_feed', { credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (!d || !d.success) return;
                        var notifs = d.notifications || [];
                        var badge = document.getElementById('notificationBadge');
                        if (badge) {
                            badge.setAttribute('data-unread', d.unread);
                            badge.textContent = d.unread;
                            badge.style.display = d.unread > 0 ? '' : 'none';
                        }
                        var newOnes = notifs.filter(function(n) {
                            return n && (parseInt(n.id, 10) || 0) > lastId;
                        });
                        if (newOnes.length) {
                            self.playNotificationSound();
                            self.showNotifToast(newOnes[0]);
                        }
                        if (notifs.length) {
                            lastId = notifs.reduce(function(m, n) { return Math.max(m, parseInt(n.id, 10) || 0); }, lastId);
                        }
                    })
                    .catch(function() {});
            }
            tick();
            setInterval(tick, 30000);
        }
    };

    document.addEventListener('DOMContentLoaded', function() { LDB.navbar.init(); });
})();