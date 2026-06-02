ALTER TABLE users
    MODIFY COLUMN role ENUM('student', 'warden', 'university_admin', 'super_admin') NOT NULL DEFAULT 'student';
