-- Migration: Remove subject_code column and related indexes from subjects table
-- NOTE: `DROP COLUMN IF EXISTS` and `DROP INDEX IF EXISTS` require MySQL 8.0+.
-- If your MySQL is older, use the provided PHP migration script instead: migrations/remove_subject_code.php

ALTER TABLE subjects DROP COLUMN IF EXISTS subject_code;
ALTER TABLE subjects DROP INDEX IF EXISTS idx_subjects_code;
ALTER TABLE subjects DROP INDEX IF EXISTS subject_code;
