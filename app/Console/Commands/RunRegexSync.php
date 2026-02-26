<?php

namespace App\Console\Commands;

use App\Models\CustomPlaylist;
use App\Services\EpgCacheService;
use App\Settings\GeneralSettings;
use Cron\CronExpression;
use Illuminate\Console\Command;

class RunRegexSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:run-regex-sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate regex patterns and clear any EPG cache according to the configured schedule';

    /**
     * Execute the console command.
     */
    public function handle(GeneralSettings $settings, EpgCacheService $cache): void
    {
        $this->info('Running regex sync for playlists with regex channel management');

        /** @var \Illuminate\Support\Collection|CustomPlaylist[] $playlists */
        $playlists = CustomPlaylist::where('use_regex_channel_management', true)->get();
        foreach ($playlists as $playlist) {
            $cron = $playlist->regex_sync_schedule;
            if (empty($cron) || ! CronExpression::isValidExpression($cron)) {
                continue;
            }

            if (! (new CronExpression($cron))->isDue()) {
                continue;
            }

            foreach ($playlist->channels as $channel) {
                $playlist->applyEventPattern($channel);
            }

            // clear any EPG cache associated with this playlist so that clients
            // fetch fresh data next time.
            $cache->clearPlaylistEpgCacheFile($playlist);
        }
    }
}
