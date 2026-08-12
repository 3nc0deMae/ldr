    <!-- jQuery -->
    <script src="<?= BASE_URL ?>/assets/vendor/js/jquery-3.7.0.min.js"></script>
    <!-- Bootstrap 5 JS -->
    <script src="<?= BASE_URL ?>/assets/vendor/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables JS -->
    <script src="<?= BASE_URL ?>/assets/vendor/dataTables/css/jquery.dataTables.min.js"></script>
    <script src="<?= BASE_URL ?>/assets/vendor/dataTables/js/dataTables.bootstrap5.min.js"></script>
    <!-- LDB-FRAS Navbar -->
    <script src="<?= BASE_URL ?>/assets/js/navbar.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/navbar.js') ?: time() ?>"></script>
    <!-- MediaPipe FaceMesh (liveness / anti-spoofing) - served locally for offline use -->
    <script>window.FACE_MESH_BASE = '<?= BASE_URL ?>/assets/vendor/face_mesh';</script>
    <script src="<?= BASE_URL ?>/assets/vendor/face_mesh/face_mesh.js"></script>
    <!-- LDB-FRAS Shared Scripts -->
    <script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/app.js') ?: time() ?>"></script>
    <script src="<?= BASE_URL ?>/assets/js/liveness.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/liveness.js') ?: time() ?>"></script>

    <!-- Offline attendance stack (kiosk: timein.php / timeout.php / register.php) -->
    <?php if (!empty($offlineKiosk)): ?>
    <span id="offlineStatusPill"
          style="position:fixed;bottom:16px;left:16px;z-index:99997;background:#28a745;color:#fff;font-size:12px;font-weight:600;padding:6px 12px;border-radius:20px;box-shadow:0 4px 12px rgba(0,0,0,0.2);">
        🟢 Online
    </span>
    <style>
        @keyframes ldbToastIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: none; } }
    </style>
    <script src="<?= BASE_URL ?>/assets/vendor/face-api/face-api.min.js"></script>
    <script src="<?= BASE_URL ?>/assets/js/offline-db.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/offline-db.js') ?: time() ?>"></script>
    <script src="<?= BASE_URL ?>/assets/js/face-scan-offline.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/face-scan-offline.js') ?: time() ?>"></script>
    <script>
        (function () {
            if (window.FaceScanOffline && window.LDB_Offline_Attendance_DB) {
                LDB_Offline_Attendance_DB.init().then(function () {
                    FaceScanOffline.init();
                }).catch(function (e) {
                    console.warn('Offline DB unavailable:', e);
                });
            }
        })();
    </script>
    <?php endif; ?>
</body>
</html>
