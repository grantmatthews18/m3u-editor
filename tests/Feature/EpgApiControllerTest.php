<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpgApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Playlist $playlist;

    protected function setUp(): void
    {
        parent::setUp();

        // add new migration columns in case the testing database was created before
        // the migration files were added for either playlist table
        foreach (['playlists', 'custom_playlists'] as $tableName) {
            if (! \Illuminate\Support\Facades\Schema::hasColumn($tableName, 'dummy_epg_category')) {
                \Illuminate\Support\Facades\Schema::table($tableName, function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->boolean('dummy_epg_category')->default(false);
                });
            }
            if (! \Illuminate\Support\Facades\Schema::hasColumn($tableName, 'use_regex_channel_management')) {
                \Illuminate\Support\Facades\Schema::table($tableName, function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->boolean('use_regex_channel_management')->default(false);
                });
            }
            if (! \Illuminate\Support\Facades\Schema::hasColumn($tableName, 'event_patterns')) {
                \Illuminate\Support\Facades\Schema::table($tableName, function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->json('event_patterns')->nullable()->default(json_encode([]))->after('dummy_epg_category');
                });
            }
        }

        // ensure the application has an encryption key for Filament
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        // prevent jobs from trying to hit Redis
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Event::fake();

        $this->user = User::factory()->create();
        $this->playlist = Playlist::factory()->for($this->user)->create([
            'dummy_epg' => true,
            'dummy_epg_length' => 120,
            'dummy_epg_category' => true,
        ]);
    }

    public function test_can_get_epg_data_for_playlist_without_epg_mapping()
    {
        // Create a group
        $group = Group::factory()->create([
            'name' => 'Test Group',
            'user_id' => $this->user->id,
        ]);

        // Create channels without EPG mapping (dummy EPG should be generated)
        // Explicitly set channel field to predictable values
        $channels = collect();
        for ($i = 1; $i <= 3; $i++) {
            $channels->push(Channel::factory()->create([
                'playlist_id' => $this->playlist->id,
                'user_id' => $this->user->id,
                'group_id' => $group->id,
                'group' => 'Test Group', // Also set the string group field for dummy EPG category
                'enabled' => true,
                'is_vod' => false,
                'channel' => 100 + $i, // Predictable channel numbers
            ]));
        }

        $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");

        $response->assertSuccessful()
            ->assertJsonStructure([
                'playlist' => ['id', 'name', 'uuid', 'type'],
                'date_range' => ['start', 'end'],
                'pagination',
                'channels',
                'programmes',
                'cache_info',
            ]);

        // Verify dummy EPG programmes were generated for channels without EPG
        $data = $response->json();
        $this->assertNotEmpty($data['programmes'], 'Dummy EPG programmes should be generated');

        // Check that programmes were generated for each channel
        foreach ($channels as $channel) {
            $channel->refresh(); // Refresh to get latest data
            $channelId = $channel->channel ?: $channel->id;
            $this->assertArrayHasKey($channelId, $data['programmes'], "Channel {$channelId} should have programmes");

            $programmes = $data['programmes'][$channelId];
            $this->assertNotEmpty($programmes, 'Programmes should not be empty');

            // Verify programme structure
            $firstProgramme = $programmes[0];
            $this->assertArrayHasKey('start', $firstProgramme);
            $this->assertArrayHasKey('stop', $firstProgramme);
            $this->assertArrayHasKey('title', $firstProgramme);
            $this->assertArrayHasKey('desc', $firstProgramme);
            $this->assertArrayHasKey('icon', $firstProgramme);

            // Verify category is included when enabled
            $this->assertArrayHasKey('category', $firstProgramme);
            $this->assertEquals($group->name, $firstProgramme['category']);

            // Verify programme length is correct (120 minutes)
            $start = Carbon::parse($firstProgramme['start']);
            $stop = Carbon::parse($firstProgramme['stop']);
            $this->assertEquals(120, $start->diffInMinutes($stop));
        }
    }

    public function test_dummy_epg_respects_date_range()
    {
        // Create a channel without EPG mapping
        $channel = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'enabled' => true,
            'is_vod' => false,
            'channel' => 998, // Explicit channel number
        ]);

        $startDate = Carbon::now()->format('Y-m-d');
        $endDate = Carbon::now()->addDay()->format('Y-m-d');

        $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data?start_date={$startDate}&end_date={$endDate}");

        $response->assertSuccessful();

        $data = $response->json();
        $channel->refresh(); // Refresh to get latest data
        $channelId = $channel->channel ?: $channel->id;
        $programmes = $data['programmes'][$channelId] ?? [];

        $this->assertNotEmpty($programmes);

        // Verify all programmes fall within the requested date range
        $rangeStart = Carbon::parse($startDate)->startOfDay();
        $rangeEnd = Carbon::parse($endDate)->endOfDay();

        foreach ($programmes as $programme) {
            $programmeStart = Carbon::parse($programme['start']);
            $this->assertGreaterThanOrEqual($rangeStart, $programmeStart);
            $this->assertLessThan($rangeEnd, $programmeStart);
        }
    }

    public function test_dummy_epg_not_generated_when_disabled()
    {
        // Disable dummy EPG
        $this->playlist->update(['dummy_epg' => false]);

        // Create a channel without EPG mapping
        $channel = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'enabled' => true,
            'is_vod' => false,
            'channel' => 997, // Explicit channel number
        ]);

        $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");

        $response->assertSuccessful();

        $data = $response->json();
        $channel->refresh(); // Refresh to get latest data
        $channelId = $channel->channel ?: $channel->id;

        // Programmes should be empty or not include the channel without EPG
        $this->assertEmpty($data['programmes'][$channelId] ?? []);
    }

    public function test_dummy_epg_category_can_be_disabled()
    {
        // Disable category in dummy EPG
        $this->playlist->update(['dummy_epg_category' => false]);

        // Create a group and channel
        $group = Group::factory()->create([
            'name' => 'Test Group',
            'user_id' => $this->user->id,
        ]);

        $channel = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'group_id' => $group->id,
            'enabled' => true,
            'is_vod' => false,
            'channel' => 996, // Explicit channel number
        ]);

        $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");

        $response->assertSuccessful();

        $data = $response->json();
        $channel->refresh(); // Refresh to get latest data
        $channelId = $channel->channel ?: $channel->id;
        $programmes = $data['programmes'][$channelId] ?? [];

        $this->assertNotEmpty($programmes);

        // Verify category is not included
        $firstProgramme = $programmes[0];
        $this->assertArrayNotHasKey('category', $firstProgramme);
    }

    public function test_mixed_epg_and_dummy_epg_channels()
    {
        // Create a group for both channels
        $group = Group::factory()->create([
            'user_id' => $this->user->id,
        ]);

        // Create an EPG
        $epg = Epg::factory()->create([
            'user_id' => $this->user->id,
            'is_cached' => true,
        ]);

        // Create EPG channel
        $epgChannel = EpgChannel::factory()->create([
            'epg_id' => $epg->id,
            'channel_id' => 'test-channel-1',
            'user_id' => $this->user->id,
        ]);

        // Create a channel with EPG mapping
        // Set explicit sort values to ensure deterministic ordering
        $channelWithEpg = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'group_id' => $group->id,
            'enabled' => true,
            'is_vod' => false,
            'epg_channel_id' => $epgChannel->id,
            'sort' => 1,
            'channel' => 1,
            'title' => 'Channel A',
        ]);

        // Create a channel without EPG mapping (should get dummy EPG)
        // Set explicit sort values to ensure deterministic ordering
        $channelWithoutEpg = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'group_id' => $group->id,
            'enabled' => true,
            'is_vod' => false,
            'sort' => 2,
            'channel' => 2,
            'title' => 'Channel B',
        ]);

        $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");

        $response->assertSuccessful();

        $data = $response->json();

        // Both channels should be in the response
        $this->assertCount(2, $data['channels']);

        // Channel without EPG should have dummy programmes
        $channelWithoutEpg->refresh(); // Refresh to get latest data
        $channelId = $channelWithoutEpg->channel ?? $channelWithoutEpg->id;
        $this->assertArrayHasKey($channelId, $data['programmes']);
        $this->assertNotEmpty($data['programmes'][$channelId]);
    }

    public function test_dummy_epg_respects_pagination()
    {
        // Create multiple channels without EPG mapping with unique channel numbers
        $channels = collect();
        for ($i = 1; $i <= 5; $i++) {
            $channels->push(Channel::factory()->create([
                'playlist_id' => $this->playlist->id,
                'user_id' => $this->user->id,
                'enabled' => true,
                'is_vod' => false,
                'channel' => 900 + $i, // Explicit unique channel numbers
            ]));
        }

        // Request first page with 2 items per page
        $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data?per_page=2&page=1");

        $response->assertSuccessful();

        $data = $response->json();

        // Should only have 2 channels on this page
        $this->assertCount(2, $data['channels']);
        $this->assertEquals(2, $data['pagination']['returned_channels']);
        $this->assertEquals(5, $data['pagination']['total_channels']);

        // Verify programmes are only generated for paginated channels
        $this->assertCount(2, $data['programmes']);
    }

    public function test_dummy_epg_with_custom_length()
    {
        // Set custom EPG length to 60 minutes
        $this->playlist->update(['dummy_epg_length' => 60]);

        $channel = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'enabled' => true,
            'is_vod' => false,
            'channel' => 999, // Explicit channel number to avoid collisions
        ]);

        $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");

        $response->assertSuccessful();

        $data = $response->json();
        $channel->refresh(); // Refresh to get latest data
        // Use same logic as controller: falsy check with ?: not null-coalescing ??
        $channelId = $channel->channel ?: $channel->id;
        $programmes = $data['programmes'][$channelId] ?? [];

        $this->assertNotEmpty($programmes);

        // Verify programme length is 60 minutes
        $firstProgramme = $programmes[0];
        $start = Carbon::parse($firstProgramme['start']);
        $stop = Carbon::parse($firstProgramme['stop']);
        $this->assertEquals(60, $start->diffInMinutes($stop));
    }

    public function test_event_pattern_parses_event_and_times_and_renames_channel()
    {
        // create a custom playlist for regex testing
        $custom = \App\Models\CustomPlaylist::factory()->for($this->user)->create([
            'dummy_epg' => true,
            'dummy_epg_length' => 120,
            'dummy_epg_category' => true,
        ]);

        // Create a group and a channel belonging to it
        $group = Group::factory()->create([
            'name' => 'Sports',
            'user_id' => $this->user->id,
        ]);

        $channel = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'custom_playlist_id' => $custom->id,
            'user_id' => $this->user->id,
            'group_id' => $group->id,
            'group' => $group->name,
            'enabled' => true,
            'is_vod' => false,
            'name' => 'US (ESPN+ 001) | Fairways of Life with Matt Adams Feb 25 9:00AM ET (2026-02-25 09:00:00)',
        ]);
        // ensure the pivot entry exists so the custom playlist sees this channel
        $custom->channels()->syncWithoutDetaching($channel->id);
        $this->assertEquals(1, $custom->channels()->count(), 'Custom playlist should have one channel after attaching');
        $this->assertDatabaseHas('channel_custom_playlist', [
            'custom_playlist_id' => $custom->id,
            'channel_id' => $channel->id,
        ]);

        // verify that the playlist query itself picks up the channel
        $countFromQuery = \App\Http\Controllers\PlaylistGenerateController::getChannelQuery($custom)->count();
        $this->assertEquals(1, $countFromQuery, 'Channel query should return 1 channel for custom playlist');

        // also ensure the cursor version (used by API) returns the same
        $cursorCount = iterator_count(\App\Http\Controllers\PlaylistGenerateController::getChannelQuery($custom)
            ->limit(50)
            ->offset(0)
            ->cursor());
        $this->assertEquals(1, $cursorCount, 'Cursor query should return 1 channel for custom playlist');

        // Configure the custom playlist event pattern for this group
        $custom->update([
            'use_regex_channel_management' => true,
            'event_patterns' => [
                [
                    'group' => 'Sports',
                    'pattern' => '/\|\s*(?P<event>.+?)\s*\((?P<start>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\)\s*$/',
                    'timezone' => 'UTC',
                    'default_length' => 60,
                    'disable_if_empty' => true,
                ],
            ],
        ]);

        // sanity check that the helper can find and apply the pattern directly
        // (avoid persisting the change here since the API call will run the matcher again)
        // $this->assertNotNull($custom->applyEventPattern($channel));

        $response = $this->getJson("/api/epg/playlist/{$custom->uuid}/data");
        $response->assertSuccessful();
        $data = $response->json();

        $channel->refresh();
        // event parsed from name should be everything between the pipe and the date
        $expectedEvent = 'Fairways of Life with Matt Adams Feb 25 9:00AM ET';
        $this->assertEquals($expectedEvent, $channel->title_custom);

        // instead of assuming key matches channel id, just pick the first programmes list
        $this->assertCount(1, $data['programmes']);

        $programmesList = array_values($data['programmes'])[0];
        $this->assertNotEmpty($programmesList);
        $firstProgramme = $programmesList[0];

        $this->assertEquals($expectedEvent, $firstProgramme['title']);
        $this->assertEquals($expectedEvent, $firstProgramme['desc']);

        // verify duration is 60 minutes
        $start = Carbon::parse($firstProgramme['start']);
        $stop = Carbon::parse($firstProgramme['stop']);
        $this->assertEquals(60, $start->diffInMinutes($stop));
    }

    public function test_event_pattern_disables_channel_when_no_match()
    {
        $custom = \App\Models\CustomPlaylist::factory()->for($this->user)->create([
            'dummy_epg' => true,
            'dummy_epg_length' => 120,
            'dummy_epg_category' => true,
        ]);

        $group = Group::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Sports',
        ]);

        $channel = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'custom_playlist_id' => $custom->id,
            'user_id' => $this->user->id,
            'group_id' => $group->id,
            'group' => $group->name,
            'enabled' => true,
            'is_vod' => false,
            'name' => 'No idea what this is',
        ]);
        $custom->channels()->syncWithoutDetaching($channel->id);
        $this->assertEquals(1, $custom->channels()->count(), 'Custom playlist should have one channel after attaching');
        $this->assertDatabaseHas('channel_custom_playlist', [
            'custom_playlist_id' => $custom->id,
            'channel_id' => $channel->id,
        ]);

        $custom->update([
            'use_regex_channel_management' => true,
            'event_patterns' => [
                [
                    'group' => 'Sports',
                    'pattern' => '/foo/',
                    'timezone' => 'UTC',
                    'default_length' => 60,
                    'disable_if_empty' => true,
                ],
            ],
        ]);

        // manually apply the pattern so the channel gets disabled
        $this->assertNull($custom->applyEventPattern($channel));
        $channel->refresh();
        $this->assertFalse($channel->enabled);
    }

    public function test_pattern_only_disables_non_matching_channels()
    {
        // set up a simple playlist with two channels
        $this->playlist->update([
            'dummy_epg' => false, // not needed for this test
        ]);

        $group = Group::factory()->create([
            'name' => 'Test',
            'user_id' => $this->user->id,
        ]);

        $matching = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'group_id' => $group->id,
            'group' => $group->name,
            'enabled' => true,
            'is_vod' => false,
            // include parentheses so the regex will actually match
            'name' => 'foo | Event (2026-02-25 09:00:00)',
        ]);

        $nonmatch = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'group_id' => $group->id,
            'group' => $group->name,
            'enabled' => true,
            'is_vod' => false,
            'name' => 'no date here',
        ]);

        $this->playlist->update([
            'use_regex_channel_management' => true,
            'event_patterns' => [
                [
                    'group' => 'Test',
                    'pattern' => '/\|\s*(?P<event>[^|]+)\s*\((?P<start>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\)\s*$/',
                    'timezone' => 'UTC',
                    'default_length' => 10,
                    'disable_if_empty' => true,
                ],
            ],
        ]);

        // apply to both channels
        $this->assertNotNull($this->playlist->applyEventPattern($matching));
        $this->assertNull($this->playlist->applyEventPattern($nonmatch));

        $matching->refresh();
        $nonmatch->refresh();

        $this->assertTrue($matching->enabled);
        $this->assertFalse($nonmatch->enabled);

        // now make the second channel match as well and re-run
        $nonmatch->update(['name' => 'bar | Event (2026-02-25 10:00:00)', 'enabled' => false]);
        $this->assertNotNull($this->playlist->applyEventPattern($nonmatch));
        $nonmatch->refresh();
        $this->assertTrue($nonmatch->enabled);
    }

    public function test_manual_channel_management_ignores_patterns()
    {
        // ensure regex management is off
        $this->playlist->update(['use_regex_channel_management' => false]);

        $group = Group::factory()->create([
            'name' => 'Sports',
            'user_id' => $this->user->id,
        ]);

        $channel = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'group_id' => $group->id,
            'group' => $group->name,
            'enabled' => true,
            'is_vod' => false,
            'name' => 'US (ESPN+ 001) | Some show (2026-02-25 09:00:00)',
        ]);

        // write a valid pattern even though it shouldn't be used
        $this->playlist->update([
            'event_patterns' => [
                [
                    'group' => 'Sports',
                    'pattern' => '/\|\s*(?P<event>.+?)\s*\((?P<start>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\)\s*$/',
                    'timezone' => 'UTC',
                    'default_length' => 60,
                    'disable_if_empty' => true,
                ],
            ],
        ]);

        // applying pattern manually should still work, but the controller should not
        $this->assertNotNull($this->playlist->applyEventPattern($channel));
        $channel->refresh();
        $this->assertEquals('Some show', $channel->title_custom);

        // now regenerate via API: because regex management is disabled the matcher
        // should not run, so the channel should remain enabled and keep original
        // title.
        $channel->update(['title_custom' => null]);
        $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");
        $response->assertSuccessful();
        $channel->refresh();
        $this->assertNull($channel->title_custom);
        $this->assertTrue($channel->enabled);
    }
}
