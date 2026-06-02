<?php

require_once __DIR__ . '/../config.php';

function pesapal_base_url(): string
{
    return HMS_PESAPAL_ENV === 'live'
        ? 'https://pay.pesapal.com/v3'
        : 'https://cybqa.pesapal.com/pesapalv3';
}

function pesapal_is_configured(): bool
{
    return HMS_PESAPAL_CONSUMER_KEY !== 'YOUR_PESAPAL_CONSUMER_KEY'
        && HMS_PESAPAL_CONSUMER_SECRET !== 'YOUR_PESAPAL_CONSUMER_SECRET'
        && HMS_PESAPAL_CONSUMER_KEY !== ''
        && HMS_PESAPAL_CONSUMER_SECRET !== '';
}

function pesapal_normalize_url(string $url): string
{
    return rtrim(strtolower($url), '/');
}

/**
 * @return array{status:int, body:array<string, mixed>}
 */
function pesapal_http_request(string $method, string $path, array $headers = [], ?array $body = null): array
{
    $url = rtrim(pesapal_base_url(), '/') . '/' . ltrim($path, '/');

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }

    $finalHeaders = ['Accept: application/json'];
    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        $finalHeaders[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }

    $finalHeaders = array_merge($finalHeaders, $headers);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => $finalHeaders,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Pesapal request failed: ' . $err);
    }
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $response = (string)$response;
    if (strncmp($response, "\xEF\xBB\xBF", 3) === 0) {
        $response = substr($response, 3);
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        $decoded = ['raw' => $response];
    }

    return ['status' => $httpCode, 'body' => $decoded];
}

function pesapal_format_error(array $res): string
{
    $code = (int)($res['status'] ?? 0);
    $b = $res['body'] ?? [];
    if (!is_array($b)) {
        return 'HTTP ' . $code;
    }
    $msg = (string)($b['message'] ?? '');
    if ($msg === '' && isset($b['error']) && is_array($b['error'])) {
        $msg = (string)($b['error']['message'] ?? '');
    }
    if ($msg === '') {
        $msg = (string)($b['raw'] ?? json_encode($b));
    }
    return 'HTTP ' . $code . ($msg !== '' ? ': ' . $msg : '');
}

/**
 * Extract bearer token from Pesapal RequestToken JSON (handles minor response shape differences).
 */
function pesapal_parse_auth_token(array $body): string
{
    $candidates = ['token', 'Token', 'access_token', 'accessToken'];
    foreach ($candidates as $key) {
        if (!empty($body[$key]) && is_string($body[$key])) {
            $t = trim($body[$key]);
            if ($t !== '') {
                return $t;
            }
        }
    }
    if (isset($body['data']) && is_array($body['data'])) {
        $nested = pesapal_parse_auth_token($body['data']);
        if ($nested !== '') {
            return $nested;
        }
    }
    return '';
}

function pesapal_access_token(): string
{
    if (!pesapal_is_configured()) {
        throw new RuntimeException('Pesapal keys are not configured.');
    }

    $res = pesapal_http_request('POST', 'api/Auth/RequestToken', [], [
        'consumer_key' => HMS_PESAPAL_CONSUMER_KEY,
        'consumer_secret' => HMS_PESAPAL_CONSUMER_SECRET,
    ]);

    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException('Pesapal auth failed: ' . pesapal_format_error($res));
    }

    $body = $res['body'];
    $apiStatus = isset($body['status']) ? (string)$body['status'] : '';
    if ($apiStatus !== '' && $apiStatus !== '200') {
        throw new RuntimeException('Pesapal auth failed: ' . pesapal_format_error($res));
    }

    $token = pesapal_parse_auth_token($body);
    if ($token === '') {
        $hint = ' If you use keys from your live merchant dashboard, set HMS_PESAPAL_ENV to live in config.local.php. '
            . 'Official sandbox demo keys only work with sandbox.';
        throw new RuntimeException('Pesapal auth token missing. ' . pesapal_format_error($res) . $hint);
    }
    return $token;
}

function pesapal_submit_order(array $payload): array
{
    $token = pesapal_access_token();
    $res = pesapal_http_request(
        'POST',
        'api/Transactions/SubmitOrderRequest',
        ['Authorization: Bearer ' . $token],
        $payload
    );
    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException('Pesapal submit failed: ' . pesapal_format_error($res));
    }
    return $res['body'];
}

/**
 * Pesapal v3: status is fetched with orderTrackingId only (see GetTransactionStatus docs).
 */
function pesapal_get_transaction_status(string $orderTrackingId): array
{
    $token = pesapal_access_token();
    $path = 'api/Transactions/GetTransactionStatus?orderTrackingId=' . urlencode($orderTrackingId);
    $res = pesapal_http_request('GET', $path, ['Authorization: Bearer ' . $token]);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException('Pesapal status check failed: ' . pesapal_format_error($res));
    }
    return $res['body'];
}

function pesapal_register_ipn(string $ipnUrl, string $notificationType = 'GET'): array
{
    $token = pesapal_access_token();
    $res = pesapal_http_request(
        'POST',
        'api/URLSetup/RegisterIPN',
        ['Authorization: Bearer ' . $token],
        [
            'url' => $ipnUrl,
            'ipn_notification_type' => strtoupper($notificationType),
        ]
    );
    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException('Pesapal IPN register failed: ' . pesapal_format_error($res));
    }
    return $res['body'];
}

