<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            // SQLite requires the exact referenced column set to be unique.
            $table->unique(['id', 'project_assignment_id'], 'milestones_id_assignment_unique');
        });
        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_assignment_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('milestone_id')->nullable();
            $table->foreignId('created_by')->index()->constrained('users')->restrictOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->date('due_date');
            $table->enum('status', ['todo', 'in_progress', 'done'])->default('todo');
            $table->timestamp('completed_at')->nullable()->comment('UTC completion time');
            // Independent optimistic tokens avoid same-second and details/status conflicts.
            $table->unsignedInteger('details_version')->default(1);
            $table->unsignedInteger('status_version')->default(1);
            $table->timestamps();
            $table->index(['project_assignment_id', 'status', 'due_date', 'id'], 'tasks_assignment_status_due_index');
            $table->index(['milestone_id', 'project_assignment_id'], 'tasks_milestone_assignment_index');
            $table->foreign(['milestone_id', 'project_assignment_id'], 'tasks_milestone_assignment_foreign')
                ->references(['id', 'project_assignment_id'])->on('milestones')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tasks');
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropUnique('milestones_id_assignment_unique');
        });
    }
};
