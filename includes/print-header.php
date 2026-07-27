<?php
/**
 * Reusable Print Header Template — LICEO DE BALENO Letterhead
 *
 * Usage (in-page print — hidden on screen, shown in @media print):
 *   <?php $printDocTitle = 'My Report Title'; ?>
 *   <?php $printDocMeta  = '<span>...</span>'; ?>
 *   <?php include __DIR__ . '/../includes/print-header.php'; ?>
 *
 * Usage (new-window print — add class "print-new-window" to <body>):
 *   $bodyClass = 'print-new-window';
 *   <?php include __DIR__ . '/../includes/print-header.php'; ?>
 *
 * Variables (all optional):
 *   $printDocTitle  — Document title shown below the header (string)
 *   $printDocMeta   — Extra metadata HTML shown in the meta bar (string)
 *   $logoBaseUrl    — Override base URL for logos (defaults to BASE_URL)
 */

$_ph_base  = $logoBaseUrl ?? BASE_URL;
$_ph_left  = $_ph_base . '/assets/images/ldb_logo.webp';
$_ph_right = $_ph_base . '/assets/images/pic.jpg';
$_ph_title = $printDocTitle ?? '';
$_ph_meta  = $printDocMeta  ?? '';
?>

<!-- ═══ LICEO DE BALENO — Standard Print Letterhead ═══ -->
<div class="print-doc-header">

    <!-- Left cluster: School Seal (overlaps text + border) + School Name -->
    <div class="print-doc-left">
        <img src="<?= $_ph_left ?>" alt="School Seal" class="print-doc-seal-left">
        <div class="print-doc-names">
            <div class="print-doc-subtitle">Diocese of Masbate Catholic School</div>
            <div class="print-doc-title">LICEO DE BALENO</div>
        </div>
    </div>

    <!-- Right cluster: Full-height blue banner + Diocese Seal (transparent bg) -->
    <div class="print-doc-right">
        <div class="print-doc-banner">BALENO, MASBATE. 5413<br>PHILIPPINES</div>
        <img src="<?= $_ph_right ?>" alt="Diocese Seal" class="print-doc-seal-right">
    </div>

</div>

<?php if ($_ph_title || $_ph_meta): ?>
<!-- Document Title Row -->
<div class="print-doc-title-row">
    <?php if ($_ph_title): ?>
        <h2><?= htmlspecialchars($_ph_title) ?></h2>
    <?php endif; ?>
    <?php if ($_ph_meta): ?>
        <p><?= $_ph_meta /* allow HTML */ ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>
