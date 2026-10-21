<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_assignment_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('title', 150);
            $table->text('reason');
            $table->timestamp('opened_at')->comment('UTC opening time');
            $table->uuid('submission_id')->unique();
            $table->timestamps();
            $table->unique(['id', 'project_assignment_id']);
            $table->index(['project_assignment_id', 'opened_at', 'id']);
        });
        Schema::create('active_disputes', function (Blueprint $table) {
            $table->foreignId('project_assignment_id')->primary()->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('dispute_case_id')->unique();
            $table->foreign(['dispute_case_id', 'project_assignment_id'], 'active_disputes_case_assignment_foreign')
                ->references(['id', 'project_assignment_id'])->on('dispute_cases')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('dispute_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_case_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('kind', 32);
            $table->text('body');
            $table->timestamp('submitted_at')->comment('UTC statement time');
            $table->uuid('submission_id')->unique();
            $table->timestamps();
            $table->index(['dispute_case_id', 'submitted_at', 'id']);
        });
        Schema::create('dispute_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_case_id')->unique()->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('decision', 32);
            $table->text('rationale');
            $table->timestamp('decided_at')->comment('UTC Admin decision time');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_resolutions');
        Schema::dropIfExists('dispute_statements');
        Schema::dropIfExists('active_disputes');
        Schema::dropIfExists('dispute_cases');
    }
};
