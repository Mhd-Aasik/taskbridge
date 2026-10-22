<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/environment.php';
use App\Actions\Accounts\ChangeAccountPassword;
use App\Actions\Accounts\ChangeAccountStatus;
use App\Actions\Deliverables\ReviewDeliverableVersion;
use App\Actions\Deliverables\SubmitDeliverable;
use App\Actions\Proposals\AcceptProposal;
use App\Actions\Proposals\SubmitProposal;
use App\Models\DeliverableVersion;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectAssignment;
use App\Models\Proposal;
use App\Models\User;
use App\Support\AccountManagement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

[$app, $config] = verificationApplication();
function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
// No migrate:fresh, truncation, reset or seed. Guarded additive migrations on the marked empty schema.
Artisan::call('migrate', ['--force' => true]);
echo 'MySQL '.DB::selectOne('SELECT VERSION() version')->version.'; guarded migrations passed.'.PHP_EOL;

function race(array $jobs, string $table, int $id): array
{
    global $config;
    verificationPdo($config);
    check(in_array($table, ['projects', 'users'], true), 'Invalid lock table');
    $blocker = verificationPdo($config);
    $observer = verificationPdo($config, true);
    $blocker->beginTransaction();
    $blocker->query('SELECT id FROM '.$table.' WHERE id='.(int) $id.' FOR UPDATE');
    $base = $config['directory'].'/race-'.bin2hex(random_bytes(8));
    $processes = [];
    try {
        foreach ($jobs as $i => $job) {
            $job['prefix'] = $base.'-'.$i;
            $job['gate'] = $base.'.go';
            file_put_contents($job['prefix'].'.json', json_encode($job, JSON_THROW_ON_ERROR));
            $process = new Process([PHP_BINARY, __DIR__.'/worker.php', $job['prefix'].'.json']);
            $process->setTimeout(45);
            $process->start();
            $processes[] = $process;
        }
        $until = microtime(true) + 25;
        do {
            $ready = count(glob($base.'-*.ready')) === count($jobs);
            foreach ($processes as $process) {
                $process->checkTimeout();
                check($process->isRunning(), 'Worker exited before barrier: '.$process->getErrorOutput());
            }
            if (microtime(true) > $until) {
                throw new RuntimeException('Worker readiness timeout');
            }
            usleep(10000);
        } while (! $ready);
        file_put_contents($base.'.go', 'release');
        $until = microtime(true) + 12;
        do {
            $waits = (int) $observer->query('SELECT COUNT(*) FROM performance_schema.data_lock_waits')->fetchColumn();
            if (microtime(true) > $until) {
                throw new RuntimeException('Real MySQL lock contention was not observed');
            }
            usleep(10000);
        } while ($waits < count($jobs));
        $blocker->commit();
        $results = [];
        foreach ($processes as $i => $process) {
            $process->wait();
            check($process->isSuccessful(), 'Worker failed: '.$process->getErrorOutput());
            $result = json_decode(file_get_contents($base.'-'.$i.'.result'), true, flags: JSON_THROW_ON_ERROR);
            check($result['kind'] !== 'error', 'Unexpected worker failure: '.json_encode($result));
            $results[] = $result;
        }

        echo 'Observed contended '.implode('/', array_column($jobs, 'operation')).': '.json_encode($results, JSON_THROW_ON_ERROR).PHP_EOL;

        return $results;
    } finally {
        if ($blocker->inTransaction()) {
            $blocker->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }
}

function project(): array
{
    $client = User::factory()->create(['role' => 'client']);
    $freelancer = User::factory()->create(['role' => 'freelancer']);
    $project = Project::factory()->create(['client_id' => $client->id, 'status' => 'open', 'published_at' => now('UTC')->subMinute(), 'budget' => '50000.00', 'deadline' => date('Y-m-d', strtotime('+20 days'))]);

    return [$client, $freelancer, $project];
}
function assigned(): array
{
    [$client,$freelancer,$project] = project();
    $proposal = Proposal::factory()->create(['project_id' => $project->id, 'freelancer_id' => $freelancer->id, 'proposed_budget' => '12345.67', 'proposed_deadline' => date('Y-m-d', strtotime('+10 days'))]);
    $assignment = ProjectAssignment::factory()->create(['proposal_id' => $proposal->id]);
    Auth::setUser($freelancer);
    $version = app(SubmitDeliverable::class)->submit($project->id, null, ['title' => 'Initial work', 'milestone_id' => null], UploadedFile::fake()->createWithContent('initial.txt', 'Initial immutable work.'), (string) Str::uuid());

    return [$client, $freelancer, $project, $assignment, $version];
}

for ($round = 1; $round <= 3; $round++) {
    [$client,$freelancer,$project] = project();
    $offers = collect([
        Proposal::factory()->create(['project_id' => $project->id, 'proposed_budget' => '12345.67', 'proposed_deadline' => date('Y-m-d', strtotime('+10 days'))]),
        Proposal::factory()->create(['project_id' => $project->id, 'proposed_budget' => '23456.78', 'proposed_deadline' => date('Y-m-d', strtotime('+12 days'))]),
    ]);
    $originalOffers = $offers->mapWithKeys(fn ($offer) => [$offer->id => [$offer->proposed_budget, $offer->proposed_deadline->toDateString()]])->all();
    $jobs = array_map(fn ($offer) => ['operation' => 'accept', 'actor' => $client->id, 'project' => $project->id, 'proposal' => $offer->id], $offers->all());
    $intruder = User::factory()->create(['role' => 'client']);
    $jobs[] = ['operation' => 'accept', 'actor' => $intruder->id, 'project' => $project->id, 'proposal' => $offers->first()->id];
    $results = race($jobs, 'projects', $project->id);
    check(count(array_filter($results, fn ($r) => $r['kind'] === 'ok')) === 1, 'One acceptance must win');
    check($results[2] === ['kind' => 'http', 'status' => 403], 'Another Client accepted this project');
    $assignment = $project->assignment()->sole();
    check([$assignment->agreed_budget, $assignment->agreed_deadline->toDateString()] === $originalOffers[$assignment->proposal_id], 'Agreed terms changed');
    foreach ($offers as $offer) {
        check([$offer->fresh()->proposed_budget, $offer->fresh()->proposed_deadline->toDateString()] === $originalOffers[$offer->id], 'Original offer terms changed');
    }
    Auth::setUser($client);
    $snapshot = $assignment->getAttributes();
    app(AcceptProposal::class)->accept($project->id, $assignment->proposal_id, true);
    check($snapshot === $assignment->fresh()->getAttributes(), 'Assignment retry changed history');
    check($project->proposals()->where('status', 'accepted')->count() === 1 && $project->proposals()->where('status', 'rejected')->count() === 1, 'Competing offer states invalid');
    check($project->activities()->where('event_type', 'project_assigned')->count() === 1, 'Missing or duplicate assignment activity');
    echo "Round $round acceptance passed (observed server lock waits).\n";

    [$client,$freelancer,$project] = project();
    $job = ['operation' => 'submit', 'actor' => $freelancer->id, 'project' => $project->id];
    $results = race([$job, $job], 'projects', $project->id);
    check($results[0]['kind'] === 'ok' && $results[1]['kind'] === 'ok' && $results[0]['id'] === $results[1]['id'], 'Duplicate submission failed retry');
    check($project->proposals()->count() === 1, 'Duplicate proposals');
    $original = $project->proposals()->sole()->getAttributes();
    Auth::setUser($freelancer);
    app(SubmitProposal::class)->submit($project->id, []);
    check($original === $project->proposals()->sole()->getAttributes(), 'Proposal retry overwrote original fields/time');
    check(DB::table('notifications')->where('notifiable_id', $client->id)->where('type', 'proposal_submitted')->count() === 1, 'Missing/duplicate submission notification');
    echo "Round $round duplicate proposal passed.\n";

    [$client,$freelancer,$project,$assignment,$version] = assigned();
    $results = race([
        ['operation' => 'upload', 'actor' => $freelancer->id, 'project' => $project->id, 'deliverable' => $version->deliverable_id, 'uuid' => (string) Str::uuid()],
        ['operation' => 'review', 'actor' => $client->id, 'project' => $project->id, 'deliverable' => $version->deliverable_id, 'version' => $version->id],
    ], 'projects', $project->id);
    check(count(array_filter($results, fn ($r) => $r['kind'] === 'ok')) === 1, 'Review/upload must have one effective winner');
    check(! ($version->review()->exists() && $version->deliverable->versions()->count() > 1), 'Approved superseded version');
    echo "Round $round upload/review passed.\n";

    [$client,$freelancer,$project,$assignment,$version] = assigned();
    Auth::setUser($client);
    app(ReviewDeliverableVersion::class)->approve($project->id, $version->deliverable_id, $version->id, ['confirmed' => true]);
    $results = race([
        ['operation' => 'complete', 'actor' => $client->id, 'project' => $project->id],
        ['operation' => 'task', 'actor' => $client->id, 'project' => $project->id],
    ], 'projects', $project->id);
    check(count(array_filter($results, fn ($r) => $r['kind'] === 'ok')) === 1, 'Completion/task creation must have one winner');
    check(! ($project->fresh()->status->value === 'completed' && $assignment->tasks()->where('status', '!=', 'done')->exists()), 'Completed project contains unfinished work');
    echo "Round $round completion/task passed.\n";

    $user = User::factory()->create();
    $token = Password::createToken($user);
    $job = ['operation' => 'reset', 'actor' => $user->id, 'credentials' => ['email' => $user->email, 'password' => 'reset-secret', 'password_confirmation' => 'reset-secret', 'token' => $token]];
    $results = race([$job, $job], 'users', $user->id);
    check(count(array_filter($results, fn ($r) => $r['id'] === Password::PASSWORD_RESET)) === 1, 'Reset token used more than once');
    check(! Password::tokenExists($user, $token) && $user->fresh()->access_version === 1, 'Reset token/version failure');
    echo "Round $round concurrent reset passed.\n";

    // Real committed revocation, then a write from a separate process retaining the old User/version.
    foreach (['password', 'suspension'] as $revocation) {
        [$client,$freelancer,$project,$assignment,$version] = assigned();
        $job = ['operation' => 'task', 'actor' => $client->id, 'project' => $project->id];
        // Pause this writer before its first locking read, then revoke through another connection.
        $prefix = $config['directory'].'/stale-'.bin2hex(random_bytes(8));
        $job += ['prefix' => $prefix, 'gate' => $prefix.'.go'];
        file_put_contents($prefix.'.json', json_encode($job, JSON_THROW_ON_ERROR));
        $process = new Process([PHP_BINARY, __DIR__.'/worker.php', $prefix.'.json']);
        $process->setTimeout(35);
        $process->start();
        try {
            $until = microtime(true) + 20;
            while (! is_file($prefix.'.ready')) {
                $process->checkTimeout();
                check($process->isRunning(), 'Stale worker exited');
                check(microtime(true) < $until, 'Stale readiness timeout');
                usleep(10000);
            }
            if ($revocation === 'password') {
                Auth::setUser($client);
                app(ChangeAccountPassword::class)->update('password', 'changed-secret');
            } else {
                Auth::setUser(User::factory()->create(['role' => 'admin']));
                app(ChangeAccountStatus::class)->change($client->id, true, ['reason' => 'Disposable concurrency verification'], AccountManagement::version($client), (string) Str::uuid(), true);
            }
            file_put_contents($prefix.'.go', 'release');
            $process->wait();
            $result = json_decode(file_get_contents($prefix.'.result'), true, flags: JSON_THROW_ON_ERROR);
            check($result['kind'] === 'http' && $result['status'] === 403 && $assignment->tasks()->count() === 0, 'Revoked stale writer authorized');
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        echo "Round $round stale-session $revocation revocation passed.\n";
    }
}

// Inject failure after storing a real file inside a real MySQL transaction.
[$client,$freelancer,$project,$assignment,$version] = assigned();
Auth::setUser($freelancer);
$beforeFiles = Storage::disk('deliverables')->allFiles();
$beforeVersions = DeliverableVersion::count();
$beforeEvents = DB::table('project_activities')->count();
$beforeNotifications = DB::table('notifications')->count();
$event = 'eloquent.creating: '.ProjectActivity::class;
Event::listen($event, fn () => false);
try {
    try {
        app(SubmitDeliverable::class)->submit($project->id, null, ['title' => 'Must roll back', 'milestone_id' => null], UploadedFile::fake()->createWithContent('rollback.txt', 'Must not survive.'), (string) Str::uuid());
        throw new RuntimeException('Injected failure was ignored');
    } catch (RuntimeException $exception) {
        check($exception->getMessage() === 'Project activity could not be recorded.', 'Unexpected injected failure');
    }
} finally {
    Event::forget($event);
}
check($beforeFiles === Storage::disk('deliverables')->allFiles() && $beforeVersions === DeliverableVersion::count() && $beforeEvents === DB::table('project_activities')->count() && $beforeNotifications === DB::table('notifications')->count(), 'Late rollback did not preserve database/files');
echo "Late MySQL transaction failure rolled back business rows, activity, notifications and stored file.\n";

// Global database uniqueness and private-file bijection, including rejected uploads.
check(DB::select('SELECT project_id,event_key,COUNT(*) n FROM project_activities GROUP BY project_id,event_key HAVING n>1') === [], 'Duplicate activity');
check(DB::select('SELECT notifiable_type,notifiable_id,event_key,COUNT(*) n FROM notifications GROUP BY notifiable_type,notifiable_id,event_key HAVING n>1') === [], 'Duplicate notifications');
$files = Storage::disk('deliverables')->allFiles();
$paths = DeliverableVersion::pluck('storage_path')->all();
sort($files);
sort($paths);
check($files === $paths, 'Orphaned or missing private files');
echo "All repeated MySQL scenarios passed; no orphan files.\n";
