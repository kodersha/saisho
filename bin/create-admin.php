<?php
/*
 * Create the initial Saisho administrator.
 *
 * Usage:
 *   SAISHO_ADMIN_USERNAME=admin php bin/create-admin.php
 *   (The password is prompted interactively and hidden on POSIX terminals.)
 *
 * PowerShell:
 *   $env:SAISHO_ADMIN_USERNAME = "admin"
 *   $env:SAISHO_ADMIN_PASSWORD = "long-random-password"
 *   php bin/create-admin.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

$root = dirname(__DIR__);
$configDir = $root . DIRECTORY_SEPARATOR . 'config';
$configFile = $configDir . DIRECTORY_SEPARATOR . 'admin.json';
$username = getenv('SAISHO_ADMIN_USERNAME');
if (!is_string($username) || $username === '') {
    fwrite(STDOUT, "Administrator username: ");
    $username = trim((string)fgets(STDIN));
}

$password = getenv('SAISHO_ADMIN_PASSWORD');
if (!is_string($password) || $password === '') {
    fwrite(STDOUT, "Administrator password: ");
    $canHideInput = DIRECTORY_SEPARATOR !== '\\\\' && function_exists('shell_exec');
    if ($canHideInput) {
        @shell_exec('stty -echo');
    }
    $password = rtrim((string)fgets(STDIN), "\\r\\n");
    if ($canHideInput) {
        @shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
}

if (!is_string($username) || !preg_match('/\A[a-zA-Z0-9_.-]{3,80}\z/', $username)) {
    fwrite(STDERR, "Set SAISHO_ADMIN_USERNAME (3-80 letters, digits, dot, underscore or hyphen).\n");
    exit(1);
}
if (!is_string($password) || strlen($password) < 14) {
    fwrite(STDERR, "Set SAISHO_ADMIN_PASSWORD to a password of at least 14 characters.\n");
    exit(1);
}
if (is_file($configFile)) {
    fwrite(STDERR, "An administrator already exists. Remove config/admin.json manually to recreate it.\n");
    exit(1);
}
if (!is_dir($configDir) && !mkdir($configDir, 0700, true)) {
    fwrite(STDERR, "Could not create config directory.\n");
    exit(1);
}

$data = json_encode([
    'username' => $username,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($data === false || file_put_contents($configFile, $data . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Could not write administrator credentials.\n");
    exit(1);
}
@chmod($configDir, 0750);
@chmod($configFile, 0640);
fwrite(STDOUT, "Administrator created. Remove SAISHO_ADMIN_PASSWORD from the environment.\n");
