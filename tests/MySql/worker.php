<?php

use App\Actions\Accounts\ChangeAccountPassword;
use App\Actions\Deliverables\ReviewDeliverableVersion;
use App\Actions\Deliverables\SubmitDeliverable;
use App\Actions\Projects\CompleteProject;
use App\Actions\Proposals\AcceptProposal;
use App\Actions\Proposals\SubmitProposal;
use App\Actions\Tasks\SaveProjectTask;
use App\Models\User;
use App\Support\AccountAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/environment.php';
[$app, $config] = verificationApplication();
$job = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
Auth::setUser(User::findOrFail($job['actor']));
session()->start();
request()->setLaravelSession(session()->driver());
session()->put(AccountAccess::SESSION_KEY, ['id' => Auth::id(), 'version' => Auth::user()->access_version]);
$once = false;
DB::connection()->beforeExecuting(function ($query) use (&$once, $job): void {
    if ($once || stripos($query, 'for update') === false) {
        return;
    }
    $once = true;
    file_put_contents($job['prefix'].'.ready', 'ready');
    $until = microtime(true) + 20;
    while (! is_file($job['gate'])) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Barrier timeout');
        }
        usleep(10000);
    }
});
try {
    $result = match ($job['operation']) {
        'accept' => app(AcceptProposal::class)->accept($job['project'], $job['proposal'], true)['assignment'],
        'submit' => app(SubmitProposal::class)->submit($job['project'], ['cover_letter' => 'Committed concurrent offer', 'proposed_budget' => '12345.67', 'proposed_deadline' => date('Y-m-d', strtotime('+10 days'))])['proposal'],
        'upload' => app(SubmitDeliverable::class)->submit($job['project'], $job['deliverable'] ?? null, ['title' => 'Concurrent work', 'milestone_id' => null], UploadedFile::fake()->createWithContent('work.txt', 'Concurrent immutable work.'), $job['uuid']),
        'review' => app(ReviewDeliverableVersion::class)->approve($job['project'], $job['deliverable'], $job['version'], ['confirmed' => true]),
        'complete' => app(CompleteProject::class)->complete($job['project'], ['confirmed' => true]),
        'task' => app(SaveProjectTask::class)->save($job['project'], null, ['title' => 'Unfinished work', 'description' => null, 'milestone_id' => null, 'due_date' => date('Y-m-d', strtotime('+5 days'))]),
        'reset' => app(ChangeAccountPassword::class)->reset($job['credentials']),
        'password' => app(ChangeAccountPassword::class)->update('password', 'changed-secret'),
        default => throw new RuntimeException('Unknown worker operation'),
    };
    $outcome = ['kind' => 'ok', 'id' => $result instanceof Model ? $result->id : $result];
} catch (ValidationException $exception) {
    $outcome = ['kind' => 'validation', 'fields' => array_keys($exception->errors())];
} catch (AuthorizationException $exception) {
    $outcome = ['kind' => 'http', 'status' => $exception->status() ?? 403];
} catch (HttpException $exception) {
    $outcome = ['kind' => 'http', 'status' => $exception->getStatusCode()];
} catch (Throwable $exception) {
    $outcome = ['kind' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
}
file_put_contents($job['prefix'].'.result', json_encode($outcome, JSON_THROW_ON_ERROR));
