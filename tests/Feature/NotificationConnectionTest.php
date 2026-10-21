<?php

namespace Tests\Feature;

use App\Enums\NotificationEvent;
use App\Models\Proposal;
use App\Services\ProjectNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class NotificationConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_requires_and_uses_the_originating_database_transaction_and_connection(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $origin = Proposal::factory()->create();
        Config::set('database.connections.notification_alternate', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
        try {
            Artisan::call('migrate', ['--database' => 'notification_alternate', '--force' => true]);
            $other = DB::connection('notification_alternate');
            foreach (['users', 'categories', 'projects', 'proposals'] as $table) {
                $other->table($table)->insert(DB::table($table)->get()->map(fn ($row) => (array) $row)->all());
            }
            $copy = Proposal::on('notification_alternate')->findOrFail($origin->id);
            try {
                app(ProjectNotifications::class)->record(NotificationEvent::ProposalSubmitted, $copy);
                $this->fail('An origin transaction is mandatory.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('originating domain transaction', $exception->getMessage());
            }
            try {
                $other->transaction(function () use ($copy): void {
                    app(ProjectNotifications::class)->record(NotificationEvent::ProposalSubmitted, $copy);
                    throw new RuntimeException('Abort non-default origin');
                });
            } catch (RuntimeException $exception) {
                $this->assertSame('Abort non-default origin', $exception->getMessage());
            }
            $this->assertSame(0, $other->table('notifications')->count());
            $alerts = $other->transaction(fn () => app(ProjectNotifications::class)->record(NotificationEvent::ProposalSubmitted, $copy));
            $this->assertSame('notification_alternate', $alerts[0]->getConnectionName());
            $this->assertSame(1, $other->table('notifications')->count());
            $this->assertDatabaseCount('notifications', 0);
        } finally {
            DB::purge('notification_alternate');
        }
    }
}
