    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <!-- LDB-FRAS Navbar -->
    <script src="<?= BASE_URL ?>/assets/js/navbar.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/navbar.js') ?: time() ?>"></script>
    <!-- MediaPipe FaceMesh (liveness / anti-spoofing) - served locally for offline use -->
    <script>window.FACE_MESH_BASE = '<?= BASE_URL ?>/assets/vendor/face_mesh';</script>
    <script src="<?= BASE_URL ?>/assets/vendor/face_mesh/face_mesh.js"></script>
    <!-- LDB-FRAS Shared Scripts -->
    <script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/app.js') ?: time() ?>"></script>
    <script src="<?= BASE_URL ?>/assets/js/liveness.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/liveness.js') ?: time() ?>"></script>
</body>
</html>
