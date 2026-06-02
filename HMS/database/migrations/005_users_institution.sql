-- Student institution / university (collected at self-registration).
ALTER TABLE users
    ADD COLUMN institution VARCHAR(200) NULL DEFAULT NULL COMMENT 'School or university (students)' AFTER phone;
