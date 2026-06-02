<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db_dialect.php';
require_once __DIR__ . '/helpers.php';

/** Max upload size in bytes (default 5 MB). Override with HMS_UPLOAD_MAX_BYTES in config.local.php. */
function hms_upload_max_bytes(): int
{
    return defined('HMS_UPLOAD_MAX_BYTES') ? (int) HMS_UPLOAD_MAX_BYTES : 5 * 1024 * 1024;
}

/** Allowed image MIME types for hostel/room photos. */
function hms_upload_allowed_mimes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

function hms_uploads_base_dir(): string
{
    return dirname(__DIR__) . '/public/uploads';
}

/** Create upload directory tree with permissions suitable for Apache www-data. */
function hms_ensure_upload_dir(string $dir): bool
{
    if (is_dir($dir) && is_writable($dir)) {
        return true;
    }

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        @chmod($dir, 0775);
    }

    return is_dir($dir) && is_writable($dir);
}

function hms_image_gallery_enabled(PDO $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = hms_table_exists($db, 'hostel_images');
    return $cached;
}

/** @return 'hostel'|'room'|null */
function hms_gallery_entity_key(string $entity): ?string
{
    return match ($entity) {
        'hostel', 'hostels' => 'hostel',
        'room', 'rooms' => 'room',
        default => null,
    };
}

/** @return array{table: string, fk: string, upload_dir: string}|null */
function hms_gallery_meta(string $entity): ?array
{
    $key = hms_gallery_entity_key($entity);
    if ($key === null) {
        return null;
    }
    if ($key === 'hostel') {
        return ['table' => 'hostel_images', 'fk' => 'hostel_id', 'upload_dir' => 'hostels'];
    }
    return ['table' => 'room_images', 'fk' => 'room_id', 'upload_dir' => 'rooms'];
}

/**
 * @param array $filesField One $_FILES field (single or multiple via photos[])
 * @return list<array>
 */
