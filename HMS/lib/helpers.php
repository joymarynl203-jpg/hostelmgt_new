<?php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/session.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** Opens Gmail compose in the browser for the given address. */
function hms_gmail_compose_url(string $to): string
{
    return 'https://mail.google.com/mail/?view=cm&fs=1&to=' . rawurlencode(trim($to));
}

/** Inline SVG for footer social buttons. */
function hms_footer_social_icon(string $platform): string
{
    $common = 'xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"';
    return match ($platform) {
        'whatsapp' => '<svg ' . $common . '><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.625.846 5.059 2.284 7.034L.789 23.492a.75.75 0 0 0 .918.918l4.458-1.495A11.95 11.95 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.75a9.72 9.72 0 0 1-4.95-1.35l-.355-.21-2.64.886.886-2.64-.21-.355A9.72 9.72 0 0 1 2.25 12C2.25 6.615 6.615 2.25 12 2.25S21.75 6.615 21.75 12 17.385 21.75 12 21.75z"/></svg>',
        'gmail' => '<svg ' . $common . ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"/><path d="m4 4 8 8 8-8"/></svg>',
        'tiktok' => '<svg ' . $common . '><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.27 6.27 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.69a8.18 8.18 0 0 0 4.77 1.52V6.76a4.85 4.85 0 0 1-1.01-.07z"/></svg>',
        'x' => '<svg ' . $common . '><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
        default => '',
    };
}

/** Inline SVG icons for dashboard metric cards (stroke style, inherits currentColor). */
function hms_stat_icon_svg(string $name): string
{
    $common = 'xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
    return match ($name) {
        'bookings' => '<svg ' . $common . '><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/></svg>',
        'payments' => '<svg ' . $common . '><rect width="20" height="14" x="2" y="5" rx="2"/><path d="M2 10h20"/><path d="M6 15h.01"/><path d="M10 15h4"/></svg>',
        'maintenance' => '<svg ' . $common . '><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
        'queue' => '<svg ' . $common . '><rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/></svg>',
        'audit' => '<svg ' . $common . '><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>',
        default => '<svg ' . $common . '><circle cx="12" cy="12" r="10"/></svg>',
    };
}

function redirect_to(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function get_csrf_token(): string
{
    return $_SESSION['csrf_token'] ?? '';
}

function ensure_flash(): void
{
    if (!isset($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
}

function flash_set(string $type, string $message): void
{
    ensure_flash();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_render(): void
{
    ensure_flash();
    if (empty($_SESSION['flash'])) {
        return;
    }

    foreach ($_SESSION['flash'] as $item) {
        $type = $item['type'] ?? 'info';
        $message = $item['message'] ?? '';
        $class = match ($type) {
            'success' => 'alert-success',
            'error' => 'alert-danger',
            'warning' => 'alert-warning',
            default => 'alert-info',
        };
        echo '<div class="alert ' . $class . ' alert-dismissible fade show mb-3" role="alert">'
            . e($message)
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }

    $_SESSION['flash'] = [];
}

function require_post(): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo 'Method Not Allowed';
        exit;
    }
}

function require_get(): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'GET') {
        http_response_code(405);
        echo 'Method Not Allowed';
        exit;
    }
}

/** HTML fragment: ` data-hms-confirm="..."` for use on `<a>` or `<form>` (handled by public/app.js). */
function hms_data_confirm(string $message): string
{
    return ' data-hms-confirm="' . e($message) . '"';
}

/** SVG icons for password show/hide toggle (paired with public/app.js). */
function hms_password_toggle_icon(): string
{
    return '<span class="hms-password-eye" aria-hidden="true">'
        . '<svg class="hms-eye-open" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>'
        . '<svg class="hms-eye-closed d-none" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
        . '</span>';
}

/**
 * Render a password field with show/hide eye toggle.
 *
 * @param array{required?: bool, minlength?: int, autocomplete?: string, help?: string, id?: string, mb_class?: string} $options
 */
function hms_render_password_field(string $name, string $label, array $options = []): void
{
    $required = !empty($options['required']);
    $minlength = (int)($options['minlength'] ?? 0);
    $autocomplete = (string)($options['autocomplete'] ?? 'new-password');
    $help = (string)($options['help'] ?? '');
    $id = (string)($options['id'] ?? $name);
    $mbClass = (string)($options['mb_class'] ?? 'mb-3');

    $reqAttr = $required ? ' required' : '';
    $minAttr = $minlength > 0 ? ' minlength="' . $minlength . '"' : '';
    $autoAttr = ' autocomplete="' . e($autocomplete) . '"';

    echo '<div class="' . e($mbClass) . '">';
    echo '<label class="form-label" for="' . e($id) . '">' . e($label);
    if ($required) {
        echo ' <span class="text-danger">*</span>';
    }
    echo '</label>';
    echo '<div class="input-group hms-password-toggle">';
    echo '<input type="password" name="' . e($name) . '" id="' . e($id) . '" class="form-control"' . $reqAttr . $minAttr . $autoAttr . '>';
    echo '<button type="button" class="btn btn-outline-secondary hms-password-toggle-btn" aria-label="Show password" aria-pressed="false" tabindex="-1">';
    echo hms_password_toggle_icon();
    echo '</button></div>';
    if ($help !== '') {
        echo '<div class="form-text">' . $help . '</div>';
    }
    echo '</div>';
}

/** @return array{ok: bool, error?: string} */
function hms_passwords_match(string $password, string $confirm): array
{
    if ($password === '' || $confirm === '') {
        return ['ok' => false, 'error' => 'Enter and confirm the password.'];
    }
    if ($password !== $confirm) {
        return ['ok' => false, 'error' => 'Passwords do not match.'];
    }
    return ['ok' => true];
}

