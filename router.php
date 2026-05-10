<?php
declare(strict_types=1);

require __DIR__ . '/app.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$staticFile = APP_BASE_DIR . $path;
$extension = strtolower(pathinfo($staticFile, PATHINFO_EXTENSION));

if ($path !== '/' && is_file($staticFile) && $extension !== 'php') {
    return false;
}

app_start_session();
app_dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
