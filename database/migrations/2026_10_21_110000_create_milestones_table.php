<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_assignment_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->index()->constrained('users')->restrictOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->date('due_date');
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['project_assignment_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestones');
    }
};
