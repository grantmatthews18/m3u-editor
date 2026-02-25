<?php

namespace Tests\Feature;

use App\Console\Commands\RunRegexSync;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Settings\GeneralSettings;
use App\Services\EpgCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RegexSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    protected GeneralSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = app(GeneralSettings::class);

        // avoid dispatching jobs to Redis during tests
        \Illuminate\Support\Facades\Queue::fake();
    }

    public function test_command_does_nothing_when_schedule_not_due()
    {
        $this->settings->regex_sync_schedule = '0 0 1 1 *'; // unlikely to be due

        $custom = CustomPlaylist::factory()->create([
            'use_regex_channel_management' => true,
            'event_patterns' => [
                ['group' => 'A', 'pattern' => '/foo/', 'disable_if_empty' => true],
            ],
        ]);

        $group = Group::factory()->create(['name' => 'A', 'user_id' => $custom->user_id]);
        $channel = Channel::factory()->create([
            'custom_playlist_id' => $custom->id,
            'user_id' => $custom->user_id,
            'group_id' => $group->id,
            'group' => $group->name,
            'enabled' => false,
            'is_vod' => false,
            'name' => 'foo',
        ]);
        $custom->channels()->syncWithoutDetaching($channel->id);

        $this->mock(EpgCacheService::class, function ($mock) {
            $mock->shouldNotReceive('clearPlaylistEpgCacheFile');
        });

        Artisan::call('app:run-regex-sync');
        $channel->refresh();
        $this->assertFalse($channel->enabled, 'Channel should remain disabled when schedule not due');
    }

    public function test_command_recalculates_and_clears_cache_when_due()
    {
        $this->settings->regex_sync_schedule = '* * * * *';

        $custom = CustomPlaylist::factory()->create([
            'use_regex_channel_management' => true,
            'event_patterns' => [
                ['group' => 'A', 'pattern' => '/foo/', 'disable_if_empty' => true],
            ],
        ]);

        $group = Group::factory()->create(['name' => 'A', 'user_id' => $custom->user_id]);
        $channel = Channel::factory()->create([
            'custom_playlist_id' => $custom->id,
            'user_id' => $custom->user_id,
            'group_id' => $group->id,
            'group' => $group->name,
            'enabled' => false,
            'is_vod' => false,
            'name' => 'foo',
        ]);
        $custom->channels()->syncWithoutDetaching($channel->id);

        $this->mock(EpgCacheService::class, function ($mock) {
            $mock->shouldReceive('clearPlaylistEpgCacheFile')->once();
        });

        Artisan::call('app:run-regex-sync');
        $channel->refresh();
        $this->assertTrue($channel->enabled, 'Channel should be enabled after regex sync');
    }
}
