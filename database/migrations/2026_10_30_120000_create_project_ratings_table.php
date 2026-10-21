<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite cannot add CHECK constraints after creating a table.
            DB::statement('CREATE TABLE project_ratings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_assignment_id INTEGER NOT NULL,
                reviewer_id INTEGER NOT NULL,
                reviewee_id INTEGER NOT NULL,
                rating INTEGER NOT NULL,
                feedback TEXT NULL,
                submitted_at DATETIME NOT NULL,
                submission_id VARCHAR(36) NOT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (project_assignment_id) REFERENCES project_assignments(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                FOREIGN KEY (reviewee_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
                CONSTRAINT project_ratings_score_check CHECK (typeof(rating) = \'integer\' AND rating BETWEEN 1 AND 5),
                CONSTRAINT project_ratings_not_self_check CHECK (reviewer_id <> reviewee_id)
            )');
        } else {
            Schema::create('project_ratings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_assignment_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
                $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
                $table->foreignId('reviewee_id')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
                $table->unsignedTinyInteger('rating');
                $table->text('feedback')->nullable();
                $table->timestamp('submitted_at')->comment('UTC feedback submission time');
                $table->uuid('submission_id');
                $table->timestamps();
            });
            DB::statement('ALTER TABLE project_ratings ADD CONSTRAINT project_ratings_score_check CHECK (rating BETWEEN 1 AND 5)');
            DB::statement('ALTER TABLE project_ratings ADD CONSTRAINT project_ratings_not_self_check CHECK (reviewer_id <> reviewee_id)');
        }
        Schema::table('project_ratings', function (Blueprint $table) {
            $table->unique(['project_assignment_id', 'reviewer_id'], 'project_ratings_assignment_reviewer_unique');
            $table->unique('submission_id');
            $table->index(['reviewee_id', 'project_assignment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_ratings');
    }
};
