-- Run once on existing databases (phpMyAdmin / mysql CLI).
ALTER TABLE hostels
    ADD COLUMN rent_period_start DATE NULL AFTER description,
    ADD COLUMN rent_period_end DATE NULL AFTER rent_period_start;
