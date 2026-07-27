-- ============================================================
-- System Settings Schema
-- Key-value configuration table for dynamic system parameters
-- ============================================================

CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default privacy policy values
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
(
  'privacy_dpo_contact',
  'dpo@liceodebaleno.edu.ph',
  'Data Protection Officer contact email or phone'
),
(
  'privacy_consent_label',
  'I agree to the Data Privacy Terms and Conditions and verify that consent has been secured.',
  'Exact consent checkbox label text'
),
(
  'privacy_kiosk_notice',
  'You are about to register your facial profile into the Attendance System. Your facial data is securely encrypted under the Philippine Data Privacy Act of 2012 (Republic Act No. 10173). This biometric information will be used solely for attendance verification and will not be shared with third parties without your explicit consent.',
  'Warning notice shown at the face registration kiosk'
),
(
  'privacy_master_terms',
  '1. Introduction\nThis Data Privacy Agreement ("Agreement") governs the collection, processing, storage, and protection of personal and sensitive personal information within the Web-Based Facial Recognition Attendance System ("System") of Liceo de Baleno. By accessing, registering, or interacting with this System, you explicitly acknowledge that you have read, understood, and consented to the processing of your data in accordance with the Republic Act No. 10173, otherwise known as the Data Privacy Act of 2012 (DPA).\n\n2. Scope of Data Collection\nTo fulfill its functions, the System processes the following information:\nBiometric Data: Multi-angle facial images (Front, Left, and Right profiles) converted into encrypted, mathematical biometric templates.\nStudent Personal Information: Full name, Learner Reference Number (LRN), grade level, section, and official school email.\nGuardian Personal Information: Full name, relationship to the student, active mobile number, and contact details.\nLogistical Data: Automated attendance timestamps, kiosk interaction logs, and historical tracking metrics.\n\n3. Purpose of Data Processing\nAll collected information is processed strictly under the principles of transparency, legitimate purpose, and proportionality for the following objectives:\nAutomating, verifying, and securing daily student attendance records.\nTriggering real-time SMS/system notifications to registered guardians regarding student arrival and departure.\nAcademic research, system evaluation, and technical validation within the scope of institutional optimization and authorized research development.\n\n4. Data Storage and Security\nLiceo de Baleno implements rigorous organizational, physical, and technical security measures:\nData is hosted on secure, encrypted servers with strict role-based access control lists (ACLs).\nFacial photographs are processed into one-way, non-reversible digital hashes to prevent reverse engineering of facial images.\nAccess is tightly restricted to authorized system administrators, institutional authorities, and designated researchers.\n\n5. Data Retention and Disposal\nPersonal and biometric data will be retained only for the duration of the student''s enrollment or the active lifecycle evaluation of this research system.\nUpon graduation, transfer, withdrawal of consent, or formal system decommissioning, all biometric vectors and personal identifiers will be permanently deleted, overwritten, or anonymized beyond recovery.\n\n6. Data Subject Rights\nUnder the DPA of 2012, students (and their legal guardians) are afforded the following rights:\nRight to be Informed: Knowing how, why, and when their biometric data is processed.\nRight to Object/Opt-out: The right to withhold or withdraw consent to biometric tracking without academic penalty (alternative manual attendance mechanisms will be provided).\nRight to Access and Rectification: Requesting a copy of stored records or correcting clerical errors in guardian contact details.\n\n7. Third-Party Disclosures\nBiometric templates and personal data will never be shared, rented, or sold to third-party commercial entities. Data transmission is limited exclusively to authorized school personnel and targeted SMS gateways used solely for transmitting automated guardian attendance updates.\n\n8. Limitation of Liability\nWhile the development team and Liceo de Baleno implement industry-standard encryption and safety protocols, no digital system is entirely immune to malicious breaches. The institution shall not be held liable for unforeseen system disruptions or unauthorized access occurring outside reasonable technical control, provided all statutory security updates and best practices have been strictly maintained.\n\n9. Governing Law\nThis Agreement shall be governed, interpreted, and enforced in absolute accordance with the laws of the Republic of the Philippines, under the regulatory oversight of the National Privacy Commission (NPC).',
  'Full 9-point DPA legal framework displayed at registration'
);
