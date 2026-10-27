<?php

// Synthetic identities only; never accepts a deployment database or environment.
use App\Actions\Accounts\RegisterByEmail;
use App\Actions\Accounts\VerifyExistingEmail;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$directory = realpath($argv[1] ?? '');
$allowed = realpath(dirname(__DIR__, 2).'/storage/framework/testing');
if (! $directory || ! $allowed || ! str_starts_with($directory, $allowed.DIRECTORY_SEPARATOR.'registration-race-')) {
    exit(2);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
$data = json_decode(file_get_contents($directory.'/input.json'), true, flags: JSON_THROW_ON_ERROR);
config(['app.env' => 'testing', 'mail.default' => 'array', 'database.default' => 'race', 'database.connections.race' => $data['connection'], 'hashing.bcrypt.rounds' => 4]);
DB::purge('race');
$number = (int) ($argv[2] ?? 0);
if (! in_array($number, [1, 2], true)) {
    exit(2);
}
file_put_contents($directory.'/ready-'.$number, 'ready');
$deadline = microtime(true) + 90;
while (! is_file($directory.'/go')) {
    if (microtime(true) > $deadline) {
        exit(3);
    }
    usleep(10000);
}
try {
    if (($data['flow'] ?? 'registration') === 'existing-verification') {
        app(VerifyExistingEmail::class)->complete($data['id'], ['email' => 'race@taskbridge.test', 'token' => $data['token']]);
    } else {
        app(RegisterByEmail::class)->complete($data['id'], ['email' => 'race@taskbridge.test', 'setup_token' => $data['token'], 'password' => 'ConcurrentPassword'.$number.'!', 'password_confirmation' => 'ConcurrentPassword'.$number.'!']);
    }
    echo 'created';
} catch (ValidationException) {
    echo 'rejected';
} catch (Throwable $error) {
    // No exception text, SQL, bindings or input tokens in process output.
    echo json_encode(['unexpected' => get_class($error), 'code' => $error->getCode(), 'state' => $error instanceof QueryException ? ($error->errorInfo[0] ?? null) : null]);
    exit(4);
}
