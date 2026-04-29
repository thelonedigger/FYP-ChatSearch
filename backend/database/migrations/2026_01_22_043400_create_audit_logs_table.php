<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('audit_id')->unique();
            $table->string('action', 100)->index(); // 'document.created', 'search.performed', etc.
            $table->string('action_category', 50)->index(); // 'data_access', 'data_modification', 'system', 'authentication'
            $table->string('entity_type', 100)->nullable()->index();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('actor_type', 50)->default('system'); // 'user', 'system', 'api', 'job'
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('session_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_method', 10)->nullable();
            $table->text('request_path')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 20)->default('success'); // 'success', 'failure', 'denied'
            $table->text('failure_reason')->nullable();
            $table->boolean('contains_pii')->default(false);
            $table->boolean('data_exported')->default(false);
            $table->timestamp('retention_expires_at')->nullable()->index();
            
            $table->timestamp('performed_at')->useCurrent();
            $table->timestamps();
            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_type', 'actor_id']);
            $table->index(['action', 'performed_at']);
            $table->index(['action_category', 'performed_at']);
            $table->index('performed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};