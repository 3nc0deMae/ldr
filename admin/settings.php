<?php

require_once __DIR__ . '/../config.php';
requireRole(['admin']);

$pageTitle = 'System Settings';
// Load all settings before handling any output
$settings = [];
try {
    $stmt = $db->query("SELECT * FROM settings ORDER BY setting_key");
    foreach ($stmt->fetchAll() as $s) {
        $settings[$s['setting_key']] = $s['setting_value'];
    }
} catch (Exception $e) {}

// Load legal / compliance engine settings from the dedicated system_settings table
$legal = [
    'dpa_master_notice'         => '',
    'system_terms_conditions'   => '',
    'dpa_version_tag'           => '',
    'dpa_consent_label_student' => '',
    'dpa_consent_label_staff'   => '',
    'privacy_dpo_contact'       => ''
];
try {
    $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('dpa_master_notice','system_terms_conditions','dpa_version_tag','dpa_consent_label_student','dpa_consent_label_staff','privacy_dpo_contact')");
    foreach ($stmt->fetchAll() as $s) {
        $legal[$s['setting_key']] = $s['setting_value'];
    }
} catch (Exception $e) {}

    $notifConfig = [];
    try {
        $stmt = $db->query("SELECT * FROM system_notification_config ORDER BY id ASC LIMIT 1");
        $notifConfig = $stmt->fetch() ?: [];
    } catch (Exception $e) {}

    // Handle save BEFORE any HTML output (header/sidebar includes)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $activeTab = sanitize($_POST['tab_name'] ?? 'school');
    $updated = 0;
    $stmt = $db->prepare(
        "INSERT INTO settings (setting_key, setting_value, updated_at)
         VALUES (:key, :value, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
    );

    foreach ($_POST as $key => $value) {
        if ($key === 'save_settings' || $key === 'tab_name') continue;
        if (strpos($key, 'setting_') === 0) {
            $settingKey = str_replace('setting_', '', $key);
            // Skip privacy-related keys — handled by save_settings_processor.php → system_settings table
            if (in_array($settingKey, ['privacy_dpo_contact','privacy_consent_label','privacy_kiosk_notice','privacy_master_terms','dpa_master_notice','system_terms_conditions','dpa_version_tag','dpa_consent_label_student','dpa_consent_label_staff'])) continue;
            $stmt->execute([':key' => $settingKey, ':value' => $value]);
            $updated++;
        }
    }

    redirect('/admin/settings.php?tab=' . $activeTab, "$updated setting(s) saved successfully.", 'success');
}

$activeTab = sanitize($_GET['tab'] ?? 'school');
$pageTitle = 'System Settings';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/notifications.php';

// Define BASE_URL for JavaScript
$baseUrl = BASE_URL;
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-theme.css">
<style>
/* ═══════════════════════════════════════════════════════════════════════════
   DESIGN SYSTEM — System Settings (Dark Theme)
   ═══════════════════════════════════════════════════════════════════════════ */

:root {
    --set-font: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    --set-mono: 'JetBrains Mono', 'SF Mono', monospace;

    --set-primary: #4f46e5;
    --set-primary-light: rgba(79,70,229,0.15);
    --set-primary-dark: #3730a3;
    --set-primary-glow: rgba(79,70,229,0.25);

    --set-accent: #4f46e5;
    --set-accent-soft: rgba(79,70,229,0.15);
    --set-accent-hover: #4338ca;

    --set-success: #10b981;
    --set-success-soft: rgba(16,185,129,0.15);
    --set-danger: #ef4444;
    --set-danger-soft: rgba(239,68,68,0.15);
    --set-warning: #f59e0b;
    --set-warning-soft: rgba(245,158,11,0.15);
    --set-info: #06b6d4;
    --set-info-soft: rgba(6,182,212,0.15);

    --set-bg: #0b0b14;
    --set-surface: rgba(255,255,255,0.04);
    --set-surface-hover: rgba(255,255,255,0.07);
    --set-surface-card: rgba(255,255,255,0.04);
    --set-border: rgba(255,255,255,0.06);
    --set-border-light: rgba(255,255,255,0.04);
    --set-text: #f0ece4;
    --set-text-secondary: rgba(255,255,255,0.55);
    --set-text-muted: rgba(255,255,255,0.3);

    --set-shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
    --set-shadow: 0 4px 16px rgba(0,0,0,0.25);
    --set-shadow-md: 0 8px 24px rgba(0,0,0,0.3);
    --set-shadow-lg: 0 12px 40px rgba(0,0,0,0.35);
    --set-shadow-xl: 0 20px 50px rgba(0,0,0,0.4);

    --set-radius-sm: 10px;
    --set-radius: 14px;
    --set-radius-lg: 16px;
    --set-radius-xl: 20px;
    --set-transition: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    --set-spring: 0.35s cubic-bezier(0.34,1.56,0.64,1);
}

/* ─── Base ────────────────────────────────────────────────────────────── */

/* ─── Top Navbar ──────────────────────────────────────────────────────── */
.top-navbar {
    padding: 16px 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    position: sticky;
    top: 0;
    z-index: 100;
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    background: rgba(11,11,20,0.85);
}
.navbar-left { display: flex; align-items: center; gap: 16px; flex-shrink: 0; min-width: 0; }
.top-navbar .page-title h5 {
    font-size: 20px; font-weight: 800; color: var(--set-text);
    margin: 0; letter-spacing: -0.03em;
}
.top-navbar .page-title small {
    font-size: 13px; color: var(--set-text-secondary); font-weight: 500;
}

