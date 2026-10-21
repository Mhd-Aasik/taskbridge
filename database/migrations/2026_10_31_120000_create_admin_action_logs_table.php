<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            // A generic subject identity keeps this log reusable for later Admin actions.
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->string('action', 80);
            $table->json('before_values');
            $table->json('after_values');
            $table->timestamp('occurred_at')->comment('UTC occurrence time');
            $table->uuid('action_id')->unique();
            $table->char('request_hash', 64);
            $table->timestamps();
            $table->index(['subject_type', 'subject_id', 'action', 'id'], 'admin_action_logs_subject_action_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_action_logs');
    }
};
