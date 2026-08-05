Remove subject_code migration

This folder contains two artifacts to remove the `subject_code` column and related indexes from the `subjects` table.

Files
- remove_subject_code.sql — SQL migration suitable for MySQL 8.0+ (uses `IF EXISTS`).
- remove_subject_code.php  — PHP migration script that checks `information_schema` and drops the column/indexes safely (recommended if MySQL < 8 or to avoid SQL errors).

Usage
1. Back up your database first (REQUIRED).

2a. Using SQL (MySQL 8+):
   mysql -u <user> -p <database> < migrations/remove_subject_code.sql

2b. Using PHP script (recommended for safety):
   php migrations/remove_subject_code.php

Notes
- The PHP script uses the existing application `config.php` to obtain DB connection information. Run it from the project root.
- The migration will attempt to drop the indexes named `idx_subjects_code` and `subject_code` if present.
- After running the migration, verify your application: subject create/update flows in the admin and API were already adjusted to not write `subject_code`.
