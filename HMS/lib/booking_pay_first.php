<?php

/**
 * Pay-first booking: 20% deposit is taken before a bookings row is created,
 * so two students cannot hold the same room on "pending booking" alone.
 */

function hms_expire_stale_deposit_intents(PDO $db): void
{
    $db->prepare('
        UPDATE payments
        SET status = "failed",
            gateway_status = "intent_expired"
        WHERE booking_id IS NULL
          AND status = "pending"
          AND created_at < DATE_SUB(NOW(), INTERVAL 45 MINUTE)
    ')->execute();
}

/**
 * Count of reservation slots used: active bookings plus in-flight deposit payments for this room.
 *
 * @param int $excludePrebookingPaymentId Pass payment id to omit (e.g. while finalizing that payment into a booking).
 */
function hms_room_pipeline_reserved_count(PDO $db, int $roomId, int $excludePrebookingPaymentId = 0): int
{
    if ($roomId <= 0) {
        return PHP_INT_MAX;
    }
    $stmt = $db->prepare('
        SELECT
            (SELECT COUNT(*) FROM bookings b
             WHERE b.room_id = ? AND b.status IN ("pending", "approved", "checked_in"))
            + (SELECT COUNT(*) FROM payments p
               WHERE p.room_id = ? AND p.booking_id IS NULL
                 AND p.status IN ("pending", "successful")
                 AND (? = 0 OR p.id <> ?)) AS n
    ');
    $stmt->execute([$roomId, $roomId, $excludePrebookingPaymentId, $excludePrebookingPaymentId]);
    $row = $stmt->fetch();

    return (int)($row['n'] ?? 0);
}

/**
 * True if at least one more student can start a deposit / booking for this room (capacity not yet reached).
 */
function hms_room_available_for_pay_first_deposit(PDO $db, int $roomId, int $excludePrebookingPaymentId = 0): bool
{
    if ($roomId <= 0) {
        return false;
    }

    $stmt = $db->prepare('SELECT capacity FROM rooms WHERE id = ?');
    $stmt->execute([$roomId]);
    $cap = (int)($stmt->fetch()['capacity'] ?? 0);
    if ($cap < 1) {
        return false;
    }

    return hms_room_pipeline_reserved_count($db, $roomId, $excludePrebookingPaymentId) < $cap;
}

/**
 * Load room + hostel for booking insert; validates active hostel and rent window set.
 *
 * @param int $excludePrebookingPaymentId See hms_room_pipeline_reserved_count()
 * @return array<string,mixed>|null
 */
function hms_load_room_for_student_booking(PDO $db, int $roomId, int $excludePrebookingPaymentId = 0): ?array
{
    $roomStmt = $db->prepare('
        SELECT r.*, h.is_active, h.managed_by, h.name AS hostel_name, h.id AS hostel_id,
            h.rent_period_start, h.rent_period_end
        FROM rooms r
        JOIN hostels h ON h.id = r.hostel_id
        WHERE r.id = ?
    ');
    $roomStmt->execute([$roomId]);
    $room = $roomStmt->fetch();
    if (!$room || (int)$room['is_active'] !== 1) {
        return null;
    }
    $rpStart = (string)($room['rent_period_start'] ?? '');
    $rpEnd = (string)($room['rent_period_end'] ?? '');
    if ($rpStart === '' || $rpEnd === '') {
        return null;
    }
    $cap = (int)$room['capacity'];
    if ($cap < 1) {
        return null;
    }
    if (hms_room_pipeline_reserved_count($db, $roomId, $excludePrebookingPaymentId) >= $cap) {
        return null;
    }

    return $room;
}

/**
 * @throws RuntimeException on validation failure
 */
function hms_assert_room_ready_for_pay_first_deposit(PDO $db, int $roomId, int $studentUserId): array
{
    hms_expire_stale_deposit_intents($db);

    $room = hms_load_room_for_student_booking($db, $roomId);
    if ($room === null) {
        throw new RuntimeException('Room is not available for booking (inactive, full, or semester dates not set).');
    }

    if (!hms_room_available_for_pay_first_deposit($db, $roomId)) {
        throw new RuntimeException('This room is already reserved by another payment or an existing allocation. Try another room or wait a few minutes.');
    }

    $dupStmt = $db->prepare('
        SELECT id FROM payments
        WHERE room_id = ? AND student_user_id = ? AND booking_id IS NULL AND status = "pending"
        LIMIT 1
    ');
    $dupStmt->execute([$roomId, $studentUserId]);
    if ($dupStmt->fetch()) {
        throw new RuntimeException('You already have a deposit payment in progress for this room. Complete or wait for it to expire, then try again.');
    }

    return $room;
}

/**
 * After Pesapal marks a pre-booking payment successful: create booking and attach payment.
 * Must be called inside an outer database transaction.
 *
 * @throws RuntimeException if booking cannot be created (rolls back with caller)
 */
function hms_finalize_prebooking_deposit_payment(PDO $db, int $paymentId): int
{
    $pStmt = $db->prepare('
        SELECT id, amount, status, room_id, student_user_id, booking_id
        FROM payments
        WHERE id = ?
        FOR UPDATE
    ');
    $pStmt->execute([$paymentId]);
    $p = $pStmt->fetch();
    if (!$p) {
        throw new RuntimeException('Payment not found.');
    }
    if ((int)($p['booking_id'] ?? 0) > 0) {
        return (int)$p['booking_id'];
    }
    if (($p['status'] ?? '') !== 'successful') {
        throw new RuntimeException('Payment is not successful.');
    }
    $roomId = (int)($p['room_id'] ?? 0);
    $studentUserId = (int)($p['student_user_id'] ?? 0);
    if ($roomId <= 0 || $studentUserId <= 0) {
        throw new RuntimeException('Invalid pre-booking payment data.');
    }

    $roomStmt = $db->prepare('
        SELECT r.id
        FROM rooms r
        WHERE r.id = ?
        FOR UPDATE
    ');
    $roomStmt->execute([$roomId]);
    if (!$roomStmt->fetch()) {
        throw new RuntimeException('Room not found.');
    }

    $room = hms_load_room_for_student_booking($db, $roomId, $paymentId);
    if ($room === null) {
        throw new RuntimeException('Room is no longer bookable.');
    }

    $cap = (int)($room['capacity'] ?? 0);
    $allocStmt = $db->prepare('
        SELECT COUNT(*) AS c FROM bookings
        WHERE room_id = ? AND status IN ("pending", "approved", "checked_in")
    ');
    $allocStmt->execute([$roomId]);
    $bookingCount = (int)($allocStmt->fetch()['c'] ?? 0);
    if ($bookingCount >= $cap) {
        throw new RuntimeException('Room is already at capacity for bookings.');
    }

    $startDate = (string)($room['rent_period_start'] ?? '');
    $endDate = (string)($room['rent_period_end'] ?? '');
    $semesters = 1;
    $totalDue = (float)$room['monthly_fee'];

    $ins = $db->prepare('
        INSERT INTO bookings (student_id, room_id, status, months, start_date, end_date, total_due)
        VALUES (?, ?, "pending", ?, ?, ?, ?)
    ');
    $ins->execute([$studentUserId, $roomId, $semesters, $startDate, $endDate, $totalDue]);
    $bookingId = (int)$db->lastInsertId();

    $upd = $db->prepare('
        UPDATE payments
        SET booking_id = ?, room_id = NULL, student_user_id = NULL
        WHERE id = ? AND booking_id IS NULL
    ');
    $upd->execute([$bookingId, $paymentId]);
    if ($upd->rowCount() !== 1) {
        throw new RuntimeException('Failed to attach payment to new booking.');
    }

    hms_audit_log($studentUserId, 'booking_created', 'booking', $bookingId, 'Room #' . $room['room_number'] . ' semester ' . $startDate . '–' . $endDate . ' (after 20% deposit paid).');

    return $bookingId;
}
