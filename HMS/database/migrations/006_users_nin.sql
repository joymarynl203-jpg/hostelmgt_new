-- National Identification Number (NIN) for wardens / staff; students use reg_no for university reg where applicable.
ALTER TABLE users
    ADD COLUMN nin VARCHAR(28) NULL DEFAULT NULL COMMENT 'National ID number' AFTER reg_no;
