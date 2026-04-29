<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_retention_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('entity_type')->index(); // 'document', 'audit_log', 'search_metric', etc.
            $table->text('description')->nullable();
            $table->integer('retention_days'); // How long to keep data
            $table->string('retention_action')->default('delete'); // 'delete', 'anonymize', 'archive'
            $table->json('conditions')->nullable(); // Additional conditions for policy application
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0); // Higher priority policies take precedence
            $table->string('legal_basis')->nullable(); // 'consent', 'contract', 'legal_obligation', 'legitimate_interest'
            $table->string('compliance_framework')->nullable(); // 'GDPR', 'CCPA', 'HIPAA', etc.
            $table->timestamp('last_executed_at')->nullable();
            $table->integer('last_affected_count')->default(0);
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_retention_policies');
    }
};