<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_metrics', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('session_id');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('result_interactions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('session_id');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['user_id', 'created_at']);
        });
        DB::statement("
            UPDATE search_metrics sm
            SET user_id = al.actor_id
            FROM (
                SELECT DISTINCT session_id, actor_id
                FROM audit_logs
                WHERE actor_type = 'user' AND actor_id IS NOT NULL
            ) al
            WHERE sm.session_id = al.session_id
              AND sm.user_id IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('search_metrics', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropColumn('user_id');
        });

        Schema::table('result_interactions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropColumn('user_id');
        });
    }
};