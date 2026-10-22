<?php

use App\Enums\ProjectActivityEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_assignments', function (Blueprint $table) {
            $table->unique(['id', 'project_id'], 'assignments_activity_project_unique');
        });
        Schema::table('milestones', function (Blueprint $table) {
            // A durable edit identity, including when two changes share a timestamp.
            $table->unsignedInteger('details_version')->default(0);
        });
        Schema::create('project_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('project_assignment_id')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->enum('event_type', array_column(ProjectActivityEvent::cases(), 'value'));
            $table->string('event_key', 120);
            $table->json('metadata');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->foreign(['project_assignment_id', 'project_id'], 'activity_assignment_project_foreign')
                ->references(['id', 'project_id'])->on('project_assignments')->restrictOnDelete();
            $table->unique(['project_id', 'event_key'], 'project_activity_event_unique');
            $table->index(['project_id', 'occurred_at', 'id'], 'project_activity_order_index');
            $table->index(['project_assignment_id', 'occurred_at', 'id'], 'assignment_activity_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_activities');
        Schema::table('milestones', fn (Blueprint $table) => $table->dropColumn('details_version'));
        Schema::table('project_assignments', fn (Blueprint $table) => $table->dropUnique('assignments_activity_project_unique'));
    }
};
