<?php

// CLI only; no application boot, secrets, database connection or filesystem writes.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$extensions = [];
foreach (['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gd', 'hash', 'iconv', 'json', 'mbstring', 'openssl', 'PDO', 'pdo_mysql', 'Phar', 'session', 'tokenizer', 'xml', 'xmlwriter', 'zip'] as $name) {
    $extensions[$name] = extension_loaded($name);
}
$limits = [];
foreach (['memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time', 'max_input_time', 'upload_tmp_dir', 'sys_temp_dir'] as $name) {
    $limits[$name] = ini_get($name);
}
$root = dirname(__DIR__, 2);
$directories = [];
foreach (['bootstrap/cache', 'storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $name) {
    $path = $root.'/'.$name;
    $directories[$name] = ['exists' => is_dir($path), 'writable' => is_writable($path), 'mode' => is_dir($path) ? substr(sprintf('%o', fileperms($path)), -4) : null];
}
echo json_encode([
    'scope' => 'CLI only; web-SAPI limits, account quota and proxy HTTPS require separate panel/web verification',
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'extensions' => $extensions,
    'limits' => $limits,
    'gd_webp' => function_exists('imagewebp'),
    'directories' => $directories,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
