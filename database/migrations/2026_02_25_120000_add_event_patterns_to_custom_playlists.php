<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('custom_playlists', function (Blueprint $table) {
            // JSON field to store event pattern configurations keyed by custom group name
            $table->json('event_patterns')->nullable()->default(json_encode([]))->after('dummy_epg_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn('event_patterns');
        });
    }
};
