<?php
/**
 * Saisho security bootstrap.
 *
 * Keep URL-derived values out of filesystem paths unless they pass a strict
 * route allow-list. This file intentionally has no dependencies.
 */

function saisho_normalize_request_uri(): void
{
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($requestUri, PHP_URL_PATH);

    if (!is_string($path)) {
        $_SERVER['REQUEST_URI'] = '/__invalid_request__';
        return;
    }

    $path = rawurldecode(trim($path, '/'));

    if ($path === '') {
        $_SERVER['REQUEST_URI'] = '/';
        return;
    }

    $valid = strpos($path, "\0") === false
        && strpos($path, '\\') === false
        && (bool)preg_match('~\A[A-Za-z0-9_-][A-Za-z0-9._-]*(?:/[A-Za-z0-9_-][A-Za-z0-9._-]*)*\z~D', $path);

    if ($valid) {
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..' || $segment[0] === '.') {
                $valid = false;
                break;
            }
        }
    }

    $_SERVER['REQUEST_URI'] = $valid ? '/' . $path : '/__invalid_request__';
}

/**
 * Reject symlinks and paths that resolve outside the content directory.
 */
function saisho_is_safe_content_file(string $file): bool
{
    $realFile = realpath($file);
    $realData = realpath(__DIR__ . '/../data');

    if ($realFile === false || $realData === false || !is_file($realFile)) {
        return false;
    }

    return strpos($realFile, rtrim($realData, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0;
}

/**
 * Do not build absolute URLs from the attacker-controlled Host header.
 */
function saisho_sanitize_host_header(): void
{
    $serverName = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');

    if (!preg_match('/\A[a-z0-9.-]+(?::[0-9]{1,5})?\z/i', $serverName)) {
        $serverName = 'localhost';
    }

    $_SERVER['HTTP_HOST'] = strtolower($serverName);
}

saisho_normalize_request_uri();
saisho_sanitize_host_header();

function saisho_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function saisho_xml_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}
