-- phpMyAdmin SQL Dump
-- Database: `ldb_fras`
-- Updated: LRN migration + name_extension column

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(11) UNSIGNED NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `template_type` varchar(50) DEFAULT 'general' COMMENT 'class_suspension,school_event,student_achievement,student_misconduct,parent_meeting,general',
  `recipients` text DEFAULT NULL COMMENT 'JSON: {"all":true} or {"grades":["7","8"]}',
  `channels` text DEFAULT NULL COMMENT 'JSON array: ["email","sms"]',
  `delivery_type` set('sms','email','both') NOT NULL DEFAULT 'both',
  `template` varchar(50) DEFAULT 'general',
  `status` enum('sent','scheduled','draft','failed') NOT NULL DEFAULT 'sent',
  `scheduled_at` datetime DEFAULT NULL,
  `created_by` int(11) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `announcements`
--

INSERT INTO `announcements` (`id`, `title`, `subject`, `body`, `template_type`, `recipients`, `channels`, `delivery_type`, `template`, `status`, `scheduled_at`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'Student Achievement Recognition', 'Student Achievement Recognition', 'Dear Parents/Guardians,\r\n\r\nWe are proud to inform you that your child, [STUDENT NAME], has achieved [ACHIEVEMENT DETAILS] in [COMPETITION/ACTIVITY] held on [DATE].\r\n\r\nCongratulations!\r\n\r\nLiceo de Baleno Administration', 'student_achievement', '{\"all\":true}', '[\"email\"]', 'both', 'general', 'sent', NULL, 1, '2026-06-07 00:52:55', NULL),
(2, 'General Announcement', 'General Announcement', 'Dear Parents/Guardians,\r\n\r\n[ANNOUNCEMENT CONTENT]\r\n\r\nThank you,\r\nLiceo de Baleno Administration', 'general', '{\"all\":true}', '[\"email\"]', 'both', 'general', 'sent', NULL, 1, '2026-06-07 00:53:00', NULL),
(3, 'Class Suspension Notice', 'Class Suspension Notice', 'Dear Parents/Guardians,\r\n\r\nPlease be informed that classes for [GRADE LEVEL/SECTION] will be suspended on [DATE] due to [REASON].\r\n\r\nClasses will resume on [RESUME DATE].\r\n\r\nThank you,\r\nLiceo de Baleno Administration', 'class_suspension', '{\"all\":true}', '[\"email\"]', 'both', 'general', 'sent', NULL, 1, '2026-06-07 22:33:49', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) UNSIGNED NOT NULL,
  `student_id` int(11) UNSIGNED NOT NULL,
  `subject_id` int(11) UNSIGNED DEFAULT NULL,
  `date` date NOT NULL,
  `time` time DEFAULT NULL,
  `status` enum('present','absent','late','pending','excused') NOT NULL DEFAULT 'present',
  `session_type` enum('gate','class') NOT NULL DEFAULT 'class',
  `recorded_by` int(11) UNSIGNED DEFAULT NULL COMMENT 'Teacher or gate personnel ID',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_records`
--

CREATE TABLE `attendance_records` (
  `id` int(11) UNSIGNED NOT NULL,
  `student_id` int(11) UNSIGNED NOT NULL,
  `gate_session_id` int(11) UNSIGNED DEFAULT NULL,
  `session_type` enum('time_in','time_out') NOT NULL DEFAULT 'time_in',
  `scan_time` datetime NOT NULL,
  `status` enum('present','late','absent') NOT NULL DEFAULT 'present',
  `confidence_score` decimal(5,2) DEFAULT NULL COMMENT 'Face match confidence %',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Old attendance_records cleared (referenced invalid student_ids)
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance_sessions`
--

