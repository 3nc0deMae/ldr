    <!-- jQuery -->
    <script src="<?= BASE_URL ?>/assets/vendor/js/jquery-3.7.0.min.js"></script>
    <!-- Bootstrap 5 JS -->
    <script src="<?= BASE_URL ?>/assets/vendor/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables JS -->
    <script src="<?= BASE_URL ?>/assets/vendor/dataTables/css/jquery.dataTables.min.js"></script>
    <script src="<?= BASE_URL ?>/assets/vendor/dataTables/js/dataTables.bootstrap5.min.js"></script>
    <!-- LDB-FRAS Navbar -->
    <script src="<?= BASE_URL ?>/assets/js/navbar.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/navbar.js') ?: time() ?>"></script>
    <!-- LDB-FRAS Shared Scripts -->
    <script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/app.js') ?: time() ?>"></script>
</body>
</html>
