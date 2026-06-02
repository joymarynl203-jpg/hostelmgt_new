<?php

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/pesapal.php';
require_once __DIR__ . '/../lib/pesapal_payment_followup.php';
require_once __DIR__ . '/../lib/booking_pay_first.php';
require_once __DIR__ . '/../db.php';

// Pesapal IPN — no login; must be reachable on the public internet (HTTPS in production).

$db = hms_db();

function pesapal_ipn_read_payload(): array
{
    $orderTrackingId = trim((string)($_GET['OrderTrackingId'] ?? $_GET['orderTrackingId'] ?? ''));
    $merchantReference = trim((string)($_GET['OrderMerchantReference'] ?? $_GET['merchantReference'] ?? ''));
    $notificationType = trim((string)($_GET['OrderNotificationType'] ?? ''));

    if ($orderTrackingId === '' && !empty($_POST)) {
        $orderTrackingId = trim((string)($_POST['OrderTrackingId'] ?? $_POST['orderTrackingId'] ?? ''));
        $merchantReference = trim((string)($_POST['OrderMerchantReference'] ?? $_POST['merchantReference'] ?? ''));
        $notificationType = trim((string)($_POST['OrderNotificationType'] ?? ''));
    }

    if ($orderTrackingId === '') {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $orderTrackingId = trim((string)($json['OrderTrackingId'] ?? $json['orderTrackingId'] ?? ''));
                $merchantReference = trim((string)($json['OrderMerchantReference'] ?? $json['orderMerchantReference'] ?? ''));
                $notificationType = trim((string)($json['OrderNotificationType'] ?? $json['orderNotificationType'] ?? ''));
            }
        }
    }

    return [$orderTrackingId, $merchantReference, $notificationType];
}

[$orderTrackingId, $merchantReference, $notificationType] = pesapal_ipn_read_payload();

header('Content-Type: application/json; charset=utf-8');

if ($orderTrackingId === '' || $merchantReference === '') {
    http_response_code(400);
    echo json_encode([
        'orderNotificationType' => 'IPNCHANGE',
        'orderTrackingId' => $orderTrackingId,
        'orderMerchantReference' => $merchantReference,
        'status' => 500,
        'message' => 'Missing OrderTrackingId or OrderMerchantReference',
    ]);
    exit;
}

try {
    $statusRes = pesapal_get_transaction_status($orderTrackingId);

    $paymentStmt = $db->prepare('
        SELECT p.id, COALESCE(b.student_id, p.student_user_id) AS student_id
        FROM payments p
        LEFT JOIN bookings b ON b.id = p.booking_id
        WHERE p.merchant_reference = ? AND p.gateway_tracking_id = ?
        LIMIT 1
    ');
    $paymentStmt->execute([$merchantReference, $orderTrackingId]);
    $payment = $paymentStmt->fetch();

    if ($payment) {
        $paymentId = (int)$payment['id'];
        $studentId = (int)$payment['student_id'];

        $followupBookingId = 0;
        $followupStudentId = 0;
        $notifyPaymentOk = false;
        $notifyPaymentFail = false;

        $db->beginTransaction();
        try {
            $result = pesapal_persist_payment_status($db, $paymentId, $statusRes);
            $interpret = $result['interpret'];
            $finalStatus = $interpret['final_status'];
            $prev = $result['previous_status'];

            $paymentStatusLabel = (string)($statusRes['payment_status_description'] ?? $interpret['gateway_label'] ?? '');
            hms_audit_log(null, 'pesapal_ipn_processed', 'payment', $paymentId, 'IPN ' . $notificationType . ' gateway: ' . $paymentStatusLabel);

            if ($finalStatus === 'successful' && $prev !== 'successful') {
                if ((int)$result['booking_id'] === 0 && (int)($result['pre_room_id'] ?? 0) > 0) {
                    $followupBookingId = hms_finalize_prebooking_deposit_payment($db, $paymentId);
                    $followupStudentId = (int)$result['student_id'];
                } elseif ((int)$result['booking_id'] > 0) {
                    $followupBookingId = (int)$result['booking_id'];
                    $followupStudentId = (int)$result['student_id'];
                }
                $notifyPaymentOk = true;
            } elseif ($finalStatus === 'failed' && $prev !== 'failed') {
                $notifyPaymentFail = true;
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if ($followupBookingId > 0 && $followupStudentId > 0) {
            hms_pesapal_run_success_followup($db, $followupBookingId, $followupStudentId);
        }

        if ($notifyPaymentOk) {
            hms_notify($studentId, 'Pesapal payment completed successfully.', 'payment');
        } elseif ($notifyPaymentFail) {
            hms_notify($studentId, 'Pesapal payment failed. Please try again.', 'payment');
        }
    }

    http_response_code(200);
    echo json_encode([
        'orderNotificationType' => 'IPNCHANGE',
        'orderTrackingId' => $orderTrackingId,
        'orderMerchantReference' => $merchantReference,
        'status' => 200,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'orderNotificationType' => 'IPNCHANGE',
        'orderTrackingId' => $orderTrackingId,
        'orderMerchantReference' => $merchantReference,
        'status' => 500,
        'message' => $e->getMessage(),
    ]);
}
