<?php

require_once __DIR__ . '/../config.php';
requireRole(['admin']);

// Handle delete track/elective BEFORE any HTML output
if (isset($_GET['delete_track'])) {
    $id = intval($_GET['delete_track']);
    if ($id) {
        try {
            $db->prepare("DELETE FROM tracks WHERE id = ?")->execute([$id]);
            redirect('/admin/strands.php', 'Track deleted.', 'success');
        } catch (Exception $e) {
            redirect('/admin/strands.php', 'Cannot delete track.', 'danger');
        }
    }
}
if (isset($_GET['delete_elective'])) {
    $id = intval($_GET['delete_elective']);
    if ($id) {
        try {
            $db->prepare("DELETE FROM electives WHERE id = ?")->execute([$id]);
            redirect('/admin/strands.php', 'Elective deleted.', 'success');
        } catch (Exception $e) {
            redirect('/admin/strands.php', 'Cannot delete elective.', 'danger');
        }
    }
}

$pageTitle = 'Elective Management';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';

// ============================================================
// MIGRATIONS — auto-create tables if missing
// ============================================================
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `tracks` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `track_name` VARCHAR(255) NOT NULL,
        `description` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { error_log('tracks: ' . $e->getMessage()); }

try {
    $db->exec("CREATE TABLE IF NOT EXISTS `electives` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `track_id` INT NOT NULL,
        `elective_name` VARCHAR(255) NOT NULL,
        `description` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_track_id (`track_id`),
        FOREIGN KEY (`track_id`) REFERENCES `tracks`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { error_log('electives: ' . $e->getMessage()); }

try {
    $db->exec("CREATE TABLE IF NOT EXISTS `elective_subjects` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `elective_id` INT NOT NULL,
        `subject_id` INT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_elective_subject (`elective_id`, `subject_id`),
        INDEX idx_elective_id (`elective_id`),
        FOREIGN KEY (`elective_id`) REFERENCES `electives`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { error_log('elective_subjects: ' . $e->getMessage()); }

// ============================================================
// FETCH STATISTICS FOR STAT CARDS
// ============================================================
$tracks = [];
$totalTracks = 0;
$totalElectives = 0;
$totalSubjects = 0;

try {
    // Get all tracks
    $tracks = $db->query("SELECT * FROM tracks ORDER BY track_name ASC")->fetchAll();
    $totalTracks = count($tracks);
    
    // Get total electives
    $totalElectives = $db->query("SELECT COUNT(*) FROM electives")->fetchColumn();
    
    // Get total subjects (based on electives they belong to)
    $totalSubjects = $db->query("SELECT COUNT(DISTINCT subject_id) FROM elective_subjects")->fetchColumn();
    
} catch (Exception $e) {
    error_log('Statistics error: ' . $e->getMessage());
}
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">

<style>
    :root {
        --tm-font: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        --tm-mono: 'JetBrains Mono', monospace;
        --tm-primary: #4f46e5; --tm-primary-light: rgba(79,70,229,0.15); --tm-primary-dark: #3730a3; --tm-primary-glow: rgba(79,70,229,0.2);
        --tm-success: #10b981; --tm-success-light: rgba(16,185,129,0.15);
        --tm-danger: #ef4444; --tm-danger-light: rgba(239,68,68,0.15);
        --tm-warning: #f59e0b; --tm-warning-light: rgba(245,158,11,0.15);
        --tm-info: #06b6d4; --tm-info-light: rgba(6,182,212,0.15);
        --tm-radius: 14px; --tm-radius-sm: 10px; --tm-radius-xs: 8px;
        --tm-shadow: 0 4px 16px rgba(0,0,0,0.25);
        --tm-transition: 0.2s cubic-bezier(0.4,0,0.2,1);
        --select-bg: #1e2a4a; --select-bg-hover: #2563eb; --select-bg-placeholder: #151d33;
        --select-text: #ffffff; --select-text-muted: rgba(255,255,255,0.45);
    }

    /* GLOBAL SELECT */
    select,.form-select{appearance:none;-webkit-appearance:none;-moz-appearance:none;background-color:rgba(255,255,255,0.05);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='rgba(255,255,255,0.5)' viewBox='0 0 16 16'%3E%3Cpath d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;background-size:12px;padding-right:40px;cursor:pointer;border:1.5px solid rgba(255,255,255,0.12);border-radius:var(--tm-radius-sm);color:inherit;font-size:14px;font-family:var(--tm-font);transition:all var(--tm-transition)}
    select:focus,.form-select:focus{outline:none;border-color:var(--tm-primary);box-shadow:0 0 0 3px var(--tm-primary-glow)}
    select option,.form-select option{background:var(--select-bg);color:var(--select-text);padding:10px 14px;font-size:14px;line-height:1.6}
    select option:hover,select option:checked,select option:active{background:var(--select-bg-hover);color:var(--select-text)}
    select option[value=""]{color:var(--select-text-muted);background:var(--select-bg-placeholder)}
    select:disabled{opacity:0.45;cursor:not-allowed}

    /* STAT CARDS */
    .stat-card{transition:all var(--tm-transition);position:relative;overflow:hidden;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:var(--tm-radius);padding:18px 16px}
    .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--tm-radius) var(--tm-radius) 0 0;opacity:0;transition:opacity var(--tm-transition)}
    .stat-card:hover{transform:translateY(-3px);background:rgba(255,255,255,0.06)}.stat-card:hover::before{opacity:1}
    .stat-card:nth-child(1)::before{background:var(--tm-primary)}
    .stat-card:nth-child(2)::before{background:var(--tm-success)}
    .stat-card:nth-child(3)::before{background:var(--tm-info)}
    @keyframes tmFadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
    .stat-card{animation:tmFadeUp .5s ease forwards;opacity:0}
    .stat-card:nth-child(1){animation-delay:.05s}
    .stat-card:nth-child(2){animation-delay:.1s}
    .stat-card:nth-child(3){animation-delay:.15s}

    .stat-value{font-size:26px;font-weight:800;letter-spacing:-0.02em;line-height:1.2}
    .stat-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,0.55);margin-top:4px}
    .stat-icon{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}

    /* Stat icon colors */
    .bg-primary-soft { background: rgba(79,70,229,0.15); color: #4f46e5; }
    .bg-success-soft { background: rgba(16,185,129,0.15); color: #10b981; }
    .bg-info-soft { background: rgba(6,182,212,0.15); color: #06b6d4; }

    /* TRACK CARD HEADER */
    .tracks-card .card-header{background:var(--tm-primary);border-bottom:none;gap:6px;justify-content:flex-start}
    .tracks-card .card-header i{opacity:1;font-size:16px}
    .tracks-card .card-header>*{color:#fff}

    /* TRACK CONTAINER */
    .track-container{border:1.5px solid rgba(255,255,255,0.1);border-radius:var(--tm-radius);overflow:hidden;margin-bottom:18px;background:rgba(255,255,255,0.02)}
    .track-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;background:rgba(255,255,255,0.04);border-bottom:1px solid rgba(255,255,255,0.06)}
    .track-header h6{font-weight:800;font-size:15px;margin:0;letter-spacing:-0.01em}
    .track-header .badge{font-weight:600;font-size:11px;padding:4px 10px;border-radius:6px}
    .track-body{padding:14px 20px 18px}
    .electives-row{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:12px;align-items:flex-start}
    .elective-item{border:1px solid rgba(255,255,255,0.08);border-radius:var(--tm-radius-sm);padding:0;background:rgba(255,255,255,0.03);min-width:0;flex:1 1 100%;max-width:100%;overflow:hidden}
    .elective-header{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:rgba(255,255,255,0.04);border-bottom:1px solid rgba(255,255,255,0.06);flex-wrap:wrap;gap:8px}
    .elective-header-left{display:flex;align-items:center;gap:8px;min-width:0;flex-wrap:wrap}
    .elective-header-left h6{margin:0;font-size:13px;font-weight:800}
    .elective-header .text-muted{font-size:12px;color:rgba(255,255,255,0.45)}
    .elective-actions{display:flex;gap:6px;flex-wrap:wrap}
    .btn-subject-add{padding:6px 12px;border-radius:6px;border:none;background:var(--tm-primary);color:#fff;font-size:11px;font-weight:700;cursor:pointer;transition:all var(--tm-transition);white-space:nowrap}
    .btn-subject-add:hover{background:var(--tm-primary-dark);border-color:var(--tm-primary-dark);box-shadow:0 2px 8px var(--tm-primary-glow);color:#fff}
    .elective-desc{font-size:12px;color:rgba(255,255,255,0.5);margin:8px 16px 0;line-height:1.4}
    .subjects-row{margin-top:12px;overflow-x:auto}
    .subjects-row table{width:100%;border-collapse:collapse;font-size:12px;background:#fff;color:#000;min-width:600px}
    .subjects-row thead th{text-align:left;padding:8px 12px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#374151;border-bottom:1px solid #e5e7eb;background:#f9fafb}
    .subjects-row tbody td{padding:10px 12px;border-bottom:1px solid #f3f4f6;color:#111827;vertical-align:middle}
    .subjects-row tbody tr{background:#fff}
    .subjects-row tbody tr:last-child td{border-bottom:none}
    .subjects-row .action-icon{width:30px;height:30px;border-radius:6px;border:1px solid #d1d5db;background:#f3f4f6;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:#374151;transition:all var(--tm-transition);font-size:12px;padding:0}
    .subjects-row .action-icon:hover{background:var(--tm-primary);border-color:var(--tm-primary);color:#fff}
    .no-subjects{padding:14px 16px;font-size:12px;color:#6b7280;font-style:italic;background:#fff}
    .btn-tm-primary{background:var(--tm-primary);border-color:var(--tm-primary);color:#fff;font-size:12px;font-weight:700;padding:5px 12px;border-radius:6px;transition:all var(--tm-transition);border:none;cursor:pointer}
    .btn-tm-primary:hover{background:var(--tm-primary-dark);border-color:var(--tm-primary-dark);color:#fff;box-shadow:0 2px 8px var(--tm-primary-glow)}
    .btn-tm-danger{background:var(--tm-danger);border-color:var(--tm-danger);color:#fff;font-size:12px;font-weight:700;padding:5px 12px;border-radius:6px;transition:all var(--tm-transition);border:none;cursor:pointer}
    .btn-tm-danger:hover{background:#dc2626;border-color:#dc2626;color:#fff;box-shadow:0 2px 8px rgba(239,68,68,0.3)}
    .empty-state{text-align:center;padding:48px 20px}
    .empty-icon{width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px;background:rgba(255,255,255,0.04)}
    .empty-state h6{font-weight:700;font-size:16px;margin-bottom:6px}.empty-state p{font-size:13px;color:rgba(255,255,255,0.55)}

    /* =====================================================
       MODAL — compact form fits perfectly
       ===================================================== */
    .event-modal-overlay{
        position:fixed;inset:0;
        background:rgba(26,29,46,0.5);
        backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);
        z-index:9998;display:none;
        align-items:center;justify-content:center;padding:20px;
    }
    .event-modal-overlay.show{display:flex}
    .event-modal{
        border-radius:20px;width:500px;max-width:100%;
        max-height:92vh;
        display:flex;flex-direction:column;overflow:hidden;
        animation:modalSlideIn .35s cubic-bezier(.34,1.56,.64,1);
        background:#1a1d2e;
        color:#fff;
        box-shadow:0 20px 60px rgba(0,0,0,0.6);
    }
    @keyframes modalSlideIn{from{opacity:0;transform:translateY(24px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}

    /* Header — pinned top */
    .event-modal-header{
        display:flex;justify-content:space-between;align-items:center;
        padding:18px 22px;flex-shrink:0;
        border-bottom:1px solid rgba(255,255,255,0.06);
    }
    .event-modal-title{display:flex;align-items:center;gap:10px;font-size:16px;font-weight:800;letter-spacing:-0.02em}
    .event-modal-title i{font-size:20px}
    .event-modal-close{width:32px;height:32px;border:none;border-radius:var(--tm-radius-xs);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all var(--tm-transition);font-size:13px;background:rgba(255,255,255,0.06);color:inherit}
    .event-modal-close:hover{background:rgba(255,255,255,0.14)}

    .evt-section-label{margin-bottom:10px}
    .repeat-row{display:block;margin-bottom:12px}
    .grade-row{display:flex;flex-wrap:wrap;gap:10px}

    /* Body — scrollable for subjects */
    .event-modal-body{
        padding:14px 22px 0;
        overflow-y:auto;
        flex:1 1 auto;
        min-height:0;
    }
    .modal-addsubject-body{padding:14px 22px 0;overflow-y:auto;flex:1 1 auto;min-height:0}
    .modal-addsubject-actions{flex-shrink:0;padding:10px 22px 0}
    .event-modal form{display:flex;flex-direction:column;flex:1;min-height:0}

    /* Footer — pinned bottom */
    .event-modal-footer{
        display:flex;justify-content:flex-end;gap:10px;
        padding:12px 22px;flex-shrink:0;
        border-top:1px solid rgba(255,255,255,0.06);
    }

    /* FORM FIELDS */
    .evt-field{margin-bottom:12px}.evt-field:last-child{margin-bottom:0}
    .evt-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-bottom:5px;line-height:1.3}
    .evt-field label .required{color:var(--tm-danger)}
    .evt-field label small{font-weight:500;text-transform:none;letter-spacing:0;opacity:0.55}
    .evt-field input[type="text"],.evt-field textarea,.evt-field select{
        width:100%;padding:9px 12px;border:1.5px solid rgba(255,255,255,0.12);
        border-radius:var(--tm-radius-sm);font-size:13px;transition:all var(--tm-transition);
        font-family:var(--tm-font);background:rgba(255,255,255,0.05);color:inherit;
    }
    .evt-field textarea{resize:vertical;min-height:60px}
    .evt-field input:focus,.evt-field textarea:focus,.evt-field select:focus{outline:none;border-color:var(--tm-primary);box-shadow:0 0 0 3px var(--tm-primary-glow)}
    .evt-field input::placeholder,.evt-field textarea::placeholder{color:rgba(255,255,255,0.25)}
    .evt-row{display:flex;gap:10px}
    .evt-flex-1{flex:1;min-width:0}
    .evt-separator{border:none;border-top:1px solid rgba(255,255,255,0.06);margin:10px 0}
    .evt-section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;display:flex;align-items:center;gap:6px;opacity:0.5}

    .repeat-row{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:12px}
    .grade-row{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;flex:0 0 100%}
    .grade-row .evt-field{margin-bottom:0}
    .repeat-row .rm-row-btn{background:var(--tm-danger-light);color:var(--tm-danger);border:none;width:32px;height:32px;border-radius:var(--tm-radius-sm);cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;transition:all var(--tm-transition);flex-shrink:0;margin-left:auto}
    .repeat-row .rm-row-btn:hover{background:var(--tm-danger);color:#fff}
    .add-row-btn{background:rgba(255,255,255,0.06);color:inherit;border:1.5px dashed rgba(255,255,255,0.18);padding:7px 14px;border-radius:var(--tm-radius-sm);cursor:pointer;font-size:13px;font-weight:700;transition:all var(--tm-transition);display:inline-flex;align-items:center;gap:6px}
    .add-row-btn:hover{background:rgba(255,255,255,0.1);border-color:rgba(255,255,255,0.3)}
    .evt-btn{padding:10px 18px;border:none;border-radius:var(--tm-radius-sm);font-size:13px;font-weight:700;cursor:pointer;transition:all var(--tm-transition);display:flex;align-items:center;gap:6px}
    .evt-btn-cancel{background:rgba(255,255,255,0.08);color:inherit}.evt-btn-cancel:hover{background:rgba(255,255,255,0.14)}
    .evt-btn-save{background:var(--tm-primary);color:#fff}.evt-btn-save:hover{background:var(--tm-primary-dark);box-shadow:0 4px 16px var(--tm-primary-glow)}
    .evt-btn-save.loading{opacity:0.7;pointer-events:none}
    .evt-btn-danger{background:var(--tm-danger);color:#fff}.evt-btn-danger:hover{background:#dc2626;box-shadow:0 4px 14px rgba(239,68,68,0.3)}

    /* Delete modal */
    .delete-modal{width:400px;height:auto;max-height:90vh}
    .delete-modal-icon{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:24px}
    .delete-modal-text{text-align:center}
    .delete-modal-text h6{font-weight:700;font-size:16px;letter-spacing:-0.02em;margin-bottom:6px}
    .delete-modal-text p{font-size:13px;max-width:280px;margin:0 auto;line-height:1.5}

    /* TOAST */
    .toast-container{position:fixed;bottom:28px;right:28px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none}
    .toast-notification{padding:14px 22px;border-radius:var(--tm-radius-sm);color:#fff;font-size:13px;font-weight:600;display:flex;align-items:center;gap:12px;max-width:400px;pointer-events:auto;animation:tmToastIn .4s cubic-bezier(.34,1.56,.64,1),tmToastOut .4s ease 3.6s forwards;font-family:var(--tm-font)}
    .toast-notification i{font-size:18px;flex-shrink:0;opacity:0.9}
    .toast-success{background:#059669}.toast-error{background:#dc2626}.toast-info{background:#0284c7}
    @keyframes tmToastIn{from{opacity:0;transform:translateX(40px) scale(.95)}to{opacity:1;transform:translateX(0) scale(1)}}
    @keyframes tmToastOut{from{opacity:1;transform:translateX(0)}to{opacity:0;transform:translateX(40px)}}

    .mobile-title-left h5 { font-size: 18px; font-weight: 800; letter-spacing: -0.03em; margin: 0; }
    .mobile-title-left small { font-size: 12px; font-weight: 500; }

    /* TABLET */
    @media(max-width:991px){
        .content-area{padding:20px}
        .navbar-brand{display:flex}.navbar-brand-logo{width:44px;height:44px}
        .navbar-brand-name{font-size:12px}.navbar-brand-sub{font-size:9px;opacity:0.45}
        .desktop-title{display:none!important}.mobile-title{display:block!important}
        .navbar-actions{gap:6px}.nav-icon-btn{width:38px;height:38px;font-size:15px}
        .btn-add-track{padding:8px 12px!important;font-size:12px!important}
        .content-area{padding:10px 12px 28px}
        .stat-card{padding:14px 12px}.stat-value{font-size:22px}.stat-label{font-size:10px;margin-top:3px}
        .stat-icon{width:36px;height:36px;font-size:14px;border-radius:10px}
        .stat-card{animation:none;opacity:1}

        .track-header{padding:12px 14px}.track-body{padding:10px 14px 14px}
        .elective-item{min-width:100%;flex:1 1 100%}

        /* Modal — mobile bottom sheet */
        .event-modal-overlay{align-items:flex-end;justify-content:center;padding:0}
        .event-modal{width:100%;max-width:100vw;height:auto;max-height:92vh;border-radius:16px 16px 0 0;animation:modalSheetUp .3s ease-out}
        @keyframes modalSheetUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
        .event-modal-header{padding:14px 18px}
        .event-modal-title{font-size:14px;gap:9px}.event-modal-title i{font-size:17px}
        .event-modal-body{padding:0 18px 12px;-webkit-overflow-scrolling:touch}
        .evt-field{margin-bottom:10px}
        .evt-field input[type="text"],.evt-field textarea,.evt-field select{padding:9px 12px;font-size:13px}
        .evt-row{flex-direction:column;gap:0}
        .event-modal-footer{padding:10px 18px;padding-bottom:calc(10px + env(safe-area-inset-bottom, 0px));border-top:1px solid rgba(255,255,255,0.06)}
        .evt-btn{padding:9px 16px;font-size:12px}
        select,.form-select{background-position:right 12px center;padding-right:36px}
        select option{padding:8px 12px;font-size:13px}
        .event-modal::before{content:'';display:block;width:36px;height:4px;border-radius:4px;background:rgba(255,255,255,0.2);margin:8px auto 0;flex-shrink:0}
        .delete-modal{width:100%;max-width:100vw;height:auto;max-height:92vh}
        .toast-container{bottom:24px;right:12px;left:12px}.toast-notification{max-width:100%;font-size:12px;padding:12px 16px}
    }

    /* SMALL PHONE */
    @media(max-width:576px){
        .top-navbar{padding:10px 10px}.navbar-brand-logo{width:38px;height:38px}
        .navbar-brand-name{font-size:11px}.navbar-brand-sub{font-size:8px}
        .navbar-actions{gap:4px}.nav-icon-btn{width:34px;height:34px;font-size:14px}
        .content-area{padding:8px 8px 24px}
        .stat-card{padding:10px 8px}.stat-value{font-size:18px}.stat-label{font-size:8px}
        .stat-icon{width:28px;height:28px;font-size:11px;border-radius:8px}
        .event-modal{max-height:95vh}
        .event-modal-body{padding:0 14px 10px}
        .event-modal-header{padding:12px 14px}
        .event-modal-footer{padding:10px 14px;padding-bottom:calc(10px + env(safe-area-inset-bottom, 0px))}
        .sidebar{width:260px}
    }

    @media(min-width:768px){.mobile-title{display:none!important}}
</style>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">

<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">
    <div class="content-area">
        <?= displayFlashMessage() ?>

        <div class="d-none d-md-flex justify-content-between align-items-center mb-3">
            <div class="page-title desktop-title mb-0"><h5>Elective Management</h5><small>Senior High School Tracks and Electives</small></div>
            <button class="btn btn-primary btn-add-track" id="openAddTrack"><i class="bi bi-plus-lg"></i> <span class="btn-text">Add Tracks</span></button>
        </div>

        <div class="page-title mobile-title"><div class="mobile-title-inner"><div class="mobile-title-left"><h5>Elective Management</h5><small>SHS Tracks and Electives</small></div><button class="btn btn-primary btn-add-track" id="openAddTrackMobile"><i class="bi bi-plus-lg"></i> <span class="btn-text">Add</span></button></div></div>

        <!-- ============================================================
        STAT CARDS - Total Tracks, Total Electives, Total Subjects
        ============================================================ -->
        <div class="row g-3 mb-4">
            <!-- Total Tracks -->
            <div class="col-6 col-md-4">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?= $totalTracks ?></div>
                            <div class="stat-label">Total Tracks</div>
                        </div>
                        <div class="stat-icon bg-primary-soft">
                            <i class="bi bi-grid-1x2"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Total Electives -->
            <div class="col-6 col-md-4">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?= $totalElectives ?></div>
                            <div class="stat-label">Total Electives</div>
                        </div>
                        <div class="stat-icon bg-success-soft">
                            <i class="bi bi-book"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Total Subjects (based on electives they belong to) -->
            <div class="col-6 col-md-4">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value"><?= $totalSubjects ?></div>
                            <div class="stat-label">Total Subjects</div>
                        </div>
                        <div class="stat-icon bg-info-soft">
                            <i class="bi bi-journal-text"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tracks -->
        <div class="card tracks-card">
            <div class="card-header" style="justify-content:flex-start;gap:2px;"><i class="bi bi-grid-1x2"></i>All Tracks</div>
            <div class="card-body">
                <?php if (empty($tracks)): ?>
                    <div class="empty-state" id="tracksEmptyState">
                        <div class="empty-icon"><i class="bi bi-grid-1x2"></i></div>
                        <h6>No Tracks Found</h6>
                        <p>Add SHS tracks to organize electives (e.g. STEM, ABM, HUMSS, TVL)</p>
                    </div>
                <?php else: ?>
                <?php foreach ($tracks as $track): ?>
                    <?php
                        $electives = [];
                        try { 
                            $electives = $db->prepare("SELECT * FROM electives WHERE track_id = ? ORDER BY elective_name ASC"); 
                            $electives->execute([$track['id']]); 
                            $electives = $electives->fetchAll(); 
                        } catch (Exception $e) { $electives = []; }
                        $trackDataAttr = htmlspecialchars(json_encode($track), ENT_QUOTES, 'UTF-8');
                    ?>
                    <div class="track-container" id="track-<?= (int)$track['id'] ?>">
                        <div class="track-header">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <h6><?= htmlspecialchars($track['track_name']) ?></h6>
                                <span class="badge bg-primary-soft text-primary">Track</span>
                                <?php if (!empty($track['description'])): ?>
                                    <small class="text-muted" style="font-size:12px;">&mdash; <?= htmlspecialchars($track['description']) ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-1">
                                <button class="btn btn-sm btn-tm-primary edit-track-trigger" data-track="<?= $trackDataAttr ?>">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                <button class="btn btn-sm btn-tm-danger delete-track-trigger" data-delete-id="<?= (int)$track['id'] ?>" data-delete-name="<?= htmlspecialchars($track['track_name']) ?>">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                        <div class="track-body">
                            <div class="electives-row">
                                <?php if (!empty($electives)): ?>
                                    <?php foreach ($electives as $elec): ?>
                                        <?php
                                            $elecSubjects = [];
                                            try { 
                                                $elecSubjects = $db->prepare("
                                                    SELECT s.subject_name, s.grade_level, s.grade_level_end, s.description, s.id 
                                                    FROM elective_subjects es 
                                                    LEFT JOIN subjects s ON es.subject_id = s.id 
                                                    WHERE es.elective_id = ? 
                                                    ORDER BY s.subject_name ASC
                                                "); 
                                                $elecSubjects->execute([$elec['id']]); 
                                                $elecSubjects = $elecSubjects->fetchAll(); 
                                            } catch (Exception $e) { $elecSubjects = []; }
                                        ?>
                                        <div class="elective-item" id="elective-<?= (int)$elec['id'] ?>">
                                            <div class="elective-header">
                                                <div class="elective-header-left">
                                                    <h6><?= htmlspecialchars($elec['elective_name']) ?></h6>
                                                    <?php if (!empty($elec['description'])): ?>
                                                        <span class="text-muted">&mdash; <?= htmlspecialchars($elec['description']) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">No description added.</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="elective-actions">
                                                    <button class="btn-subject-add open-add-subject-modal" data-elective='<?= htmlspecialchars(json_encode(['id'=>$elec['id'],'name'=>$elec['elective_name']]),ENT_QUOTES,'UTF-8') ?>'>
                                                        <i class="bi bi-plus-lg"></i> Add Subject
                                                    </button>
                                                    <button class="action-icon btn-tm-primary btn-edit-trigger" style="border:none" data-elective='<?= htmlspecialchars(json_encode($elec),ENT_QUOTES,'UTF-8') ?>' title="Edit Elective"><i class="bi bi-pencil"></i></button>
                                                    <button class="action-icon btn-tm-danger btn-delete-trigger" style="border:none" data-delete-id="<?= (int)$elec['id'] ?>" data-delete-name="<?= htmlspecialchars($elec['elective_name']) ?>" title="Delete Elective"><i class="bi bi-trash"></i></button>
                                                </div>
                                            </div>
                                            <div class="subjects-row">
                                                <table>
                                                    <thead>
                                                        <tr>
                                                            <th>Subject Name</th>
                                                            <th>From Grade</th>
                                                            <th>To Grade</th>
                                                            <th>Description (Optional)</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (!empty($elecSubjects)): ?>
                                                            <?php foreach ($elecSubjects as $subj): ?>
                                                              <tr>
                                                                  <td><?= htmlspecialchars($subj['subject_name']) ?></td>
                                                                  <td><?= htmlspecialchars($subj['grade_level'] ?? '-') ?></td>
                                                                  <td><?= htmlspecialchars($subj['grade_level_end'] ?? '-') ?></td>
                                                                  <td><?= !empty($subj['description']) ? htmlspecialchars($subj['description']) : '-' ?></td>
                                                                  <td>
                                                                      <button class="action-icon btn-tm-primary" style="border:none" title="Edit"><i class="bi bi-pencil"></i></button>
                                                                      <button class="action-icon btn-tm-danger delete-subject-trigger" style="border:none;margin-left:6px" data-elective-id="<?= (int)$elec['id'] ?>" data-subject-id="<?= (int)$subj['id'] ?>" title="Delete"><i class="bi bi-trash"></i></button>
                                                                  </td>
                                                              </tr>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <tr><td colspan="6" class="no-subjects">No subjects added yet.</td></tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <button class="add-row-btn open-add-elective-modal" data-track-id="<?= (int)$track['id'] ?>">
                                    <i class="bi bi-plus-lg"></i> Add electives
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

  <!-- ADD TRACK MODAL -->
  <div class="event-modal-overlay" id="addTrackOverlay">
      <div class="event-modal">
          <div class="event-modal-header">
              <div class="event-modal-title"><i class="bi bi-plus-circle-fill"></i><span>Add New Track</span></div>
              <button class="event-modal-close" id="addTrackClose"><i class="bi bi-x-lg"></i></button>
          </div>
          <form id="addTrackForm" autocomplete="off">
              <input type="hidden" name="action" value="add_track">
              <div class="event-modal-body">
                  <div class="evt-section-label"><i class="bi bi-grid-1x2"></i> Track Information</div>
                  <div class="evt-field"><label>Track Name <span class="required">*</span></label><input type="text" name="track_name" placeholder="e.g. Science, Technology, Engineering, and Mathematics" required maxlength="255"></div>
                  <div class="evt-field"><label>Description <small>(optional)</small></label><textarea name="description" rows="2" placeholder="Brief description of this track..."></textarea></div>
              </div>
              <div class="event-modal-footer">
                  <button type="button" class="evt-btn evt-btn-cancel" id="addTrackCancel">Cancel</button>
                  <button type="submit" class="evt-btn evt-btn-save" id="addTrackSave"><i class="bi bi-plus-lg me-1"></i> Add track</button>
              </div>
          </form>
      </div>
  </div>

  <!-- EDIT TRACK MODAL -->
  <div class="event-modal-overlay" id="editTrackOverlay">
      <div class="event-modal">
          <div class="event-modal-header">
              <div class="event-modal-title"><i class="bi bi-pencil-square"></i><span>Edit Track</span></div>
              <button class="event-modal-close" id="editTrackClose"><i class="bi bi-x-lg"></i></button>
          </div>
          <form id="editTrackForm" autocomplete="off">
              <input type="hidden" name="action" value="update_track">
              <input type="hidden" name="id" id="track-id">
              <div class="event-modal-body">
                  <div class="evt-section-label"><i class="bi bi-grid-1x2"></i> Track Information</div>
                  <div class="evt-field"><label>Track Name <span class="required">*</span></label><input type="text" name="track_name" id="track-name" required maxlength="255"></div>
                  <div class="evt-field"><label>Description</label><textarea name="description" id="track-description" rows="2"></textarea></div>
              </div>
              <div class="event-modal-footer">
                  <button type="button" class="evt-btn evt-btn-cancel" id="editTrackCancel">Cancel</button>
                  <button type="submit" class="evt-btn evt-btn-save" id="editTrackSave"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
              </div>
          </form>
      </div>
  </div>

  <!-- DELETE TRACK MODAL -->
  <div class="event-modal-overlay" id="deleteTrackOverlay">
      <div class="event-modal delete-modal">
          <div class="event-modal-header" style="padding-bottom:0"><div></div><button class="event-modal-close" id="deleteTrackClose"><i class="bi bi-x-lg"></i></button></div>
          <div class="event-modal-body" style="padding-top:4px">
              <div class="delete-modal-icon" style="background:var(--tm-danger-light);color:var(--tm-danger)"><i class="bi bi-exclamation-triangle-fill"></i></div>
              <div class="delete-modal-text"><h6>Delete Track?</h6><p>This will permanently remove <strong id="deleteTrackNameDisplay"></strong>. This action cannot be undone.</p></div>
          </div>
          <div class="event-modal-footer" style="justify-content:center">
              <button type="button" class="evt-btn evt-btn-cancel" id="deleteTrackCancel">Cancel</button>
              <a href="#" class="evt-btn evt-btn-danger" id="confirmDeleteTrackBtn"><i class="bi bi-trash3 me-1"></i> Delete</a>
          </div>
      </div>
  </div>

  <!-- ADD ELECTIVE MODAL -->
  <div class="event-modal-overlay" id="addElectiveOverlay">
      <div class="event-modal">
          <div class="event-modal-header">
              <div class="event-modal-title"><i class="bi bi-plus-circle-fill"></i><span>Add New Elective</span></div>
              <button class="event-modal-close" id="addElectiveClose"><i class="bi bi-x-lg"></i></button>
          </div>
          <form id="addElectiveForm" autocomplete="off">
              <input type="hidden" name="action" value="add_elective">
              <input type="hidden" name="track_id" id="add-elective-track-id">
              <div class="event-modal-body">
                  <div class="evt-section-label"><i class="bi bi-grid-1x2"></i> Elective Information</div>
                  <div class="evt-field"><label>Elective Name <span class="required">*</span></label><input type="text" name="elective_name" placeholder="e.g. General Academic" required maxlength="255"></div>
                  <div class="evt-field"><label>Description <small>(optional)</small></label><textarea name="description" rows="2" placeholder="Brief description of this elective..."></textarea></div>
              </div>
              <div class="event-modal-footer">
                  <button type="button" class="evt-btn evt-btn-cancel" id="addElectiveCancel">Cancel</button>
                  <button type="submit" class="evt-btn evt-btn-save" id="addElectiveSave"><i class="bi bi-plus-lg me-1"></i> Add elective</button>
              </div>
          </form>
      </div>
  </div>

  <!-- EDIT ELECTIVE MODAL -->
  <div class="event-modal-overlay" id="editElectiveOverlay">
      <div class="event-modal">
          <div class="event-modal-header">
              <div class="event-modal-title"><i class="bi bi-pencil-square"></i><span>Edit Elective</span></div>
              <button class="event-modal-close" id="editElectiveClose"><i class="bi bi-x-lg"></i></button>
          </div>
          <form id="editElectiveForm" autocomplete="off">
              <input type="hidden" name="action" value="update_elective">
              <input type="hidden" name="id" id="elective-id">
              <div class="event-modal-body">
                  <div class="evt-section-label"><i class="bi bi-grid-1x2"></i> Elective Information</div>
                  <div class="evt-field"><label>Elective Name <span class="required">*</span></label><input type="text" name="elective_name" id="elective-name" required maxlength="255"></div>
                  <div class="evt-field"><label>Description</label><textarea name="description" id="elective-description" rows="2"></textarea></div>
              </div>
              <div class="event-modal-footer">
                  <button type="button" class="evt-btn evt-btn-cancel" id="editElectiveCancel">Cancel</button>
                  <button type="submit" class="evt-btn evt-btn-save" id="editElectiveSave"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
              </div>
          </form>
      </div>
  </div>

  <!-- DELETE ELECTIVE MODAL -->
   <div class="event-modal-overlay" id="deleteElectiveOverlay">
       <div class="event-modal delete-modal">
           <div class="event-modal-header" style="padding-bottom:0"><div></div><button class="event-modal-close" id="deleteElectiveClose"><i class="bi bi-x-lg"></i></button></div>
           <div class="event-modal-body" style="padding-top:4px">
               <div class="delete-modal-icon" style="background:var(--tm-danger-light);color:var(--tm-danger)"><i class="bi bi-exclamation-triangle-fill"></i></div>
               <div class="delete-modal-text"><h6>Delete Elective?</h6><p>This will permanently remove <strong id="deleteElectiveNameDisplay"></strong>. This action cannot be undone.</p></div>
           </div>
           <div class="event-modal-footer" style="justify-content:center">
               <button type="button" class="evt-btn evt-btn-cancel" id="deleteElectiveCancel">Cancel</button>
               <a href="#" class="evt-btn evt-btn-danger" id="confirmDeleteElectiveBtn"><i class="bi bi-trash3 me-1"></i> Delete</a>
           </div>
       </div>
   </div>

   <!-- DELETE SUBJECT MODAL -->
   <div class="event-modal-overlay" id="deleteSubjectOverlay">
       <div class="event-modal delete-modal">
           <div class="event-modal-header" style="padding-bottom:0"><div></div><button class="event-modal-close" id="deleteSubjectClose"><i class="bi bi-x-lg"></i></button></div>
           <div class="event-modal-body" style="padding-top:4px">
               <div class="delete-modal-icon" style="background:var(--tm-danger-light);color:var(--tm-danger)"><i class="bi bi-exclamation-triangle-fill"></i></div>
               <div class="delete-modal-text"><h6>Delete Subject?</h6><p>This will permanently remove <strong id="deleteSubjectNameDisplay"></strong> from this elective. This action cannot be undone.</p></div>
           </div>
           <div class="event-modal-footer" style="justify-content:center">
               <button type="button" class="evt-btn evt-btn-cancel" id="deleteSubjectCancel">Cancel</button>
               <button type="button" class="evt-btn evt-btn-danger" id="confirmDeleteSubjectBtn"><i class="bi bi-trash3 me-1"></i> Delete</button>
           </div>
       </div>
   </div>

  <!-- ADD SUBJECT MODAL -->
   <div class="event-modal-overlay" id="addSubjectOverlay">
       <div class="event-modal">
           <div class="event-modal-header">
               <div class="event-modal-title"><i class="bi bi-book-plus"></i><span>Add Subjects to Elective</span></div>
               <button class="event-modal-close" id="addSubjectClose"><i class="bi bi-x-lg"></i></button>
           </div>
           <form id="addSubjectForm" autocomplete="off">
               <input type="hidden" name="action" value="add_elective_subject_bulk">
               <input type="hidden" name="elective_id" id="add-subject-elective-id">
               <div class="modal-addsubject-body">
                   <div class="evt-section-label"><i class="bi bi-book"></i> Subject Details</div>
                   <div id="subjectsRepeatable"></div>
               </div>
               <div class="modal-addsubject-actions">
                   <button type="button" class="add-row-btn" id="addSubjectRow"><i class="bi bi-plus-lg"></i> Add another subject</button>
               </div>
               <div class="event-modal-footer">
                   <button type="button" class="evt-btn evt-btn-cancel" id="addSubjectCancel">Cancel</button>
                   <button type="submit" class="evt-btn evt-btn-save" id="addSubjectSave"><i class="bi bi-check-lg me-1"></i> Save Subjects</button>
               </div>
           </form>
       </div>
   </div>

  <!-- EDIT SUBJECT MODAL -->
  <div class="event-modal-overlay" id="editSubjectOverlay">
      <div class="event-modal">
          <div class="event-modal-header">
              <div class="event-modal-title"><i class="bi bi-pencil-square"></i><span>Edit Subject</span></div>
              <button class="event-modal-close" id="editSubjectClose"><i class="bi bi-x-lg"></i></button>
          </div>
          <form id="editSubjectForm" autocomplete="off">
              <input type="hidden" name="action" value="update_elective_subject">
              <input type="hidden" name="subject_id" id="edit-subject-id">
              <div class="event-modal-body">
                  <div class="evt-section-label"><i class="bi bi-book"></i> Subject Details</div>
                  <!-- Subject code removed: generated automatically -->
                  <div class="evt-field"><label>Subject Name <span class="required">*</span></label><input type="text" name="subject_name" id="edit-subject-name" required maxlength="255"></div>
                  <div class="evt-row">
                      <div class="evt-flex-1 evt-field"><label>From Grade <span class="required">*</span></label>
                          <select name="grade_level" id="edit-subject-grade-from" class="form-select" required>
                              <option value="">Select</option>
                              <option value="11">Grade 11</option>
                              <option value="12">Grade 12</option>
                          </select>
                      </div>
                      <div class="evt-flex-1 evt-field"><label>To Grade <span class="required">*</span></label>
                          <select name="grade_level_end" id="edit-subject-grade-to" class="form-select" required>
                              <option value="">Select</option>
                              <option value="11">Grade 11</option>
                              <option value="12">Grade 12</option>
                          </select>
                      </div>
                  </div>
                  <div class="evt-field"><label>Description <small>(Optional)</small></label><textarea name="description" id="edit-subject-description" rows="2" placeholder="Optional"></textarea></div>
              </div>
              <div class="event-modal-footer">
                  <button type="button" class="evt-btn evt-btn-cancel" id="editSubjectCancel">Cancel</button>
                  <button type="submit" class="evt-btn evt-btn-save" id="editSubjectSave"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
              </div>
          </form>
      </div>
  </div>

  <div class="toast-container" id="toastContainer"></div>

<script>
(function() {
    'use strict';

    function openModal(el){if(!el)return;el.classList.add('show');document.body.style.overflow='hidden';var f=el.querySelector('input[type="text"],textarea,select');if(f)setTimeout(function(){f.focus();},200);}
    function closeModal(el){if(!el)return;el.classList.remove('show');if(!document.querySelector('.event-modal-overlay.show')){document.body.style.overflow='';}}
    function closeAll(){document.querySelectorAll('.event-modal-overlay.show').forEach(function(e){e.classList.remove('show');});document.body.style.overflow='';}
    document.querySelectorAll('.event-modal-overlay').forEach(function(o){o.addEventListener('click',function(e){if(e.target===o)closeModal(o);});});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')closeAll();});

    window.showToast=function(msg,type){type=type||'success';var c=document.getElementById('toastContainer');if(!c)return;var t=document.createElement('div');t.className='toast-notification toast-'+type;var icons={success:'check-circle-fill',info:'info-circle-fill',warning:'exclamation-triangle-fill',error:'exclamation-circle-fill'};var d=document.createElement('div');d.appendChild(document.createTextNode(msg));t.innerHTML='<i class="bi bi-'+(icons[type]||'info-circle-fill')+'"></i><span>'+d.innerHTML+'</span>';c.appendChild(t);setTimeout(function(){if(t.parentNode)t.remove();},4200);};

    function submitPostForm(url,form,btnId,successMsg,reload){
        var fd=new FormData(form);
        fd.append('csrf_token', getCsrf());
        return fetch(apiUrl(url),{method:'POST',credentials:'same-origin',body:fd}).then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
        .then(function(d){
            if(d.success||d.id){showToast(successMsg||'Saved', 'success');closeAll();if(reload!==false)setTimeout(function(){location.reload();},600);}
            else{showToast(d.message||'Failed.', 'error');}
        })
        .catch(function(err){showToast((err&&err.message)?err.message:'Something went wrong.', 'error');closeAll();if(reload!==false)setTimeout(function(){location.reload();},600);})
        .finally(function(){if(btnId){var b=document.getElementById(btnId);if(b){b.classList.remove('loading');b.innerHTML=b.getAttribute('data-original')||b.innerHTML;}}});
    }

    function escapeHtml(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}

    var BASE = window.BASE_URL || '';
    function apiUrl(path){
        if(BASE) return BASE + path;
        var p=window.location.pathname;
        var m=p.match(/^(.+?)\/(admin|teacher|gate)(\/|$)/);
        return m?m[1]+path:path;
    }
    function getCsrf(){var m=document.querySelector('meta[name="csrf-token"]');return m?m.getAttribute('content'):'';}

    /* ===== TRACKS ===== */
    var trackO=document.getElementById('addTrackOverlay'),trackF=document.getElementById('addTrackForm'),trackB=document.getElementById('addTrackSave');
    if(trackB) trackB.setAttribute('data-original', trackB.innerHTML);
    var openTrack=function(){if(trackF)trackF.reset();openModal(trackO);};
    document.getElementById('openAddTrack').addEventListener('click',openTrack);
    var openTrackMobile=document.getElementById('openAddTrackMobile');if(openTrackMobile)openTrackMobile.addEventListener('click',openTrack);
    document.getElementById('addTrackClose').addEventListener('click',function(){closeModal(trackO);});
    document.getElementById('addTrackCancel').addEventListener('click',function(){closeModal(trackO);});
    if(trackF){
        trackF.addEventListener('submit',function(e){
            e.preventDefault();
            var name=trackF.querySelector('[name="track_name"]').value.trim();
            if(!name){showToast('Please fill in Track Name.','error');return;}
            if(trackB){trackB.classList.add('loading');trackB.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Adding...';}
            submitPostForm('/api/subjects.php', trackF, 'addTrackSave', 'Track added!', true);
        });
    }

    var editTrackO=document.getElementById('editTrackOverlay'),editTrackF=document.getElementById('editTrackForm');
    document.querySelectorAll('.edit-track-trigger').forEach(function(btn){btn.addEventListener('click',function(){
        var t;try{t=JSON.parse(btn.getAttribute('data-track'));}catch(e){return;}
        document.getElementById('track-id').value=t.id||'';
        document.getElementById('track-name').value=t.track_name||'';
        document.getElementById('track-description').value=t.description||'';
        openModal(editTrackO);
    });});
    document.getElementById('editTrackClose').addEventListener('click',function(){closeModal(editTrackO);});
    document.getElementById('editTrackCancel').addEventListener('click',function(){closeModal(editTrackO);});
    if(editTrackF){
        editTrackF.addEventListener('submit',function(e){
            e.preventDefault();
            var id=document.getElementById('track-id').value;
            var name=document.getElementById('track-name').value.trim();
            if(!id||!name){showToast('Track ID and name are required.','error');return;}
            var saveB=document.getElementById('editTrackSave');if(saveB){saveB.classList.add('loading');saveB.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Saving...';}
            submitPostForm('/api/subjects.php', editTrackF, 'editTrackSave', 'Track updated!', true);
        });
    }

    var delTrackO=document.getElementById('deleteTrackOverlay'),delTrackN=document.getElementById('deleteTrackNameDisplay'),delTrackB=document.getElementById('confirmDeleteTrackBtn');
    document.querySelectorAll('.delete-track-trigger').forEach(function(btn){btn.addEventListener('click',function(){
        var id=btn.getAttribute('data-delete-id'),name=btn.getAttribute('data-delete-name')||'this track';
        if(delTrackN)delTrackN.textContent=name;if(delTrackB)delTrackB.href=apiUrl('/admin/strands.php')+'?delete_track='+(id||'0');
        openModal(delTrackO);
    });});
    document.getElementById('deleteTrackClose').addEventListener('click',function(){closeModal(delTrackO);});
    document.getElementById('deleteTrackCancel').addEventListener('click',function(){closeModal(delTrackO);});
    if(delTrackB){
        delTrackB.addEventListener('click',function(e){e.preventDefault();var h=delTrackB.href;if(!h||h.endsWith('delete_track=0'))return;delTrackB.classList.add('disabled');delTrackB.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Deleting...';location.href=h;});
    }

    /* ===== ELECTIVES ===== */
    var elecO=document.getElementById('addElectiveOverlay'),elecF=document.getElementById('addElectiveForm'),elecB=document.getElementById('addElectiveSave');
    if(elecB) elecB.setAttribute('data-original', elecB.innerHTML);
    document.querySelectorAll('.open-add-elective-modal').forEach(function(btn){
        btn.addEventListener('click',function(){
            var tid=btn.getAttribute('data-track-id');
            document.getElementById('add-elective-track-id').value=tid||'';
            if(elecF){elecF.reset();document.getElementById('add-elective-track-id').value=tid||'';}
            openModal(elecO);
        });
    });
    document.getElementById('addElectiveClose').addEventListener('click',function(){closeModal(elecO);});
    document.getElementById('addElectiveCancel').addEventListener('click',function(){closeModal(elecO);});
    if(elecF){
        elecF.addEventListener('submit',function(e){
            e.preventDefault();
            var tid=document.getElementById('add-elective-track-id').value;
            var name=elecF.querySelector('[name="elective_name"]').value.trim();
            if(!tid||!name){showToast('Track and Elective Name are required.','error');return;}
            if(elecB){elecB.classList.add('loading');elecB.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Adding...';}
            submitPostForm('/api/subjects.php', elecF, 'addElectiveSave', 'Elective added!', true);
        });
    }

    var editElecO=document.getElementById('editElectiveOverlay'),editElecF=document.getElementById('editElectiveForm');
    document.querySelectorAll('.btn-edit-trigger').forEach(function(btn){btn.addEventListener('click',function(){
        var t;try{t=JSON.parse(btn.getAttribute('data-elective'));}catch(e){return;}
        document.getElementById('elective-id').value=t.id||'';
        document.getElementById('elective-name').value=t.elective_name||'';
        document.getElementById('elective-description').value=t.description||'';
        openModal(editElecO);
    });});
    document.getElementById('editElectiveClose').addEventListener('click',function(){closeModal(editElecO);});
    document.getElementById('editElectiveCancel').addEventListener('click',function(){closeModal(editElecO);});
    if(editElecF){
        editElecF.addEventListener('submit',function(e){
            e.preventDefault();
            var id=document.getElementById('elective-id').value;
            var name=document.getElementById('elective-name').value.trim();
            if(!id||!name){showToast('Elective ID and name are required.','error');return;}
            var saveB=document.getElementById('editElectiveSave');if(saveB){saveB.classList.add('loading');saveB.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Saving...';}
            submitPostForm('/api/subjects.php', editElecF, 'editElectiveSave', 'Elective updated!', true);
        });
    }

    var delElecO=document.getElementById('deleteElectiveOverlay'),delElecN=document.getElementById('deleteElectiveNameDisplay'),delElecB=document.getElementById('confirmDeleteElectiveBtn');
    document.querySelectorAll('.btn-delete-trigger').forEach(function(btn){btn.addEventListener('click',function(){
        var id=btn.getAttribute('data-delete-id'),name=btn.getAttribute('data-delete-name')||'this elective';
        if(delElecN)delElecN.textContent=name;if(delElecB)delElecB.href=apiUrl('/admin/strands.php')+'?delete_elective='+(id||'0');
        openModal(delElecO);
    });});
    document.getElementById('deleteElectiveClose').addEventListener('click',function(){closeModal(delElecO);});
    document.getElementById('deleteElectiveCancel').addEventListener('click',function(){closeModal(delElecO);});
    if(delElecB){
        delElecB.addEventListener('click',function(e){e.preventDefault();var h=delElecB.href;if(!h||h.endsWith('delete_elective=0'))return;delElecB.classList.add('disabled');delElecB.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Deleting...';location.href=h;});
    }

    var delSubjO=document.getElementById('deleteSubjectOverlay'),delSubjN=document.getElementById('deleteSubjectNameDisplay'),delSubjB=document.getElementById('confirmDeleteSubjectBtn');
    var currentDeleteSubjectData = { electiveId: null, subjectId: null };
    /* Delegated: survives tbody innerHTML rebuilds from refreshElectiveTable() */
    document.addEventListener('click',function(e){
        var btn = e.target.closest ? e.target.closest('.delete-subject-trigger') : null;
        if (!btn) return;
        try {
            var row = btn.closest('tr');
            var subjectId = btn.getAttribute('data-subject-id');
            var electiveId = btn.getAttribute('data-elective-id');
            var nameCell = row ? row.querySelector('td:first-child') : null;
            var subjectName = (nameCell && nameCell.textContent.trim()) ? nameCell.textContent.trim() : 'this subject';
            if (!electiveId || !subjectId) { showToast('Missing IDs.', 'error'); return; }
            currentDeleteSubjectData = { electiveId: electiveId, subjectId: subjectId };
            if (delSubjN) delSubjN.textContent = subjectName;
            openModal(delSubjO);
        } catch (err) { showToast('Failed to open delete dialog.', 'error'); }
    });
    document.getElementById('deleteSubjectClose').addEventListener('click',function(){closeModal(delSubjO);});
    document.getElementById('deleteSubjectCancel').addEventListener('click',function(){closeModal(delSubjO);});
    if(delSubjB){
        delSubjB.addEventListener('click',function(){
            var eid = currentDeleteSubjectData.electiveId;
            var sid = currentDeleteSubjectData.subjectId;
            if (!eid || !sid) { showToast('Missing IDs.', 'error'); return; }
            delSubjB.classList.add('disabled');
            delSubjB.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Deleting...';
            var fd = new FormData();
            fd.append('action', 'delete_elective_subject');
            fd.append('elective_id', eid);
            fd.append('subject_id', sid);
            fd.append('csrf_token', getCsrf());
            fetch(apiUrl('/api/subjects.php'), { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function(r) { if (!r.ok) throw new Error(); return r.json(); })
                .then(function(d) {
                    if (d && d.success) {
                        showToast('Subject removed.', 'success');
                        closeModal(delSubjO);
                        if (eid) refreshElectiveTable(eid);
                    } else {
                        showToast((d && d.message) ? d.message : 'Failed to remove subject.', 'error');
                        delSubjB.classList.remove('disabled');
                        delSubjB.innerHTML = '<i class="bi bi-trash3 me-1"></i> Delete';
                    }
                })
                .catch(function(err) {
                    showToast('Failed to remove subject.', 'error');
                    delSubjB.classList.remove('disabled');
                    delSubjB.innerHTML = '<i class="bi bi-trash3 me-1"></i> Delete';
                });
        });
    }

    /* ===== SUBJECTS ===== */
    var subjO=document.getElementById('addSubjectOverlay'),subjF=document.getElementById('addSubjectForm'),subjB=document.getElementById('addSubjectSave');
    if(subjB) subjB.setAttribute('data-original', subjB.innerHTML);
    var subjectRowsContainer=null;
    function buildSubjectRow(){
        var row=document.createElement('div');row.className='repeat-row';
        // Subject code removed; codes will be generated automatically server-side

        var nameWrap=document.createElement('div');nameWrap.className='evt-field';nameWrap.style.cssText='flex:1 1 auto;min-width:140px';
        var nameLbl=document.createElement('label');nameLbl.innerHTML='<span class="required">*</span> Subject Name';
        var nameInp=document.createElement('input');nameInp.type='text';nameInp.name='subject_name[]';nameInp.placeholder='e.g. Biology 1';nameInp.maxLength=150;nameInp.required=true;
        nameWrap.appendChild(nameLbl);nameWrap.appendChild(nameInp);row.appendChild(nameWrap);

        var gradeRow=document.createElement('div');gradeRow.className='grade-row';
        var fromWrap=document.createElement('div');fromWrap.className='evt-field';fromWrap.style.cssText='flex:1 1 auto;min-width:90px';
        var fromLbl=document.createElement('label');fromLbl.innerHTML='<span class="required">*</span> From Grade';
        var fromSel=document.createElement('select');fromSel.name='grade_from[]';fromSel.required=true;fromSel.className='form-select';
        [{value:'11',text:'Grade 11'},{value:'12',text:'Grade 12'}].forEach(function(o){var opt=document.createElement('option');opt.value=o.value;opt.textContent=o.text;fromSel.appendChild(opt);});
        fromWrap.appendChild(fromLbl);fromWrap.appendChild(fromSel);gradeRow.appendChild(fromWrap);

        var toWrap=document.createElement('div');toWrap.className='evt-field';toWrap.style.cssText='flex:1 1 auto;min-width:90px';
        var toLbl=document.createElement('label');toLbl.innerHTML='<span class="required">*</span> To Grade';
        var toSel=document.createElement('select');toSel.name='grade_to[]';toSel.required=true;toSel.className='form-select';
        [{value:'11',text:'Grade 11'},{value:'12',text:'Grade 12'}].forEach(function(o){var opt=document.createElement('option');opt.value=o.value;opt.textContent=o.text;toSel.appendChild(opt);});
        toWrap.appendChild(toLbl);toWrap.appendChild(toSel);gradeRow.appendChild(toWrap);
        row.appendChild(gradeRow);

        var descWrap=document.createElement('div');descWrap.className='evt-field';descWrap.style.cssText='flex:1 1 auto;min-width:120px';
        var descLbl=document.createElement('label');descLbl.innerHTML='Description <small>(Optional)</small>';
        var descInp=document.createElement('input');descInp.type='text';descInp.name='subject_desc[]';descInp.placeholder='Optional';descInp.maxLength=50;descInp.required=false;
        descWrap.appendChild(descLbl);descWrap.appendChild(descInp);row.appendChild(descWrap);

        var rmWrap=document.createElement('div');rmWrap.style.cssText='flex:0 0 100%;display:flex;justify-content:flex-end';
        var rm=document.createElement('button');rm.type='button';rm.className='rm-row-btn';rm.title='Remove';rm.innerHTML='<i class="bi bi-x-lg"></i>';
        rm.addEventListener('click',function(){if(subjectRowsContainer.children.length>1)row.remove();});
        rmWrap.appendChild(rm);
        row.appendChild(rmWrap);
        return row;
    }
    function resetSubjectRows(){if(subjectRowsContainer){subjectRowsContainer.innerHTML='';subjectRowsContainer.appendChild(buildSubjectRow());}}
    document.querySelectorAll('.open-add-subject-modal').forEach(function(btn){
        btn.addEventListener('click',function(){
            var raw=btn.getAttribute('data-elective');var parsed=null;try{parsed=JSON.parse(raw);}catch(err){}
            if(parsed){
                document.getElementById('add-subject-elective-id').value=parsed.id||'';
                resetSubjectRows();
                openModal(subjO);
            }
        });
    });
    document.getElementById('addSubjectClose').addEventListener('click',function(){closeModal(subjO);});
    document.getElementById('addSubjectCancel').addEventListener('click',function(){closeModal(subjO);});
    if(subjF){
        subjF.addEventListener('submit',function(e){
            e.preventDefault();
            var eid=document.getElementById('add-subject-elective-id').value;
            if(!eid){showToast('Elective ID missing.','error');return;}
            if(subjB){subjB.classList.add('loading');subjB.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Saving...';}
            var fd=new FormData(subjF);
            fd.append('csrf_token', getCsrf());
            fetch(apiUrl('/api/subjects.php'),{method:'POST',credentials:'same-origin',body:fd}).then(function(r){if(!r.ok)throw new Error();return r.json();})
            .then(function(d){
                if(d && d.success && d.id){
                    showToast('Subjects saved!','success');
                    var eid=document.getElementById('add-subject-elective-id').value;
                    closeModal(subjO);
                    setTimeout(function(){refreshElectiveTable(eid);},600);
                }
                else{showToast((d && d.message) ? d.message : 'Failed to save subjects.','error');}
            })
            .catch(function(err){showToast('Failed to save subjects.','error');closeModal(subjO);setTimeout(function(){refreshElectiveTable(document.getElementById('add-subject-elective-id').value);},600);})
            .finally(function(){if(subjB){subjB.classList.remove('loading');subjB.innerHTML='<i class="bi bi-check-lg me-1"></i> Save Subjects';}});
        });
    }
    subjectRowsContainer=document.getElementById('subjectsRepeatable');
    if(subjectRowsContainer){
        subjectRowsContainer.appendChild(buildSubjectRow());
    }

    document.getElementById('addSubjectRow').addEventListener('click',function(){if(subjectRowsContainer)subjectRowsContainer.appendChild(buildSubjectRow());});

    var currentEditElectiveId = '';
    var editSubjO=document.getElementById('editSubjectOverlay'),editSubjF=document.getElementById('editSubjectForm');
    /* Delegated: survives tbody innerHTML rebuilds from refreshElectiveTable() */
    document.addEventListener('click',function(e){
        var btn = e.target.closest ? e.target.closest('.subjects-row .action-icon[title="Edit"]') : null;
        if (!btn) return;
        var cell=btn.closest('td');
        if(!cell)return;
        var delBtn=cell.querySelector('.delete-subject-trigger');
        if(!delBtn)return;
        var sid=delBtn.getAttribute('data-subject-id');
        var eid=delBtn.getAttribute('data-elective-id');
        if(!sid||!eid){showToast('Missing subject or elective ID.','error');return;}
        currentEditElectiveId=eid;
        var fd=new FormData();fd.append('action','get');fd.append('id',sid);fd.append('csrf_token',getCsrf());
        fetch(apiUrl('/api/subjects.php'),{method:'POST',credentials:'same-origin',body:fd}).then(function(r){if(!r.ok)throw new Error();return r.json();}).then(function(d){
            if(d && d.data){
                document.getElementById('edit-subject-id').value=d.data.id||sid;
                document.getElementById('edit-subject-name').value=d.data.subject_name||'';
                document.getElementById('edit-subject-grade-from').value=d.data.grade_level||'';
                document.getElementById('edit-subject-grade-to').value=d.data.grade_level_end||'';
                document.getElementById('edit-subject-description').value=d.data.description||'';
                openModal(editSubjO);
            }else{
                showToast('Subject not found.','error');
            }
        }).catch(function(err){showToast('Failed to load subject.','error');});
    });
    document.getElementById('editSubjectClose').addEventListener('click',function(){closeModal(editSubjO);});
    document.getElementById('editSubjectCancel').addEventListener('click',function(){closeModal(editSubjO);});
    if(editSubjF){
        editSubjF.addEventListener('submit',function(e){
            e.preventDefault();
            var sid=document.getElementById('edit-subject-id').value;
            var name=document.getElementById('edit-subject-name').value.trim();
            var gradeFrom=document.getElementById('edit-subject-grade-from').value;
            var gradeTo=document.getElementById('edit-subject-grade-to').value;
            if(!sid||!name){showToast('Subject ID and name are required.','error');return;}
            if(!gradeFrom||!gradeTo){showToast('Grade levels are required.','error');return;}
            var saveB=document.getElementById('editSubjectSave');if(saveB){saveB.classList.add('loading');saveB.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Saving...';}
            var fd=new FormData(editSubjF);
            fd.append('csrf_token', getCsrf());
            fetch(apiUrl('/api/subjects.php'),{method:'POST',credentials:'same-origin',body:fd}).then(function(r){if(!r.ok)throw new Error();return r.json();})
            .then(function(d){
                if(d && d.success){
                    showToast('Subject updated!','success');
                    closeModal(editSubjO);
                    if(currentEditElectiveId) refreshElectiveTable(currentEditElectiveId);
                }else{
                    showToast((d && d.message) ? d.message : 'Failed to update subject.','error');
                }
            })
            .catch(function(err){showToast('Failed to update subject.','error');closeModal(editSubjO);})
            .finally(function(){if(saveB){saveB.classList.remove('loading');saveB.innerHTML='<i class="bi bi-check-lg me-1"></i> Save Changes';}});
        });
    }

    function refreshElectiveTable(eid){
        eid = eid || document.getElementById('add-subject-elective-id').value;
        if(!eid)return;
        var fd=new FormData();fd.append('action','list_elective_subjects');fd.append('elective_id',eid);fd.append('csrf_token', getCsrf());
        fetch(apiUrl('/api/subjects.php'),{method:'POST',credentials:'same-origin',body:fd}).then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();}).then(function(d){
            var item=document.getElementById('elective-'+eid);
            if(item){
                var tbody=item.querySelector('.subjects-row tbody');
                if(tbody){
                    if(d && d.data && d.data.length){
                        var html='';
                        d.data.forEach(function(s){
                            html+='<tr><td>'+escapeHtml(s.subject_name)+'</td><td>'+(s.grade_level?escapeHtml(s.grade_level):'-')+'</td><td>'+(s.grade_level_end?escapeHtml(s.grade_level_end):'-')+'</td><td>'+(s.description?escapeHtml(s.description):'-')+'</td><td><button class="action-icon btn-tm-primary" style="border:none" title="Edit"><i class="bi bi-pencil"></i></button><button class="action-icon btn-tm-danger delete-subject-trigger" style="border:none;margin-left:6px" data-elective-id="'+eid+'" data-subject-id="'+s.id+'" title="Delete"><i class="bi bi-trash"></i></button></td></tr>';
                        });
                        tbody.innerHTML=html;
                    }else{
                        tbody.innerHTML='<tr><td colspan="5" class="no-subjects">No subjects added yet.</td></tr>';
                    }
                }
            }
        }).catch(function(err){console.error('refreshElectiveTable error:',err);});
    }

})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>