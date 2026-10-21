<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['projects', 'milestones'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('completed_at')->nullable()->comment('UTC explicit Client completion time');
                $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
                $table->text('completion_note')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['milestones', 'projects'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('completed_by');
                $table->dropColumn(['completed_at', 'completion_note']);
            });
        }
    }
};
