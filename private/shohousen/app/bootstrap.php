<?php
declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config/config.php';
if (!file_exists($configPath)) {
    $configPath = dirname(__DIR__) . '/config/config.sample.php';
}
$GLOBALS['config'] = require $configPath;

date_default_timezone_set((string)($GLOBALS['config']['app']['timezone'] ?? 'Asia/Tokyo'));

/**
 * Config accessor.
 */
function app_config(string $key, mixed $default = null): mixed
{
    $parts = explode('.', $key);
    $value = $GLOBALS['config'] ?? [];
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/**
 * Resolve public base path safely.
 *
 * Xserver can be used in either of these layouts:
 * 1) https://domain.com/shohousen/index.php      -> base_path = /shohousen
 * 2) https://shohousen.domain.com/index.php      -> base_path = ''
 *
 * If config.php still contains an old '/shohousen' value while the site is
 * actually running at the subdomain root, this function ignores the stale
 * value so CSS/JS and page links do not become /shohousen/xxx.
 */
function app_base_path(): string
{
    $configured = rtrim((string)app_config('app.base_path', ''), '/');
    if ($configured === '/') {
        $configured = '';
    }

    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($configured !== '') {
        if ($scriptName === $configured || str_starts_with($scriptName, $configured . '/')) {
            return $configured;
        }
        return '';
    }

    return '';
}

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => app_base_path() ?: '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/View.php';
require_once __DIR__ . '/Repositories.php';
require_once __DIR__ . '/OpenAiPrescriptionClient.php';
require_once __DIR__ . '/PrescriptionImagePreprocessor.php';
require_once __DIR__ . '/PrescriptionTemplateDetector.php';
require_once __DIR__ . '/PrescriptionKnowledgeService.php';
require_once __DIR__ . '/PrescriptionCorrectionService.php';
require_once __DIR__ . '/PrescriptionOcrService.php';
require_once __DIR__ . '/PrescriptionFeedbackService.php';
require_once __DIR__ . '/PrescriptionQrService.php';


function current_company_uid(): string
{
    return (string)(
        $_SESSION['company_uid']
        ?? ($_SESSION['user']['company_uid'] ?? null)
        ?? app_config('app.default_company_uid', app_config('app.company_uid', 'cmp_dev_0001'))
    );
}

function current_branch_uid(): string
{
    return (string)(
        $_SESSION['branch_uid']
        ?? ($_SESSION['user']['branch_uid'] ?? null)
        ?? app_config('app.default_branch_uid', app_config('app.branch_uid', 'br_dev_0001'))
    );
}

function current_location_id(): ?int
{
    $value = $_SESSION['location_id'] ?? ($_SESSION['user']['location_id'] ?? null);
    return $value !== null ? (int)$value : null;
}

function json_encode_ja(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path = ''): string
{
    $base = app_base_path();

    if ($path === '') {
        return $base !== '' ? $base : '/';
    }
    if (preg_match('/^https?:\/\//', $path) === 1) {
        return $path;
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    return $base . $path;
}

function redirect(string $path): void
{
    header('Location: ' . app_url($path));
    exit;
}
