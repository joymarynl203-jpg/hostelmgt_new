-- Optional pin for Google Maps (set by admin when creating/updating a hostel).
ALTER TABLE hostels
    ADD COLUMN map_latitude DECIMAL(10, 7) NULL DEFAULT NULL COMMENT 'WGS84 latitude' AFTER location,
    ADD COLUMN map_longitude DECIMAL(11, 7) NULL DEFAULT NULL COMMENT 'WGS84 longitude' AFTER map_latitude;
