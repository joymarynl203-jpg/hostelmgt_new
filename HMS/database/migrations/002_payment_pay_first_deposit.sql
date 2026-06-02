-- Pay 20% deposit before a booking row exists (avoids two students "pending" on the same room).
-- Run once on existing databases. If DROP FOREIGN KEY fails, find the real name via:
--   SHOW CREATE TABLE payments;

ALTER TABLE payments DROP FOREIGN KEY fk_payments_booking;

ALTER TABLE payments
    MODIFY booking_id INT NULL COMMENT 'NULL until pre-deposit payment is finalized into a booking',
    ADD COLUMN student_user_id INT NULL COMMENT 'Student paying when booking_id is NULL' AFTER booking_id,
    ADD COLUMN room_id INT NULL COMMENT 'Room reserved for pre-booking deposit' AFTER student_user_id;

ALTER TABLE payments
    ADD CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE;

CREATE INDEX idx_payments_room_prebooking ON payments (room_id, status, booking_id);
