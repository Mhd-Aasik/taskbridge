<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->index();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->text('suspension_reason')->nullable();
            $table->unsignedBigInteger('access_version')->default(0);
            $table->index(['role', 'suspended_at', 'id'], 'users_role_suspension_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['suspended_by']);
            $table->dropIndex(['suspended_at']);
            $table->dropIndex('users_role_suspension_id_index');
            $table->dropColumn(['suspended_at', 'suspended_by', 'suspension_reason', 'access_version']);
        });
    }
};
