<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('filepath');
            $table->text('content');
            $table->string('file_hash')->unique();
            $table->integer('total_chunks')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('file_hash');
            $table->index('filename');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};