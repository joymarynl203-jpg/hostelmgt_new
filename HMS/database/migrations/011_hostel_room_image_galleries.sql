-- Multiple required photos per hostel/room (replaces single image_path columns from migration 010).

CREATE TABLE IF NOT EXISTS hostel_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hostel_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_hostel_images_hostel FOREIGN KEY (hostel_id) REFERENCES hostels(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS room_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_room_images_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
);

SET @has_hostel_image_path = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'hostels'
      AND COLUMN_NAME = 'image_path'
);

SET @migrate_hostel_images = IF(
    @has_hostel_image_path > 0,
    'INSERT INTO hostel_images (hostel_id, image_path, sort_order)
     SELECT h.id, h.image_path, 0
     FROM hostels h
     WHERE h.image_path IS NOT NULL AND TRIM(h.image_path) <> ''''
       AND NOT EXISTS (
           SELECT 1 FROM hostel_images hi WHERE hi.hostel_id = h.id AND hi.image_path = h.image_path
       )',
    'SELECT 1'
);
PREPARE migrate_hostel_images_stmt FROM @migrate_hostel_images;
EXECUTE migrate_hostel_images_stmt;
DEALLOCATE PREPARE migrate_hostel_images_stmt;

SET @has_room_image_path = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rooms'
      AND COLUMN_NAME = 'image_path'
);

SET @migrate_room_images = IF(
    @has_room_image_path > 0,
    'INSERT INTO room_images (room_id, image_path, sort_order)
     SELECT r.id, r.image_path, 0
     FROM rooms r
     WHERE r.image_path IS NOT NULL AND TRIM(r.image_path) <> ''''
       AND NOT EXISTS (
           SELECT 1 FROM room_images ri WHERE ri.room_id = r.id AND ri.image_path = r.image_path
       )',
    'SELECT 1'
);
PREPARE migrate_room_images_stmt FROM @migrate_room_images;
EXECUTE migrate_room_images_stmt;
DEALLOCATE PREPARE migrate_room_images_stmt;

SET @drop_hostel_image_path = IF(
    @has_hostel_image_path > 0,
    'ALTER TABLE hostels DROP COLUMN image_path',
    'SELECT 1'
);
PREPARE drop_hostel_image_path_stmt FROM @drop_hostel_image_path;
EXECUTE drop_hostel_image_path_stmt;
DEALLOCATE PREPARE drop_hostel_image_path_stmt;

SET @drop_room_image_path = IF(
    @has_room_image_path > 0,
    'ALTER TABLE rooms DROP COLUMN image_path',
    'SELECT 1'
);
PREPARE drop_room_image_path_stmt FROM @drop_room_image_path;
EXECUTE drop_room_image_path_stmt;
DEALLOCATE PREPARE drop_room_image_path_stmt;
