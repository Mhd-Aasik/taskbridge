<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_assignment_id')->unique()->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->decimal('amount', 12, 2)->comment('Full agreed amount, LKR');
            $table->char('currency', 3);
            $table->string('method', 32);
            $table->date('paid_on');
            $table->string('reference', 150)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('reported_by')->index()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('reported_at')->comment('UTC Client reporting time');
            $table->uuid('submission_id')->unique();
            $table->timestamps();
        });
        Schema::create('payment_acknowledgments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_report_id')->unique()->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('acknowledged_by')->index()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('acknowledged_at')->comment('UTC Freelancer acknowledgment time');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_acknowledgments');
        Schema::dropIfExists('payment_reports');
    }
};
