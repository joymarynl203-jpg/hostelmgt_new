-- Cover photos for hostels and rooms (shown to students when browsing/booking).
ALTER TABLE hostels
    ADD COLUMN image_path VARCHAR(255) NULL DEFAULT NULL COMMENT 'Relative path under public/uploads/' AFTER description;

ALTER TABLE rooms
    ADD COLUMN image_path VARCHAR(255) NULL DEFAULT NULL COMMENT 'Relative path under public/uploads/' AFTER monthly_fee;