/* MOBILE PAGE TITLE */
.mobile-title { display: none; padding: 14px 0 4px; }
.mobile-title-inner { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.mobile-title-left h5 { font-size: 18px; font-weight: 800; letter-spacing: -0.03em; margin: 0; color: var(--set-text); }
.mobile-title-left small { font-size: 12px; font-weight: 500; color: var(--set-text-secondary); }
.mobile-date { font-size: 11px; font-weight: 600; padding: 6px 10px; border-radius: var(--set-radius-sm); display: flex; align-items: center; gap: 6px; white-space: nowrap; flex-shrink: 0; margin-top: 2px; background: var(--set-surface); color: var(--set-text-secondary); }
.mobile-date i { font-size: 12px; color: #ffffff; }

.navbar-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.navbar-actions .date-pill {
    font-size: 13px; font-weight: 600; padding: 8px 16px;
    border-radius: var(--set-radius-sm);
    background: var(--set-surface); border: none;
    color: var(--set-text-secondary);
    display: flex; align-items: center; gap: 8px;
}
.navbar-actions .date-pill i { font-size: 14px; color: #ffffff; }

/* NAV ICON BUTTON */
.nav-icon-btn {
    width: 40px; height: 40px;
    border-radius: var(--set-radius-sm);
    background: var(--set-surface); border: none;
    color: var(--set-text);
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all var(--set-transition); font-size: 16px;
    flex-shrink: 0;
}
.nav-icon-btn:hover { transform: translateY(-1px); background: var(--set-surface-hover); }

.content-area { padding: 24px 28px 40px; }

/* ─── Cards ───────────────────────────────────────────────────────────── */
.card {
    background: var(--set-surface-card);
    border: none;
    border-radius: var(--set-radius);
    box-shadow: var(--set-shadow-sm);
    transition: all var(--set-transition);
    overflow: hidden;
    margin-bottom: 20px;
}
.card:hover { box-shadow: var(--set-shadow); }
.card-header {
    background: transparent;
    border-bottom: 1px solid var(--set-border);
    padding: 14px 20px;
    font-size: 14px; font-weight: 700;
    color: var(--set-text);
    letter-spacing: -0.01em;
    display: flex; align-items: center;
}
.card-header i { color: #ffffff; font-size: 15px; }
.card-body { padding: 20px; color: var(--set-text); }

/* ─── Tabs — Pill style, wrapping so every tab stays visible ──────────── */
.settings-tabs-wrapper {
    margin-bottom: 24px;
    overflow-x: visible;
}
.settings-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    list-style: none;
    padding: 0;
    margin: 0;
    white-space: normal;
}
.settings-tab {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 10px 20px;
    border-radius: var(--set-radius-sm);
    font-size: 13px;
    font-weight: 600;
    color: var(--set-text-secondary);
    background: var(--set-surface);
    border: 1.5px solid transparent;
    text-decoration: none;
    transition: all var(--set-transition);
    cursor: pointer;
    flex-shrink: 0;
}
.settings-tab:hover {
    color: var(--set-text);
    background: var(--set-surface-hover);
    border-color: var(--set-border);
    transform: translateY(-1px);
}
.settings-tab.active {
    color: #fff;
    background: var(--set-accent);
    border-color: var(--set-accent);
    box-shadow: 0 4px 16px rgba(79,70,229,0.3);
}
.settings-tab i { font-size: 14px; color: #ffffff; }
.settings-tab.active i { color: #ffffff; }

/* ─── Form Controls ───────────────────────────────────────────────────── */
.set-label {
    display: block;
    font-size: 12px; font-weight: 700;
    color: var(--set-text);
    margin-bottom: 7px;
    letter-spacing: -0.01em;
}
.set-label .label-hint {
    font-weight: 500;
    color: var(--set-text-muted);
    font-size: 11px;
    margin-left: 4px;
}
.set-input,
.set-select,
.set-textarea {
    width: 100%;
    padding: 11px 16px;
    border: 1.5px solid var(--set-border);
    border-radius: var(--set-radius-sm);
    font-size: 14px;
    font-weight: 500;
    color: var(--set-text);
    background: var(--set-surface);
    transition: all var(--set-transition);
    font-family: var(--set-font);
}
.set-input:focus,
.set-select:focus,
.set-textarea:focus {
    border-color: var(--set-accent);
    box-shadow: 0 0 0 3px var(--set-primary-glow);
    outline: none;
    background: var(--set-surface-hover);
}
.set-input::placeholder { color: var(--set-text-muted); font-weight: 500; }
.set-select {
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='rgba(255,255,255,0.4)' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 36px;
    cursor: pointer;
}
.set-select option { background: #1a1a2e; color: var(--set-text); }
.set-hint {
    font-size: 11px; font-weight: 500;
    color: var(--set-text-muted);
    margin-top: 5px;
    display: block;
}
.set-hint a { color: var(--set-accent); text-decoration: underline; }
.set-hint a:hover { color: #818cf8; }

/* Password visibility toggle */
.input-password-wrap { position: relative; }
.input-password-wrap .set-input { padding-right: 44px; }
.input-password-toggle {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    width: 32px; height: 32px;
    border: none; border-radius: 8px;
    background: transparent;
    color: var(--set-text-muted);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    transition: all var(--set-transition);
}
.input-password-toggle:hover {
    color: var(--set-text);
    background: var(--set-surface-hover);
}

/* ─── Field Group ─────────────────────────────────────────────────────── */
.field-group { margin-bottom: 20px; }
.field-group:last-child { margin-bottom: 0; }

/* ─── Buttons ─────────────────────────────────────────────────────────── */
.btn-save {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 14px 32px;
    border: none;
    border-radius: var(--set-radius-sm);
    font-size: 15px;
    font-weight: 700;
    color: #fff;
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    box-shadow: 0 4px 20px rgba(79,70,229,0.4), 0 0 0 1px rgba(79,70,229,0.3);
    cursor: pointer;
    transition: all var(--set-spring);
    letter-spacing: 0.01em;
    position: relative;
    overflow: hidden;
}
.btn-save::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    opacity: 0;
    transition: opacity 0.3s ease;
}
.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 32px rgba(79,70,229,0.5), 0 0 0 1px rgba(124,58,237,0.4);
    color: #fff;
}
.btn-save:hover::before { opacity: 1; }
.btn-save:active { transform: translateY(0); box-shadow: 0 2px 12px rgba(79,70,229,0.3); }
.btn-save i, .btn-save span { position: relative; z-index: 1; }

.btn-test {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 18px;
    border: 1.5px solid transparent;
    border-radius: var(--set-radius-sm);
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all var(--set-transition);
    letter-spacing: 0.01em;
    flex-shrink: 0;
}
.btn-test-email {
    color: #fff;
    background: linear-gradient(135deg, #0891b2 0%, #06b6d4 100%);
    box-shadow: 0 2px 12px rgba(6,182,212,0.25);
}
.btn-test-email:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(6,182,212,0.35);
    color: #fff;
}
.btn-test-sms {
    color: #fff;
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    box-shadow: 0 2px 12px rgba(16,185,129,0.25);
}
.btn-test-sms:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(16,185,129,0.35);
    color: #fff;
}

/* ─── Test Result Alert ───────────────────────────────────────────────── */
.test-result {
    padding: 12px 16px;
    border-radius: var(--set-radius-sm);
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 12px;
    animation: fadeUp 0.3s ease forwards;
}
.test-result.info { background: rgba(6,182,212,0.12); color: #22d3ee; border: 1px solid rgba(6,182,212,0.2); }
.test-result.success { background: rgba(16,185,129,0.12); color: #34d399; border: 1px solid rgba(16,185,129,0.2); }
.test-result.danger { background: rgba(239,68,68,0.12); color: #f87171; border: 1px solid rgba(239,68,68,0.2); }
.test-result.warning { background: rgba(245,158,11,0.12); color: #fbbf24; border: 1px solid rgba(245,158,11,0.2); }
.test-result i { font-size: 16px; flex-shrink: 0; }

/* ═══════════════════════════════════════════════════════════════════════════
   MODALS — MATCHING ANNOUNCEMENTS PAGE EXACTLY
   ═══════════════════════════════════════════════════════════════════════════ */
.event-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(26, 29, 46, 0.5);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    z-index: 10000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 24px;
}
.event-modal-overlay.show { display: flex; }

.event-modal {
    border-radius: 20px;
    width: min(400px, 100%);
    max-width: 100%;
    height: auto;
    max-height: 85vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    background: rgba(26, 29, 46, 0.98);
    border: 1px solid rgba(79, 70, 229, 0.15);
    box-shadow: 0 24px 60px rgba(0,0,0,0.5), 0 0 0 1px rgba(79, 70, 229, 0.08), 0 0 80px rgba(26, 29, 46, 0.5);
    animation: eventModalSlideIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
    color: var(--set-text);
    margin: auto;
}
@keyframes eventModalSlideIn {
    from { opacity: 0; transform: translateY(24px) scale(0.96); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

.event-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 18px 22px;
    flex-shrink: 0;
}
.event-modal-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 15px;
    font-weight: 800;
    letter-spacing: -0.02em;
}
.event-modal-title i { font-size: 18px; }
.event-modal-close {
    width: 30px;
    height: 30px;
    border: none;
    border-radius: var(--set-radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all var(--set-transition);
    font-size: 12px;
    background: rgba(255,255,255,0.06);
    color: var(--set-text-secondary);
}
.event-modal-close:hover {
    background: rgba(255,255,255,0.14);
    color: var(--set-text);
}

.event-modal-body {
    padding: 18px 22px;
    overflow-y: auto;
    flex: 1;
}
.event-modal-body::-webkit-scrollbar { width: 5px; }
.event-modal-body::-webkit-scrollbar-track { background: transparent; }
.event-modal-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.12); border-radius: 10px; }
.event-modal-body::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.22); }
.event-modal-body { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.12) transparent; }

.event-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 14px 22px;
    flex-shrink: 0;
    border-top: 1px solid rgba(255,255,255,0.06);
    background: rgba(255,255,255,0.02);
}

.evt-btn {
    padding: 9px 16px;
    border: none;
    border-radius: var(--set-radius-sm);
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: all var(--set-spring);
    display: inline-flex;
    align-items: center;
    gap: 6px;
    letter-spacing: 0.01em;
}
.evt-btn-cancel {
    background: rgba(255,255,255,0.08);
    color: var(--set-text-secondary);
    border: 1.5px solid rgba(255,255,255,0.1);
}
.evt-btn-cancel:hover {
    background: rgba(255,255,255,0.16);
    color: var(--set-text);
    border-color: rgba(255,255,255,0.25);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}
.evt-btn-save {
    background: var(--set-primary);
    color: #fff;
    border: 1.5px solid transparent;
    box-shadow: 0 3px 12px rgba(79,70,229,0.4);
    position: relative;
    overflow: hidden;
}
.evt-btn-save::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(255,255,255,0.15) 0%, transparent 60%);
    opacity: 0;
    transition: opacity 0.3s ease;
}
.evt-btn-save:hover {
    background: var(--set-primary-dark, #3730a3);
    box-shadow: 0 6px 24px rgba(79,70,229,0.5);
    transform: translateY(-1px);
}
.evt-btn-save:hover::before { opacity: 1; }
.evt-btn-save:active {
    transform: translateY(0);
    box-shadow: 0 2px 8px rgba(79,70,229,0.3);
}
.evt-btn-save.loading {
    opacity: 0.65;
    pointer-events: none;
}

/* ─── Toasts ──────────────────────────────────────────────────────────── */
.toast-container {
    position: fixed; bottom: 28px; right: 28px; z-index: 9999;
    display: flex; flex-direction: column; gap: 10px;
    pointer-events: none;
}
.set-toast {
    padding: 14px 22px; border-radius: var(--set-radius-sm);
    color: #fff; font-size: 13px; font-weight: 600;
    display: flex; align-items: center; gap: 12px;
    max-width: 400px; pointer-events: auto;
    animation: toastIn 0.4s cubic-bezier(0.34,1.56,0.64,1), toastOut 0.4s ease 3.6s forwards;
    font-family: var(--set-font);
    box-shadow: 0 8px 24px rgba(0,0,0,0.5);
}
.set-toast.success { background: #059669; }
.set-toast.danger  { background: #dc2626; }
.set-toast.warning { background: #d97706; color: #1a1a1a; }
.set-toast.info    { background: #2563eb; }
.set-toast i { font-size: 18px; flex-shrink: 0; opacity: 0.9; }
@keyframes toastIn { from { opacity: 0; transform: translateX(40px) scale(0.95); } to { opacity: 1; transform: translateX(0) scale(1); } }
@keyframes toastOut { from { opacity: 1; transform: translateX(0); } to { opacity: 0; transform: translateX(40px); } }

/* ─── Stagger Animation ───────────────────────────────────────────────── */
.animate-in {
    opacity: 0; transform: translateY(16px);
    animation: fadeUp 0.5s cubic-bezier(0.4, 0, 0.2, 1) forwards;
}
.animate-in:nth-child(2) { animation-delay: 0.05s; }
.animate-in:nth-child(3) { animation-delay: 0.1s; }
.animate-in:nth-child(4) { animation-delay: 0.15s; }
@keyframes fadeUp { to { opacity: 1; transform: translateY(0); } }

/* ─── Scrollbar ───────────────────────────────────────────────────────── */
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }

/* ─── Form Field Spacing ──────────────────────────────────────────────── */
.row.g-3 > * { padding-left: 8px; padding-right: 8px; }
.row.g-3 { margin-left: -8px; margin-right: -8px; }

/* ═══════════════════════════════════════════════════════════════════════════
   RESPONSIVE — TABLET (max-width: 991px)
   ═══════════════════════════════════════════════════════════════════════════ */
@media (max-width: 991px) {
    .content-area { padding: 20px; }
    .top-navbar { padding: 16px; }
    .settings-tab { padding: 9px 16px; font-size: 12px; }
}

/* ═══════════════════════════════════════════════════════════════════════════
   RESPONSIVE — MOBILE (max-width: 767px) — MATCHING ANNOUNCEMENTS PAGE EXACTLY
   ═══════════════════════════════════════════════════════════════════════════ */
@media (max-width: 767px) {
    /* ─── Navbar — EXACT MATCH with announcements page ──────────────── */
    .top-navbar { padding: 12px 14px; flex-wrap: nowrap; gap: 8px; align-items: center; }
    .navbar-left { flex: 1; gap: 10px; min-width: 0; }
    #sidebarToggle { width: 38px; height: 38px; font-size: 20px; flex-shrink: 0; }
    .navbar-brand { display: flex; }
    .navbar-brand-logo { width: 44px; height: 44px; }
    .navbar-brand-name { font-size: 12px; }
    .navbar-brand-sub { font-size: 9px; opacity: 0.45; }
    .desktop-title { display: none !important; }
    .desktop-date { display: none !important; }
    .mobile-title { display: block !important; }
    .navbar-actions { gap: 6px; flex-shrink: 0; }
    .nav-icon-btn { width: 38px; height: 38px; font-size: 15px; }

    /* ─── Content ─────────────────────────────────────────────────────── */
    .content-area { padding: 10px 12px 28px; }
    .card { border-radius: var(--set-radius-sm); margin-bottom: 16px; }
    .card-header { font-size: 13px; padding: 12px 16px; }
    .card-header .d-flex { flex-wrap: wrap; gap: 8px; }
    .card-body { padding: 16px; }

    /* ─── Tabs ────────────────────────────────────────────────────────── */
    .settings-tabs-wrapper { margin-bottom: 16px; margin-left: -4px; margin-right: -4px; padding: 0 4px; }
    .settings-tabs { gap: 5px; }
    .settings-tab { padding: 8px 14px; font-size: 11px; gap: 5px; }
    .settings-tab i { font-size: 13px; }

    /* ─── Forms ───────────────────────────────────────────────────────── */
    .set-label { font-size: 11px; margin-bottom: 5px; }
    .set-input, .set-select { padding: 10px 14px; font-size: 13px; }
    .set-hint { font-size: 10px; }
    .field-group { margin-bottom: 16px; }

    /* ─── Grid ────────────────────────────────────────────────────────── */
    .row.g-3 > * { padding-left: 4px; padding-right: 4px; }
    .row.g-3 { margin-left: -4px; margin-right: -4px; }
    .col-6 { flex: 0 0 50%; max-width: 50%; }

    /* ─── Buttons ─────────────────────────────────────────────────────── */
    .btn-save { padding: 12px 24px; font-size: 14px; width: 100%; justify-content: center; }
    .btn-test { padding: 7px 12px; font-size: 10px; white-space: nowrap; }

    /* ─── MODALS — BOTTOM SHEET (matching announcements page) ────────── */
    .event-modal-overlay {
        align-items: flex-end;
        padding: 0;
    }
    .event-modal {
        width: 100%;
        max-width: 100vw;
        border-radius: 16px 16px 0 0;
        animation: eventSheetUp 0.3s ease-out;
        height: auto;
        max-height: 90vh;
    }
    @keyframes eventSheetUp {
        from { transform: translateY(100%); }
        to   { transform: translateY(0); }
    }
    .event-modal::before {
        content: '';
        display: block;
        width: 36px;
        height: 4px;
        border-radius: 4px;
        background: rgba(255,255,255,0.2);
        margin: 8px auto 0;
        flex-shrink: 0;
    }
    .event-modal-header { padding: 14px 18px; }
    .event-modal-title { font-size: 14px; gap: 9px; }
    .event-modal-title i { font-size: 17px; }
    .event-modal-close { width: 28px; height: 28px; font-size: 11px; }
    .event-modal-body { padding: 14px 18px 18px; }
    .event-modal-footer {
        padding: 12px 18px;
        padding-bottom: calc(12px + env(safe-area-inset-bottom, 0px));
    }
    .evt-btn { padding: 8px 14px; font-size: 11px; }

    /* ─── Toasts ──────────────────────────────────────────────────────── */
    .toast-container { bottom: 24px; right: 12px; left: 12px; }
    .set-toast { max-width: 100%; font-size: 12px; padding: 12px 16px; }

    /* ─── Animations ──────────────────────────────────────────────────── */
    .animate-in { animation: none; opacity: 1; }
    
    /* ─── Password toggle ────────────────────────────────────────────── */
    .input-password-toggle { width: 28px; height: 28px; font-size: 12px; }
    .input-password-wrap .set-input { padding-right: 36px; }
}

/* ═══════════════════════════════════════════════════════════════════════════
   RESPONSIVE — SMALL PHONE (max-width: 576px)
   ═══════════════════════════════════════════════════════════════════════════ */
@media (max-width: 576px) {
    /* ─── Navbar — EXACT MATCH ──────────────────────────────────────── */
    .top-navbar { padding: 10px 10px; }
    .navbar-brand-logo { width: 38px; height: 38px; }
    .navbar-brand-name { font-size: 11px; }
    .navbar-brand-sub { font-size: 8px; }
    .navbar-actions { gap: 4px; }
    .nav-icon-btn { width: 34px; height: 34px; font-size: 14px; }
    #sidebarToggle { width: 34px; height: 34px; font-size: 18px; }
    .mobile-date { font-size: 10px; padding: 5px 8px; }
    .content-area { padding: 8px 8px 24px; }

    .card { border-radius: 10px; }
    .card-header { font-size: 12px; padding: 10px 12px; }
    .card-body { padding: 12px; }

    /* ─── Tabs ────────────────────────────────────────────────────────── */
    .settings-tabs { gap: 4px; }
    .settings-tab { padding: 6px 10px; font-size: 10px; gap: 4px; }
    .settings-tab i { font-size: 11px; }

    /* ─── Forms ───────────────────────────────────────────────────────── */
    .set-label { font-size: 10px; }
    .set-input, .set-select { padding: 9px 12px; font-size: 12px; }
    .set-hint { font-size: 9px; }
    .field-group { margin-bottom: 12px; }

    /* ─── Buttons ─────────────────────────────────────────────────────── */
    .btn-save { padding: 11px 18px; font-size: 13px; }
    .btn-test { padding: 5px 10px; font-size: 9px; }

    /* ─── MODALS — Small phone ────────────────────────────────────────── */
    .event-modal { max-height: 95vh; height: auto; }
    .event-modal::before { margin-top: 8px; }
    .event-modal-header { padding: 12px 14px; }
    .event-modal-title { font-size: 14px; }
    .event-modal-body { padding: 14px 14px 14px; }
    .event-modal-footer {
        padding: 10px 14px;
        padding-bottom: calc(10px + env(safe-area-inset-bottom, 0px));
    }
    .evt-btn { padding: 8px 14px; font-size: 11px; }

    .set-toast { font-size: 11px; padding: 10px 14px; gap: 10px; }
    .set-toast i { font-size: 16px; }

    .sidebar { width: 260px; }
}

/* ─── Desktop — hide mobile-only elements ─────────────────────────────── */
@media (min-width: 768px) {
    .mobile-title { display: none !important; }
}

/* ═══════════════════════════════════════════════════════════════════════════
   LEGAL & COMPLIANCE — SPLIT-CARD GRID (Glassmorphic)
   ═══════════════════════════════════════════════════════════════════════════ */
.split-card-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    align-items: start;
}
@media (max-width: 991px) {
    .split-card-grid { grid-template-columns: 1fr; }
}

.legal-card-badge {
    width: 46px; height: 46px;
    border-radius: 14px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 22px; flex-shrink: 0;
}
.badge-dpa { background: rgba(6,182,212,0.15); color: #22d3ee; box-shadow: 0 0 0 1px rgba(6,182,212,0.25) inset; }
.badge-tc  { background: rgba(168,85,247,0.15); color: #c084fc; box-shadow: 0 0 0 1px rgba(168,85,247,0.25) inset; }
.legal-card-title { font-size: 15px; font-weight: 800; color: var(--set-text); margin: 0; letter-spacing: -0.02em; }
.legal-card-sub { font-size: 12px; font-weight: 500; color: var(--set-text-secondary); }

/* Scroll-optimized legal text viewports */
.legal-scroll {
    max-height: 15rem;       /* ~ max-h-60 */
    overflow-y: auto;
    resize: vertical;
    line-height: 1.55;
    font-family: var(--set-mono);
    font-size: 12.5px;
    white-space: pre-wrap;
}
.legal-scroll::-webkit-scrollbar { width: 6px; }
.legal-scroll::-webkit-scrollbar-track { background: transparent; }
.legal-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.14); border-radius: 3px; }
.legal-scroll::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.24); }

/* Live preview trigger link */
.preview-link {
    border: none; background: transparent; cursor: pointer;
    font-size: 11px; font-weight: 700; color: var(--set-info);
    display: inline-flex; align-items: center; gap: 5px;
    padding: 2px 4px; border-radius: 6px;
    transition: all var(--set-transition);
}
.preview-link:hover { color: #67e8f9; background: rgba(6,182,212,0.12); }
.preview-link i { font-size: 12px; }

/* ─── Symmetric Action Footer ─────────────────────────────────────────── */
.action-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
}
.action-footer .footer-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}
.btn-save.btn-save-green {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    box-shadow: 0 4px 20px rgba(16,185,129,0.4), 0 0 0 1px rgba(16,185,129,0.3);
}
.btn-save.btn-save-green:hover {
    box-shadow: 0 8px 32px rgba(16,185,129,0.5), 0 0 0 1px rgba(16,185,129,0.4);
}
.btn-save.btn-save-green::before { background: linear-gradient(135deg, #10b981 0%, #34d399 100%); }

.btn-reset {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    padding: 14px 26px;
    border: 1.5px solid var(--set-border);
    border-radius: var(--set-radius-sm);
    font-size: 14px; font-weight: 700;
    color: var(--set-text-secondary);
    background: var(--set-surface);
    text-decoration: none;
    cursor: pointer;
    transition: all var(--set-transition);
}
.btn-reset:hover {
    color: var(--set-text);
    background: var(--set-surface-hover);
    border-color: rgba(255,255,255,0.18);
    transform: translateY(-1px);
}
.btn-reset i { font-size: 15px; }

/* ─── Live Portal Preview Modal ───────────────────────────────────────── */
.preview-portal {
    width: min(440px, 100%);
    background: linear-gradient(160deg, rgba(15,23,42,0.98) 0%, rgba(2,6,23,0.98) 100%);
    border: 1px solid rgba(34,211,238,0.25);
    border-radius: 20px;
    box-shadow: 0 24px 60px rgba(0,0,0,0.6), 0 0 80px rgba(8,47,73,0.4);
    color: #e2e8f0;
    max-height: 85vh;
    display: flex; flex-direction: column;
    overflow: hidden;
    animation: eventModalSlideIn 0.35s cubic-bezier(0.34,1.56,0.64,1);
}
.preview-portal-head {
    padding: 16px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    display: flex; align-items: center; gap: 10px;
    font-size: 13px; font-weight: 800; letter-spacing: -0.02em;
}
.preview-portal-head .kiosk-dot {
    width: 9px; height: 9px; border-radius: 50%;
    background: #22d3ee; box-shadow: 0 0 10px #22d3ee;
}
.preview-portal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
    font-size: 12.5px;
    line-height: 1.6;
    white-space: pre-wrap;
    font-family: var(--set-mono);
}
.preview-portal-body::-webkit-scrollbar { width: 5px; }
.preview-portal-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.14); border-radius: 10px; }
.preview-portal-foot {
    padding: 14px 20px;
    border-top: 1px solid rgba(255,255,255,0.08);
    background: rgba(255,255,255,0.02);
    display: flex; align-items: center; gap: 10px;
}
.preview-consent {
    flex: 1;
    font-size: 11.5px; color: rgba(226,232,240,0.75);
    display: flex; align-items: flex-start; gap: 8px;
}
.preview-consent input { margin-top: 2px; flex-shrink: 0; accent-color: #22d3ee; }

@media (max-width: 767px) {
    .action-footer { flex-direction: column-reverse; align-items: stretch; }
    .action-footer .footer-actions { flex-direction: column-reverse; }
    .btn-reset, .btn-save { width: 100%; justify-content: center; }
    .preview-portal { width: 100%; max-width: 100vw; border-radius: 16px 16px 0 0; }
}

</style>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/pages-navbar.css">
<style>
.card { background: var(--set-surface-card); border: none; border-radius: var(--set-radius); box-shadow: var(--set-shadow-sm); transition: all var(--set-transition); overflow: hidden; margin-bottom: 20px; }
.card:hover { box-shadow: var(--set-shadow); }
.card-header { background: transparent; border-bottom: 1px solid var(--set-border); padding: 14px 20px; font-size: 14px; font-weight: 700; color: var(--set-text); letter-spacing: -0.01em; display: flex; align-items: center; }
.card-header i { color: #ffffff; font-size: 15px; }
.card-body { padding: 20px; color: var(--set-text); }
</style>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<?php require_once __DIR__ . '/../includes/pages-topnavbar.php'; ?>
<div class="main-content">

    <div class="content-area">

        <div class="d-none d-md-flex justify-content-between align-items-center gap-3 mb-3">
            <div class="page-title mb-0">
                <h5 class="mb-0">System Settings</h5>
                <small>Configure school info, email, SMS, and attendance rules</small>
            </div>
        </div>

        <!-- MOBILE TITLE -->
        <div class="page-title mobile-title">
            <div class="mobile-title-inner">
                <div class="mobile-title-left">
                    <h5>System Settings</h5>
                    <small>Configure school info, email, SMS, and attendance rules</small>
                </div>
            </div>
        </div>

        <!-- ═══ Tabs ═══ -->
        <div class="settings-tabs-wrapper animate-in">
            <div class="settings-tabs" role="tablist">
                <a class="settings-tab <?= $activeTab === 'school' ? 'active' : '' ?>" href="?tab=school">
                    <i class="bi bi-building"></i> <span>School Info</span>
                </a>
                <a class="settings-tab <?= $activeTab === 'smtp' ? 'active' : '' ?>" href="?tab=smtp">
                    <i class="bi bi-envelope"></i> <span>SMTP</span>
                </a>
                <a class="settings-tab <?= $activeTab === 'sms' ? 'active' : '' ?>" href="?tab=sms">
                    <i class="bi bi-chat-dots"></i> <span>SMS</span>
                </a>
                <a class="settings-tab <?= $activeTab === 'attendance' ? 'active' : '' ?>" href="?tab=attendance">
                    <i class="bi bi-calendar-check"></i> <span>Attendance</span>
                </a>
                <a class="settings-tab <?= $activeTab === 'legal' ? 'active' : '' ?>" href="?tab=legal">
                    <i class="bi bi-shield-lock"></i> <span>Legal &amp; Compliance</span>
                </a>
                <a class="settings-tab <?= $activeTab === 'privacy' ? 'active' : '' ?>" href="?tab=privacy">
                    <i class="bi bi-shield-check"></i> <span>Privacy Policy</span>
                </a>
            </div>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/save_settings_processor.php">
            <?= csrfField() ?>
            <input type="hidden" name="save_settings" value="1">
            <input type="hidden" name="tab_name" value="<?= $activeTab ?>">

            <?php if ($activeTab === 'school'): ?>
            <!-- ===== SCHOOL INFORMATION ===== -->
            <div class="card animate-in">
                <div class="card-header"><span style="display:inline-flex;align-items:center;gap:6px;"><i class="bi bi-building"></i> School Information</span></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="set-label">School Name</label>
                                <input type="text" class="set-input" name="setting_school_name"
                                       value="<?= htmlspecialchars($settings['school_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="Liceo de Baleno">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-group">
                                <label class="set-label">School Address</label>
                                <input type="text" class="set-input" name="setting_school_address"
                                       value="<?= htmlspecialchars($settings['school_address'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="Baleno, Masbate, Philippines">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="field-group">
                                <label class="set-label">School Year</label>
                                <input type="text" class="set-input" name="setting_school_year"
                                       value="<?= htmlspecialchars($settings['school_year'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="2025-2026">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="field-group">
                                <label class="set-label">Principal Name</label>
                                <input type="text" class="set-input" name="setting_principal_name"
                                       value="<?= htmlspecialchars($settings['principal_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="Dr. Juan Dela Cruz">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="field-group">
                                <label class="set-label">Contact Number</label>
                                <input type="text" class="set-input" name="setting_school_phone"
                                       value="<?= htmlspecialchars($settings['school_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="09XX-XXX-XXXX">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif ($activeTab === 'smtp'): ?>
            <!-- ===== SMTP CONFIGURATION ===== -->
            <div class="card animate-in">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span style="display:flex;align-items:center;gap:6px;"><i class="bi bi-envelope"></i> <span class="d-none d-sm-inline">SMTP / Email Configuration</span><span class="d-inline d-sm-none">SMTP</span></span>
                    <button type="button" class="btn-test btn-test-email" id="btnTestEmail">
                        <i class="bi bi-send"></i> <span class="d-none d-sm-inline">Test Email</span><span class="d-inline d-sm-none">Test</span>
                    </button>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <div class="field-group">
                                <label class="set-label">Enable Email</label>
                                <select name="setting_enable_email_notifications" class="set-select">
                                    <option value="1" <?= ($settings['enable_email_notifications'] ?? '1') === '1' ? 'selected' : '' ?>>Enabled</option>
                                    <option value="0" <?= ($settings['enable_email_notifications'] ?? '') === '0' ? 'selected' : '' ?>>Disabled</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="field-group">
                                <label class="set-label">SMTP Host</label>
                                <input type="text" class="set-input" name="setting_smtp_host"
                                       value="<?= htmlspecialchars($settings['smtp_host'] ?? 'smtp.gmail.com', ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="smtp.gmail.com">
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="field-group">
                                <label class="set-label">Port</label>
                                <input type="number" class="set-input" name="setting_smtp_port"
                                       value="<?= htmlspecialchars($settings['smtp_port'] ?? '587', ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="587">
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="field-group">
                                <label class="set-label">Encryption</label>
                                <select name="setting_smtp_encryption" class="set-select">
                                    <option value="tls" <?= ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS</option>
                                    <option value="ssl" <?= ($settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                    <option value="none" <?= ($settings['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="field-group">
                                <label class="set-label">SMTP Username / Email</label>
                                <input type="text" class="set-input" name="setting_smtp_username"
                                       value="<?= htmlspecialchars($settings['smtp_username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="your-email@gmail.com">
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="field-group">
                                <label class="set-label">SMTP Password</label>
                                <div class="input-password-wrap">
                                    <input type="password" class="set-input" name="setting_smtp_password" id="smtpPassword"
                                           value="<?= htmlspecialchars($settings['smtp_password'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                           placeholder="App password">
                                    <button type="button" class="input-password-toggle" onclick="togglePassword('smtpPassword', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <span class="set-hint">Use App Password for Gmail</span>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="field-group">
                                <label class="set-label">From Email</label>
                                <input type="email" class="set-input" name="setting_smtp_from_email"
                                       value="<?= htmlspecialchars($settings['smtp_from_email'] ?? 'noreply@liceodebaleno.edu.ph', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="field-group">
                                <label class="set-label">From Name</label>
                                <input type="text" class="set-input" name="setting_smtp_from_name"
                                       value="<?= htmlspecialchars($settings['smtp_from_name'] ?? 'LDB-FRAS', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="field-group">
                                <label class="set-label">Email Monitor</label>
                                <div id="emailMonitorBox" style="display:flex;align-items:center;gap:16px;padding:14px 18px;border-radius:var(--set-radius-sm);background:var(--set-surface);border:1px solid var(--set-border);">
                                    <div id="emailMonitorIcon" style="width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#4f46e5,#7c3aed);flex-shrink:0;">
                                        <i class="bi bi-envelope-check" style="font-size:20px;color:#fff;"></i>
                                    </div>
                                    <div style="flex:1;min-width:0;">
                                        <div style="font-size:26px;font-weight:800;color:var(--set-text);letter-spacing:-0.03em;line-height:1.1;" id="emailQuotaText">0</div>
                                        <div style="font-size:12px;font-weight:500;color:var(--set-text-secondary);margin-top:2px;" id="emailQuotaStatus">No emails sent today</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif ($activeTab === 'sms'): ?>
            <!-- ===== SMS CONFIGURATION ===== -->
            <div class="card animate-in">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span style="display:flex;align-items:center;gap:6px;"><i class="bi bi-chat-dots"></i> <span class="d-none d-sm-inline">SMS Configuration</span><span class="d-inline d-sm-none">SMS</span></span>
                    <button type="button" class="btn-test btn-test-sms" id="btnTestSMS">
                        <i class="bi bi-send"></i> <span class="d-none d-sm-inline">Test SMS</span><span class="d-inline d-sm-none">Test</span>
                    </button>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <div class="field-group">
                                <label class="set-label">Enable SMS</label>
                                <select name="setting_enable_sms_notifications" class="set-select">
                                    <option value="0" <?= ($settings['enable_sms_notifications'] ?? '0') === '0' ? 'selected' : '' ?>>Disabled</option>
                                    <option value="1" <?= ($settings['enable_sms_notifications'] ?? '') === '1' ? 'selected' : '' ?>>Enabled</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="field-group">
                                <label class="set-label">Provider</label>
                                <select name="setting_sms_provider" class="set-select">
                                    <option value="textbee" <?= ($settings['sms_provider'] ?? 'textbee') === 'textbee' ? 'selected' : '' ?>>textbee</option>
                                    <option value="twilio" <?= ($settings['sms_provider'] ?? '') === 'twilio' ? 'selected' : '' ?>>Twilio</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="field-group">
                                <label class="set-label">Sender ID</label>
                                <input type="text" class="set-input" name="setting_sms_sender_id"
                                       value="<?= htmlspecialchars($settings['sms_sender_id'] ?? 'LDBFRAS', ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="LDBFRAS" maxlength="11">
                            </div>
                        </div>
                        <div class="col-12 col-md-8">
                            <div class="field-group">
                                <label class="set-label">API Key</label>
                                <div class="input-password-wrap">
                                    <input type="password" class="set-input" name="setting_sms_api_key" id="smsApiKey"
                                           value="<?= htmlspecialchars($settings['sms_api_key'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                           placeholder="Your TextBee API key">
                                    <button type="button" class="input-password-toggle" onclick="togglePassword('smsApiKey', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <span class="set-hint">Get API key from <a href="https://textbee.dev/dashboard" target="_blank">textbee.dev/dashboard</a></span>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="field-group">
                                <label class="set-label">Device ID</label>
                                <input type="text" class="set-input" name="setting_sms_device_id"
                                       value="<?= htmlspecialchars($settings['sms_device_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="e.g. 6a5c9a37806d...">
                                <span class="set-hint">Android device ID from <a href="https://textbee.dev/dashboard" target="_blank">dashboard</a></span>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="field-group">
                                <label class="set-label">SMS Monitor</label>
                                <div style="display:flex;align-items:center;gap:12px;">
                                    <div style="flex:1;height:10px;border-radius:10px;background:rgba(255,255,255,0.06);overflow:hidden;">
                                        <div id="smsQuotaBarAdmin" style="height:100%;border-radius:10px;background:linear-gradient(90deg,#4f46e5,#7c3aed);transition:width 0.4s ease;width:0%;"></div>
                                    </div>
                                    <span id="smsQuotaTextAdmin" style="font-size:12px;font-weight:700;color:var(--set-text-secondary);white-space:nowrap;">0/50</span>
                                </div>
                                <span class="set-hint" id="smsQuotaStatusAdmin">Daily SMS sent count</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif ($activeTab === 'attendance'): ?>
            <!-- ===== ATTENDANCE RULES ===== -->
            <div class="card animate-in">
                <div class="card-header"><span style="display:inline-flex;align-items:center;gap:6px;"><i class="bi bi-calendar-check"></i> Attendance Rules & Defaults</span></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <div class="field-group">
                                <label class="set-label">Late Threshold <span class="label-hint">(min)</span></label>
                                <input type="number" class="set-input" name="setting_late_threshold"
                                       value="<?= htmlspecialchars($settings['late_threshold'] ?? '15', ENT_QUOTES, 'UTF-8') ?>"
                                       min="1" max="120">
                                <span class="set-hint d-none d-sm-block">Min after start = late</span>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="field-group">
                                <label class="set-label">Face Confidence <span class="label-hint">(%)</span></label>
                                <input type="number" class="set-input" name="setting_face_confidence"
                                       value="<?= htmlspecialchars($settings['face_confidence'] ?? '60', ENT_QUOTES, 'UTF-8') ?>"
                                       min="30" max="95">
                                <span class="set-hint d-none d-sm-block">Min confidence for match</span>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="field-group">
                                <label class="set-label">Time-In Morning Start</label>
                                <input type="time" class="set-input" name="setting_gate_time_in_morning_start"
                                       value="<?= htmlspecialchars($settings['gate_time_in_morning_start'] ?? '06:00', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="field-group">
                                <label class="set-label">Time-In Morning End</label>
                                <input type="time" class="set-input" name="setting_gate_time_in_morning_end"
                                       value="<?= htmlspecialchars($settings['gate_time_in_morning_end'] ?? '08:00', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="field-group">
                                <label class="set-label">Time-In Afternoon Start</label>
                                <input type="time" class="set-input" name="setting_gate_time_in_afternoon_start"
                                       value="<?= htmlspecialchars($settings['gate_time_in_afternoon_start'] ?? '12:30', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="field-group">
                                <label class="set-label">Time-In Afternoon End</label>
                                <input type="time" class="set-input" name="setting_gate_time_in_afternoon_end"
                                       value="<?= htmlspecialchars($settings['gate_time_in_afternoon_end'] ?? '13:30', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="field-group">
                                <label class="set-label">Time-Out Morning Start</label>
                                <input type="time" class="set-input" name="setting_gate_time_out_morning_start"
                                       value="<?= htmlspecialchars($settings['gate_time_out_morning_start'] ?? '10:30', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="field-group">
                                <label class="set-label">Time-Out Morning End</label>
                                <input type="time" class="set-input" name="setting_gate_time_out_morning_end"
                                       value="<?= htmlspecialchars($settings['gate_time_out_morning_end'] ?? '11:30', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="field-group">
                                <label class="set-label">Time-Out Afternoon Start</label>
                                <input type="time" class="set-input" name="setting_gate_time_out_afternoon_start"
                                       value="<?= htmlspecialchars($settings['gate_time_out_afternoon_start'] ?? '15:30', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="field-group">
                                <label class="set-label">Time-Out Afternoon End</label>
                                <input type="time" class="set-input" name="setting_gate_time_out_afternoon_end"
                                       value="<?= htmlspecialchars($settings['gate_time_out_afternoon_end'] ?? '17:00', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="field-group">
                                <label class="set-label">Auto Mark Absent</label>
                                <select name="setting_auto_mark_absent" class="set-select">
                                    <option value="1" <?= ($settings['auto_mark_absent'] ?? '1') === '1' ? 'selected' : '' ?>>Enabled</option>
                                    <option value="0" <?= ($settings['auto_mark_absent'] ?? '') === '0' ? 'selected' : '' ?>>Disabled</option>
                                </select>
                                <span class="set-hint d-none d-sm-block">After session ends</span>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="field-group">
                                <label class="set-label">Notify on Absent</label>
                                <select name="setting_notify_on_absent" class="set-select">
                                    <option value="1" <?= ($settings['notify_on_absent'] ?? '1') === '1' ? 'selected' : '' ?>>Enabled</option>
                                    <option value="0" <?= ($settings['notify_on_absent'] ?? '') === '0' ? 'selected' : '' ?>>Disabled</option>
                                </select>
                                <span class="set-hint d-none d-sm-block">SMS/Email parent</span>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="field-group">
                                <label class="set-label">🚪 Gate Activity Channel</label>
                                <select name="notif_gate_notification_channel" class="set-select">
                                    <option value="both" <?= ($notifConfig['gate_notification_channel'] ?? 'both') === 'both' ? 'selected' : '' ?>>Both SMS &amp; Email</option>
                                    <option value="sms" <?= ($notifConfig['gate_notification_channel'] ?? '') === 'sms' ? 'selected' : '' ?>>SMS Only</option>
                                    <option value="email" <?= ($notifConfig['gate_notification_channel'] ?? '') === 'email' ? 'selected' : '' ?>>Email Only</option>
                                    <option value="disabled" <?= ($notifConfig['gate_notification_channel'] ?? '') === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                                </select>
                                <span class="set-hint d-none d-sm-block">Time-In / Time-Out alerts</span>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="field-group">
                                <label class="set-label">⚠️ Classroom Absences Channel</label>
                                <select name="notif_absence_notification_channel" class="set-select">
                                    <option value="both" <?= ($notifConfig['absence_notification_channel'] ?? 'email') === 'both' ? 'selected' : '' ?>>Both SMS &amp; Email</option>
                                    <option value="sms" <?= ($notifConfig['absence_notification_channel'] ?? '') === 'sms' ? 'selected' : '' ?>>SMS Only</option>
                                    <option value="email" <?= ($notifConfig['absence_notification_channel'] ?? 'email') === 'email' ? 'selected' : '' ?>>Email Only</option>
                                    <option value="disabled" <?= ($notifConfig['absence_notification_channel'] ?? '') === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                                </select>
                                <span class="set-hint d-none d-sm-block">Teacher Marking alerts</span>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="field-group">
                                <label class="set-label">Consecutive Absence Limit</label>
                                <input type="number" class="set-input" name="notif_consecutive_absence_limit" value="<?= htmlspecialchars($notifConfig['consecutive_absence_limit'] ?? '3', ENT_QUOTES, 'UTF-8') ?>" min="2" max="10">
                                <span class="set-hint d-none d-sm-block">Trigger after N absences</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif ($activeTab === 'legal'): ?>
            <!-- ===== LEGAL & COMPLIANCE ENGINE ===== -->
            <div class="split-card-grid animate-in">
                <!-- ── LEFT CARD: Regulatory Compliance (DPA) ── -->
                <div class="card">
                    <div class="card-header">
                        <span style="display:inline-flex;align-items:center;gap:6px;">
                            <i class="bi bi-shield-check"></i> Regulatory Compliance
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="field-group">
                            <label class="set-label">DPO Contact <span class="label-hint">(email / phone)</span></label>
                            <input type="text" class="set-input" name="legal_privacy_dpo_contact"
                                   value="<?= htmlspecialchars($legal['privacy_dpo_contact'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="dpo@liceodebaleno.edu.ph">
                        </div>
                        <div class="field-group">
                            <label class="set-label">Effective Version / Date Tag</label>
                            <input type="text" class="set-input" name="legal_dpa_version_tag"
                                   value="<?= htmlspecialchars($legal['dpa_version_tag'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   placeholder="July 2026 - v1.2">
                            <span class="set-hint">Shown on the public notice as the document revision stamp.</span>
                        </div>
                        <div class="field-group">
                            <label class="set-label d-flex justify-content-between align-items-center">
                                <span>Master Data Privacy Notice</span>
                                <button type="button" class="preview-link" data-preview="dpa">
                                    <i class="bi bi-eye"></i> Live Portal Preview Mode
                                </button>
                            </label>
                            <textarea class="set-textarea legal-scroll" name="legal_dpa_master_notice" id="dpaMasterNotice"
                                      placeholder="Paste the full Data Privacy Notice (RA 10173)..."><?= htmlspecialchars($legal['dpa_master_notice'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <div class="field-group">
                                    <label class="set-label">Student Enrolment Consent Text</label>
                                    <textarea class="set-textarea" name="legal_dpa_consent_label_student" rows="3"
                                              placeholder="Consent checkbox label for student registration..."><?= htmlspecialchars($legal['dpa_consent_label_student'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="field-group">
                                    <label class="set-label">Staff Onboarding Consent Text</label>
                                    <textarea class="set-textarea" name="legal_dpa_consent_label_staff" rows="3"
                                              placeholder="Consent checkbox label for staff portal onboarding..."><?= htmlspecialchars($legal['dpa_consent_label_staff'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── RIGHT CARD: System Operational Agreements (T&C) ── -->
                <div class="card">
                    <div class="card-header">
                        <span style="display:inline-flex;align-items:center;gap:6px;">
                            <i class="bi bi-scale"></i> System Operational Agreements
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="field-group">
                            <label class="set-label d-flex justify-content-between align-items-center">
                                <span>Terms &amp; Conditions Guidelines</span>
                                <button type="button" class="preview-link" data-preview="tc">
                                    <i class="bi bi-eye"></i> Live Portal Preview Mode
                                </button>
                            </label>
                            <textarea class="set-textarea legal-scroll" name="legal_system_terms_conditions" id="tcConditions"
                                      placeholder="Paste the full Terms & Conditions user agreement..."><?= htmlspecialchars($legal['system_terms_conditions'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif ($activeTab === 'privacy'): ?>
            <!-- ===== PRIVACY POLICY (READ-ONLY VIEW) ===== -->
            <div class="card animate-in">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span style="display:inline-flex;align-items:center;gap:6px;"><i class="bi bi-shield-check"></i> Data Privacy Notice (RA 10173)</span>
                    <span class="badge rounded-pill" style="background:rgba(6,182,212,0.15);color:#22d3ee;font-size:11px;font-weight:700;border:1px solid rgba(6,182,212,0.25);">
                        <i class="bi bi-tag"></i> <?= htmlspecialchars($legal['dpa_version_tag'] ?? 'Version N/A', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="legal-scroll" style="max-height:28rem;line-height:1.65;white-space:pre-wrap;font-size:13px;color:rgba(255,255,255,0.82);font-family:var(--set-mono);padding:14px 16px;border:1px solid var(--set-border);border-radius:var(--set-radius-sm);background:var(--set-surface);">
<?= htmlspecialchars($legal['dpa_master_notice'] ?? 'The Data Privacy Notice is currently being finalized by the administration.', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-3" style="font-size:12px;color:var(--set-text-secondary);">
                        <i class="bi bi-envelope-paper"></i>
                        <span>Data Protection Officer:</span>
                        <strong style="color:var(--set-text);"><?= htmlspecialchars($legal['privacy_dpo_contact'] ?? 'dpo@liceodebaleno.edu.ph', ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <p class="set-hint" style="margin-top:6px;">This published notice is managed under the <strong>Legal &amp; Compliance</strong> tab. Update the Master Data Privacy Notice there to revise this view.</p>
                </div>
            </div>

            <?php elseif ($activeTab === 'hardware'): ?>
            <!-- ===== HARDWARE & TERMINALS ===== -->
            <div class="card animate-in">
                <div class="card-header"><i class="bi bi-camera"></i> <span style="margin-left:2px;">Hardware &amp; Terminal Configuration</span></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="field-group">
                                <label class="set-label">IP Camera Stream URL Link</label>
                                <input type="text" class="set-input" name="setting_camera_stream_url"
                                       value="<?= htmlspecialchars($settings['camera_stream_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="rtsp://192.168.1.100:554/stream or http://camera.local/mjpg/video.mjpg">
                                <span class="set-hint">Direct stream URL consumed by the face-recognition capture service</span>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="field-group">
                                <label class="set-label">Toggle Kiosk Sound Feedback Audio</label>
                                <select name="setting_kiosk_sound_feedback" class="set-select">
                                    <option value="1" <?= ($settings['kiosk_sound_feedback'] ?? '1') === '1' ? 'selected' : '' ?>>Enabled</option>
                                    <option value="0" <?= ($settings['kiosk_sound_feedback'] ?? '') === '0' ? 'selected' : '' ?>>Disabled</option>
                                </select>
                                <span class="set-hint">Play beep / voice prompts at the face-registration kiosk</span>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="field-group">
                                <label class="set-label">Welcome Screen Custom Message</label>
                                <input type="text" class="set-input" name="setting_welcome_screen_message"
                                       value="<?= htmlspecialchars($settings['welcome_screen_message'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="Welcome! Please stand in front of the camera to begin attendance...">
                                <span class="set-hint">Message displayed on the kiosk/welcome screen before face capture</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php endif; ?>

            <!-- Save Button -->
            <div class="action-footer mt-4 animate-in" style="animation-delay:0.15s;">
                <div class="footer-spacer"></div>
                <div class="footer-actions">
                <button type="submit" class="btn-save btn-save-green" name="save_settings" value="1">
                    <i class="bi bi-check-lg"></i>
                    <span>Save Configuration Modifications</span>
                </button>
                    <a href="?tab=<?= $activeTab ?>" class="btn-reset" title="Reset to Defaults">
                        <i class="bi bi-arrow-clockwise"></i>
                        <span>Reset to Defaults</span>
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ═══ Live Portal Preview Modals ═══ -->
<div class="event-modal-overlay" id="previewDpaOverlay">
    <div class="event-modal preview-portal">
        <div class="preview-portal-head">
            <span class="kiosk-dot"></span>
            <span>Student Registration Terminal &middot; Data Privacy Notice</span>
            <button class="event-modal-close" data-preview-close title="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="preview-portal-body" id="previewDpaBody"></div>
        <div class="preview-portal-foot">
            <label class="preview-consent">
                <input type="checkbox" disabled checked>
                <span id="previewDpaConsent"></span>
            </label>
        </div>
    </div>
</div>

<div class="event-modal-overlay" id="previewTcOverlay">
    <div class="event-modal preview-portal">
        <div class="preview-portal-head">
            <span class="kiosk-dot"></span>
            <span>Portal Onboarding Terminal &middot; Terms &amp; Conditions</span>
            <button class="event-modal-close" data-preview-close title="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="preview-portal-body" id="previewTcBody"></div>
        <div class="preview-portal-foot">
            <label class="preview-consent">
                <input type="checkbox" disabled checked>
                <span>I have read and agree to the System Terms &amp; Conditions.</span>
            </label>
        </div>
    </div>
</div>

<!-- ═══ Test Email Modal (Bottom Sheet on mobile) ═══ -->
<div class="event-modal-overlay" id="testEmailOverlay">
    <div class="event-modal">
        <div class="event-modal-header">
            <div class="event-modal-title">
                <i class="bi bi-envelope" style="color:#06b6d4;"></i>
                <span>Test Email</span>
            </div>
            <button class="event-modal-close" id="testEmailClose" title="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="event-modal-body">
            <div class="field-group">
                <label class="set-label">Send test email to:</label>
                <input type="email" class="set-input" id="testEmailTo" placeholder="your@email.com">
            </div>
            <div id="testEmailResult"></div>
        </div>
        <div class="event-modal-footer">
            <button type="button" class="evt-btn evt-btn-cancel" id="testEmailCancelBtn">Cancel</button>
            <button type="button" class="evt-btn evt-btn-save" id="testEmailSendBtn">
                <i class="bi bi-send"></i> Send Test
            </button>
        </div>
    </div>
</div>

<!-- ═══ Test SMS Modal (Bottom Sheet on mobile) ═══ -->
<div class="event-modal-overlay" id="testSMSOverlay">
    <div class="event-modal">
        <div class="event-modal-header">
            <div class="event-modal-title">
                <i class="bi bi-chat-dots" style="color:#10b981;"></i>
                <span>Test SMS</span>
            </div>
            <button class="event-modal-close" id="testSMSClose" title="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="event-modal-body">
            <div class="field-group">
                <label class="set-label">Send test SMS to:</label>
                <input type="tel" class="set-input" id="testSMSTo" placeholder="09XX-XXX-XXXX">
            </div>
            <div id="testSMSResult"></div>
        </div>
        <div class="event-modal-footer">
            <button type="button" class="evt-btn evt-btn-cancel" id="testSMSCancelBtn">Cancel</button>
            <button type="button" class="evt-btn evt-btn-save" id="testSMSSendBtn">
                <i class="bi bi-send"></i> Send Test
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    // ─── Toast ──────────────────────────────────────────────────────────
    function showToast(type, message) {
        var c = document.getElementById('toastContainer');
        var icons = {
            success: 'bi-check-circle-fill',
            danger: 'bi-x-circle-fill',
            warning: 'bi-exclamation-triangle-fill',
            info: 'bi-info-circle-fill'
        };
        var t = document.createElement('div');
        t.className = 'set-toast ' + type;
        t.innerHTML = '<i class="bi ' + (icons[type] || icons.info) + '"></i><span>' + esc(message) + '</span>';
        c.appendChild(t);
        setTimeout(function() { if (t.parentNode) t.remove(); }, 4200);
    }

    // Flash messages from PHP redirect
    <?php if (!empty($_SESSION['flash_message'])): ?>
    showToast('<?= $_SESSION['flash_message']['type'] ?>', <?= json_encode($_SESSION['flash_message']['message']) ?>);
    <?php unset($_SESSION['flash_message']); endif; ?>

    // ─── Helper ─────────────────────────────────────────────────────────
    function esc(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    // ─── Password Toggle ────────────────────────────────────────────────
    window.togglePassword = function(inputId, btn) {
        var input = document.getElementById(inputId);
        var icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'bi bi-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'bi bi-eye';
        }
    };


    // ══════════════════════════════════════════════════════════════════
    // MODAL SYSTEM — MATCHING ANNOUNCEMENTS PAGE
    // ══════════════════════════════════════════════════════════════════
    var cur = null;
    
    function openModal(el) {
        if (!el) return;
        el.classList.add('show');
        document.body.style.overflow = 'hidden';
        cur = el.id;
    }
    
    function closeModal(el) {
        if (!el) return;
        el.classList.remove('show');
        if (!document.querySelector('.event-modal-overlay.show')) {
            document.body.style.overflow = '';
            cur = null;
        }
    }
    
    function closeAllModals() {
        document.querySelectorAll('.event-modal-overlay.show').forEach(function(e) {
            e.classList.remove('show');
        });
        document.body.style.overflow = '';
        cur = null;
    }
    
    document.querySelectorAll('.event-modal-overlay').forEach(function(o) {
        o.addEventListener('click', function(e) {
            if (e.target === o) closeModal(o);
        });
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeAllModals();
    });

    // ══════════════════════════════════════════════════════════════════
    // TEST EMAIL MODAL
    // ══════════════════════════════════════════════════════════════════
    var emailOverlay   = document.getElementById('testEmailOverlay');
    var emailCloseBtn  = document.getElementById('testEmailClose');
    var emailCancelBtn = document.getElementById('testEmailCancelBtn');
    var emailSendBtn   = document.getElementById('testEmailSendBtn');
    var emailResultDiv = document.getElementById('testEmailResult');
    var emailInput     = document.getElementById('testEmailTo');
    var btnTestEmail   = document.getElementById('btnTestEmail');

    if (btnTestEmail) {
        btnTestEmail.addEventListener('click', function() {
            emailResultDiv.innerHTML = '';
            openModal(emailOverlay);
            setTimeout(function() { emailInput.focus(); }, 100);
        });
    }
    
    if (emailCloseBtn) emailCloseBtn.addEventListener('click', function() { closeModal(emailOverlay); });
    if (emailCancelBtn) emailCancelBtn.addEventListener('click', function() { closeModal(emailOverlay); });
    if (emailOverlay) emailOverlay.addEventListener('click', function(e) {
        if (e.target === emailOverlay) closeModal(emailOverlay);
    });

    if (emailSendBtn) {
        emailSendBtn.addEventListener('click', async function() {
            var to = emailInput.value.trim();
            if (!to) {
                emailResultDiv.innerHTML = '<div class="test-result warning"><i class="bi bi-exclamation-triangle-fill"></i><span>Please enter an email address.</span></div>';
                return;
            }
            emailSendBtn.classList.add('loading');
            emailSendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending...';
            emailResultDiv.innerHTML = '<div class="test-result info"><i class="bi bi-arrow-repeat"></i><span>Sending test email...</span></div>';

            var formData = new FormData();
            formData.append('action', 'test_email');
            formData.append('to', to);
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            formData.append('csrf_token', csrfMeta ? csrfMeta.getAttribute('content') : '');

            try {
                var response = await fetch('<?= BASE_URL ?>/api/notifications.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                });
                var data = await response.json();
                if (data.success) {
                    emailResultDiv.innerHTML = '<div class="test-result success"><i class="bi bi-check-circle-fill"></i><span>' + esc(data.message) + '</span></div>';
                    showToast('success', 'Test email sent successfully!');
                } else {
                    emailResultDiv.innerHTML = '<div class="test-result danger"><i class="bi bi-x-circle-fill"></i><span>' + esc(data.message) + '</span></div>';
                }
            } catch (e) {
                emailResultDiv.innerHTML = '<div class="test-result danger"><i class="bi bi-wifi-off"></i><span>Network error. Please check your connection.</span></div>';
            } finally {
                emailSendBtn.classList.remove('loading');
                emailSendBtn.innerHTML = '<i class="bi bi-send"></i> Send Test';
            }
        });
    }

    // ══════════════════════════════════════════════════════════════════
    // TEST SMS MODAL
    // ══════════════════════════════════════════════════════════════════
    var smsOverlay   = document.getElementById('testSMSOverlay');
    var smsCloseBtn  = document.getElementById('testSMSClose');
    var smsCancelBtn = document.getElementById('testSMSCancelBtn');
    var smsSendBtn   = document.getElementById('testSMSSendBtn');
    var smsResultDiv = document.getElementById('testSMSResult');
    var smsInput     = document.getElementById('testSMSTo');
    var btnTestSMS   = document.getElementById('btnTestSMS');

    if (btnTestSMS) {
        btnTestSMS.addEventListener('click', function() {
            smsResultDiv.innerHTML = '';
            openModal(smsOverlay);
            setTimeout(function() { smsInput.focus(); }, 100);
        });
    }
    
    if (smsCloseBtn) smsCloseBtn.addEventListener('click', function() { closeModal(smsOverlay); });
    if (smsCancelBtn) smsCancelBtn.addEventListener('click', function() { closeModal(smsOverlay); });
    if (smsOverlay) smsOverlay.addEventListener('click', function(e) {
        if (e.target === smsOverlay) closeModal(smsOverlay);
    });

    if (smsSendBtn) {
        smsSendBtn.addEventListener('click', async function() {
            var to = smsInput.value.trim();
            if (!to) {
                smsResultDiv.innerHTML = '<div class="test-result warning"><i class="bi bi-exclamation-triangle-fill"></i><span>Please enter a phone number.</span></div>';
                return;
            }
            smsSendBtn.classList.add('loading');
            smsSendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending...';
            smsResultDiv.innerHTML = '<div class="test-result info"><i class="bi bi-arrow-repeat"></i><span>Sending test SMS...</span></div>';

            var formData = new FormData();
            formData.append('action', 'test_sms');
            formData.append('to', to);
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            formData.append('csrf_token', csrfMeta ? csrfMeta.getAttribute('content') : '');

            try {
                var response = await fetch('<?= BASE_URL ?>/api/notifications.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                });
                var data = await response.json();
                if (data.success) {
                    smsResultDiv.innerHTML = '<div class="test-result success"><i class="bi bi-check-circle-fill"></i><span>' + esc(data.message) + '</span></div>';
                    showToast('success', 'Test SMS sent successfully!');
                } else {
                    smsResultDiv.innerHTML = '<div class="test-result danger"><i class="bi bi-x-circle-fill"></i><span>' + esc(data.message) + '</span></div>';
                }
            } catch (e) {
                smsResultDiv.innerHTML = '<div class="test-result danger"><i class="bi bi-wifi-off"></i><span>Network error. Please check your connection.</span></div>';
            } finally {
                smsSendBtn.classList.remove('loading');
                smsSendBtn.innerHTML = '<i class="bi bi-send"></i> Send Test';
            }
        });
    }

    // ══════════════════════════════════════════════════════════════════
    // LIVE PORTAL PREVIEW MODE (DPA + T&C)
    // ══════════════════════════════════════════════════════════════════
    function wirePreview(triggerSel, overlayId, sourceId, consentId) {
        var triggers = document.querySelectorAll(triggerSel);
        var overlay  = document.getElementById(overlayId);
        if (!overlay) return;

        triggers.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var src = document.getElementById(sourceId);
                var body = overlay.querySelector('.preview-portal-body');
                if (body) body.textContent = src ? src.value : '';
                if (consentId) {
                    var consentEl = document.getElementById(consentId);
                    var consentOut = overlay.querySelector('#' + consentId);
                    if (consentOut && consentEl) consentOut.textContent = consentEl.value || consentEl.placeholder || '';
                }
                openModal(overlay);
            });
        });

        overlay.querySelectorAll('[data-preview-close]').forEach(function(c) {
            c.addEventListener('click', function() { closeModal(overlay); });
        });
    }

    wirePreview('[data-preview="dpa"]', 'previewDpaOverlay', 'dpaMasterNotice', 'previewDpaConsent');
    wirePreview('[data-preview="tc"]',  'previewTcOverlay',  'tcConditions',     null);

    // ══════════════════════════════════════════════════════════════════
    // DATABASE MAINTENANCE ACTIONS
    // ══════════════════════════════════════════════════════════════════
    var dbActionResult = document.getElementById('dbActionResult');

    function renderDbResult(type, message) {
        if (!dbActionResult) return;
        var icon = type === 'success' ? 'bi-check-circle-fill' : type === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-x-circle-fill';
        dbActionResult.innerHTML = '<div class="test-result ' + type + '"><i class="bi ' + icon + '"></i><span>' + esc(message) + '</span></div>';
    }

    var btnGenerateBackup = document.getElementById('btnGenerateBackup');
    if (btnGenerateBackup) {
        btnGenerateBackup.addEventListener('click', async function() {
            var btn = this;
            btn.classList.add('loading');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Generating...';
            if (dbActionResult) dbActionResult.innerHTML = '';

            var formData = new FormData();
            formData.append('db_action', 'generate_backup');
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            formData.append('csrf_token', csrfMeta ? csrfMeta.getAttribute('content') : '');

            try {
                var response = await fetch('<?= BASE_URL ?>/save_settings_processor.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                });
                var data = await response.json();
                if (data.success) {
                    renderDbResult('success', data.message || 'Backup file generated successfully.');
                    showToast('success', 'Local backup file generated.');
                } else {
                    renderDbResult('danger', data.message || 'Backup generation failed.');
                }
            } catch (e) {
                renderDbResult('danger', 'Network error. Please try again.');
            } finally {
                btn.classList.remove('loading');
                btn.innerHTML = '<i class="bi bi-folder2-open"></i> Generate Local Backup File';
            }
        });
    }

    var btnOptimizeTables = document.getElementById('btnOptimizeTables');
    if (btnOptimizeTables) {
        btnOptimizeTables.addEventListener('click', async function() {
            var btn = this;
            btn.classList.add('loading');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Optimizing...';
            if (dbActionResult) dbActionResult.innerHTML = '';

            var formData = new FormData();
            formData.append('db_action', 'optimize_tables');
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            formData.append('csrf_token', csrfMeta ? csrfMeta.getAttribute('content') : '');

            try {
                var response = await fetch('<?= BASE_URL ?>/save_settings_processor.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                });
                var data = await response.json();
                if (data.success) {
                    renderDbResult('success', data.message || 'Performance tables optimized successfully.');
                    showToast('success', 'Database tables optimized.');
                } else {
                    renderDbResult('danger', data.message || 'Optimization failed.');
                }
            } catch (e) {
                renderDbResult('danger', 'Network error. Please try again.');
            } finally {
                btn.classList.remove('loading');
                btn.innerHTML = '<i class="bi bi-lightning-charge"></i> Optimize Performance Tables';
            }
        });
    }

    function updateEmailQuotaBar() {
        var box = document.getElementById('emailMonitorBox');
        var icon = document.getElementById('emailMonitorIcon');
        var text = document.getElementById('emailQuotaText');
        var status = document.getElementById('emailQuotaStatus');
        if (!text || !status) return;

        var dailyCount = <?= (int)($notifConfig['email_daily_count'] ?? 0) ?>;

        text.textContent = dailyCount;

        if (dailyCount === 0) {
            if (icon) { icon.style.background = 'linear-gradient(135deg,#4f46e5,#7c3aed)'; }
            if (box) { box.style.borderColor = 'var(--set-border)'; }
            status.textContent = 'No emails sent today';
            status.style.color = 'var(--set-text-secondary)';
        } else {
            if (icon) { icon.style.background = 'linear-gradient(135deg,#10b981,#34d399)'; }
            if (box) { box.style.borderColor = 'rgba(16,185,129,0.3)'; }
            status.textContent = dailyCount + ' email' + (dailyCount > 1 ? 's' : '') + ' sent today';
            status.style.color = '#34d399';
        }
    }

    function updateSmsQuotaBar() {
        var bar = document.getElementById('smsQuotaBarAdmin');
        var text = document.getElementById('smsQuotaTextAdmin');
        var status = document.getElementById('smsQuotaStatusAdmin');
        if (!bar || !text || !status) return;

        var dailyCount = <?= (int)($notifConfig['sms_daily_count'] ?? 0) ?>;
        var limit = 50;
        var pct = Math.min(100, Math.round((dailyCount / limit) * 100));

        bar.style.width = pct + '%';
        text.textContent = dailyCount + '/' + limit;

        if (dailyCount === 0) {
            bar.style.background = 'linear-gradient(90deg,#4f46e5,#7c3aed)';
            status.textContent = 'No SMS sent today';
            status.style.color = 'var(--set-text-secondary)';
        } else if (dailyCount >= limit) {
            bar.style.background = 'linear-gradient(90deg,#ef4444,#dc2626)';
            status.textContent = dailyCount + '/' + limit + ' quota reached';
            status.style.color = '#ef4444';
        } else {
            bar.style.background = 'linear-gradient(90deg,#10b981,#34d399)';
            status.textContent = dailyCount + ' SMS sent today';
            status.style.color = '#34d399';
        }
    }

    updateEmailQuotaBar();
    updateSmsQuotaBar();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
