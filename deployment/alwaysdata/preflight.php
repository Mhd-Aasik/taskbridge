<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// CLI-only and read-only for the database. Never expose this file as a web route.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$offline = in_array('--offline', $argv, true);
$mailDisabled = in_array('--mail-disabled', $argv, true);
$root = dirname(__DIR__, 2);
$failures = [];
$check = function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ').$label.PHP_EOL;
    if (! $ok) {
        $failures[] = $label;
    }
};

try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $check($app->environment('production') && config('app.debug') === false, 'production mode and debug disabled');
    $check((bool) preg_match('~\Ahttps://[a-z0-9][a-z0-9-]*\.alwaysdata\.net/?\z~', (string) config('app.url')) && ! str_contains(strtolower(config('app.url')), 'replace'), 'canonical supplied HTTPS subdomain');
    $key = (string) config('app.key');
    $check(str_starts_with($key, 'base64:') && strlen((string) base64_decode(substr($key, 7), true)) === 32, 'deployment encryption key configured');
    $check(config('session.secure') === true && config('session.http_only') === true && config('session.same_site') === 'lax' && config('session.domain') === null, 'secure host-only session cookie');
    $check(config('database.default') === 'mysql', 'mysql PDO driver selected for MariaDB');
    $check(config('queue.default') === 'sync', 'no background queue requirement');
    if (! $offline) {
        $check(config('session.driver') === 'database', 'database session revocation backend');
    }
    foreach (['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gd', 'hash', 'iconv', 'json', 'mbstring', 'openssl', 'PDO', 'pdo_mysql', 'Phar', 'session', 'tokenizer', 'xml', 'xmlwriter', 'zip'] as $extension) {
        $check(extension_loaded($extension) || ($offline && in_array($extension, ['ctype', 'mbstring'], true)), 'extension '.$extension);
    }
    $check(function_exists('imagewebp') && (imagetypes() & IMG_WEBP) !== 0 && (imagetypes() & IMG_JPG) !== 0 && (imagetypes() & IMG_PNG) !== 0, 'GD JPEG PNG WebP support');
    $public = realpath($root.'/public');
    foreach (['app/private', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $path) {
        $directory = realpath(storage_path($path));
        $check($directory !== false && is_writable($directory) && ! str_starts_with(str_replace('\\', '/', $directory).'/', str_replace('\\', '/', $public).'/'), 'private writable storage '.$path);
    }
    $check(is_writable($root.'/bootstrap/cache'), 'release cache directory writable');
    $manifestPath = $root.'/public/build/manifest.json';
    $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR) : [];
    $check(isset($manifest['resources/css/app.css']), 'built CSS manifest');
    foreach ($manifest as $entry) {
        $file = $root.'/public/build/'.$entry['file'];
        $check(is_file($file) && filesize($file) > 0, 'nonempty manifest asset');
    }
    $check(! is_file($root.'/public/hot') && ! file_exists($root.'/public/storage'), 'no development hot file or public storage link');
    if (! $offline && $mailDisabled) {
        $check(config('mail.default') === 'array', 'external email disabled pending sender and recipient approval');
    } elseif (! $offline) {
        $check(config('mail.default') === 'smtp', 'SMTP configured after sender confirmation');
        $sender = (string) config('mail.from.address');
        $check(filter_var($sender, FILTER_VALIDATE_EMAIL) !== false && ! str_contains(strtolower($sender), 'replace'), 'confirmed SMTP sender configured (ownership requires separate confirmation)');
        $check(config('mail.mailers.smtp.scheme') === 'smtps' && (int) config('mail.mailers.smtp.port') === 465, 'implicit TLS SMTP configured');
    }
    if (! $offline) {
        $databaseOption = array_values(array_filter($argv, fn (string $value): bool => str_starts_with($value, '--database=')));
        $expectedDatabase = isset($databaseOption[0]) ? substr($databaseOption[0], 11) : '';
        if ($expectedDatabase === '' || str_contains($expectedDatabase, 'REPLACE')) {
            throw new RuntimeException('Explicit --database identity required.');
        }
        $identity = DB::selectOne('SELECT DATABASE() AS db, VERSION() AS version');
        $check($identity->db === $expectedDatabase, 'expected database identity');
        $check(str_contains($identity->version, 'MariaDB') && (bool) preg_match('/(?:10\.11|11\.4|11\.8)\./', $identity->version), 'documented MariaDB server family');
        $tables = DB::select('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?', ['BASE TABLE']);
        $check(count($tables) >= 33 && array_all_compatible($tables), 'application tables use InnoDB');
        $check(DB::table('migrations')->count() === count(glob($root.'/database/migrations/*.php')), 'all checked-in migrations applied');
    } else {
        echo 'SKIP live database, database sessions and SMTP configuration in offline mode.'.PHP_EOL;
    }
    echo $offline ? 'OFFLINE ONLY: database, web TLS/proxy, cookies and external mail remain unverified.'.PHP_EOL : 'CLI checks complete: web TLS/proxy, cookies, private endpoints and external delivery still require deployment verification.'.PHP_EOL;
} catch (Throwable) {
    // Do not print exceptions: database/SMTP errors may contain secrets or identities.
    $check(false, 'preflight could not complete; inspect protected configuration privately');
}
exit($failures === [] ? 0 : 1);

function array_all_compatible(array $tables): bool
{
    foreach ($tables as $table) {
        if ($table->ENGINE !== 'InnoDB') {
            return false;
        }
    }

    return true;
}
