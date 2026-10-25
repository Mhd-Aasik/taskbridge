<?php

use App\Actions\Proposals\AcceptProposal;
use App\Actions\Proposals\SubmitProposal;
use App\Enums\ProjectStatus;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Mutating, one-shot probe ONLY for a new, explicitly named disposable database.
// Never creates/drops databases, imports data, runs seeders or accesses uploads.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$options = getopt('', ['disposable:']);
$expected = $options['disposable'] ?? '';
$root = dirname(__DIR__, 2);
if (! is_string($expected) || ! preg_match('/\A[a-z0-9_-]+_tbprobe_[0-9]{8}_[a-f0-9]{6}\z/', $expected)
    || ! is_file($root.'/.taskbridge-disposable')
    || trim(file_get_contents($root.'/.taskbridge-disposable')) !== $expected) {
    fwrite(STDERR, "REFUSED: require --disposable=ACCOUNT_tbprobe_YYYYMMDD_HEX6 and matching private .taskbridge-disposable marker in an isolated release.\n");
    exit(2);
}
$assert = static function (bool $ok, string $label): void {
    if (! $ok) {
        fwrite(STDERR, 'FAIL '.$label.PHP_EOL);
        throw new RuntimeException($label);
    }
    echo 'PASS '.$label.PHP_EOL;
};
$connection = null;
$secondary = null;
$lockName = 'tbprobe:'.substr(hash('sha256', $expected), 0, 40);
$stage = 'bootstrap';
try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    // These overrides apply only in this process. No session/cache/mail records.
    config(['session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array', 'queue.default' => 'sync']);
    $assert(config('database.default') === 'mysql', 'mysql PDO driver');
    $stage = 'disposable database identity and engine';
    $connection = DB::connection();
    $identity = $connection->selectOne('SELECT DATABASE() AS db, VERSION() AS version');
    $assert($identity->db === $expected, 'exact disposable database identity');
    $assert(str_contains($identity->version, 'MariaDB') && preg_match('/(?:10\.11|11\.4|11\.8)\./', $identity->version), 'supported actual MariaDB family');
    echo 'SERVER '.preg_replace('/[^a-zA-Z0-9.+_~:-]/', '', $identity->version).PHP_EOL;
    $assert((int) $connection->selectOne('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->n === 0, 'disposable database initially empty');
    $connection->statement('SET SESSION innodb_lock_wait_timeout = 2');
    $connection->statement('SET SESSION lock_wait_timeout = 3');
    $connection->statement('SET SESSION max_statement_time = 8');
    $assert((int) $connection->selectOne('SELECT GET_LOCK(?, 1) AS held', [$lockName])->held === 1, 'bounded named advisory lock');
    $stage = 'migrations';
    $assert(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'all application migrations');
    $assert(DB::table('migrations')->count() === count(glob($root.'/database/migrations/*.php')), 'migration count matches release');
    $tables = $connection->select('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?', ['BASE TABLE']);
    $assert(count($tables) >= 33 && array_filter($tables, fn ($table) => $table->ENGINE !== 'InnoDB') === [], 'application tables use InnoDB');
    $configuration = $connection->getConfig();
    config(['database.connections.tbprobe_secondary' => $configuration]);
    $secondary = DB::connection('tbprobe_secondary');
    $secondary->statement('SET SESSION innodb_lock_wait_timeout = 2');
    $assert((int) $secondary->selectOne('SELECT GET_LOCK(?, 0) AS held', [$lockName])->held === 0, 'advisory lock excludes second connection');
    $stage = 'transactions and application workflow';
    $connection->beginTransaction();
    $now = CarbonImmutable::now('UTC');
    $client = User::forceCreate(['name' => 'Disposable Client', 'email' => 'client@tbprobe.test', 'role' => 'client', 'password' => bin2hex(random_bytes(32))]);
    $freelancer = User::forceCreate(['name' => 'Disposable Freelancer', 'email' => 'freelancer@tbprobe.test', 'role' => 'freelancer', 'password' => bin2hex(random_bytes(32))]);
    $category = Category::forceCreate(['name' => 'Disposable', 'slug' => 'tbprobe', 'is_active' => true]);
    $project = Project::forceCreate(['client_id' => $client->id, 'category_id' => $category->id, 'title' => 'Disposable compatibility probe', 'description' => 'Synthetic database-only fixture.', 'budget' => '1000.00', 'deadline' => $now->addDays(30)->toDateString(), 'status' => ProjectStatus::Open, 'published_at' => $now->subMinute()]);
    Auth::setUser($freelancer);
    $input = ['cover_letter' => 'Synthetic probe proposal.', 'proposed_budget' => '900.00', 'proposed_deadline' => $now->addDays(20)->toDateString()];
    $submitted = app(SubmitProposal::class)->submit($project->id, $input);
    $repeated = app(SubmitProposal::class)->submit($project->id, $input);
    $assert($submitted['created'] && ! $repeated['created'] && $submitted['proposal']->id === $repeated['proposal']->id, 'proposal submission and idempotent replay');
    Auth::setUser($client);
    $accepted = app(AcceptProposal::class)->accept($project->id, $submitted['proposal']->id, true);
    $counts = [DB::table('notifications')->count(), DB::table('project_activities')->count()];
    $reaccepted = app(AcceptProposal::class)->accept($project->id, $submitted['proposal']->id, true);
    $assert($accepted['created'] && ! $reaccepted['created'] && $project->fresh()->status === ProjectStatus::InProgress, 'proposal acceptance and idempotent replay');
    $assert($counts[0] > 0 && $counts[1] > 0 && $counts === [DB::table('notifications')->count(), DB::table('project_activities')->count()], 'transactional notifications and activity remain idempotent');
    $rating = ['project_assignment_id' => $accepted['assignment']->id, 'reviewer_id' => $client->id, 'reviewee_id' => $freelancer->id, 'rating' => 5, 'submitted_at' => $now, 'submission_id' => (string) Str::uuid()];
    $rejects = static function (callable $operation, int $code, string $label) use ($assert): void {
        try {
            DB::transaction($operation);
        } catch (QueryException $exception) {
            $assert((int) ($exception->errorInfo[1] ?? 0) === $code, $label);

            return;
        }
        throw new RuntimeException($label);
    };
    $rejects(fn () => DB::table('project_ratings')->insert(array_replace($rating, ['rating' => 6])), 4025, 'rating range CHECK enforced');
    $rejects(fn () => DB::table('project_ratings')->insert(array_replace($rating, ['reviewee_id' => $client->id])), 4025, 'rating self-review CHECK enforced');
    $rejects(fn () => DB::table('project_ratings')->insert(array_replace($rating, ['reviewee_id' => 999999999])), 1452, 'rating foreign key enforced');
    DB::table('project_ratings')->insert($rating);
    try {
        DB::transaction(fn () => DB::table('project_ratings')->insert($rating));
        throw new RuntimeException('duplicate rating was accepted');
    } catch (UniqueConstraintViolationException $exception) {
        $assert($exception->getCode() === '23000' && (int) $exception->errorInfo[1] === 1062, 'Laravel unique-conflict classification and MariaDB 1062');
    }
    $assert(DB::table('project_ratings')->count() === 1, 'savepoint rollback preserves outer transaction');
    $connection->rollBack();
    $assert(DB::table('users')->count() === 0 && DB::table('projects')->count() === 0, 'synthetic workflow transaction rolled back');
    // A committed synthetic row lets the other connection observe row locking.
    $id = DB::table('categories')->insertGetId(['name' => 'Lock probe', 'slug' => 'tbprobe-lock', 'is_active' => true]);
    $connection->beginTransaction();
    DB::table('categories')->where('id', $id)->lockForUpdate()->first();
    try {
        $secondary->table('categories')->where('id', $id)->update(['name' => 'Contender']);
        throw new RuntimeException('row lock did not block contender');
    } catch (QueryException $exception) {
        $assert((int) ($exception->errorInfo[1] ?? 0) === 1205, 'FOR UPDATE excludes second connection within two-second wait');
    }
    $connection->rollBack();
    DB::table('categories')->where('id', $id)->delete();
    echo "COMPLETE: disposable MariaDB smoke checks only; no production, HTTP, broad concurrency or SMTP verification. Schema retained for inspection.\n";
    $status = 0;
} catch (Throwable $exception) {
    // Never print exception messages, SQL bindings, DSNs or credentials.
    fwrite(STDERR, 'FAIL '.$stage.'; '.get_class($exception).PHP_EOL);
    if ($exception instanceof QueryException || $exception instanceof PDOException) {
        $state = preg_replace('/[^A-Z0-9]/', '', (string) ($exception->errorInfo[0] ?? ''));
        fwrite(STDERR, 'SQLSTATE '.$state.'; driver code '.(int) ($exception->errorInfo[1] ?? 0).PHP_EOL);
    }
    $status = 1;
} finally {
    if ($connection !== null) {
        try {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $connection->selectOne('SELECT RELEASE_LOCK(?)', [$lockName]);
        } catch (Throwable) {
            $status = 1;
        }
    }
    if ($secondary !== null) {
        DB::disconnect('tbprobe_secondary');
    }
}
exit($status);
