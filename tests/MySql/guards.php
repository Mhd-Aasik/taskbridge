<?php

use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/environment.php';
$config = verificationEnvironment();
$path = $config['directory'].'/connection.json';
$original = file_get_contents($path);
$checks = 0;
try {
    foreach (['database' => 'taskbridge', 'port' => 3306, 'marker' => 'unmarked'] as $key => $value) {
        $bad = $config;
        $bad[$key] = $value;
        file_put_contents($path, json_encode($bad, JSON_THROW_ON_ERROR));
        $rejected = false;
        try {
            verificationEnvironment();
        } catch (RuntimeException) {
            $rejected = true;
        }
        if (! $rejected) {
            throw new RuntimeException('Unsafe configuration was accepted.');
        }
        $checks++;
        file_put_contents($path, $original);
    }
    foreach (['DB_URL', 'DATABASE_URL'] as $key) {
        $previous = getenv($key);
        putenv($key.'=mysql://unsafe/taskbridge');
        try {
            $rejected = false;
            try {
                verificationEnvironment();
            } catch (RuntimeException) {
                $rejected = true;
            }
            if (! $rejected) {
                throw new RuntimeException('URL override was accepted.');
            }
            $checks++;
        } finally {
            $previous === false ? putenv($key) : putenv($key.'='.$previous);
        }
    }
} finally {
    file_put_contents($path, $original);
}
// An inherited cached-config path and application DB name cannot redirect bootstrap.
putenv('APP_CONFIG_CACHE='.dirname(__DIR__, 2).'/bootstrap/cache/config.php');
putenv('DB_DATABASE=taskbridge');
[$app] = verificationApplication();
if (DB::selectOne('SELECT DATABASE() name')->name !== 'taskbridge_test_mysql_20261022') {
    throw new RuntimeException('Application/cached configuration leaked into test bootstrap.');
}
echo (++$checks)." safety checks passed; no schema/reset/cleanup commands used.\n";
