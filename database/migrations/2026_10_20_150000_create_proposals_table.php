<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('freelancer_id')->constrained('users')->restrictOnDelete();
            $table->text('cover_letter');
            $table->decimal('proposed_budget', 12, 2)->comment('Offered fixed budget in LKR');
            $table->date('proposed_deadline');
            $table->enum('status', ['submitted', 'accepted', 'rejected', 'withdrawn'])->default('submitted');
            $table->timestamp('submitted_at')->comment('UTC submission time');
            $table->timestamps();
            $table->unique(['project_id', 'freelancer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
