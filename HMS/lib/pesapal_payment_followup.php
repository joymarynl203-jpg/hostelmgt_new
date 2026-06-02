<?php

/**
 * Run booking/deposit side effects after a payment row is marked successful.
 * Safe to call from both browser callback and server IPN.
 */
function hms_pesapal_run_success_followup(PDO $db, int $bookingId, int $studentUserId): void
{
    $sumStmt = $db->prepare('
        SELECT
            b.id AS booking_id,
            b.status AS booking_status,
            b.total_due,
            r.room_number,
            h.id AS hostel_id,
            h.name AS hostel_name,
            h.managed_by,
            (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = b.id AND status = "successful") AS paid_amount
        FROM bookings b
        JOIN rooms r ON r.id = b.room_id
        JOIN hostels h ON h.id = r.hostel_id
        WHERE b.id = ?
        LIMIT 1
    ');
    $sumStmt->execute([$bookingId]);
    $bookingData = $sumStmt->fetch();

    if (!$bookingData) {
        return;
    }

    $requiredDeposit = ((float)$bookingData['total_due']) * 0.20;
    $paidAmount = (float)($bookingData['paid_amount'] ?? 0);

    if ($bookingData['booking_status'] === 'pending' && $paidAmount >= $requiredDeposit && $requiredDeposit > 0) {
        $wardenId = $bookingData['managed_by'];
        if ($wardenId !== null && (int)$wardenId > 0) {
            hms_notify((int)$wardenId, 'New booking request is ready for review (20% deposit paid) for room ' . $bookingData['room_number'] . ' (' . $bookingData['hostel_name'] . ').', 'booking');
        }
        $admins = $db->prepare('
            SELECT al.actor_user_id AS id
            FROM audit_logs al
            WHERE al.entity_type = "hostel"
              AND al.action = "hostel_created"
              AND al.entity_id = ?
        ');
        $admins->execute([(int)($bookingData['hostel_id'] ?? 0)]);
        foreach ($admins->fetchAll() as $a) {
            $adminId = (int)($a['id'] ?? 0);
            if ($adminId > 0) {
                hms_notify($adminId, 'New student booking request is ready for review (deposit paid).', 'booking');
            }
        }
        hms_audit_log($studentUserId, 'booking_request_sent_after_deposit', 'booking', (int)$bookingData['booking_id'], '20% deposit threshold met.');
    }
}
