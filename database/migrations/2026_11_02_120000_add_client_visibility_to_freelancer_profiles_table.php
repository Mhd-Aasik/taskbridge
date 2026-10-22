<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelancer_profiles', function (Blueprint $table) {
            $table->boolean('share_account_photo')->default(false);
            $table->timestamp('first_published_at')->nullable();
            $table->unsignedBigInteger('profile_version')->default(0);
            $table->index(['is_public', 'first_published_at', 'id'], 'profiles_client_discovery_index');
        });
    }

    public function down(): void
    {
        Schema::table('freelancer_profiles', function (Blueprint $table) {
            $table->dropIndex('profiles_client_discovery_index');
            $table->dropColumn(['share_account_photo', 'first_published_at', 'profile_version']);
        });
    }
};
