<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_assignment_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedBigInteger('milestone_id')->nullable();
            $table->foreignId('created_by')->index()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['project_assignment_id', 'id'], 'deliverables_assignment_id_index');
            $table->index(['milestone_id', 'project_assignment_id'], 'deliverables_milestone_assignment_index');
            $table->foreign(['milestone_id', 'project_assignment_id'], 'deliverables_milestone_assignment_foreign')
                ->references(['id', 'project_assignment_id'])->on('milestones')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('deliverable_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliverable_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedInteger('version_number');
            $table->foreignId('uploaded_by')->index()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('storage_path')->unique();
            $table->string('original_filename');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->text('submission_note')->nullable();
            $table->timestamp('submitted_at')->comment('UTC submission time');
            $table->uuid('submission_id')->unique();
            $table->timestamps();
            $table->unique(['deliverable_id', 'version_number'], 'deliverable_versions_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverable_versions');
        Schema::dropIfExists('deliverables');
    }
};
