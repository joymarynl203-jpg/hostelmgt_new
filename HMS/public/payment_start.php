<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/pesapal.php';
require_once __DIR__ . '/../lib/booking_pay_first.php';
require_once __DIR__ . '/../db.php';

require_login();
require_role(['student']);

$user = hms_current_user();
$userId = (int)$user['id'];
$db = hms_db();
$purpose = (string)($_REQUEST['purpose'] ?? 'rent');
$paymentReturnKey = (string)($_REQUEST['return'] ?? '');
$studentPaymentReturnUrl = ($paymentReturnKey === 'my_payments') ? hms_url('my_payments.php') : hms_url('bookings.php');

if (!pesapal_is_configured()) {
    flash_set('error', 'Pesapal keys are not configured. Update config.php first.');
    redirect_to($studentPaymentReturnUrl);
}

/**
 * Insert pending payment + submit to Pesapal. Caller manages transaction.
 *
 * @param array{amount:float,desc:string,booking_id:?int,room_id:?int,student_user_id:?int,student_name?:string,student_email?:string,student_phone?:string,cancellation_url?:string} $pay
 */
function hms_pesapal_submit_for_payment(PDO $db, int $userId, array $user, array $pay): string
{
    $bookingId = $pay['booking_id'];
    $roomId = $pay['room_id'];
    $studentUserId = $pay['student_user_id'];
    $amountToPay = (float)$pay['amount'];
    $desc = (string)$pay['desc'];

    $paymentStmt = $db->prepare('
        INSERT INTO payments (booking_id, student_user_id, room_id, amount, method, provider, gateway, status, created_at)
        VALUES (?, ?, ?, ?, "mobile_money", "other", "pesapal", "pending", NOW())
    ');
    $paymentStmt->execute([$bookingId, $studentUserId, $roomId, $amountToPay]);
    $paymentId = (int)$db->lastInsertId();

    $merchantReference = 'HMSp' . $paymentId;
    if ($bookingId !== null && $bookingId > 0) {
        $merchantReference = 'HMSb' . $bookingId . 'p' . $paymentId;
    }
    if (strlen($merchantReference) > 50) {
        $merchantReference = 'HMSp' . $paymentId;
    }

    $ipnUrl = hms_abs_url('payment_ipn.php');
    $notificationId = pesapal_resolve_notification_id($ipnUrl);
    if ($notificationId === '') {
        throw new RuntimeException('Unable to resolve Pesapal IPN notification_id.');
    }

    $callbackUrl = hms_abs_url('payment_callback.php');
    $cancellationUrl = (string)($pay['cancellation_url'] ?? hms_url('bookings.php'));

    $fullName = trim((string)($pay['student_name'] ?? $user['name']));
    $firstName = $fullName;
    $lastName = '';
    if (preg_match('/^(.+?)\s+(\S+)$/', $fullName, $m)) {
        $firstName = $m[1];
        $lastName = $m[2];
    }

    $email = trim((string)($pay['student_email'] ?? $user['email'] ?? ''));
    $phone = preg_replace('/\s+/', '', (string)($pay['student_phone'] ?? ''));
    if ($email === '' && $phone === '') {
        throw new RuntimeException('Student profile must include an email or phone number for Pesapal billing.');
    }

    if (function_exists('mb_substr')) {
        $desc = mb_substr($desc, 0, 100, 'UTF-8');
    } else {
        $desc = substr($desc, 0, 100);
    }

    $payload = [
        'id' => $merchantReference,
        'currency' => 'UGX',
        'amount' => (float)$amountToPay,
        'description' => $desc,
        'callback_url' => $callbackUrl,
        'cancellation_url' => $cancellationUrl,
        'notification_id' => $notificationId,
        'branch' => 'HMS Hostel',
        'billing_address' => [
            'email_address' => $email,
            'phone_number' => $phone,
            'country_code' => 'UG',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'line_1' => 'Hostel booking',
            'line_2' => '',
            'city' => 'Kampala',
            'state' => '',
            'postal_code' => '',
            'zip_code' => '',
        ],
    ];

    $response = pesapal_submit_order($payload);

    $redirectUrl = $response['redirect_url'] ?? '';
    $trackingId = $response['order_tracking_id'] ?? ($response['OrderTrackingId'] ?? '');

    if ($redirectUrl === '' || $trackingId === '') {
        throw new RuntimeException('Pesapal did not return tracking id or redirect URL.');
    }

    $db->prepare('
        UPDATE payments
        SET transaction_ref = ?, merchant_reference = ?, gateway_tracking_id = ?, callback_payload = ?
        WHERE id = ?
    ')->execute([
        $merchantReference,
        $merchantReference,
        $trackingId,
        json_encode($response, JSON_UNESCAPED_SLASHES),
        $paymentId,
    ]);

    hms_audit_log($userId, 'pesapal_payment_started', 'payment', $paymentId, 'Tracking ID: ' . $trackingId);

    return $redirectUrl;
}

