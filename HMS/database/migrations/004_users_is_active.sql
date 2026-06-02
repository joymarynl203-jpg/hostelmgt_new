-- Student (and other) accounts: 0 blocks login for students; staff should remain 1.
ALTER TABLE users
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER phone;
