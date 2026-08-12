/* LDB-FRAS Offline Face Recognition + Background Sync
 * ======================================================
 * Responsibilities:
 *   1. Load face-api.js models (SSD-MobileNetV1 + 68-landmark + recognition)
 *      from local assets/models/ so NO CDN is needed at runtime.
 *   2. Sync the student roster (with 128-d face descriptors) into IndexedDB
 *      while online so matching can happen entirely on-device when offline.
 *   3. Provide localScan() - an offline substitute for the server-side
 *      recognize_attendance flow that matches against cached descriptors.
 *   4. Background-sync queued offline attendance logs to Railway when the
 *      browser comes back online (POST api/sync_offline_attendance.php).
 *
 * Dependencies: assets/vendor/face-api/face-api.min.js,
 *               assets/js/offline-db.js  (window.LDB_Offline_Attendance_DB)
 */
(function () {
    'use strict';

    const MATCH_THRESHOLD = 0.55;
    const ROSTER_URL = (window.BASE_URL || '') + '/api/students.php?action=offline_roster';
    const SYNC_URL = (window.BASE_URL || '') + '/api/sync_offline_attendance.php';

    const state = {
        modelsReady: false,
        modelLoading: null,
        rosterReady: false,
        offline: typeof navigator !== 'undefined' ? !navigator.onLine : false,
        syncing: false
    };

    function getTf() {
        return (window.faceapi && window.faceapi.tf) || window.tf || null;
    }

    /* ---------------- Model lifecycle ---------------- */

    function ensureModels() {
        if (state.modelsReady) return Promise.resolve(true);
        if (state.modelLoading) return state.modelLoading;
        if (!window.faceapi) {
            return Promise.reject(new Error('face-api.js not loaded'));
        }

        state.modelLoading = (async function load() {
            const tf = getTf();
            if (tf && tf.setBackend) {
                try {
                    if (tf.getBackend() !== 'webgl') {
                        await tf.setBackend('webgl');
                    }
                } catch (e) {
                    try { await tf.setBackend('cpu'); } catch (e2) { /* ignore */ }
                }
            }

            const modelUrl = (window.BASE_URL || '') + '/assets/models';
            await Promise.all([
                window.faceapi.nets.ssdMobilenetv1.loadFromUri(modelUrl),
                window.faceapi.nets.faceLandmark68Net.loadFromUri(modelUrl),
                window.faceapi.nets.faceRecognitionNet.loadFromUri(modelUrl)
            ]);
            state.modelsReady = true;
            return true;
        })();
        return state.modelLoading;
    }

    function modelsReady() {
        return state.modelsReady;
    }

    /* ---------------- Roster sync (online) ---------------- */

    async function loadRoster() {
        try {
            const res = await fetch(ROSTER_URL, { credentials: 'same-origin' });
            if (!res.ok) throw new Error('roster fetch ' + res.status);
            const data = await res.json();
            if (!data.success || !Array.isArray(data.students)) {
                throw new Error('roster payload invalid');
            }
            const students = data.students
                .filter(function (s) {
                    return Array.isArray(s.face_descriptor) && s.face_descriptor.length === 128;
                })
                .map(function (s) {
                    return {
                        id: s.id,
                        lrn: String(s.student_id),
                        full_name: (s.first_name || '') + ' ' + (s.last_name || ''),
                        grade_level: s.grade_level,
                        section: s.section || '',
                        face_descriptor: s.face_descriptor.slice()
                    };
                });
            if (students.length) {
                await LDB_Offline_Attendance_DB.bulkUpsertStudents(students);
            }
            state.rosterReady = true;
            return students.length;
        } catch (e) {
            console.warn('[FaceScanOffline] roster sync failed:', e);
            return 0;
        }
    }

    /* ---------------- Descriptor helpers ---------------- */

    function imageFromBase64(imageBase64) {
        return new Promise(function (resolve, reject) {
            const img = new Image();
            img.onload = function () { resolve(img); };
            img.onerror = function () { reject(new Error('could not decode image')); };
            img.src = imageBase64;
        });
    }

    async function computeDescriptorFromImage(imageBase64) {
        const img = await imageFromBase64(imageBase64);
        const result = await window.faceapi.detectSingleFace(
            img,
            new window.faceapi.SsdMobilenetv1Options({ minConfidence: 0.5 })
        ).withFaceLandmarks().withFaceDescriptor();
        return result ? result.descriptor : null;
    }

    function euclideanDistance(a, b) {
        let sum = 0;
        for (let i = 0; i < a.length; i++) {
            const d = a[i] - b[i];
            sum += d * d;
        }
        return Math.sqrt(sum);
    }

    async function findBestMatch(imageBase64) {
        const query = await computeDescriptorFromImage(imageBase64);
        if (!query) return null;

        const students = await LDB_Offline_Attendance_DB.getAllStudents();
        let best = null;
        let bestDistance = Infinity;

        students.forEach(function (s) {
            const desc = s.face_descriptor;
            if (!desc || desc.length !== 128) return;
            const dist = euclideanDistance(query, desc);
            if (dist < bestDistance) {
                bestDistance = dist;
                best = s;
            }
        });

        if (!best || bestDistance > MATCH_THRESHOLD) {
            return null;
        }
        return { student: best, distance: bestDistance };
    }

    /* ---------------- Offline scan ---------------- */

    async function localScan(imageBase64, opts) {
        opts = opts || {};
        await ensureModels();

        const match = await findBestMatch(imageBase64);
        if (!match) {
            return {
                success: false,
                matched: false,
                offline: true,
                error: 'Face not recognized (offline)'
            };
        }

        const s = match.student;
        const now = new Date();
        let status = 'present';
        if (opts.session_type === 'time_in' && opts.session_start && opts.late_threshold) {
            const minutesLate = Math.max(
                0,
                (now.getTime() - new Date(opts.session_start).getTime()) / 60000
            );
            if (minutesLate > Number(opts.late_threshold)) status = 'late';
        }

        const scan = {
            sync_guid: uuid(),
            student_id: s.lrn,
            db_student_id: s.id,
            full_name: s.full_name,
            grade_level: s.grade_level,
            section: s.section,
            session_id: opts.session_id || null,
            session_type: opts.session_type || 'time_in',
            scan_time: toDBDateTime(now),
            status: status,
            confidence: Number((100 * (1 - match.distance)).toFixed(2))
        };

        await LDB_Offline_Attendance_DB.saveOfflineScan(scan);
        updateStatusUI();
        toast('✓ Scanned Offline - Queued for Sync');

        return {
            success: true,
            matched: true,
            offline: true,
            student_id: s.lrn,
            student_name: s.full_name,
            grade_level: s.grade_level,
            section: s.section || '',
            status: status,
            confidence: scan.confidence,
            duplicate: false,
            message: 'Attendance saved offline'
        };
    }

    /* ---------------- Online descriptor enrichment ---------------- */

    async function cacheDescriptorForStudent(student, descriptor) {
        const lrn = String(student.student_id);
        let existing = null;
        try {
            existing = await LDB_Offline_Attendance_DB.getStudentByLrn(lrn);
        } catch (e) { /* fresh row below */ }
        const row = existing || {
            lrn: lrn,
            full_name: (student.first_name || '') + ' ' + (student.last_name || ''),
            grade_level: student.grade_level,
            section: student.section || ''
        };
        if (student.id) row.id = student.id;
        row.full_name = (student.first_name || '') + ' ' + (student.last_name || '');
        row.grade_level = student.grade_level || row.grade_level;
        row.section = student.section || row.section;
        row.face_descriptor = Array.from(descriptor);
        await LDB_Offline_Attendance_DB.upsertStudent(row);
    }

    /* ---------------- Background sync ---------------- */

    async function syncOfflineLogs() {
        if (state.syncing || state.offline) return { success: false, skipped: true };
        state.syncing = true;
        try {
            const pending = await LDB_Offline_Attendance_DB.getPendingLogs();
            if (!pending.length) return { success: true, synced: 0, skipped: true };

            const payload = pending.map(function (log) {
                return {
                    sync_guid: log.sync_guid,
                    student_id: log.student_id,
                    session_id: log.session_id,
                    session_type: log.session_type,
                    scan_time: log.scan_time,
                    status: log.status,
                    confidence: log.confidence
                };
            });

            const res = await fetch(SYNC_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    logs: payload
                })
            });
            const data = await res.json();
            if (data.success && Array.isArray(data.synced_ids) && data.synced_ids.length) {
                await LDB_Offline_Attendance_DB.markLogsSynced(data.synced_ids);
                await LDB_Offline_Attendance_DB.deleteLogs(data.synced_ids);
                toast('🔄 Offline attendance logs synced to Railway!');
            }
            updateStatusUI();
            return data;
        } catch (e) {
            console.warn('[FaceScanOffline] sync failed:', e);
            return { success: false, error: e.message };
        } finally {
            state.syncing = false;
        }
    }

    /* ---------------- UI helpers ---------------- */

    function toast(message) {
        let container = document.getElementById('ldbFrasToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'ldbFrasToastContainer';
            container.style.cssText =
                'position:fixed;top:16px;right:16px;z-index:99999;display:flex;flex-direction:column;gap:8px;';
            document.body.appendChild(container);
        }
        const el = document.createElement('div');
        el.style.cssText =
            'background:#0A224C;color:#fff;padding:12px 16px;border-radius:8px;font-size:13px;' +
            'box-shadow:0 6px 20px rgba(0,0,0,0.25);animation:ldbToastIn 0.25s ease;';
        el.textContent = message;
        container.appendChild(el);
        setTimeout(function () {
            el.style.opacity = '0';
            el.style.transition = 'opacity 0.4s';
            setTimeout(function () { el.remove(); }, 400);
        }, 4200);
    }

    function updateStatusUI() {
        const pill = document.getElementById('offlineStatusPill');
        if (!pill) return;
        LDB_Offline_Attendance_DB.countPendingLogs().then(function (count) {
            if (state.offline) {
                pill.textContent = '🔴 OFFLINE' + (count ? ' (' + count + ' pending)' : '');
                pill.style.background = '#dc3545';
            } else if (count) {
                pill.textContent = '🟢 Syncing… (' + count + ' pending)';
                pill.style.background = '#fd7e14';
            } else {
                pill.textContent = '🟢 Online';
                pill.style.background = '#28a745';
            }
        }).catch(function () {
            pill.textContent = state.offline ? '🔴 OFFLINE' : '🟢 Online';
            pill.style.background = state.offline ? '#dc3545' : '#28a745';
        });
    }

    function showOfflineBanner() {
        if (document.getElementById('ldbFrasOfflineBanner')) return;
        const banner = document.createElement('div');
        banner.id = 'ldbFrasOfflineBanner';
        banner.style.cssText =
            'position:fixed;top:0;left:0;right:0;z-index:99998;background:#dc3545;color:#fff;' +
            'text-align:center;padding:8px 12px;font-size:13px;font-weight:600;';
        banner.textContent = '🔌 No internet connection — scans are being saved offline and will sync when you reconnect.';
        document.body.insertBefore(banner, document.body.firstChild);
    }

    function hideOfflineBanner() {
        const banner = document.getElementById('ldbFrasOfflineBanner');
        if (banner) banner.remove();
    }

    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'guid-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    }

    function toDBDateTime(d) {
        function p(n) { return String(n).padStart(2, '0'); }
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) +
            ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }

    /* ---------------- Public bootstrap ---------------- */

    function init() {
        if (!window.LDB_Offline_Attendance_DB || !window.faceapi) return;

        // Live offline/online detection
        window.addEventListener('online', function () {
            state.offline = false;
            hideOfflineBanner();
            updateStatusUI();
            syncOfflineLogs();
        });
        window.addEventListener('offline', function () {
            state.offline = true;
            showOfflineBanner();
            updateStatusUI();
        });

        // Initial banner state
        if (state.offline) showOfflineBanner();

        // Background warm-up (models + roster) while online.
        setTimeout(function () {
            ensureModels().catch(function () { /* wait until page interactivity */ });
            loadRoster();
        }, 3000);

        // Try an initial background sync shortly after load.
        setTimeout(function () {
            syncOfflineLogs();
        }, 6000);
    }

    window.FaceScanOffline = {
        init: init,
        ensureModels: ensureModels,
        modelsReady: modelsReady,
        loadRoster: loadRoster,
        computeDescriptorFromImage: computeDescriptorFromImage,
        cacheDescriptorForStudent: cacheDescriptorForStudent,
        localScan: localScan,
        syncOfflineLogs: syncOfflineLogs,
        get isOffline() { return state.offline; },
        get pendingCount() { return LDB_Offline_Attendance_DB.countPendingLogs(); }
    };
})();
