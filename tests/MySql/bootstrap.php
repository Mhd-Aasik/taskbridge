<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/environment.php';

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;

[$app, $config] = verificationApplication();
function bootstrapCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
bootstrapCheck(Artisan::call('migrate', ['--force' => true]) === 0, 'Guarded migration failed.');
bootstrapCheck(User::count() === 0, 'Bootstrap harness requires empty test users; refusing reuse.');
$client = User::factory()->create(['role' => 'client']);
$freelancer = User::factory()->create(['role' => 'freelancer']);
$original = [$client->fresh()->getAttributes(), $freelancer->fresh()->getAttributes()];
$lock = 'taskbridge:bootstrap:'.substr(hash('sha256', $config['database']), 0, 40);
$observer = verificationPdo($config, true);
$blocker = verificationPdo($config);

for ($round = 1; $round <= 3; $round++) {
    verificationPdo($config);
    bootstrapCheck((int) $blocker->query('SELECT GET_LOCK('.$blocker->quote($lock).', 1)')->fetchColumn() === 1, 'Cannot hold test barrier lock.');
    $gate = $config['directory'].'/bootstrap.go';
    if (is_file($gate)) {
        unlink($gate);
    }
    $processes = [];
    try {
        for ($i = 0; $i < 2; $i++) {
            $prefix = $config['directory'].'/bootstrap-'.$round.'-'.$i;
            $process = new Process([PHP_BINARY, __DIR__.'/bootstrap-worker.php', $prefix]);
            $process->setTimeout(45);
            $process->start();
            $processes[$prefix] = $process;
        }
        $until = microtime(true) + 25;
        do {
            $ready = true;
            foreach ($processes as $prefix => $process) {
                bootstrapCheck($process->isRunning(), 'Worker exited before gate.');
                $ready = $ready && is_file($prefix.'.ready');
            }
            bootstrapCheck(microtime(true) < $until, 'Worker readiness timeout.');
            if (! $ready) {
                usleep(10000);
            }
        } while (! $ready);
        file_put_contents($gate, 'release');
        $until = microtime(true) + 7;
        do {
            $pending = (int) $observer->query("SELECT COUNT(*) FROM performance_schema.metadata_locks WHERE OBJECT_TYPE = 'USER LEVEL LOCK' AND LOCK_STATUS = 'PENDING' AND OBJECT_NAME = ".$observer->quote($lock))->fetchColumn();
            bootstrapCheck(microtime(true) < $until, 'Genuine advisory-lock contention not observed.');
            if ($pending < 2) {
                usleep(10000);
            }
        } while ($pending < 2);
        $blocker->query('SELECT RELEASE_LOCK('.$blocker->quote($lock).')');
        $outcomes = [];
        foreach ($processes as $prefix => $process) {
            bootstrapCheck($process->wait() === 0, 'Worker process failed.');
            $outcomes[] = json_decode(file_get_contents($prefix.'.result'), true, flags: JSON_THROW_ON_ERROR);
        }
        $kinds = array_column($outcomes, 'kind');
        sort($kinds);
        bootstrapCheck($kinds === ['created', 'refused'], 'Race did not have exactly one winner.');
        $admin = User::where('role', 'admin')->sole();
        bootstrapCheck(Hash::check('Isolated-Admin-734!', $admin->password) && $admin->email_verified_at === null && $admin->access_version === 0, 'Winner account fields invalid.');
        bootstrapCheck(Auth::attempt(['email' => $admin->email, 'password' => 'Isolated-Admin-734!']), 'Test Admin could not authenticate.');
        Auth::logout();
        bootstrapCheck($original === [$client->fresh()->getAttributes(), $freelancer->fresh()->getAttributes()], 'Existing test accounts changed.');
        bootstrapCheck(DB::table('notifications')->count() === 0 && DB::table('admin_action_logs')->count() === 0 && DB::table('project_activities')->count() === 0, 'Unexpected domain event.');
        bootstrapCheck($blocker->query('SELECT IS_FREE_LOCK('.$blocker->quote($lock).')')->fetchColumn() === 1, 'Bootstrap leaked its lock.');
        echo "Round {$round}: two distinct processes blocked on the same observed MySQL lock; one first Admin, one refusal, hash/login/optional verification and unchanged participants passed.\n";
        // Only our just-created fixture is removed, after validating the test identity again.
        verificationPdo($config);
        DB::table('users')->where('id', $admin->id)->where('role', 'admin')->delete();
    } finally {
        $blocker->query('SELECT RELEASE_LOCK('.$blocker->quote($lock).')');
        foreach ($processes as $process) {
            $process->stop(1);
        }
    }
}
echo 'MySQL '.DB::selectOne('SELECT VERSION() version')->version.' bootstrap concurrency: 3/3 genuine contention rounds passed; no application records used.'.PHP_EOL;
