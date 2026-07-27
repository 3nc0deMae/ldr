<?php require_once __DIR__ . '/../config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= generateCSRFToken() ?>">
    <title><?= $pageTitle ?? APP_NAME ?> - <?= APP_FULL_NAME ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.3.0/dist/chart.umd.min.js"></script>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- App typeface (used by the page theme / navbar design tokens) -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <!-- LDB-FRAS Design System -->
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">

    <!-- Runtime CSS Variable Overrides -->
    <style>
        :root {
            --primary:       <?= COLOR_PRIMARY ?>;
            --primary-light: #122d5e;
            --primary-dark:  #061633;
            --secondary:     <?= COLOR_SECONDARY ?>;
            --secondary-dark:#0052cc;
            --bg-color:      <?= COLOR_BACKGROUND ?>;
            --success:       <?= COLOR_SUCCESS ?>;
            --success-light: rgba(40,167,69,0.1);
            --warning:       <?= COLOR_WARNING ?>;
            --warning-light: rgba(255,193,7,0.1);
            --danger:        <?= COLOR_DANGER ?>;
            --danger-light:  rgba(220,53,69,0.1);
            --info:          #17A2B8;
            --info-light:    rgba(23,162,184,0.1);
        }
    </style>
    <!-- Dynamic Base URL for JavaScript -->
    <script>
        window.BASE_URL = '<?= BASE_URL ?>';
    </script>
    <!-- Global Search Term Highlighter -->
    <script>
    (function() {
        var params = new URLSearchParams(window.location.search);
        var q = params.get('q') || params.get('search') || '';
        if (!q || q.length < 2) return;
        var term = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        if (!term) return;
        var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
            acceptNode: function(node) {
                var parent = node.parentNode;
                if (!parent) return NodeFilter.FILTER_REJECT;
                var tag = parent.tagName.toLowerCase();
                if (['script','style','textarea','input','select','code','pre'].indexOf(tag) !== -1) return NodeFilter.FILTER_REJECT;
                if (parent.closest('a, button, .search-result-item, .notif-item, .ntf-card')) return NodeFilter.FILTER_REJECT;
                if (node.nodeValue.toLowerCase().indexOf(term.toLowerCase()) === -1) return NodeFilter.FILTER_SKIP;
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        var nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach(function(node) {
            var span = document.createElement('span');
            span.innerHTML = node.nodeValue.replace(new RegExp('(' + term + ')', 'gi'),
                '<mark style="background:#fef08a;color:#000;padding:0 2px;border-radius:2px;">$1</mark>');
            node.parentNode.replaceChild(span, node);
        });
    })();
    </script>
</head>
<body>