/**
 * @return list<array<string, mixed>>
 */
function pesapal_get_registered_ipns(): array
{
    $token = pesapal_access_token();
    $res = pesapal_http_request('GET', 'api/URLSetup/GetIpnList', ['Authorization: Bearer ' . $token]);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException('Pesapal GetIpnList failed: ' . pesapal_format_error($res));
    }
    $body = $res['body'];
    if (!is_array($body)) {
        return [];
    }
    if (isset($body['ipn_id'])) {
        return [$body];
    }
    $isList = array_keys($body) === range(0, count($body) - 1);
    return $isList ? $body : [];
}

/**
 * Returns notification_id (IPN GUID) for SubmitOrderRequest.
 * Uses HMS_PESAPAL_IPN_ID when set; otherwise reuses an existing registered URL match, else registers.
 */
function pesapal_resolve_notification_id(string $ipnUrl): string
{
    $configured = defined('HMS_PESAPAL_IPN_ID') ? (string)HMS_PESAPAL_IPN_ID : '';
    if ($configured !== '') {
        return $configured;
    }

    $want = pesapal_normalize_url($ipnUrl);
    try {
        $list = pesapal_get_registered_ipns();
    } catch (Throwable) {
        $list = [];
    }
    foreach ($list as $row) {
        $u = pesapal_normalize_url((string)($row['url'] ?? ''));
        if ($u !== '' && $u === $want) {
            $id = (string)($row['ipn_id'] ?? '');
            if ($id !== '') {
                return $id;
            }
        }
    }

    $reg = pesapal_register_ipn($ipnUrl, 'GET');
    return (string)($reg['ipn_id'] ?? '');
}

/**
 * Map Pesapal GetTransactionStatus JSON to our payments.status values.
 *
 * @return array{final_status:string, paid_at:?string, gateway_label:string, description:string}
 */
function pesapal_interpret_status(array $statusRes): array
{
    $code = (int)($statusRes['status_code'] ?? -1);
    $desc = strtoupper((string)($statusRes['payment_status_description'] ?? ''));
    $description = (string)($statusRes['description'] ?? '');

    $paidAt = null;
    $final = 'pending';

    if ($code === 1 || str_contains($desc, 'COMPLETED')) {
        $final = 'successful';
        $paidAt = date('Y-m-d H:i:s');
    } elseif (
        $code === 2
        || $code === 0
        || $code === 3
        || str_contains($desc, 'FAILED')
        || str_contains($desc, 'INVALID')
        || str_contains($desc, 'REVERSED')
    ) {
        $final = 'failed';
    }

    $gatewayLabel = $desc !== '' ? $desc : $description;

    return [
        'final_status' => $final,
        'paid_at' => $paidAt,
        'gateway_label' => $gatewayLabel,
        'description' => $description,
    ];
}

/**
 * @return array{
 *   interpret: array,
 *   previous_status: string,
 *   booking_id: int,
 *   student_id: int
 * }
 */
function pesapal_persist_payment_status(PDO $db, int $paymentId, array $statusRes): array
{
    $prevStmt = $db->prepare('
        SELECT
            p.status AS prev_status,
            p.booking_id,
            p.room_id AS pre_room_id,
            p.student_user_id AS pre_student_id,
            COALESCE(b.student_id, p.student_user_id) AS student_id
        FROM payments p
        LEFT JOIN bookings b ON b.id = p.booking_id
        WHERE p.id = ?
    ');
    $prevStmt->execute([$paymentId]);
    $prevRow = $prevStmt->fetch();
    $previousStatus = (string)($prevRow['prev_status'] ?? '');
    $bookingId = (int)($prevRow['booking_id'] ?? 0);
    $studentId = (int)($prevRow['student_id'] ?? 0);

    $interpret = pesapal_interpret_status($statusRes);
    $finalStatus = $interpret['final_status'];
    $paidAt = $interpret['paid_at'];
    $gatewayLabel = $interpret['gateway_label'];
    $description = $interpret['description'];

    $update = $db->prepare('
        UPDATE payments
        SET status = ?,
            gateway_status = ?,
            callback_payload = ?,
            paid_at = CASE WHEN ? IS NULL THEN paid_at ELSE ? END,
            provider = CASE
                WHEN LOWER(?) LIKE "%mtn%" THEN "mtn"
                WHEN LOWER(?) LIKE "%airtel%" THEN "airtel"
                ELSE provider
            END
        WHERE id = ?
    ');
    $update->execute([
        $finalStatus,
        $gatewayLabel !== '' ? $gatewayLabel : $description,
        json_encode($statusRes, JSON_UNESCAPED_SLASHES),
        $paidAt,
        $paidAt,
        $description,
        $description,
        $paymentId,
    ]);

    return [
        'interpret' => $interpret,
        'previous_status' => $previousStatus,
        'booking_id' => $bookingId,
        'student_id' => $studentId,
        'pre_room_id' => (int)($prevRow['pre_room_id'] ?? 0),
        'pre_student_id' => (int)($prevRow['pre_student_id'] ?? 0),
    ];
}
