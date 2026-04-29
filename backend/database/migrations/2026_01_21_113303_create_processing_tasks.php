<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('task_id')->unique();
            $table->string('filepath');
            $table->string('filename');
            $table->string('status')->default('pending'); // pending, queued, processing, completed, failed, cancelled
            $table->string('current_stage')->nullable();
            $table->json('stages');
            $table->json('options')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->string('error_stage')->nullable();
            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(3);
            $table->integer('progress_percent')->default(0);
            $table->bigInteger('file_size')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
            $table->index(['status', 'created_at']);
        });

        Schema::create('processing_task_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processing_task_id')->constrained()->cascadeOnDelete();
            $table->string('stage');
            $table->string('level')->default('info'); // info, warning, error, debug
            $table->text('message');
            $table->json('context')->nullable();
            $table->float('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['processing_task_id', 'stage']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_task_logs');
        Schema::dropIfExists('processing_tasks');
    }
};