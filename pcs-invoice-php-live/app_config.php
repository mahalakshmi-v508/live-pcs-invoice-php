<?php
/**
 * Central app URL/path config.
 * Works on localhost and live hosting without editing each file.
 */

if (!defined('LIVE_BASE_URL')) {
    define('LIVE_BASE_URL', 'https://pcstech.in/pcs_invoice/');
}

if (!defined('BASE_URL')) {
    $configuredBase = getenv('APP_BASE_URL') ?: '';

    if ($configuredBase !== '') {
        $baseUrl = rtrim($configuredBase, '/') . '/';
    } else {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $isLocalHost = (
            $host === '' ||
            $host === 'localhost' ||
            $host === '127.0.0.1' ||
            str_starts_with($host, 'localhost:') ||
            str_starts_with($host, '127.0.0.1:') ||
            str_starts_with($host, '192.168.') ||
            str_starts_with($host, '10.') ||
            $host === '::1' ||
            str_starts_with($host, '[::1]')
        );

        if ($isLocalHost && $host !== '') {
            $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $scheme = $isHttps ? 'https' : 'http';
            $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
            $scriptDir = trim($scriptDir, '/');
            $basePath = $scriptDir !== '' ? '/' . $scriptDir . '/' : '/';
            $baseUrl = $scheme . '://' . $host . $basePath;
        } else {
            $baseUrl = LIVE_BASE_URL;
        }
    }

    define('BASE_URL', rtrim($baseUrl, '/') . '/');
}

if (!function_exists('base_url')) {
    function base_url(string $path = ''): string
    {
        if ($path === '') {
            return BASE_URL;
        }
        return BASE_URL . ltrim($path, '/');
    }
}

if (!function_exists('asset_url')) {
    function asset_url(string $path): string
    {
        return base_url($path);
    }
}

if (!function_exists('asset_path')) {
    function asset_path(string $path): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }
}
