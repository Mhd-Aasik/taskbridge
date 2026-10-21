<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_assignment_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->text('body');
            $table->timestamp('sent_at')->comment('UTC sending time');
            $table->uuid('submission_id')->unique();
            $table->timestamps();
            $table->unique(['id', 'project_assignment_id']);
            $table->index(['project_assignment_id', 'id']);
            $table->index(['sender_id', 'id']);
        });
        Schema::create('project_read_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_assignment_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('user_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('last_read_message_id');
            $table->timestamps();
            $table->unique(['project_assignment_id', 'user_id']);
            $table->foreign(['last_read_message_id', 'project_assignment_id'], 'project_read_cursors_message_assignment_foreign')
                ->references(['id', 'project_assignment_id'])->on('project_messages')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_read_cursors');
        Schema::dropIfExists('project_messages');
    }
};