CREATE TABLE `attendance_sessions` (
  `id` int(11) UNSIGNED NOT NULL,
  `session_type` enum('gate','class') NOT NULL DEFAULT 'gate',
  `subject_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'For class sessions',
  `grade_level` enum('7','8','9','10','11','12') DEFAULT NULL,
  `section` varchar(100) DEFAULT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `late_threshold` int(11) UNSIGNED NOT NULL DEFAULT 15 COMMENT 'Minutes before marked late',
  `status` enum('active','completed','cancelled') NOT NULL DEFAULT 'active',
  `created_by` int(11) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) UNSIGNED NOT NULL,
  `user_id` int(11) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'login', 'User logged in as admin', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-07 00:14:16'),
(2, 2, 'login', 'User logged in as gate', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-07 01:38:00'),
(3, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-07 01:41:32'),
(4, 1, 'login', 'User logged in as admin', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-07 01:42:25'),
(5, 2, 'gate_session_end', 'Ended time_in session. 0 students marked absent.', '::1', NULL, '2026-06-07 01:45:21'),
(6, 2, 'gate_session_start', 'Started time_out session from 15:30 to 17:00', '::1', NULL, '2026-06-07 01:45:35'),
(7, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-07 01:45:38'),
(8, 2, 'gate_session_end', 'Ended time_in session. 0 students marked absent.', '::1', NULL, '2026-06-07 01:45:44'),
(9, 2, 'gate_session_end', 'Ended time_in session. 0 students marked absent.', '::1', NULL, '2026-06-07 01:45:55'),
(10, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-07 01:46:34'),
(11, 2, 'gate_session_end', 'Ended time_in session. 0 students marked absent.', '::1', NULL, '2026-06-07 01:47:38'),
(12, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-07 01:47:39'),
(13, 2, 'gate_session_end', 'Ended time_in session. 0 students marked absent.', '::1', NULL, '2026-06-07 01:47:40'),
(14, 2, 'gate_session_end', 'Ended time_out session. 1 students marked absent.', '::1', NULL, '2026-06-07 01:47:43'),
(15, 2, 'gate_session_start', 'Started time_out session from 15:30 to 17:00', '::1', NULL, '2026-06-07 01:47:44'),
(16, 2, 'gate_session_end', 'Ended time_out session. 0 students marked absent.', '::1', NULL, '2026-06-07 01:47:46'),
(17, 2, 'gate_session_start', 'Started time_out session from 15:30 to 17:00', '::1', NULL, '2026-06-07 01:47:47'),
(18, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-07 01:51:46'),
(19, 2, 'gate_session_end', 'Ended time_out session. 0 students marked absent.', '::1', NULL, '2026-06-07 02:01:03'),
(20, 2, 'gate_session_end', 'Ended time_in session. 0 students marked absent.', '::1', NULL, '2026-06-07 02:01:08'),
(21, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-07 02:01:18'),
(22, 2, 'gate_session_end', 'Ended time_in session. 0 students marked absent.', '::1', NULL, '2026-06-07 02:02:08'),
(23, 2, 'gate_session_start', 'Started time_out session from 15:30 to 17:00', '::1', NULL, '2026-06-07 02:02:10'),
(24, 1, 'login', 'User logged in as admin', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36', '2026-06-07 22:33:19'),
(25, 2, 'login', 'User logged in as gate', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-07 22:34:33'),
(26, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-07 22:34:49'),
(27, 2, 'login', 'User logged in as gate', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-07 22:35:52'),
(28, 2, 'gate_session_end', 'Ended time_in session. 0 students marked absent.', '::1', NULL, '2026-06-07 22:36:07'),
(29, 2, 'gate_session_end', 'Ended time_out session. 0 students marked absent.', '::1', NULL, '2026-06-07 22:36:10'),
(30, 2, 'gate_session_start', 'Started time_out session from 15:30 to 17:00', '::1', NULL, '2026-06-07 22:36:37'),
(31, 3, 'login', 'User logged in as teacher', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-07 22:46:27'),
(32, 2, 'login', 'User logged in as gate', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-14 10:08:02'),
(33, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-14 10:09:13'),
(34, 2, 'gate_session_end', 'Ended time_in session. 1 students marked absent.', '::1', NULL, '2026-06-14 10:11:51'),
(35, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-14 10:12:00'),
(36, 2, 'gate_session_end', 'Ended time_in session. 0 students marked absent.', '::1', NULL, '2026-06-14 10:13:08'),
(37, 2, 'gate_session_end', 'Ended time_out session. 0 students marked absent.', '::1', NULL, '2026-06-14 10:13:17'),
(38, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-14 10:13:26'),
(39, 2, 'gate_session_end', 'Ended time_in session. 0 students marked absent.', '::1', NULL, '2026-06-14 10:14:39'),
(40, 1, 'login', 'User logged in as admin', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-14 10:17:11'),
(41, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-14 10:18:22'),
(42, 2, 'gate_session_end', 'Ended time_in session. 0 students marked absent.', '::1', NULL, '2026-06-14 10:18:50'),
(43, 2, 'login', 'User logged in as gate', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-17 01:07:19'),
(44, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-17 01:07:25'),
(45, 2, 'gate_session_end', 'Ended time_in session. 1 students marked absent.', '::1', NULL, '2026-06-17 01:08:35'),
(46, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-17 01:08:43'),
(47, 2, 'gate_session_end', 'Ended time_in session. 0 students marked absent.', '::1', NULL, '2026-06-17 01:09:42'),
(48, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-17 01:11:01'),
(49, 2, 'gate_session_end', 'Ended time_in session. 0 students marked absent.', '::1', NULL, '2026-06-17 01:13:27'),
(50, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-17 01:14:21'),
(51, 2, 'gate_session_end', 'Ended time_in session. 0 students marked absent.', '::1', NULL, '2026-06-17 01:15:00'),
(52, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-17 01:15:37'),
(53, 2, 'gate_session_end', 'Ended time_in session. 0 students marked absent.', '::1', NULL, '2026-06-17 01:17:31'),
(54, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-17 01:18:01'),
(55, 2, 'gate_session_end', 'Ended time_in session. 0 students marked absent.', '::1', NULL, '2026-06-17 01:23:31'),
(56, 2, 'gate_session_start', 'Started time_out session from 15:30 to 17:00', '::1', NULL, '2026-06-17 01:23:39'),
(57, 2, 'gate_session_end', 'Ended time_out session. 0 students marked absent.', '::1', NULL, '2026-06-17 01:26:00'),
(58, 2, 'gate_session_start', 'Started time_in session from 07:00 to 08:00', '::1', NULL, '2026-06-17 01:26:07'),
(59, 1, 'login', 'User logged in as admin', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-17 01:31:17'),
(60, 2, 'login', 'User logged in as gate', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-18 01:02:08'),
(61, 1, 'login', 'User logged in as admin', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-18 01:09:36');

-- --------------------------------------------------------

--
-- Table structure for table `gate_logs`
--

CREATE TABLE `gate_logs` (
  `id` int(11) UNSIGNED NOT NULL,
  `student_id` int(11) UNSIGNED NOT NULL,
  `time_in` datetime DEFAULT NULL,
  `time_out` datetime DEFAULT NULL,
  `status` enum('time-in','time-out','late') NOT NULL DEFAULT 'time-in',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Old gate_logs cleared (referenced invalid student_ids)
--

-- --------------------------------------------------------

--
-- Table structure for table `gate_sessions`
--

CREATE TABLE `gate_sessions` (
  `id` int(11) UNSIGNED NOT NULL,
  `created_by` int(11) UNSIGNED DEFAULT NULL,
  `session_type` enum('time_in','time_out') NOT NULL DEFAULT 'time_in',
  `session_period` enum('morning','afternoon') DEFAULT NULL COMMENT 'Daily period: morning or afternoon',
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `late_threshold` int(11) UNSIGNED NOT NULL DEFAULT 15 COMMENT 'Minutes after start = late',
  `status` enum('active','completed','cancelled') NOT NULL DEFAULT 'active',
  `ended_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Old gate_sessions cleared (referenced invalid student attendance data)
--

-- --------------------------------------------------------

--
-- Table structure for table `guardians`
--

CREATE TABLE `guardians` (
  `id` int(11) UNSIGNED NOT NULL,
  `student_id` int(11) UNSIGNED NOT NULL,
  `guardian_name` varchar(255) NOT NULL,
  `relationship` varchar(50) DEFAULT 'Parent',
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `guardians` (matches new LRN students)
--

INSERT INTO `guardians` (`id`, `student_id`, `guardian_name`, `relationship`, `phone`, `email`, `address`, `created_at`) VALUES
(1, 1, 'Pedro Dela Cruz', 'Parent', '09171234567', 'pedro.delacruz@email.com', 'Baleno, Masbate, Philippines', '2026-06-18 15:00:00'),
(2, 2, 'Ana Reyes', 'Parent', '09181234567', 'ana.reyes@email.com', 'Baleno, Masbate, Philippines', '2026-06-18 15:00:00'),
(3, 3, 'Jose Santos Sr.', 'Parent', '09191234567', 'jose.santos@email.com', 'Baleno, Masbate, Philippines', '2026-06-18 15:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) UNSIGNED NOT NULL,
  `recipient` varchar(255) NOT NULL COMMENT 'Email or phone number',
  `channel` enum('email','sms') NOT NULL,
  `message` text NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `status` enum('sent','failed','pending') NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `recipient`, `channel`, `message`, `subject`, `status`, `error_message`, `sent_at`) VALUES
(1, 'all_parents', 'email', 'Dear Parents/Guardians,\r\n\r\nWe are proud to inform you that your child, [STUDENT NAME], has achieved [ACHIEVEMENT DETAILS] in [COMPETITION/ACTIVITY] held on [DATE].\r\n\r\nCongratulations!\r\n\r\nLiceo de Baleno Administration', 'Student Achievement Recognition', 'sent', NULL, '2026-06-07 00:52:56'),
(2, 'all_parents', 'email', 'Dear Parents/Guardians,\r\n\r\n[ANNOUNCEMENT CONTENT]\r\n\r\nThank you,\r\nLiceo de Baleno Administration', 'General Announcement', 'sent', NULL, '2026-06-07 00:53:00'),
(3, 'all_parents', 'email', 'Dear Parents/Guardians,\r\n\r\nPlease be informed that classes for [GRADE LEVEL/SECTION] will be suspended on [DATE] due to [REASON].\r\n\r\nClasses will resume on [RESUME DATE].\r\n\r\nThank you,\r\nLiceo de Baleno Administration', 'Class Suspension Notice', 'sent', NULL, '2026-06-07 22:33:49');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `description`, `updated_at`) VALUES
(1, 'school_name', 'Liceo de Baleno', 'School name', NULL),
(2, 'school_address', 'Baleno, Masbate, Philippines', 'School address', NULL),
(3, 'school_year', '2025-2026', 'Current school year', NULL),
(4, 'smtp_host', 'smtp.gmail.com', 'SMTP server host', NULL),
(5, 'smtp_port', '587', 'SMTP server port', NULL),
(6, 'smtp_username', '', 'SMTP username/email', NULL),
(7, 'smtp_password', '', 'SMTP password', NULL),
(8, 'smtp_from_email', 'noreply@liceodebaleno.edu.ph', 'From email address', NULL),
(9, 'smtp_from_name', 'LDB-FRAS', 'From email name', NULL),
(10, 'sms_api_key', '', 'Semaphore/Twilio API key', NULL),
(11, 'sms_api_url', 'https://api.semaphore.co/api/v4/messages', 'SMS API endpoint', NULL),
(12, 'sms_sender_id', 'LDBFRAS', 'SMS sender name', NULL),
(13, 'late_threshold', '15', 'Default late threshold in minutes', NULL),
(14, 'gate_time_in_start', '06:00', 'Gate time-in session start', NULL),
(15, 'gate_time_in_end', '08:00', 'Gate time-in session end', NULL),
(16, 'enable_email_notifications', '1', 'Enable email notifications', NULL),
(17, 'enable_sms_notifications', '0', 'Enable SMS notifications', NULL),
(18, 'smtp_encryption', 'tls', 'SMTP encryption (tls/ssl/none)', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `strands`
--

CREATE TABLE `strands` (
  `id` int(11) UNSIGNED NOT NULL,
  `strand_name` varchar(255) NOT NULL,
  `strand_code` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `strands`
--

INSERT INTO `strands` (`id`, `strand_name`, `strand_code`, `description`, `created_at`, `updated_at`) VALUES
(1, 'Science, Technology, Engineering, and Mathematics', 'STEM', NULL, '2026-06-06 23:53:23', NULL),
(2, 'Accountancy, Business, and Management', 'ABM', NULL, '2026-06-06 23:53:23', NULL),
(3, 'Humanities and Social Sciences', 'HUMSS', NULL, '2026-06-06 23:53:23', NULL),
(4, 'Technical-Vocational-Livelihood', 'TVL', NULL, '2026-06-06 23:53:23', NULL),
(5, 'General Academic Strand', 'GAS', NULL, '2026-06-06 23:53:23', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `students`
-- UPDATED: Added `name_extension` column, student_id is now LRN format
--

CREATE TABLE `students` (
  `id` int(11) UNSIGNED NOT NULL,
  `student_id` varchar(50) NOT NULL COMMENT 'LRN: 12 digits starting with 1134',
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT '',
  `last_name` varchar(100) NOT NULL,
  `name_extension` varchar(10) DEFAULT NULL COMMENT 'Jr., Sr., II, III, IV, V, etc.',
  `age` int(3) UNSIGNED DEFAULT NULL,
  `gender` enum('Male','Female') NOT NULL,
  `address` text DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `grade_level` enum('7','8','9','10','11','12') NOT NULL,
  `section` varchar(100) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive','transferred','graduated') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `students` (valid LRN format)
--

INSERT INTO `students` (`id`, `student_id`, `first_name`, `middle_name`, `last_name`, `name_extension`, `age`, `gender`, `address`, `email`, `grade_level`, `section`, `photo`, `status`, `created_at`, `updated_at`) VALUES
(1, '113400000001', 'Juan', 'Santos', 'Dela Cruz', NULL, 16, 'Male', 'Baleno, Masbate, Philippines', 'juan.delacruz@student.edu.ph', '11', 'STEM-A', NULL, 'active', '2026-06-18 15:00:00', NULL),
(2, '113400000002', 'Maria', 'Cruz', 'Reyes', NULL, 15, 'Female', 'Baleno, Masbate, Philippines', 'maria.reyes@student.edu.ph', '10', 'St. Peter', NULL, 'active', '2026-06-18 15:00:00', NULL),
(3, '113400000003', 'Jose', 'Garcia', 'Santos', 'Jr.', 17, 'Male', 'Baleno, Masbate, Philippines', 'jose.santos@student.edu.ph', '12', 'ABM-B', NULL, 'active', '2026-06-18 15:00:00', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `student_faces`
--

CREATE TABLE `student_faces` (
  `id` int(11) UNSIGNED NOT NULL,
  `student_id` int(11) UNSIGNED NOT NULL,
  `front_face` varchar(255) DEFAULT NULL COMMENT 'Path to front face image',
  `left_face` varchar(255) DEFAULT NULL COMMENT 'Path to left face image',
  `right_face` varchar(255) DEFAULT NULL COMMENT 'Path to right face image',
  `face_encoding` text DEFAULT NULL COMMENT 'JSON encoded face embedding',
  `encoding_status` enum('pending','processed','failed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Old student_faces cleared (referenced invalid student_ids)
--

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` int(11) UNSIGNED NOT NULL,
  `subject_name` varchar(255) NOT NULL,
  `subject_code` varchar(50) NOT NULL,
  `grade_level` enum('7','8','9','10','11','12') NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teachers`
--

CREATE TABLE `teachers` (
  `id` int(11) UNSIGNED NOT NULL,
  `employee_id` varchar(50) DEFAULT NULL COMMENT 'Employee/teacher ID',
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `subjects_handled` varchar(500) DEFAULT NULL COMMENT 'Comma-separated subject names',
  `advisory_class` varchar(100) DEFAULT NULL COMMENT 'e.g. Grade 10-St. Peter',
  `user_id` int(11) UNSIGNED DEFAULT NULL COMMENT 'Linked user account',
  `privacy_accepted_at` datetime DEFAULT NULL COMMENT 'Timestamp of data privacy consent',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teacher_subjects`
--

CREATE TABLE `teacher_subjects` (
  `id` int(11) UNSIGNED NOT NULL,
  `teacher_id` int(11) UNSIGNED NOT NULL,
  `subject_id` int(11) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) UNSIGNED NOT NULL,
  `role` enum('admin','teacher','gate','parent') NOT NULL DEFAULT 'teacher',
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `otp_code` varchar(10) DEFAULT NULL,
  `otp_expires` datetime DEFAULT NULL,
  `remember_token` varchar(255) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `role`, `email`, `password`, `status`, `otp_code`, `otp_expires`, `remember_token`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'admin@liceodebaleno.edu.ph', '$2y$12$.ScqJSlzbL9ruk26bnZ6qeSZ80GH//fzvatDJ7EINcq2myT3aO0Ji', 'active', NULL, NULL, '4627fa1b0ae00352501add469cc521bb0470216253b0da0da21bf76e569d6b83', '2026-06-18 14:36:50', '2026-06-06 23:53:23', '2026-06-18 14:36:50'),
(2, 'gate', 'gate@liceodebaleno.edu.ph', '$2y$12$rWMIx6QGuTh9nexJuyD9veCtUYNaA86IYgcEkwBYNRDDdXrjeLB6W', 'active', NULL, NULL, NULL, '2026-06-18 14:05:46', '2026-06-07 01:27:49', '2026-06-18 14:05:46'),
(3, 'teacher', 'teacher@liceodebaleno.edu.ph', '$2y$12$rWMIx6QGuTh9nexJuyD9veCtUYNaA86IYgcEkwBYNRDDdXrjeLB6W', 'active', NULL, NULL, NULL, '2026-06-18 14:16:37', '2026-06-07 01:27:49', '2026-06-18 14:16:37'),
(4, 'parent', 'parent@liceodebaleno.edu.ph', '$2y$12$rWMIx6QGuTh9nexJuyD9veCtUYNaA86IYgcEkwBYNRDDdXrjeLB6W', 'active', NULL, NULL, NULL, '2026-06-07 22:46:27', '2026-06-07 01:27:49', '2026-06-07 22:46:27');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_announcements_created` (`created_at`),
  ADD KEY `idx_announcements_status` (`status`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_attendance` (`student_id`,`subject_id`,`date`),
  ADD KEY `idx_attendance_date` (`date`),
  ADD KEY `idx_attendance_status` (`status`),
  ADD KEY `idx_attendance_student` (`student_id`),
  ADD KEY `idx_attendance_subject` (`subject_id`);

--
-- Indexes for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ar_student` (`student_id`),
  ADD KEY `idx_ar_session` (`gate_session_id`),
  ADD KEY `idx_ar_scan_time` (`scan_time`),
  ADD KEY `idx_ar_status` (`status`),
  ADD KEY `idx_ar_session_type` (`session_type`);

--
-- Indexes for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_session_subject` (`subject_id`),
  ADD KEY `idx_sessions_status` (`status`),
  ADD KEY `idx_sessions_type` (`session_type`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_user` (`user_id`),
  ADD KEY `idx_audit_action` (`action`),
  ADD KEY `idx_audit_created` (`created_at`);

--
-- Indexes for table `gate_logs`
--
ALTER TABLE `gate_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gate_student` (`student_id`),
  ADD KEY `idx_gate_date` (`time_in`),
  ADD KEY `idx_gate_status` (`status`);

--
-- Indexes for table `gate_sessions`
--
ALTER TABLE `gate_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gate_sessions_status` (`status`),
  ADD KEY `idx_gate_sessions_type` (`session_type`);

--
-- Indexes for table `guardians`
--
ALTER TABLE `guardians`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_guardian_student` (`student_id`),
  ADD KEY `idx_guardians_phone` (`phone`),
  ADD KEY `idx_guardians_email` (`email`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_recipient` (`recipient`),
  ADD KEY `idx_notifications_channel` (`channel`),
  ADD KEY `idx_notifications_status` (`status`),
  ADD KEY `idx_notifications_sent` (`sent_at`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`),
  ADD KEY `idx_settings_key` (`setting_key`);

--
-- Indexes for table `strands`
--
ALTER TABLE `strands`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `strand_code` (`strand_code`),
  ADD KEY `idx_strands_code` (`strand_code`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_id` (`student_id`),
  ADD KEY `idx_students_student_id` (`student_id`),
  ADD KEY `idx_students_grade` (`grade_level`),
  ADD KEY `idx_students_section` (`section`),
  ADD KEY `idx_students_name` (`last_name`,`first_name`);

--
-- Indexes for table `student_faces`
--
ALTER TABLE `student_faces`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_face_student` (`student_id`),
  ADD KEY `idx_faces_encoding_status` (`encoding_status`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `subject_code` (`subject_code`),
  ADD KEY `idx_subjects_code` (`subject_code`),
  ADD KEY `idx_subjects_grade` (`grade_level`);

--
-- Indexes for table `teachers`
--
ALTER TABLE `teachers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_teachers_employee_id` (`employee_id`),
  ADD KEY `idx_teachers_email` (`email`),
  ADD KEY `idx_teachers_user_id` (`user_id`);

--
-- Indexes for table `teacher_subjects`
--
ALTER TABLE `teacher_subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_teacher_subject` (`teacher_id`,`subject_id`),
  ADD KEY `fk_ts_subject` (`subject_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_email` (`email`),
  ADD KEY `idx_users_status` (`status`);

--
-- AUTO_INCREMENT for dumped tables
--

ALTER TABLE `announcements`       MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
ALTER TABLE `attendance`          MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `attendance_records`  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `attendance_sessions` MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `audit_logs`          MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;
ALTER TABLE `gate_logs`           MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `gate_sessions`       MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `guardians`           MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
ALTER TABLE `notifications`       MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
ALTER TABLE `settings`            MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;
ALTER TABLE `strands`             MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;
ALTER TABLE `students`            MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
ALTER TABLE `student_faces`       MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `subjects`            MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `teachers`            MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `teacher_subjects`    MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE `users`               MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_attendance_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_attendance_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `attendance_records`
  ADD CONSTRAINT `fk_ar_session` FOREIGN KEY (`gate_session_id`) REFERENCES `gate_sessions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ar_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `attendance_sessions`
  ADD CONSTRAINT `fk_session_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `gate_logs`
  ADD CONSTRAINT `fk_gate_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `guardians`
  ADD CONSTRAINT `fk_guardian_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `student_faces`
  ADD CONSTRAINT `fk_face_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `teacher_subjects`
  ADD CONSTRAINT `fk_ts_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ts_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;