function hms_normalize_uploaded_files(array $filesField): array
{
    if (!isset($filesField['name'])) {
        return [];
    }
    if (!is_array($filesField['name'])) {
        if ((int) ($filesField['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        return [$filesField];
    }

    $normalized = [];
    foreach ($filesField['name'] as $i => $name) {
        if ($name === '' || $name === null) {
            continue;
        }
        $normalized[] = [
            'name' => $filesField['name'][$i],
            'type' => $filesField['type'][$i] ?? '',
            'tmp_name' => $filesField['tmp_name'][$i] ?? '',
            'error' => $filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $filesField['size'][$i] ?? 0,
        ];
    }
    return $normalized;
}

/**
 * Save an uploaded image file. Returns a web-relative path (e.g. uploads/hostels/abc.jpg).
 *
 * @param array  $file     One entry from $_FILES
 * @param string $category Subdirectory: hostels | rooms
 * @return array{ok: bool, path?: string, error?: string}
 */
function hms_upload_image(array $file, string $category): array
{
    $allowedCategories = ['hostels', 'rooms'];
    if (!in_array($category, $allowedCategories, true)) {
        return ['ok' => false, 'error' => 'Invalid upload category.'];
    }

    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file selected.'];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed. Please try again.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > hms_upload_max_bytes()) {
        $mb = (int) ceil(hms_upload_max_bytes() / (1024 * 1024));
        return ['ok' => false, 'error' => 'Each image must be ' . $mb . ' MB or smaller.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $allowed = hms_upload_allowed_mimes();
    if (!is_string($mime) || !isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Only JPEG, PNG, and WebP images are allowed.'];
    }

    $ext = $allowed[$mime];
    $dir = hms_uploads_base_dir() . '/' . $category;
    if (!hms_ensure_upload_dir($dir)) {
        return ['ok' => false, 'error' => 'Upload folder is not writable on the server. Contact the administrator.'];
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!@move_uploaded_file($tmp, $dest)) {
        error_log('HMS upload failed: cannot write to ' . $dest);

        return ['ok' => false, 'error' => 'Unable to save uploaded image.'];
    }

    return ['ok' => true, 'path' => 'uploads/' . $category . '/' . $filename];
}

/**
 * Upload one or more images from a form field.
 *
 * @return array{ok: bool, paths: list<string>, error?: string}
 */
function hms_upload_images(array $filesField, string $category, bool $allowEmpty = false): array
{
    $files = hms_normalize_uploaded_files($filesField);
    if ($files === []) {
        if ($allowEmpty) {
            return ['ok' => true, 'paths' => []];
        }
        return ['ok' => false, 'paths' => [], 'error' => 'At least one photo is required.'];
    }

    $paths = [];
    foreach ($files as $file) {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $result = hms_upload_image($file, $category);
        if (!$result['ok']) {
            foreach ($paths as $path) {
                hms_upload_delete($path);
            }
            return ['ok' => false, 'paths' => [], 'error' => $result['error'] ?? 'Upload failed.'];
        }
        $paths[] = $result['path'];
    }

    if ($paths === [] && !$allowEmpty) {
        return ['ok' => false, 'paths' => [], 'error' => 'At least one photo is required.'];
    }

    return ['ok' => true, 'paths' => $paths];
}

/** Delete uploaded files from disk. */
function hms_upload_delete_many(array $relativePaths): void
{
    foreach ($relativePaths as $path) {
        hms_upload_delete(is_string($path) ? $path : null);
    }
}

/** Delete a previously stored upload by its relative path. */
function hms_upload_delete(?string $relativePath): void
{
    if ($relativePath === null || trim($relativePath) === '') {
        return;
    }
    $relativePath = str_replace('\\', '/', trim($relativePath));
    if (str_contains($relativePath, '..') || !str_starts_with($relativePath, 'uploads/')) {
        return;
    }
    $full = dirname(__DIR__) . '/public/' . $relativePath;
    if (is_file($full)) {
        @unlink($full);
    }
}

/** Public URL for a stored upload path, or null if empty. */
function hms_upload_url(?string $relativePath): ?string
{
    if ($relativePath === null || trim($relativePath) === '') {
        return null;
    }
    return hms_url(ltrim(str_replace('\\', '/', $relativePath), '/'));
}

function hms_gallery_count(PDO $db, string $entity, int $entityId): int
{
    $meta = hms_gallery_meta($entity);
    if ($meta === null || $entityId <= 0) {
        return 0;
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM ' . $meta['table'] . ' WHERE ' . $meta['fk'] . ' = ?');
    $stmt->execute([$entityId]);
    return (int) $stmt->fetchColumn();
}

/** @return list<array{id: int, image_path: string, sort_order: int}> */
function hms_gallery_fetch(PDO $db, string $entity, int $entityId): array
{
    $meta = hms_gallery_meta($entity);
    if ($meta === null || $entityId <= 0) {
        return [];
    }
    $stmt = $db->prepare('
        SELECT id, image_path, sort_order
        FROM ' . $meta['table'] . '
        WHERE ' . $meta['fk'] . ' = ?
        ORDER BY sort_order ASC, id ASC
    ');
    $stmt->execute([$entityId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param list<int> $entityIds
 * @return array<int, list<array{id: int, image_path: string, sort_order: int}>>
 */
function hms_gallery_fetch_bulk(PDO $db, string $entity, array $entityIds): array
{
    $meta = hms_gallery_meta($entity);
    $entityIds = array_values(array_filter(array_map('intval', $entityIds), static fn (int $id): bool => $id > 0));
    if ($meta === null || $entityIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
    $stmt = $db->prepare('
        SELECT id, ' . $meta['fk'] . ' AS entity_id, image_path, sort_order
        FROM ' . $meta['table'] . '
        WHERE ' . $meta['fk'] . ' IN (' . $placeholders . ')
        ORDER BY ' . $meta['fk'] . ' ASC, sort_order ASC, id ASC
    ');
    $stmt->execute($entityIds);

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) ($row['entity_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $grouped[$id][] = [
            'id' => (int) ($row['id'] ?? 0),
            'image_path' => (string) ($row['image_path'] ?? ''),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }
    return $grouped;
}

/** @param list<string> $paths */
function hms_gallery_insert(PDO $db, string $entity, int $entityId, array $paths): void
{
    $meta = hms_gallery_meta($entity);
    if ($meta === null || $entityId <= 0 || $paths === []) {
        return;
    }
    $startOrder = hms_gallery_count($db, $entity, $entityId);
    $stmt = $db->prepare('INSERT INTO ' . $meta['table'] . ' (' . $meta['fk'] . ', image_path, sort_order) VALUES (?, ?, ?)');
    foreach ($paths as $i => $path) {
        $stmt->execute([$entityId, $path, $startOrder + $i]);
    }
}

/** @return array{ok: bool, error?: string, hostel_id?: int, room_id?: int} */
function hms_gallery_delete_image(PDO $db, string $entity, int $imageId, int $entityId): array
{
    $meta = hms_gallery_meta($entity);
    if ($meta === null || $imageId <= 0 || $entityId <= 0) {
        return ['ok' => false, 'error' => 'Invalid image.'];
    }

    if (hms_gallery_count($db, $entity, $entityId) <= 1) {
        return ['ok' => false, 'error' => 'At least one photo must remain.'];
    }

    $stmt = $db->prepare('SELECT id, image_path FROM ' . $meta['table'] . ' WHERE id = ? AND ' . $meta['fk'] . ' = ? LIMIT 1');
    $stmt->execute([$imageId, $entityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'Image not found.'];
    }

    $db->prepare('DELETE FROM ' . $meta['table'] . ' WHERE id = ?')->execute([$imageId]);
    hms_upload_delete((string) ($row['image_path'] ?? ''));

    return ['ok' => true];
}

/**
 * Render a Bootstrap carousel (or single image) for gallery rows.
 *
 * @param list<array{id?: int, image_path: string}> $images
 */
function hms_render_gallery_image(string $url, string $alt, string $imgClass, int $heightPx, string $groupId, int $index, bool $clickable): string
{
    $style = 'height:' . $heightPx . 'px;object-fit:cover;width:100%;';
    $img = '<img src="' . e($url) . '" alt="' . e($alt) . '" class="' . e($imgClass) . ($clickable ? ' hms-gallery-thumb' : '') . '" style="' . $style . ($clickable ? 'cursor:zoom-in;' : '') . '">';
    if (!$clickable) {
        return $img;
    }

    return '<button type="button" class="hms-gallery-open w-100 border-0 p-0 bg-transparent d-block" '
        . 'data-hms-gallery-group="' . e($groupId) . '" data-hms-gallery-index="' . $index . '" '
        . 'data-hms-gallery-src="' . e($url) . '" data-hms-gallery-alt="' . e($alt) . '" '
        . 'aria-label="View full size: ' . e($alt) . '">' . $img . '</button>';
}

function hms_render_image_gallery(array $images, string $carouselId, string $altLabel, int $heightPx = 180, string $imgClass = 'card-img-top', bool $clickable = false): string
{
    if ($images === []) {
        return '';
    }

    $urls = [];
    foreach ($images as $image) {
        $url = hms_upload_url((string) ($image['image_path'] ?? ''));
        if ($url !== null) {
            $urls[] = $url;
        }
    }
    if ($urls === []) {
        return '';
    }

    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '-', $carouselId) ?: 'gallery';

    if (count($urls) === 1) {
        $inner = hms_render_gallery_image($urls[0], $altLabel, $imgClass, $heightPx, $safeId, 0, $clickable);
        return '<div class="hms-gallery-frame' . ($clickable ? ' hms-gallery-frame--clickable' : '') . '">' . $inner . '</div>';
    }

    $html = '<div class="hms-gallery-frame' . ($clickable ? ' hms-gallery-frame--clickable' : '') . '">';
    $html .= '<div id="' . e($safeId) . '" class="carousel slide hms-gallery-carousel" data-bs-ride="carousel">';
    $html .= '<div class="carousel-indicators hms-gallery-indicators">';
    foreach ($urls as $i => $url) {
        $active = $i === 0 ? ' class="active" aria-current="true"' : '';
        $html .= '<button type="button" data-bs-target="#' . e($safeId) . '" data-bs-slide-to="' . $i . '"' . $active . ' aria-label="Photo ' . ($i + 1) . '"></button>';
    }
    $html .= '</div>';
    $html .= '<div class="carousel-inner">';
    foreach ($urls as $i => $url) {
        $active = $i === 0 ? ' active' : '';
        $photoAlt = $altLabel . ' — photo ' . ($i + 1);
        $html .= '<div class="carousel-item' . $active . '">';
        $html .= hms_render_gallery_image($url, $photoAlt, 'd-block w-100 ' . $imgClass, $heightPx, $safeId, $i, $clickable);
        $html .= '</div>';
    }
    $html .= '</div>';
    $html .= '<button class="carousel-control-prev" type="button" data-bs-target="#' . e($safeId) . '" data-bs-slide="prev"><span class="carousel-control-prev-icon" aria-hidden="true"></span><span class="visually-hidden">Previous</span></button>';
    $html .= '<button class="carousel-control-next" type="button" data-bs-target="#' . e($safeId) . '" data-bs-slide="next"><span class="carousel-control-next-icon" aria-hidden="true"></span><span class="visually-hidden">Next</span></button>';
    $html .= '</div></div>';
    return $html;
}
