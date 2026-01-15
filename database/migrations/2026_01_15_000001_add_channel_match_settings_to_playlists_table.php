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
        Schema::table('playlists', function (Blueprint $table) {
            // Channel match strategies - JSON array of strategies to use in order of priority
            // Example: ['stream_id', 'name_group'] means try stream_id first, then name_group
            $table->json('channel_match_strategies')->nullable()->after('auto_merge_config');

            // Whether to preserve custom playlist associations when channels are removed and re-added
            $table->boolean('preserve_custom_playlist_associations')->default(true)->after('channel_match_strategies');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('channel_match_strategies');
            $table->dropColumn('preserve_custom_playlist_associations');
        });
    }
};
