<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;

// Standalone opt-in harness. Never included by phpunit.xml.
function verificationEnvironment(): array
{
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $directory = $root.'/storage/framework/testing/mysql-verification-20261022';
    $config = json_decode(file_get_contents($directory.'/connection.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($config['database'] ?? '') !== 'taskbridge_test_mysql_20261022' || ($config['port'] ?? 0) !== 33079
        || ($config['marker'] ?? '') !== 'taskbridge-disposable-mysql-verification-20261022'
        || trim(file_get_contents($directory.'/owner.marker')) !== $config['marker']) {
        throw new RuntimeException('Disposable identity/marker mismatch.');
    }
    foreach (['DB_URL', 'DATABASE_URL'] as $key) {
        if (getenv($key)) {
            throw new RuntimeException('Database URL overrides are forbidden.');
        }
    }
    $config['directory'] = $directory;
    if (is_file($directory.'/no-config.php') || is_file($directory.'/no-routes.php')) {
        throw new RuntimeException('Unexpected cached configuration/routes in disposable storage.');
    }

    return $config;
}

function verificationPdo(array $config, bool $root = false): PDO
{
    $pdo = new PDO('mysql:host=127.0.0.1;port=33079;dbname='.$config['database'], $root ? 'root' : 'verification', $root ? $config['root_password'] : $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $identity = $pdo->query('SELECT DATABASE() db, @@port port, @@datadir directory')->fetch(PDO::FETCH_ASSOC);
    if ($identity['db'] !== $config['database'] || (int) $identity['port'] !== 33079
        || str_replace('\\', '/', rtrim($identity['directory'], '/\\')) !== $config['directory'].'/data') {
        throw new RuntimeException('Resolved MySQL instance mismatch.');
    }
    if ($pdo->query('SELECT marker FROM verification_safety')->fetchColumn() !== $config['marker']) {
        throw new RuntimeException('Database safety marker missing.');
    }

    return $pdo;
}

function verificationApplication(): array
{
    $config = verificationEnvironment();
    verificationPdo($config); // Validate before Laravel or any migrations.
    foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $config['directory'].'/no-config.php', 'APP_ROUTES_CACHE' => $config['directory'].'/no-routes.php', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('v', 32))] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->useEnvironmentPath($config['directory']);
    $app->loadEnvironmentFrom('no-application.env');
    $app->afterBootstrapping(LoadConfiguration::class, function ($app) use ($config): void {
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 33079, 'database' => $config['database'], 'username' => 'verification', 'password' => $config['password'], 'url' => null, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true]);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('hashing.bcrypt.rounds', 4);
        foreach (['local', 'avatars', 'deliverables', 'project_covers'] as $disk) {
            $app['config']->set('filesystems.disks.'.$disk, ['driver' => 'local', 'root' => $config['directory'].'/files/'.$disk, 'throw' => true]);
        }
        $app['config']->set('view.compiled', $config['directory'].'/views');
        $app['config']->set('logging.default', 'single');
        $app['config']->set('logging.channels.single.path', $config['directory'].'/application.log');
    });
    $app->make(Kernel::class)->bootstrap();
    set_exception_handler(function (Throwable $exception): void {
        fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
        exit(1);
    });
    $pdo = DB::connection()->getPdo();
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $config['database']) {
        throw new RuntimeException('Laravel resolved an unsafe database.');
    }
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=15');

    return [$app, $config];
}
