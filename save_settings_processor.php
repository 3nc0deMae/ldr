<?php

require_once __DIR__ . '/config.php';
requireRole(['admin', 'teacher', 'gate']);
csrfMiddleware();

$activeTab = isset($_POST['tab_name']) ? sanitize($_POST['tab_name']) : 'school';
$updated = 0;
$isActionRequest = isset($_POST['db_action']);
$loggedInUserId  = $_SESSION['user_id'] ?? 0;
$loggedInRole    = $_SESSION['user_role'] ?? '';

header('Content-Type: application/json');

try {
    $dbAction = $_POST['db_action'] ?? '';

    if ($isActionRequest && $dbAction === 'generate_backup') {
        $backupDir = UPLOADS_PATH . '/backups';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }

        $timestamp = date('Y-m-d_Hi-s');
        $filename  = "ldb_fras_backup_{$timestamp}.sql";
        $filepath  = $backupDir . DIRECTORY_SEPARATOR . $filename;

        $tables = [];
        $stmt   = $db->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        $sql = "-- LDB-FRAS Database Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";

        foreach ($tables as $table) {
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
        }

        foreach ($tables as $table) {
            $createStmt = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $sql .= $createStmt['Create Table'] . ";\n\n";

            $dataStmt = $db->query("SELECT * FROM `$table`");
            while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
                $cols   = array_map(fn($c) => "`$c`", array_keys($row));
                $vals   = array_map(fn($v) => $db->quote($v), array_values($row));
                $sql   .= "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
            }
            $sql .= "\n";
        }

        file_put_contents($filepath, $sql);

        $retentionDays = isset($_POST['setting_backup_retention_days']) ? (int) $_POST['setting_backup_retention_days'] : 30;
        if ($retentionDays > 0 && is_dir($backupDir)) {
            $files = glob($backupDir . '/*.sql');
            $cutoff = time() - ($retentionDays * 86400);
            foreach ($files as $f) {
                if (filemtime($f) < $cutoff) {
                    @unlink($f);
                }
            }
        }

        logAudit($db, 'db_backup', "Generated local backup: $filename");
        jsonResponse(['success' => true, 'message' => "Backup generated: $filename (" . round(filesize($filepath) / 1024, 1) . " KB)"]);
    }

    if ($isActionRequest && $dbAction === 'optimize_tables') {
        $tables = [];
        $stmt   = $db->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        $results = [];
        foreach ($tables as $table) {
            $db->exec("OPTIMIZE TABLE `$table`");
            $results[] = $table;
        }

        logAudit($db, 'db_optimize', "Optimized tables: " . implode(', ', $results));
        jsonResponse(['success' => true, 'message' => "Optimized " . count($results) . " tables: " . implode(', ', $results)]);
    }

    // ── All roles: handle email update ──
    if ($loggedInUserId > 0 && isset($_POST['save_account_email']) && $_POST['save_account_email'] === '1') {
        $newEmail = trim($_POST['account_email'] ?? '');
        if ($newEmail !== '' && filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $checkStmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            $checkStmt->execute([$newEmail, $loggedInUserId]);
            if ($checkStmt->fetch()) {
                $sourcePage = $_POST['source_page'] ?? 'root';
                $tabParam = $sourcePage === 'root' ? 'myaccount' : 'account';
                if ($loggedInRole === 'admin') $tabParam = 'myaccount';
                redirect('/settings.php?tab=' . $tabParam, 'This email address is already in use by another account.', 'danger');
            }

            $updEmail = $db->prepare("UPDATE users SET email = ?, updated_at = NOW() WHERE id = ?");
            $updEmail->execute([$newEmail, $loggedInUserId]);

            if ($loggedInRole !== 'admin') {
                try {
                    $table = ($loggedInRole === 'teacher') ? 'teachers' : 'users';
                    $col   = ($loggedInRole === 'teacher') ? 'email' : 'email';
                    $updTbl = $db->prepare("UPDATE $table SET $col = ?, updated_at = NOW() WHERE user_id = ?");
                    $updTbl->execute([$newEmail, $loggedInUserId]);
                } catch (Exception $e) {}
            }

            $_SESSION['user_email'] = $newEmail;
            $userEmail = $newEmail;

            logAudit($db, 'update_email', "User #{$loggedInUserId} changed email to {$newEmail}");
            $sourcePage = $_POST['source_page'] ?? 'root';
            if ($loggedInRole === 'admin') {
                redirect('/settings.php?tab=myaccount', 'Email updated successfully.', 'success');
            } elseif ($sourcePage === 'root') {
                redirect('/settings.php?tab=account', 'Email updated successfully.', 'success');
            } else {
                redirect('/admin/settings.php?tab=account', 'Email updated successfully.', 'success');
            }
        } else {
            $sourcePage = $_POST['source_page'] ?? 'root';
            $tabParam = ($loggedInRole === 'admin') ? 'myaccount' : 'account';
            redirect('/settings.php?tab=' . $tabParam, 'Please enter a valid email address.', 'danger');
        }
    }

    // ── All roles: handle password update ──
    if ($loggedInUserId > 0 && isset($_POST['update_password']) && $_POST['update_password'] === '1') {
        $currentPass = $_POST['current_password'] ?? '';
        $newPass     = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if ($currentPass !== '' && $newPass !== '' && $newPass === $confirmPass && strlen($newPass) >= 8) {
            $userStmt = $db->prepare("SELECT password FROM users WHERE id = ?");
            $userStmt->execute([$loggedInUserId]);
            $row = $userStmt->fetch();

            if ($row && password_verify($currentPass, $row['password'])) {
                $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
                $updPass = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                $updPass->execute([$hash, $loggedInUserId]);
                logAudit($db, 'update_password', "User #{$loggedInUserId} changed their password");
                if ($loggedInRole === 'admin') {
                    redirect('/settings.php?tab=myaccount', 'Password updated successfully.', 'success');
                } else {
                    redirect('/settings.php?tab=account', 'Password updated successfully.', 'success');
                }
            } else {
                if ($loggedInRole === 'admin') {
                    redirect('/settings.php?tab=myaccount', 'Current password is incorrect.', 'danger');
                } else {
                    redirect('/settings.php?tab=account', 'Current password is incorrect.', 'danger');
                }
            }
        } elseif ($newPass !== '' && $newPass !== $confirmPass) {
            if ($loggedInRole === 'admin') {
                redirect('/settings.php?tab=myaccount', 'New passwords do not match.', 'danger');
            } else {
                redirect('/settings.php?tab=account', 'New passwords do not match.', 'danger');
            }
        }
    }

    // ── Teacher / Gate: save per-user preferences ──
    if (in_array($loggedInRole, ['teacher', 'gate'], true) && $loggedInUserId > 0) {
        $prefCols    = [];
        $prefValues  = [];

        // Teacher preference keys
        $teacherPrefMap = [
            'pref_default_subject'               => 'default_subject',
            'pref_webcam_device'                 => 'webcam_device',
            'pref_low_attendance_warning_margin' => 'low_attendance_warning_margin',
            'pref_session_timeout_limit'         => 'session_timeout_limit',
        ];

        // Gate preference keys
        $gatePrefMap = [
            'pref_audio_feedback_profile'    => 'audio_feedback_profile',
            'pref_log_stream_refresh_rate'   => 'log_stream_refresh_rate',
            'pref_biometric_tolerance_limit' => 'biometric_tolerance_limit',
            'pref_startup_checkpoint_mode'   => 'startup_checkpoint_mode',
        ];

        $activePrefMap = ($loggedInRole === 'teacher') ? $teacherPrefMap : $gatePrefMap;

        foreach ($activePrefMap as $postKey => $dbCol) {
            if (!isset($_POST[$postKey])) continue;
            $raw = trim($_POST[$postKey]);

            switch ($dbCol) {
                case 'low_attendance_warning_margin':
                    $val = abs((int) $raw);
                    $val = max(1, min(100, $val));
                    $prefCols[$dbCol] = (string) $val;
                    break;

                case 'session_timeout_limit':
                    $val = (int) $raw;
                    $val = in_array($val, [15, 30, 45]) ? $val : 30;
                    $prefCols[$dbCol] = (string) $val;
                    break;

                case 'webcam_device':
                    $prefCols[$dbCol] = in_array($raw, ['0', '1']) ? $raw : '0';
                    break;

                case 'biometric_tolerance_limit':
                    $intVal = abs((int) $raw);
                    $intVal = max(40, min(70, $intVal));
                    $prefCols[$dbCol] = number_format($intVal / 100, 2, '.', '');
                    break;

                case 'audio_feedback_profile':
                    $allowed = ['standard_chime', 'voice_greeting', 'muted'];
                    $prefCols[$dbCol] = in_array($raw, $allowed) ? $raw : 'standard_chime';
                    break;

                case 'log_stream_refresh_rate':
                    $allowed = ['realtime', '5', '30'];
                    $prefCols[$dbCol] = in_array($raw, $allowed) ? $raw : 'realtime';
                    break;

                case 'startup_checkpoint_mode':
                    $allowed = ['time_in', 'time_out'];
                    $prefCols[$dbCol] = in_array($raw, $allowed) ? $raw : 'time_in';
                    break;

                default:
                    $prefCols[$dbCol] = cleanString($raw);
                    break;
            }
        }

        if (!empty($prefCols)) {
            // Check if row exists
            $checkStmt = $db->prepare("SELECT id FROM user_preferences WHERE user_id = ? LIMIT 1");
            $checkStmt->execute([$loggedInUserId]);
            $exists = $checkStmt->fetch();

            if ($exists) {
                $setClauses = [];
                $bindParams = [];
                foreach ($prefCols as $col => $val) {
                    $setClauses[]           = "`$col` = :$col";
                    $bindParams[":$col"]    = $val;
                }
                $setClauses[]               = "`updated_at` = NOW()";
                $bindParams[':uid']         = $loggedInUserId;

                $sql = "UPDATE user_preferences SET " . implode(', ', $setClauses) . " WHERE user_id = :uid";
                $updStmt = $db->prepare($sql);
                $updStmt->execute($bindParams);
            } else {
                $prefCols['user_id']    = $loggedInUserId;
                $prefCols['created_at'] = date('Y-m-d H:i:s');

                $colNames  = implode(', ', array_map(fn($c) => "`$c`", array_keys($prefCols)));
                $placeholders = implode(', ', array_map(fn($c) => ":$c", array_keys($prefCols)));

                $insParams = [];
                foreach ($prefCols as $col => $val) {
                    $insParams[":$col"] = $val;
                }
                $insStmt = $db->prepare("INSERT INTO user_preferences ($colNames) VALUES ($placeholders)");
                $insStmt->execute($insParams);
            }

            $updated += count($prefCols);
            logAudit($db, 'update_user_preferences', "Updated " . count($prefCols) . " preference(s) for user #{$loggedInUserId} (role: {$loggedInRole})");
        }

        // Save UI preferences (checkboxes)
        $uiAudio = isset($_POST['ui_audio_alerts']) ? 1 : 0;
        $_SESSION['ui_audio_alerts'] = $uiAudio;

        if ($updated > 0) {
            redirect('/settings.php?tab=' . ($activeTab ?? 'school'), "$updated preference(s) saved successfully.", 'success');
        } else {
            redirect('/settings.php?tab=' . ($activeTab ?? 'school'), 'Preferences saved.', 'success');
        }
    }

    $stmt = $db->prepare(
        "INSERT INTO settings (setting_key, setting_value, updated_at)
         VALUES (:key, :value, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
    );

    foreach ($_POST as $key => $value) {
        if ($key === 'save_settings' || $key === 'tab_name' || $key === 'csrf_token' || $key === 'db_action' || $key === 'source_page' || $key === 'account_email' || $key === 'save_account_email' || $key === 'update_password' || $key === 'current_password' || $key === 'new_password' || $key === 'confirm_password') continue;
        if (strpos($key, 'setting_') === 0) {
            $settingKey = str_replace('setting_', '', $key);
            $privacyKeys = ['privacy_dpo_contact','privacy_consent_label','privacy_kiosk_notice','privacy_master_terms','dpa_master_notice','system_terms_conditions','dpa_version_tag','dpa_consent_label_student','dpa_consent_label_staff'];
            if (in_array($settingKey, $privacyKeys)) continue;

            if ($settingKey === 'backup_retention_days') {
                $raw   = isset($_POST[$key]) ? $_POST[$key] : '';
                $value = abs((int) trim($raw));
                if ($value < 1)  $value = 1;
                if ($value > 365) $value = 365;
                $value = (string) $value;
            } elseif ($settingKey === 'kiosk_sound_feedback') {
                $value = in_array($value, ['0', '1']) ? $value : '1';
            } elseif ($settingKey === 'camera_stream_url') {
                $value = filter_var(trim($value), FILTER_SANITIZE_URL) ?: '';
            } elseif ($settingKey === 'session_idle_timeout') {
                $raw   = isset($_POST[$key]) ? $_POST[$key] : '';
                $value = abs((int) trim($raw));
                if ($value < 1)   $value = 1;
                if ($value > 480) $value = 480;
                $value = (string) $value;
            } elseif (in_array($settingKey, ['welcome_screen_message', 'allowed_ip_whitelist'])) {
                $raw   = isset($_POST[$key]) ? $_POST[$key] : '';
                $value = cleanString($raw);
            } else {
                $value = isset($_POST[$key]) ? $_POST[$key] : '';
            }

            $stmt->execute([':key' => $settingKey, ':value' => $value]);
            $updated++;
        }
    }

    // Auto-migrate split notification channel columns if missing
    try {
        $colsStmt = $db->query("SHOW COLUMNS FROM system_notification_config WHERE Field IN ('gate_notification_channel','absence_notification_channel','consecutive_absence_limit','template_gate_absent','email_daily_count','email_monthly_count','email_yearly_count')");
        $existingCols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff(['gate_notification_channel','absence_notification_channel','consecutive_absence_limit','template_gate_absent','email_daily_count','email_monthly_count','email_yearly_count'], $existingCols);
        if (!empty($missing)) {
            $alterParts = [];
            foreach ($missing as $col) {
                if ($col === 'gate_notification_channel') {
                    $alterParts[] = "ADD COLUMN `gate_notification_channel` ENUM('both','sms','email','disabled') DEFAULT 'both' AFTER `last_counter_reset`";
                } elseif ($col === 'absence_notification_channel') {
                    $alterParts[] = "ADD COLUMN `absence_notification_channel` ENUM('both','sms','email','disabled') DEFAULT 'email' AFTER `gate_notification_channel`";
                } elseif ($col === 'consecutive_absence_limit') {
                    $alterParts[] = "ADD COLUMN `consecutive_absence_limit` INT DEFAULT 3 AFTER `absence_notification_channel`";
                } elseif ($col === 'template_gate_absent') {
                    $alterParts[] = "ADD COLUMN `template_gate_absent` TEXT DEFAULT NULL AFTER `template_absent_3x`";
                } elseif ($col === 'email_daily_count') {
                    $alterParts[] = "ADD COLUMN `email_daily_count` INT DEFAULT 0 AFTER `sms_yearly_count`";
                } elseif ($col === 'email_monthly_count') {
                    $alterParts[] = "ADD COLUMN `email_monthly_count` INT DEFAULT 0 AFTER `email_daily_count`";
                } elseif ($col === 'email_yearly_count') {
                    $alterParts[] = "ADD COLUMN `email_yearly_count` INT DEFAULT 0 AFTER `email_monthly_count`";
                }
            }
            if (!empty($alterParts)) {
                $db->exec("ALTER TABLE `system_notification_config` " . implode(', ', $alterParts));
            }
        }
    } catch (Exception $e) {
        error_log('Auto-migration error: ' . $e->getMessage());
    }

    $notifStmt = $db->prepare(
        "INSERT INTO system_notification_config 
         (id, sms_subscription_tier, gate_notification_channel, absence_notification_channel, consecutive_absence_limit, template_time_in, template_time_out, template_absent_3x, template_gate_absent, updated_at)
         VALUES (1, :tier, :gate, :absence, :limit, :t_in, :t_out, :t_3x, :t_gate, NOW())
         ON DUPLICATE KEY UPDATE 
         sms_subscription_tier = VALUES(sms_subscription_tier), 
         gate_notification_channel = VALUES(gate_notification_channel), 
         absence_notification_channel = VALUES(absence_notification_channel), 
         consecutive_absence_limit = VALUES(consecutive_absence_limit), 
         template_time_in = VALUES(template_time_in), 
         template_time_out = VALUES(template_time_out), 
         template_absent_3x = VALUES(template_absent_3x), 
         template_gate_absent = VALUES(template_gate_absent), 
         updated_at = NOW()"
    );

    $notifFields = [
        'notif_sms_subscription_tier'    => ['tier', 'free_50', ['free_50','monthly_unlimited','yearly_unlimited']],
        'notif_gate_notification_channel'=> ['gate', 'both', ['both','sms','email','disabled']],
        'notif_absence_notification_channel' => ['absence', 'email', ['both','sms','email','disabled']],
        'notif_consecutive_absence_limit'  => ['limit', '3', null],
        'notif_template_time_in'           => ['t_in', ''],
        'notif_template_time_out'          => ['t_out', ''],
        'notif_template_absent_3x'         => ['t_3x', ''],
        'notif_template_gate_absent'       => ['t_gate', ''],
    ];

    $notifValues = [];
    foreach ($notifFields as $postKey => $cfg) {
        $col = $cfg[0];
        $default = $cfg[1];
        $allowed = $cfg[2] ?? null;
        $raw = isset($_POST[$postKey]) ? trim($_POST[$postKey]) : null;

        if ($raw !== null && $raw !== '') {
            $notifValues[$col] = ($allowed !== null && !in_array($raw, $allowed)) ? $default : $raw;
        } else {
            $notifValues[$col] = $default;
        }
    }

    if (!empty(array_intersect(array_keys($_POST), array_keys($notifFields)))) {
        $notifStmt->execute($notifValues);
        $updated++;
    }

    // ── Admin: persist Legal & Compliance Engine blocks → system_settings ──
    $legalMap = [
        'legal_privacy_dpo_contact'       => 'privacy_dpo_contact',
        'legal_dpa_version_tag'           => 'dpa_version_tag',
        'legal_dpa_master_notice'         => 'dpa_master_notice',
        'legal_dpa_consent_label_student' => 'dpa_consent_label_student',
        'legal_dpa_consent_label_staff'   => 'dpa_consent_label_staff',
        'legal_system_terms_conditions'   => 'system_terms_conditions',
    ];

    $legalStmt = $db->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, updated_at)
         VALUES (:key, :value, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
    );

    foreach ($legalMap as $postKey => $dbKey) {
        if (!isset($_POST[$postKey])) continue;
        $raw   = trim($_POST[$postKey]);
        $value = cleanString($raw);
        $legalStmt->execute([':key' => $dbKey, ':value' => $value]);
        $updated++;
    }

    logAudit($db, 'update_settings', "Updated $updated setting(s) for tab: $activeTab");

    if ($isActionRequest) {
        jsonResponse(['success' => true, 'message' => "$updated setting(s) saved."]);
    }

    $sourcePage = $_POST['source_page'] ?? 'admin';
    if ($sourcePage === 'root') {
        redirect('/settings.php?tab=' . $activeTab, "$updated setting(s) saved successfully.", 'success');
    } else {
        redirect('/admin/settings.php?tab=' . $activeTab, "$updated setting(s) saved successfully.", 'success');
    }
} catch (Exception $e) {
    if ($isActionRequest) {
        jsonResponse(['success' => false, 'message' => 'Operation failed: ' . $e->getMessage()], 500);
    }
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Save settings error: ' . $e->getMessage());
    $sourcePage = $_POST['source_page'] ?? 'admin';
    if ($sourcePage === 'root') {
        redirect('/settings.php?tab=' . $activeTab, 'Failed to save settings. Please try again.', 'danger');
    } else {
        redirect('/admin/settings.php?tab=' . $activeTab, 'Failed to save settings. Please try again.', 'danger');
    }
}
