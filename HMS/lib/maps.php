<?php

/**
 * Google Maps helpers. Set HMS_GOOGLE_MAPS_API_KEY in config.local.php (see config.php default)
 * and enable Maps JavaScript API + Maps Embed API (HTTP referrer restrictions recommended).
 */

function hms_google_maps_api_key(): string
{
    return trim((string)HMS_GOOGLE_MAPS_API_KEY);
}

function hms_google_maps_configured(): bool
{
    return hms_google_maps_api_key() !== '';
}

/**
 * @return array{lat: float, lng: float}|null
 */
function hms_parse_map_coords(?string $latStr, ?string $lngStr): ?array
{
    if ($latStr === null || $lngStr === null) {
        return null;
    }
    $latStr = trim($latStr);
    $lngStr = trim($lngStr);
    if ($latStr === '' && $lngStr === '') {
        return null;
    }
    if ($latStr === '' || $lngStr === '') {
        return null;
    }
    if (!is_numeric($latStr) || !is_numeric($lngStr)) {
        return null;
    }
    $lat = (float)$latStr;
    $lng = (float)$lngStr;
    if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
        return null;
    }
    return ['lat' => $lat, 'lng' => $lng];
}

/**
 * @param array<string, mixed> $hostelRow
 */
function hms_hostel_has_map_pin(array $hostelRow): bool
{
    $lat = $hostelRow['map_latitude'] ?? null;
    $lng = $hostelRow['map_longitude'] ?? null;
    if ($lat === null || $lng === null || $lat === '' || $lng === '') {
        return false;
    }
    return is_numeric($lat) && is_numeric($lng);
}

/**
 * @param array<string, mixed> $hostelRow
 */
function hms_hostel_map_lat_lng(array $hostelRow): ?array
{
    if (!hms_hostel_has_map_pin($hostelRow)) {
        return null;
    }
    return ['lat' => (float)$hostelRow['map_latitude'], 'lng' => (float)$hostelRow['map_longitude']];
}

function hms_google_maps_embed_url(float $lat, float $lng, int $zoom = 16): string
{
    $key = hms_google_maps_api_key();
    if ($key === '') {
        return '';
    }
    $q = rawurlencode($lat . ',' . $lng);
    return 'https://www.google.com/maps/embed/v1/place?key=' . rawurlencode($key) . '&q=' . $q . '&zoom=' . max(1, min(21, $zoom));
}

function hms_google_maps_external_url(float $lat, float $lng): string
{
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($lat . ',' . $lng);
}
