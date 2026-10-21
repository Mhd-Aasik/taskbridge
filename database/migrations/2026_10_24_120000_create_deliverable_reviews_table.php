<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliverable_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliverable_version_id')->unique()->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('reviewed_by')->index()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('decision', 32);
            $table->text('remarks')->nullable();
            $table->timestamp('reviewed_at')->comment('UTC review time');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverable_reviews');
    }
};
