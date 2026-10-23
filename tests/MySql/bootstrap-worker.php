<?php

use App\Actions\Accounts\BootstrapAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Opt-in worker: guarded MySQL only. Test credentials never arrive through CLI arguments.
require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/environment.php';
[$app, $config] = verificationApplication();
$prefix = $argv[1];
if (! str_starts_with(str_replace('\\', '/', $prefix), $config['directory'].'/bootstrap-')) {
    throw new RuntimeException('Unexpected worker path.');
}
$ready = false;
DB::connection()->beforeExecuting(function ($query) use ($prefix, &$ready): void {
    if ($ready || ! str_contains($query, 'GET_LOCK')) {
        return;
    }
    $ready = true;
    file_put_contents($prefix.'.ready', 'ready');
    $until = microtime(true) + 20;
    while (! is_file(dirname($prefix).'/bootstrap.go')) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Bootstrap barrier timed out.');
        }
        usleep(10000);
    }
});
try {
    $admin = app(BootstrapAdmin::class)->create([
        'name' => 'Concurrent Admin', 'email' => basename($prefix).'@taskbridge.test',
        'password' => 'Isolated-Admin-734!', 'password_confirmation' => 'Isolated-Admin-734!',
    ]);
    $result = ['kind' => 'created', 'id' => $admin->id];
} catch (ValidationException $error) {
    $result = ['kind' => 'refused', 'fields' => array_keys($error->errors())];
} catch (Throwable $error) {
    $result = ['kind' => 'error', 'class' => $error::class];
}
file_put_contents($prefix.'.result', json_encode($result, JSON_THROW_ON_ERROR));