if ($purpose === 'new_booking_deposit') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        flash_set('error', 'Use the booking form to start a deposit payment.');
        redirect_to(hms_url('bookings.php'));
    }
    csrf_verify($_POST['csrf_token'] ?? '');
    $roomId = (int)($_POST['room_id'] ?? 0);

    try {
        $room = hms_assert_room_ready_for_pay_first_deposit($db, $roomId, $userId);
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect_to($studentPaymentReturnUrl);
    }

    $totalDue = (float)$room['monthly_fee'];
    $requiredDeposit = $totalDue * 0.20;
    if ($requiredDeposit <= 0) {
        flash_set('error', 'Invalid room fee for deposit.');
        redirect_to($studentPaymentReturnUrl);
    }

    $redirectUrl = '';
    $db->beginTransaction();
    try {
        hms_assert_room_ready_for_pay_first_deposit($db, $roomId, $userId);
        $redirectUrl = hms_pesapal_submit_for_payment($db, $userId, $user, [
            'amount' => $requiredDeposit,
            'desc' => '20% booking deposit (room #' . $room['room_number'] . ' ' . $room['hostel_name'] . ')',
            'booking_id' => null,
            'room_id' => $roomId,
            'student_user_id' => $userId,
            'student_name' => $user['name'],
            'student_email' => $user['email'] ?? '',
            'student_phone' => $user['phone'] ?? '',
            'cancellation_url' => $studentPaymentReturnUrl,
        ]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        flash_set('error', 'Unable to start Pesapal payment: ' . $e->getMessage());
        redirect_to($studentPaymentReturnUrl);
    }

    header('Location: ' . $redirectUrl);
    exit;
}

$bookingId = (int)($_GET['booking_id'] ?? 0);
if ($bookingId <= 0) {
    flash_set('error', 'Booking is required for payment.');
    redirect_to($studentPaymentReturnUrl);
}

$stmt = $db->prepare('
    SELECT b.*, r.room_number, h.name AS hostel_name, u.name AS student_name, u.email AS student_email, u.phone AS student_phone
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN hostels h ON h.id = r.hostel_id
    JOIN users u ON u.id = b.student_id
    WHERE b.id = ? AND b.student_id = ?
');
$stmt->execute([$bookingId, $userId]);
$booking = $stmt->fetch();

if (!$booking) {
    flash_set('error', 'Booking not found.');
    redirect_to($studentPaymentReturnUrl);
}

$paidStmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) AS paid_amount FROM payments WHERE booking_id = ? AND status = "successful"');
$paidStmt->execute([$bookingId]);
$paid = (float)($paidStmt->fetch()['paid_amount'] ?? 0);
$due = (float)($booking['total_due'] ?? 0);
$outstanding = max(0, $due - $paid);
$requiredDeposit = $due * 0.20;

if ($purpose === 'deposit') {
    if ($booking['status'] !== 'pending') {
        flash_set('error', 'Deposit payment is only available while booking is pending.');
        redirect_to($studentPaymentReturnUrl);
    }
    $amountToPay = max(0, $requiredDeposit - $paid);
    if ($amountToPay <= 0) {
        flash_set('success', 'Deposit requirement already met for this booking.');
        redirect_to($studentPaymentReturnUrl);
    }
} else {
    if (!in_array($booking['status'], ['approved', 'checked_in'], true)) {
        flash_set('error', 'You can only pay the remaining rent for approved or checked-in bookings.');
        redirect_to($studentPaymentReturnUrl);
    }
    $amountToPay = $outstanding;
}

if ($amountToPay <= 0) {
    flash_set('success', 'This booking is already fully paid.');
    redirect_to($studentPaymentReturnUrl);
}

$db->beginTransaction();
try {
    $desc = ($purpose === 'deposit' ? '20% booking deposit' : 'Hostel rent payment')
        . ' booking #' . $bookingId . ' ' . $booking['hostel_name'] . ' r' . $booking['room_number'];
    $redirectUrl = hms_pesapal_submit_for_payment($db, $userId, $user, [
        'amount' => $amountToPay,
        'desc' => $desc,
        'booking_id' => $bookingId,
        'room_id' => null,
        'student_user_id' => null,
        'student_name' => $booking['student_name'] ?? $user['name'],
        'student_email' => $booking['student_email'] ?? $user['email'] ?? '',
        'student_phone' => $booking['student_phone'] ?? $user['phone'] ?? '',
        'cancellation_url' => $studentPaymentReturnUrl,
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    flash_set('error', 'Unable to start Pesapal payment: ' . $e->getMessage());
    redirect_to($studentPaymentReturnUrl);
}

header('Location: ' . $redirectUrl);
exit;
