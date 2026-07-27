<?php
/**
 * LDB-FRAS - Face Registration Kiosk
 * Self-service kiosk for students to register their faces
 * Optimized for speed: Student ID → Capture → Done
 */
require_once __DIR__ . '/../config.php';
requireRole(['admin', 'gate']);

$backToUrl = trim((string)($_GET['return_to'] ?? ''));
if ($backToUrl === '') {
    $backToUrl = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
}
if ($backToUrl !== '' && !preg_match('/^https?:\/\//i', $backToUrl)) {
    $backToUrl = '';
}
$defaultBackUrl = BASE_URL . '/gate/index.php';
$effectiveBackUrl = $backToUrl !== '' ? $backToUrl : $defaultBackUrl;

// Get registration statistics
$stats = [
    'total'      => 0,
    'registered' => 0,
    'pending'    => 0
];

try {
    $total = $db->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn();
    $registered = $db->query("SELECT COUNT(*) FROM student_faces WHERE face_encoding IS NOT NULL")->fetchColumn();
    
    $stats['total'] = $total;
    $stats['registered'] = $registered;
    $stats['pending'] = max(0, $total - $registered);
} catch (Exception $e) {}

// Get recent registrations
$recentRegistrations = [];
try {
    $stmt = $db->query(
        "SELECT s.student_id, s.first_name, s.last_name, s.grade_level, s.section, sf.updated_at
         FROM student_faces sf
         JOIN students s ON sf.student_id = s.id
         WHERE sf.face_encoding IS NOT NULL
         ORDER BY sf.updated_at DESC LIMIT 10"
    );
    $recentRegistrations = $stmt->fetchAll();
} catch (Exception $e) {}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Registration Kiosk - <?= APP_NAME ?></title>
    <meta name="csrf-token" content="<?= generateCSRFToken() ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0A224C 0%, #1a3a6d 100%);
            min-height: 100vh;
            color: #fff;
        }
        .kiosk-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .kiosk-header {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 0;
        }
        .back-link {
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 999px;
            background: rgba(255,255,255,0.12);
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .back-link:hover {
            background: rgba(255,255,255,0.2);
            color: #fff;
            transform: translateY(-50%) translateX(-2px);
        }
        .header-copy {
            text-align: center;
        }
        .kiosk-header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        .kiosk-header .subtitle {
            opacity: 0.8;
            font-size: 1rem;
        }
        .progress-bar-container {
            background: rgba(255,255,255,0.08);
            border-radius: 50px;
            padding: 15px 25px;
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 20px;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.06);
        }
        .progress-bar-container .progress {
            flex: 1;
            height: 20px;
            background: rgba(255,255,255,0.1);
            border-radius: 50px;
            overflow: hidden;
        }
        .progress-bar-container .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #28A745, #20c997);
            border-radius: 50px;
            transition: width 0.5s ease;
        }
        .progress-stats {
            display: flex;
            gap: 30px;
            font-size: 0.9rem;
        }
        .progress-stats .stat {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .progress-stats .stat-value {
            font-weight: 700;
            font-size: 1.2rem;
        }
        .main-content {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 25px;
            margin-top: 20px;
        }
        
        /* ============================================================
           DARKER CONTAINERS FOR BETTER READABILITY
           ============================================================ */
        .camera-section {
            background: rgba(8, 16, 35, 0.92);
            border-radius: 16px;
            padding: 25px;
            border: 1px solid rgba(255,255,255,0.08);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
        }
        .student-info-card {
            background: rgba(8, 16, 35, 0.92);
            border-radius: 16px;
            padding: 25px;
            border: 1px solid rgba(255,255,255,0.08);
            min-height: 200px;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
        }
        .recent-card {
            background: rgba(8, 16, 35, 0.92);
            border-radius: 16px;
            padding: 20px;
            border: 1px solid rgba(255,255,255,0.08);
            flex: 1;
            overflow-y: auto;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
        }
        
        .camera-feed {
            position: relative;
            width: 100%;
            aspect-ratio: 4/3;
            background: #000;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
            border: 2px solid rgba(255,255,255,0.08);
        }
        /* Camera inverted (mirrored) */
        .camera-feed video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scaleX(-1);
            -webkit-transform: scaleX(-1);
            -moz-transform: scaleX(-1);
            -ms-transform: scaleX(-1);
            -o-transform: scaleX(-1);
        }
        .camera-overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 250px;
            height: 250px;
            border: 3px dashed rgba(255,255,255,0.5);
            border-radius: 50%;
            pointer-events: none;
        }
        .camera-status {
            position: absolute;
            bottom: 15px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .pulse-dot {
            width: 10px;
            height: 10px;
            background: #28A745;
            border-radius: 50%;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.8); }
        }
        .input-section {
            margin-top: 20px;
        }
        .input-section .form-control {
            font-size: 1.5rem;
            padding: 15px 20px;
            text-align: center;
            letter-spacing: 2px;
            font-weight: 600;
            background: rgba(255,255,255,0.08);
            border: 2px solid rgba(255,255,255,0.15);
            color: #fff;
            border-radius: 12px;
        }
        .input-section .form-control:focus {
            background: rgba(255,255,255,0.12);
            border-color: #0066FE;
            color: #fff;
            box-shadow: 0 0 0 3px rgba(0,102,254,0.3);
        }
        .input-section .form-control::placeholder {
            color: rgba(255,255,255,0.4);
        }
        .btn-register {
            width: 100%;
            padding: 15px;
            font-size: 1.2rem;
            font-weight: 600;
            border-radius: 12px;
            margin-top: 15px;
            background: linear-gradient(135deg, #28A745, #20c997);
            border: none;
            color: #fff;
            transition: all 0.3s ease;
        }
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(40,167,69,0.4);
        }
        .btn-register:disabled {
            opacity: 0.5;
            transform: none;
            box-shadow: none;
        }
        .btn-capture-face {
            width: 100%;
            padding: 15px;
            font-size: 1.2rem;
            font-weight: 600;
            border-radius: 12px;
            margin-top: 15px;
            background: linear-gradient(135deg, #0066FE, #00a2ff);
            border: none;
            color: #fff;
            transition: all 0.3s ease;
            animation: pulseBtn 2s infinite;
        }
        .btn-capture-face:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0,102,254,0.4);
        }
        @keyframes pulseBtn {
            0%, 100% { box-shadow: 0 0 0 0 rgba(0,102,254,0.4); }
            50% { box-shadow: 0 0 0 8px rgba(0,102,254,0); }
        }
        .btn-retake {
            flex: 1;
            padding: 15px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 12px;
            margin-top: 15px;
            background: rgba(255,255,255,0.1);
            border: 2px solid rgba(255,255,255,0.25);
            color: #fff;
            transition: all 0.3s ease;
        }
        .btn-retake:hover {
            background: rgba(255,255,255,0.18);
            border-color: rgba(255,255,255,0.4);
            color: #fff;
            transform: translateY(-2px);
        }
        .btn-group-preview .btn-register {
            flex: 1.3;
            margin-top: 15px;
        }

        /* Captured Image Preview Overlay */
        .captured-preview-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 5;
            border-radius: 12px;
            overflow: hidden;
            background: #000;
        }
        .captured-preview-overlay img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scaleX(-1);
            -webkit-transform: scaleX(-1);
        }
        .captured-preview-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            background: rgba(0,0,0,0.75);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 6px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-section {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .student-info-card h5 {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .student-detail {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }
        .student-detail:last-child { border-bottom: none; }
        .student-detail .label { opacity: 0.7; }
        .student-detail .value { font-weight: 600; }
        .recent-card h5 {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .recent-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            background: rgba(255,255,255,0.04);
            border-radius: 8px;
            margin-bottom: 8px;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .recent-item .icon {
            width: 35px;
            height: 35px;
            background: rgba(40,167,69,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .recent-item .info { flex: 1; }
        .recent-item .info .name { font-weight: 600; font-size: 0.9rem; }
        .recent-item .info .meta { font-size: 0.75rem; opacity: 0.7; }
        .result-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.85);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .result-overlay.show { display: flex; }
        .result-card {
            background: rgba(8, 16, 35, 0.95);
            border-radius: 20px;
            padding: 50px;
            text-align: center;
            max-width: 500px;
            animation: popIn 0.3s ease;
            border: 1px solid rgba(255,255,255,0.08);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        @keyframes popIn {
            from { transform: scale(0.8); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .result-card.success { border: 3px solid #28A745; }
        .result-card.error { border: 3px solid #DC3545; }
        .result-card .icon { font-size: 60px; margin-bottom: 20px; }
        .result-card h2 { margin-bottom: 10px; }
        .result-card p { opacity: 0.8; }
        .capture-preview { display: none; }
        .instructions {
            background: rgba(0,102,254,0.15);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #0066FE;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }
        .instructions h6 { margin-bottom: 8px; }
        .instructions ol { margin: 0; padding-left: 20px; }
        .instructions li { margin-bottom: 4px; font-size: 0.9rem; }
        
        /* ============================================================
           MOBILE RESPONSIVE
           ============================================================ */
        @media (max-width: 991px) {
            .main-content {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .sidebar-section {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
            }
            .kiosk-container {
                padding: 15px;
            }
        }
        
        @media (max-width: 767px) {
            .kiosk-container {
                padding: 10px;
            }
            .kiosk-header {
                flex-direction: column;
                padding: 10px 0;
                gap: 10px;
            }
            .kiosk-header h1 {
                font-size: 1.5rem;
            }
            .kiosk-header .subtitle {
                font-size: 0.85rem;
            }
            .back-link {
                position: static;
                transform: none;
                align-self: flex-start;
                padding: 8px 14px;
                font-size: 0.9rem;
            }
            .back-link:hover {
                transform: none;
            }
            .header-copy {
                text-align: center;
            }
            
            /* Progress bar - stacked on mobile */
            .progress-bar-container {
                flex-direction: column;
                gap: 12px;
                padding: 15px 18px;
                border-radius: 30px;
            }
            .progress-stats {
                flex-wrap: wrap;
                justify-content: center;
                gap: 15px;
                font-size: 0.8rem;
            }
            .progress-stats .stat-value {
                font-size: 1rem;
            }
            .progress-bar-container .progress {
                width: 100%;
                height: 16px;
            }
            
            /* Main content - stacked */
            .main-content {
                grid-template-columns: 1fr;
                gap: 15px;
                margin-top: 15px;
            }
            
            /* Camera - larger on mobile (portrait) */
            .camera-section {
                padding: 15px;
                border-radius: 14px;
            }
            .camera-feed {
                aspect-ratio: 3/4 !important;
                border-radius: 14px;
                margin-bottom: 15px;
                border-width: 2px;
            }
            .camera-overlay {
                width: 200px;
                height: 200px;
            }
            .camera-status {
                font-size: 0.75rem;
                padding: 6px 14px;
                bottom: 10px;
            }
            .pulse-dot {
                width: 8px;
                height: 8px;
            }
            
            /* Instructions - smaller on mobile */
            .instructions {
                padding: 12px;
                font-size: 0.85rem;
            }
            .instructions ol {
                padding-left: 16px;
            }
            .instructions li {
                font-size: 0.85rem;
            }
            
            /* Input - larger touch targets */
            .input-section .form-control {
                font-size: 1.2rem;
                padding: 14px 16px;
                border-radius: 10px;
            }
            .btn-register, .btn-capture-face {
                padding: 14px;
                font-size: 1rem;
                border-radius: 10px;
                margin-top: 12px;
            }
            .btn-retake {
                padding: 14px;
                font-size: 0.95rem;
                border-radius: 10px;
                margin-top: 12px;
            }
            .captured-preview-badge {
                font-size: 0.7rem;
                padding: 4px 10px;
            }
            
            /* Sidebar - stacked on mobile */
            .sidebar-section {
                display: flex;
                flex-direction: column;
                gap: 15px;
            }
            .student-info-card {
                padding: 18px;
                border-radius: 14px;
                min-height: auto;
            }
            .student-info-card h5 {
                font-size: 1rem;
                margin-bottom: 15px;
            }
            .student-detail {
                padding: 8px 0;
                font-size: 0.9rem;
            }
            .recent-card {
                padding: 15px;
                border-radius: 14px;
                max-height: 300px;
            }
            .recent-card h5 {
                font-size: 1rem;
                margin-bottom: 12px;
            }
            .recent-item {
                padding: 8px 12px;
                border-radius: 8px;
            }
            .recent-item .info .name {
                font-size: 0.85rem;
            }
            
            /* Result overlay - mobile */
            .result-card {
                padding: 30px 25px;
                max-width: 90%;
                border-radius: 16px;
            }
            .result-card .icon {
                font-size: 50px;
            }
            .result-card h2 {
                font-size: 1.3rem;
            }
            .result-card p {
                font-size: 0.9rem;
            }
            .result-card .btn {
                font-size: 0.9rem;
                padding: 10px 20px;
            }
            
            .capture-preview { display: none !important; }
        }
        
        @media (max-width: 576px) {
            .kiosk-container {
                padding: 6px;
            }
            .kiosk-header h1 {
                font-size: 1.2rem;
            }
            .kiosk-header .subtitle {
                font-size: 0.75rem;
            }
            .back-link {
                font-size: 0.8rem;
                padding: 6px 12px;
            }
            .progress-bar-container {
                padding: 12px 14px;
                border-radius: 20px;
                gap: 10px;
            }
            .progress-stats {
                gap: 10px;
                font-size: 0.7rem;
            }
            .progress-stats .stat-value {
                font-size: 0.9rem;
            }
            .camera-section {
                padding: 12px;
                border-radius: 12px;
            }
            .camera-feed {
                aspect-ratio: 9/12 !important;
                border-radius: 12px;
            }
            .camera-overlay {
                width: 160px;
                height: 160px;
            }
            .camera-status {
                font-size: 0.65rem;
                padding: 5px 12px;
                bottom: 8px;
            }
            .input-section .form-control {
                font-size: 1rem;
                padding: 12px 14px;
                letter-spacing: 1px;
            }
            .btn-register, .btn-capture-face {
                padding: 12px;
                font-size: 0.9rem;
            }
            .btn-retake {
                padding: 12px;
                font-size: 0.85rem;
            }
            .student-info-card {
                padding: 14px;
            }
            .student-detail {
                font-size: 0.8rem;
                padding: 6px 0;
            }
            .recent-card {
                padding: 12px;
                max-height: 250px;
            }
            .recent-item {
                padding: 6px 10px;
            }
            .recent-item .info .name {
                font-size: 0.8rem;
            }
            .recent-item .info .meta {
                font-size: 0.65rem;
            }
            .result-card {
                padding: 25px 20px;
                max-width: 95%;
            }
            .result-card .icon {
                font-size: 40px;
            }
            .result-card h2 {
                font-size: 1.1rem;
            }
            .capture-preview { display: none !important; }

        /* Event modal overlay - matches teachers.php */
        .event-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(26, 29, 46, 0.55);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 9998;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .event-modal-overlay.show {
            display: flex;
        }
        .event-modal {
            border-radius: 20px;
            width: 100%;
            max-width: 760px;
            max-height: 88vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: rgba(10, 34, 76, 0.90);
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.4);
            animation: modalSlideIn 0.35s cubic-bezier(.34,1.56,.64,1);
        }
        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(24px) scale(.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .event-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 22px;
            flex-shrink: 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .event-modal-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #ffffff;
        }
        .event-modal-title i {
            font-size: 20px;
            color: #60A5FA;
        }
        .event-modal-close {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 13px;
            background: rgba(255, 255, 255, 0.06);
            color: rgba(255, 255, 255, 0.5);
        }
        .event-modal-close:hover {
            background: rgba(239, 68, 68, 0.2);
            color: #F87171;
        }
        .event-modal-body {
            padding: 0 22px 14px;
            overflow-y: auto;
            flex: 1 1 auto;
            min-height: 0;
            -webkit-overflow-scrolling: touch;
            color: rgba(255, 255, 255, 0.65);
            font-size: 13px;
            line-height: 1.6;
        }
        .event-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 12px 22px;
            flex-shrink: 0;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.02);
        }
        .evt-btn-cancel {
            background: rgba(255, 255, 255, 0.08);
            color: rgba(255, 255, 255, 0.7);
            border: none;
            border-radius: 8px;
            padding: 10px 18px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .evt-btn-cancel:hover {
            background: rgba(255, 255, 255, 0.14);
            color: #fff;
        }
        .evt-btn-save {
            background: #4f46e5;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 18px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 16px rgba(79, 70, 229, 0.3);
        }
        .evt-btn-save:hover {
            background: #4338ca;
            box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4);
        }
    </style>
</head>
<body>
    <?php
    // Remove navbar include
    // require_once __DIR__ . '/../includes/pages-topnavbar.php';
    ?>
    <div class="kiosk-container">
        <!-- Header -->
        <div class="kiosk-header">
            <a class="back-link" href="<?= htmlspecialchars($effectiveBackUrl, ENT_QUOTES, 'UTF-8') ?>" onclick="if (window.location.search.indexOf('return_to=') !== -1) { return true; } if (document.referrer && document.referrer.indexOf(window.location.origin) === 0) { history.back(); return false; }">
                <i class="bi bi-arrow-left"></i>
                <span>Back</span>
            </a>
            <div class="header-copy">
                <h1><i class="bi bi-camera-fill"></i> Face Registration Kiosk</h1>
                <div class="subtitle">LDB-FRAS - Student Self-Service Registration</div>
            </div>
        </div>

        <!-- Progress Bar -->
        <div class="progress-bar-container">
            <div class="progress-stats">
                <div class="stat">
                    <i class="bi bi-people-fill"></i>
                    <span>Total: <span class="stat-value"><?= $stats['total'] ?></span></span>
                </div>
                <div class="stat">
                    <i class="bi bi-check-circle-fill" style="color: #28A745;"></i>
                    <span>Registered: <span class="stat-value"><?= $stats['registered'] ?></span></span>
                </div>
                <div class="stat">
                    <i class="bi bi-clock-fill" style="color: #FFC107;"></i>
                    <span>Pending: <span class="stat-value"><?= $stats['pending'] ?></span></span>
                </div>
            </div>
            <div class="progress">
                <div class="progress-bar" style="width: <?= $stats['total'] > 0 ? round(($stats['registered'] / $stats['total']) * 100) : 0 ?>%"></div>
            </div>
            <span class="fw-bold"><?= $stats['total'] > 0 ? round(($stats['registered'] / $stats['total']) * 100) : 0 ?>%</span>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Camera Section -->
            <div class="camera-section">
                <div class="instructions">
                    <h6><i class="bi bi-info-circle"></i> How to Register</h6>
                    <ol>
                        <li>Enter your Student LRN below</li>
                        <li>Click "Capture &amp; Register" to start the camera</li>
                        <li>Position your face in the circle, then click "Capture Face"</li>
                        <li>Review the photo, then click "Register" (or "Retake" to try again)</li>
                    </ol>
                </div>

                <div class="camera-feed" id="cameraContainer">
                    <video id="cameraVideo" autoplay playsinline></video>
                    <div class="camera-overlay"></div>
                    <div class="camera-status">
                        <span class="pulse-dot"></span>
                        <span id="cameraStatus">Awaiting Consent</span>
                    </div>
                    <!-- Captured Image Preview (full camera feed overlay) -->
                    <div class="captured-preview-overlay" id="capturedPreviewOverlay" style="display:none;">
                        <img id="capturedPreviewImg" src="" alt="Captured Face">
                        <div class="captured-preview-badge">
                            <i class="bi bi-image"></i> Preview
                        </div>
                    </div>
                </div>

                <!-- Input Section -->
                <div class="input-section">
                    <div class="mb-3">
                        <input type="text" class="form-control" id="studentIdInput" 
                                placeholder="Enter LRN" 
                               autofocus autocomplete="off">
                    </div>
                    <!-- State: Initial / Camera Starting -->
                    <div id="btnGroup-initial">
                        <button type="button" class="btn btn-register" id="registerBtn" onclick="startCameraAndCapture()" disabled>
                            <i class="bi bi-camera-fill"></i> Capture & Register
                        </button>
                    </div>
                    <!-- State: Camera Active - Ready to Capture -->
                    <div id="btnGroup-cameraActive" style="display:none;">
                        <button type="button" class="btn btn-capture-face" id="captureFaceBtn" onclick="captureFace()">
                            <i class="bi bi-camera"></i> Capture Face
                        </button>
                    </div>
                    <!-- State: Image Captured - Preview with Retake/Register -->
                    <div id="btnGroup-preview" style="display:none;">
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-retake" onclick="retakePhoto()">
                                <i class="bi bi-arrow-counterclockwise"></i> Retake
                            </button>
                            <button type="button" class="btn btn-register" id="confirmRegisterBtn" onclick="confirmRegister()">
                                <i class="bi bi-check-circle-fill"></i> Register
                            </button>
                        </div>
                    </div>
                    <!-- State: Processing -->
                    <div id="btnGroup-processing" style="display:none;">
                        <button type="button" class="btn btn-register" disabled>
                            <span class="spinner-border spinner-border-sm"></span> Processing...
                        </button>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="sidebar-section">
                <!-- Student Info -->
                <div class="student-info-card" id="studentInfoCard">
                    <h5><i class="bi bi-person-circle"></i> Student Information</h5>
                    <div id="studentInfo">
                        <div class="text-center py-4" style="opacity: 0.5;">
                            <i class="bi bi-search" style="font-size: 40px;"></i>
                            <p class="mt-2">Enter Student LRN to verify</p>
                        </div>
                    </div>
                </div>
                

                <!-- Recent Registrations -->
                <div class="recent-card">
                    <h5><i class="bi bi-clock-history"></i> Recent Registrations</h5>
                    <div id="recentList">
                        <?php if (empty($recentRegistrations)): ?>
                            <div class="text-center py-3" style="opacity: 0.5;">
                                <p>No registrations yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentRegistrations as $reg): ?>
                            <div class="recent-item">
                                <div class="icon">
                                    <i class="bi bi-check-lg" style="color: #28A745;"></i>
                                </div>
                                <div class="info">
                                    <div class="name"><?= sanitize($reg['first_name'] . ' ' . $reg['last_name']) ?></div>
                                    <div class="meta"><?= sanitize($reg['student_id']) ?> · Grade <?= $reg['grade_level'] ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Result Overlay -->
    <div class="result-overlay" id="resultOverlay">
        <div class="result-card" id="resultCard">
            <div class="icon" id="resultIcon"></div>
            <h2 id="resultTitle"></h2>
            <p id="resultMessage"></p>
            <button class="btn btn-light btn-lg mt-3" onclick="closeResult()">Continue</button>
        </div>
    </div>

    <!-- Consent Modal Overlay -->
    <div class="event-modal-overlay" id="consent-modal">
        <div class="event-modal">
            <div class="event-modal-header">
                <div class="event-modal-title">
                    <i class="bi bi-shield-lock-fill" style="color:#60A5FA;"></i>
                    <span>Data Privacy Verification</span>
                </div>
                <button class="event-modal-close" id="consentModalClose"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="event-modal-body">
                <p style="color:rgba(255,255,255,0.65);font-size:13px;line-height:1.6;">
                    You are about to register your facial profile into the <strong>Liceo de Baleno Automated Attendance System</strong>. By clicking <strong>"Accept & Start Camera"</strong>, you authorize the system to capture your facial features to automate your daily school logs. Your facial data is securely encrypted and protected under the Philippine Data Privacy Act of 2012. If you do not consent, click <strong>"Decline"</strong> to log attendance through traditional manual verification.
                </p>
            </div>
            <div class="event-modal-footer">
                <button type="button" class="evt-btn evt-btn-cancel" id="btn-decline">Decline & Exit</button>
                <button type="button" class="evt-btn evt-btn-save" id="btn-accept">Accept & Start Camera</button>
            </div>
        </div>
    </div>

    <!-- Update Face Confirmation Modal -->
    <div class="event-modal-overlay" id="updateFaceModal">
        <div class="event-modal">
            <div class="event-modal-header">
                <div class="event-modal-title">
                    <i class="bi bi-arrow-repeat" style="color:#f59e0b;"></i>
                    <span>Update Face Registration</span>
                </div>
                <button class="event-modal-close" id="updateFaceClose"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="event-modal-body">
                <p>This student already has a registered face. Do you want to <strong>UPDATE</strong> the existing face with this new photo?</p>
            </div>
            <div class="event-modal-footer">
                <button type="button" class="evt-btn evt-btn-cancel" id="updateFaceCancel">Retake Photo</button>
                <button type="button" class="evt-btn evt-btn-save" id="updateFaceConfirm"><i class="bi bi-arrow-repeat me-1"></i> Update Face</button>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- MediaPipe FaceMesh (liveness / anti-spoofing) - served locally for offline use -->
    <script>window.FACE_MESH_BASE = '<?= BASE_URL ?>/assets/vendor/face_mesh';</script>
    <script src="<?= BASE_URL ?>/assets/vendor/face_mesh/face_mesh.js"></script>
    <script src="<?= BASE_URL ?>/assets/js/liveness.js?v=<?= @filemtime(__DIR__ . '/../assets/js/liveness.js') ?: time() ?>"></script>
    <script>
        const BASE_URL = '<?= BASE_URL ?>';
        const CSRF_TOKEN = '<?= generateCSRFToken() ?>';
        
        let cameraStream = null;
        let currentStudentId = null;
        let isProcessing = false;
        let currentStudentHasFace = false;
        let consentGiven = false;
        let capturedImageBase64 = null;
        let liveness = null;        // liveness (anti-spoofing) watcher
        let livenessOk = false;     // becomes true once a real blink is verified

        // State: 'idle' | 'cameraActive' | 'preview' | 'processing'
        let appState = 'idle';

        // ============================================
        // STATE MANAGEMENT
        // ============================================
        function setState(newState) {
            appState = newState;
            const btnInitial = document.getElementById('btnGroup-initial');
            const btnCamera = document.getElementById('btnGroup-cameraActive');
            const btnPreview = document.getElementById('btnGroup-preview');
            const btnProcessing = document.getElementById('btnGroup-processing');
            const previewOverlay = document.getElementById('capturedPreviewOverlay');

            btnInitial.style.display = 'none';
            btnCamera.style.display = 'none';
            btnPreview.style.display = 'none';
            btnProcessing.style.display = 'none';
            previewOverlay.style.display = 'none';

            switch (newState) {
                case 'idle':
                    btnInitial.style.display = '';
                    updateRegisterButton();
                    break;
                case 'cameraActive':
                    btnCamera.style.display = '';
                    document.getElementById('cameraStatus').textContent = '👁️ Please blink to verify you are present';
                    updateCaptureButton();
                    break;
                case 'preview':
                    btnPreview.style.display = '';
                    previewOverlay.style.display = '';
                    document.getElementById('cameraStatus').textContent = 'Preview - Check your photo';
                    break;
                case 'processing':
                    btnProcessing.style.display = '';
                    document.getElementById('cameraStatus').textContent = 'Processing...';
                    break;
            }
        }

        function updateRegisterButton() {
            const btn = document.getElementById('registerBtn');
            if (currentStudentHasFace) {
                btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Update Face';
                btn.style.background = 'linear-gradient(135deg, #FFC107, #FF9800)';
            } else {
                btn.innerHTML = '<i class="bi bi-camera-fill"></i> Capture & Register';
                btn.style.background = 'linear-gradient(135deg, #28A745, #20c997)';
            }
        }

        // ============================================
        // INPUT HANDLING
        // ============================================
        window.addEventListener('load', function() {
            setupInputListeners();
        });

        function setupInputListeners() {
            const input = document.getElementById('studentIdInput');
            let lookupTimer = null;
            
            input.addEventListener('input', () => {
                clearTimeout(lookupTimer);
                const value = input.value.trim();
                if (value.length >= 3) {
                    lookupTimer = setTimeout(() => lookupStudent(value), 500);
                } else {
                    clearStudentInfo();
                }
            });

            input.addEventListener('keypress', (e) => {
                if (e.key === 'Enter' && !isProcessing) {
                    e.preventDefault();
                    if (appState === 'idle') startCameraAndCapture();
                    else if (appState === 'cameraActive') captureFace();
                    else if (appState === 'preview') confirmRegister();
                }
            });
        }

        // ============================================
        // STUDENT LOOKUP
        // ============================================
        async function lookupStudent(studentId) {
            try {
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('action', 'lookup_student');
                formData.append('student_id', studentId);

                const response = await fetch(`${BASE_URL}/api/gate.php`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                });

                if (!response.ok) {
                    clearStudentInfo();
                    return;
                }

                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    clearStudentInfo();
                    return;
                }

                let data;
                try {
                    data = await response.json();
                } catch (e) {
                    clearStudentInfo();
                    return;
                }

                if (data.success && data.student) {
                    showStudentInfo(data.student);
                    currentStudentId = data.student.id;
                    currentStudentHasFace = data.student.has_face || false;
                    document.getElementById('registerBtn').disabled = false;
                    updateRegisterButton();
                } else {
                    clearStudentInfo();
                }
            } catch (err) {
                console.error('Lookup error:', err);
            }
        }

        function showStudentInfo(student) {
            const hasFace = student.has_face;
            const registeredAt = student.face_registered_at;
            
            let statusHtml = hasFace
                ? `<span style="color: #FFC107;"><i class="bi bi-exclamation-triangle"></i> Already Registered</span>`
                : `<span style="color: #28A745;"><i class="bi bi-clock"></i> Pending Registration</span>`;
            
            let warningHtml = hasFace ? `
                <div class="alert alert-warning mt-3 mb-0" style="background: rgba(255,193,7,0.15); border-color: #FFC107; color: #fff; border-radius: 10px;">
                    <h6><i class="bi bi-exclamation-triangle-fill me-1"></i> Face Already Registered</h6>
                    <p class="mb-2">This student already has a registered face${registeredAt ? ' (registered on ' + registeredAt + ')' : ''}.</p>
                    <p class="mb-0">Click <strong>"Update Face"</strong> to replace with a new photo.</p>
                </div>
            ` : '';
            
            document.getElementById('studentInfo').innerHTML = `
                <div class="student-detail">
                    <span class="label">Name</span>
                    <span class="value">${student.first_name} ${student.last_name}</span>
                </div>
                <div class="student-detail">
                    <span class="label">Student LRN</span>
                    <span class="value">${student.student_id}</span>
                </div>
                <div class="student-detail">
                    <span class="label">Grade & Section</span>
                    <span class="value">Grade ${student.grade_level} - ${student.section || 'N/A'}</span>
                </div>
                <div class="student-detail">
                    <span class="label">Face Status</span>
                    <span class="value">${statusHtml}</span>
                </div>
                ${warningHtml}
            `;
        }

        function clearStudentInfo() {
            currentStudentId = null;
            currentStudentHasFace = false;
            capturedImageBase64 = null;
            
            setState('idle');
            stopCamera();

            document.getElementById('studentInfo').innerHTML = `
                <div class="text-center py-4" style="opacity: 0.5;">
                    <i class="bi bi-search" style="font-size: 40px;"></i>
                    <p class="mt-2">Enter Student LRN to verify</p>
                </div>
            `;
        }

        // ============================================
        // CAMERA CONTROL
        // ============================================
        async function initCamera() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: { 
                        width: { ideal: 640 }, 
                        height: { ideal: 480 }, 
                        facingMode: 'user' 
                    }
                });
                const video = document.getElementById('cameraVideo');
                video.srcObject = stream;
                cameraStream = stream;
                return true;
            } catch (err) {
                document.getElementById('cameraStatus').textContent = 'Camera Access Denied';
                console.error('Camera error:', err);
                return false;
            }
        }

        function stopCamera() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                cameraStream = null;
            }
            const video = document.getElementById('cameraVideo');
            video.srcObject = null;
            if (liveness) { liveness.stop(); liveness = null; }
            livenessOk = false;
        }

        async function waitForCamera(video) {
            return new Promise((resolve) => {
                if (video.videoWidth > 0 && video.videoHeight > 0) {
                    resolve();
                    return;
                }
                video.addEventListener('loadeddata', function onReady() {
                    video.removeEventListener('loadeddata', onReady);
                    resolve();
                });
            });
        }

        // ============================================
        // CAPTURE FLOW
        // ============================================
        async function startCameraAndCapture() {
            if (isProcessing || !currentStudentId) return;
            
            if (!consentGiven) {
                showConsentModal();
                return;
            }

            // Start camera
            const started = await initCamera();
            if (!started) {
                showResult('error', 'Camera Error', 'Failed to start camera. Please allow camera access.');
                return;
            }

            const video = document.getElementById('cameraVideo');
            await waitForCamera(video);
            setState('cameraActive');

            // Start liveness (anti-spoofing) so an ID photo cannot be registered.
            livenessOk = false;
            liveness = new Liveness({
                video: video,
                onStatus: onRegisterLivenessStatus
            });
            await liveness.start();
        }

        // Liveness prompt + button gating for the registration kiosk.
        function onRegisterLivenessStatus(status, isLive) {
            if (appState !== 'cameraActive') return;
            const statusEl = document.getElementById('cameraStatus');
            if (isLive) {
                livenessOk = true;   // a real blink was verified; keep it latched
            }
            if (livenessOk) {
                statusEl.textContent = 'Verified live - you may capture your face';
            } else if (status === 'no_face') {
                statusEl.textContent = 'Position your face in the circle...';
            } else {
                statusEl.textContent = '👁️ Please blink to verify you are present';
            }
            updateCaptureButton();
        }

        // Enable "Capture Face" only after liveness is verified. If MediaPipe
        // failed to load, liveness is disabled (fail-open) and capture stays on.
        function updateCaptureButton() {
            const btn = document.getElementById('captureFaceBtn');
            if (!btn) return;
            const enforce = liveness && !liveness._failOpen;
            const allow = !enforce || livenessOk;
            btn.disabled = !allow;
            btn.style.opacity = allow ? '' : '0.5';
            btn.style.cursor = allow ? '' : 'not-allowed';
        }

        function captureFace() {
            if (appState !== 'cameraActive') return;

            // Anti-spoofing: block capture until a genuine live blink is verified.
            const enforce = liveness && !liveness._failOpen;
            if (enforce && !livenessOk) {
                document.getElementById('cameraStatus').textContent =
                    '👁️ Please blink first so we can verify you are present';
                return;
            }

            const video = document.getElementById('cameraVideo');
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0);
            capturedImageBase64 = captureFrameInZone('cameraVideo', 0.15);

            if (!capturedImageBase64) {
                document.getElementById('cameraStatus').textContent =
                    'Camera error. Please try again.';
                return;
            }

            document.getElementById('capturedPreviewImg').src = capturedImageBase64;
            setState('preview');
        }

        function retakePhoto() {
            capturedImageBase64 = null;
            livenessOk = false;
            if (liveness) liveness.reset();
            setState('cameraActive');
            hideUpdateFaceModal();
        }

        function showUpdateFaceModal() {
            var modal = document.getElementById('updateFaceModal');
            if (modal) modal.classList.add('show');
        }

        function hideUpdateFaceModal() {
            var modal = document.getElementById('updateFaceModal');
            if (modal) modal.classList.remove('show');
        }

        async function proceedWithUpdate() {
            hideUpdateFaceModal();
            await doRegister();
        }

        async function confirmRegister() {
            if (isProcessing || !currentStudentId || !capturedImageBase64) return;

            if (currentStudentHasFace) {
                showUpdateFaceModal();
                return;
            }

            await doRegister();
        }

        async function doRegister() {
            setState('processing');
            isProcessing = true;

            try {
                const formData = new FormData();
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('action', 'register_face');
                formData.append('student_id', currentStudentId);
                formData.append('front_face', capturedImageBase64);
                formData.append('left_face', '');
                formData.append('right_face', '');

                const response = await fetch(`${BASE_URL}/api/gate.php`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`Server error: ${response.status} ${response.statusText}`);
                }

                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Invalid response from server. Please try again.');
                }

                let data;
                try {
                    data = await response.json();
                } catch (e) {
                    throw new Error('Server returned an invalid response. Please try again.');
                }

                if (data.success) {
                    const isUpdate = currentStudentHasFace;
                    const title = isUpdate ? 'Face Updated Successfully!' : 'Registration Successful!';
                    const message = isUpdate
                        ? `Face has been updated for ${data.student_name || 'this student'}.<br><small>Faces encoded: ${data.faces_encoded || 1}</small>`
                        : `${data.message}<br><small>Faces encoded: ${data.faces_encoded || 1}</small>`;
                    
                    showResult('success', title, message);
                    addToRecentList(data.student_name || 'Student', document.getElementById('studentIdInput').value);
                    
                    if (!isUpdate) updateStats(1);
                    
                    document.getElementById('studentIdInput').value = '';
                    currentStudentHasFace = false;
                    capturedImageBase64 = null;
                    clearStudentInfo();
                } else {
                    showResult('error', 'Registration Failed', data.error || 'Please try again');
                    setState('cameraActive');
                }
            } catch (err) {
                console.error('Registration error:', err);
                showResult('error', 'Error', err.message || 'Network error. Please try again.');
                setState('cameraActive');
            } finally {
                isProcessing = false;
            }
        }

        // ============================================
        // RESULT OVERLAY
        // ============================================
        function showResult(type, title, message) {
            const overlay = document.getElementById('resultOverlay');
            const card = document.getElementById('resultCard');
            const icon = document.getElementById('resultIcon');

            card.className = `result-card ${type}`;
            icon.innerHTML = type === 'success'
                ? '<i class="bi bi-check-circle-fill" style="color: #28A745;"></i>'
                : '<i class="bi bi-x-circle-fill" style="color: #DC3545;"></i>';
            
            document.getElementById('resultTitle').textContent = title;
            document.getElementById('resultMessage').innerHTML = message;
            overlay.classList.add('show');

            if (type === 'success') {
                setTimeout(() => {
                    closeResult();
                    document.getElementById('studentIdInput').focus();
                }, 2000);
            }
        }

        function closeResult() {
            document.getElementById('resultOverlay').classList.remove('show');
            document.getElementById('studentIdInput').focus();
        }

        // ============================================
        // RECENT LIST & STATS
        // ============================================
        function addToRecentList(name, studentId) {
            const list = document.getElementById('recentList');
            const html = `
                <div class="recent-item">
                    <div class="icon">
                        <i class="bi bi-check-lg" style="color: #28A745;"></i>
                    </div>
                    <div class="info">
                        <div class="name">${name}</div>
                        <div class="meta">${studentId} · Just now</div>
                    </div>
                </div>
            `;
            list.insertAdjacentHTML('afterbegin', html);
            
            const emptyMsg = list.querySelector('.text-center');
            if (emptyMsg) emptyMsg.remove();
            
            while (list.children.length > 10) {
                list.lastChild.remove();
            }
        }

        function updateStats(increment) {
            const registeredEl = document.querySelector('.progress-stats .stat:nth-child(2) .stat-value');
            const pendingEl = document.querySelector('.progress-stats .stat:nth-child(3) .stat-value');
            const percentEl = document.querySelector('.kiosk-container > .progress-bar-container .fw-bold');
            const progressBar = document.querySelector('.progress-bar');

            let registered = parseInt(registeredEl.textContent) + increment;
            let pending = parseInt(pendingEl.textContent) - increment;
            let total = parseInt(document.querySelector('.progress-stats .stat:first-child .stat-value').textContent);

            registeredEl.textContent = registered;
            pendingEl.textContent = Math.max(0, pending);

            const percent = total > 0 ? Math.round((registered / total) * 100) : 0;
            percentEl.textContent = percent + '%';
            progressBar.style.width = percent + '%';
        }

        // ============================================
        // CONSENT MODAL
        // ============================================
        function showConsentModal() {
            var modal = document.getElementById('consent-modal');
            if (modal) modal.classList.add('show');
        }

        function hideConsentModal() {
            var modal = document.getElementById('consent-modal');
            if (modal) modal.classList.remove('show');
        }

        function declineAndExit() {
            hideConsentModal();
            consentGiven = false;
            var input = document.getElementById('studentIdInput');
            if (input) {
                input.value = '';
                input.focus();
            }
            clearStudentInfo();
        }

        async function acceptAndStartCamera() {
            hideConsentModal();
            consentGiven = true;
            
            try {
                const started = await initCamera();
                if (!started) {
                    showResult('error', 'Camera Error', 'Failed to initialize camera. Please allow camera access and try again.');
                    return;
                }
                var video = document.getElementById('cameraVideo');
                await waitForCamera(video);
                setState('cameraActive');

                // Start liveness (anti-spoofing) so an ID photo cannot be registered.
                livenessOk = false;
                liveness = new Liveness({
                    video: video,
                    onStatus: onRegisterLivenessStatus
                });
                await liveness.start();
            } catch (err) {
                console.error('Camera init failed:', err);
                showResult('error', 'Camera Error', 'Failed to initialize camera. Please allow camera access and try again.');
            }
        }

        // Consent modal event listeners
        document.getElementById('btn-decline').addEventListener('click', declineAndExit);
        document.getElementById('btn-accept').addEventListener('click', acceptAndStartCamera);

        var consentModal = document.getElementById('consent-modal');
        var consentCloseBtn = document.getElementById('consentModalClose');
        if (consentCloseBtn) {
            consentCloseBtn.addEventListener('click', declineAndExit);
        }
        if (consentModal) {
            consentModal.addEventListener('click', function(e) {
                if (e.target === consentModal) declineAndExit();
            });
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && consentModal && consentModal.classList.contains('show')) {
                declineAndExit();
            }
        });

        // Update face modal event listeners
        document.getElementById('updateFaceConfirm').addEventListener('click', proceedWithUpdate);
        document.getElementById('updateFaceCancel').addEventListener('click', retakePhoto);

        var updateFaceModal = document.getElementById('updateFaceModal');
        var updateFaceCloseBtn = document.getElementById('updateFaceClose');
        if (updateFaceCloseBtn) {
            updateFaceCloseBtn.addEventListener('click', retakePhoto);
        }
        if (updateFaceModal) {
            updateFaceModal.addEventListener('click', function(e) {
                if (e.target === updateFaceModal) retakePhoto();
            });
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && updateFaceModal && updateFaceModal.classList.contains('show')) {
                retakePhoto();
            }
        });

        // Cleanup on page unload
        window.addEventListener('beforeunload', () => {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
            }
        });
    </script>
</body>
</html>