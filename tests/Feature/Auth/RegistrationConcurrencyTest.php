<?php

namespace Tests\Feature\Auth;

use App\Models\EmailVerificationToken;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RegistrationConcurrencyTest extends TestCase
{
    #[DataProvider('flows')]
    public function test_two_independent_connections_cannot_complete_the_same_registration_twice(string $flow): void
    {
        $directory = storage_path('framework/testing/registration-race-'.bin2hex(random_bytes(8)));
        File::makeDirectory($directory, 0700, true);
        $connection = config('database.connections.sqlite');
        $connection['database'] = $directory.'/race.sqlite';
        $connection['busy_timeout'] = 10000;
        touch($connection['database']);
        if (getenv('TASKBRIDGE_RACE_MYSQL') === '1') {
            // A fixed disposable service in this workflow, never a deploy credential.
            $this->assertSame('true', getenv('CI'));
            $connection = array_replace(config('database.connections.mysql'), ['url' => null, 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'taskbridge_ci_registration', 'username' => 'taskbridge_ci', 'password' => 'ci-only-disposable-password', 'unix_socket' => '', 'options' => []]);
        }
        $original = config('database.default');
        $processes = [];
        try {
            config(['database.default' => 'race', 'database.connections.race' => $connection]);
            DB::purge('race');
            Artisan::call('migrate', ['--force' => true]);
            $token = Str::random(64);
            if ($flow === 'registration') {
                $pending = PendingRegistration::create(['id' => (string) Str::uuid(), 'name' => 'Race Identity', 'email' => 'race@taskbridge.test', 'role' => 'freelancer', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(10)]);
            } else {
                $existing = new User;
                $existing->forceFill(['name' => 'Race Identity', 'email' => 'race@taskbridge.test', 'role' => 'freelancer', 'password' => Hash::make('ExistingPassword123!'), 'email_verified_at' => null])->saveOrFail();
                $pending = EmailVerificationToken::create(['id' => (string) Str::uuid(), 'user_id' => $existing->id, 'email' => $existing->email, 'access_version' => $existing->access_version, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(10)]);
            }
            file_put_contents($directory.'/input.json', json_encode(['id' => $pending->id, 'token' => $token, 'connection' => $connection, 'flow' => $flow], JSON_THROW_ON_ERROR));
            foreach ([1, 2] as $number) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/registration-race.php'), $directory, (string) $number], base_path(), ['APP_ENV' => 'testing']);
                $process->setTimeout(120)->start();
                $processes[$number] = $process;
            }
            $deadline = microtime(true) + 90;
            while (! is_file($directory.'/ready-1') || ! is_file($directory.'/ready-2')) {
                $this->assertTrue(microtime(true) < $deadline && $processes[1]->isRunning() && $processes[2]->isRunning(), 'Both independent workers must reach the start barrier.');
                usleep(10000);
            }
            file_put_contents($directory.'/go', 'go');
            $results = [];
            foreach ($processes as $number => $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), 'Race worker failed: '.$process->getOutput());
                $results[$number] = trim($process->getOutput());
            }
            $this->assertEqualsCanonicalizing(['created', 'rejected'], array_values($results));
            $this->assertSame(1, User::count());
            $user = User::sole();
            $this->assertSame('Race Identity', $user->name);
            $this->assertSame('freelancer', $user->role);
            $this->assertNotNull($user->email_verified_at);
            $winner = array_search('created', $results, true);
            $this->assertTrue(Hash::check($flow === 'registration' ? 'ConcurrentPassword'.$winner.'!' : 'ExistingPassword123!', $user->password));
            $this->assertNotNull($pending->fresh()->consumed_at);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            if (getenv('TASKBRIDGE_RACE_MYSQL') === '1') {
                Artisan::call('db:wipe', ['--database' => 'race', '--force' => true]);
            }
            DB::disconnect('race');
            config(['database.default' => $original]);
            File::deleteDirectory($directory);
        }
    }

    public static function flows(): array
    {
        return [['registration'], ['existing-verification']];
    }
}
