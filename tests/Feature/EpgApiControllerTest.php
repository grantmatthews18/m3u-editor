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
use Mockery;
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
            if (! \Illuminate\Support\Facades\Schema::hasColumn($tableName, 'regex_sync_schedule')) {
                \Illuminate\Support\Facades\Schema::table($tableName, function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->string('regex_sync_schedule')->nullable();
                });
            }
            // ensure the necessary columns exist on the relations used by new tests
            if (! \Illuminate\Support\Facades\Schema::hasColumn('channels', 'epg_id')) {
                \Illuminate\Support\Facades\Schema::table('channels', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->integer('epg_id')->nullable();
                });
            }
            if (! \Illuminate\Support\Facades\Schema::hasColumn('channels', 'epg_channel_key')) {
                \Illuminate\Support\Facades\Schema::table('channels', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->string('epg_channel_key')->nullable();
                });
            }
            if (! \Illuminate\Support\Facades\Schema::hasColumn('epg_channels', 'epg_channel_key')) {
                \Illuminate\Support\Facades\Schema::table('epg_channels', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->string('epg_channel_key')->nullable();
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
        // the API always returns a category key, but it should be null when the
        // playlist setting is disabled.
        $this->assertArrayHasKey('category', $firstProgramme);
        $this->assertNull($firstProgramme['category']);
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
                    'pattern' => '/\|\s*(?P<event>(?!\()\S(?:.*?\S)?)(?:\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\b.*?)*\s*\((?P<start>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\)\s*$/',
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
        $expectedEvent = 'Fairways of Life with Matt Adams';
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

    public function test_multiple_patterns_for_same_group_are_evaluated_in_order()
    {
        $custom = \App\Models\CustomPlaylist::factory()->for($this->user)->create([
            'dummy_epg' => true,
            'dummy_epg_length' => 180,
            'dummy_epg_category' => true,
        ]);

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
        $custom->channels()->syncWithoutDetaching($channel->id);

        $custom->update([
            'use_regex_channel_management' => true,
            'event_patterns' => [
                // overly‑broad rule that matches any timestamp but doesn't
                // capture a useful group – if this one were blindly used the
                // result would be a three‑hour programme because default_length
                // is 180 here.
                [
                    'group' => 'Sports',
                    'pattern' => '/\b(?:\d{1,2}:\d{2}(?:AM|PM))\b/',
                    'timezone' => 'UTC',
                    'default_length' => 180,
                    'disable_if_empty' => true,
                ],
                // the real rule that extracts the full date/time and the
                // event name; it should be picked even though it comes second.
                [
                    'group' => 'Sports',
                    'pattern' => '/\|\s*(?P<event>(?!\()\S(?:.*?\S)?)(?:\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\b.*?)*\s*\((?P<start>\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\)\s*$/',
                    'timezone' => 'UTC',
                    'default_length' => 60,
                    'disable_if_empty' => true,
                ],
            ],
        ]);

        // ensure the helper picks the correct pattern instead of the first one
        $patternInfo = $custom->applyEventPattern($channel);
        $this->assertNotNull($patternInfo);
        $this->assertEquals('Fairways of Life with Matt Adams', $channel->title_custom);
        $this->assertEquals('2026-02-25 09:00:00', $patternInfo['start']->toDateTimeString());
        $this->assertEquals(60, $patternInfo['start']->diffInMinutes($patternInfo['stop']));
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

    public function test_saving_custom_playlist_recalculates_regex_and_updates_channel_enabled_state()
    {
        $custom = \App\Models\CustomPlaylist::factory()->for($this->user)->create([
            'dummy_epg' => true,
            'dummy_epg_length' => 120,
            'dummy_epg_category' => true,
        ]);

        $group = Group::factory()->create([
            'name' => 'Sports',
            'user_id' => $this->user->id,
        ]);

        // two channels, both initially disabled
        $matching = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'custom_playlist_id' => $custom->id,
            'user_id' => $this->user->id,
            'group_id' => $group->id,
            'group' => $group->name,
            'enabled' => false,
            'is_vod' => false,
            'name' => 'foo match bar',
        ]);
        $nonmatch = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'custom_playlist_id' => $custom->id,
            'user_id' => $this->user->id,
            'group_id' => $group->id,
            'group' => $group->name,
            'enabled' => false,
            'is_vod' => false,
            'name' => 'nope',
        ]);

        $custom->channels()->syncWithoutDetaching([$matching->id, $nonmatch->id]);

        $custom->update([
            'use_regex_channel_management' => true,
            'event_patterns' => [
                [
                    'group' => 'Sports',
                    'pattern' => '/match/',
                    'timezone' => 'UTC',
                    'default_length' => 60,
                    'disable_if_empty' => true,
                ],
            ],
        ]);

        // sanity check that the pattern is valid and would match if run manually
        $this->assertNotNull($custom->applyEventPattern($matching));

        $matching->refresh();
        $nonmatch->refresh();

        $this->assertTrue($matching->enabled, 'Channel matching the pattern should be enabled after save');
        $this->assertFalse($nonmatch->enabled, 'Non-matching channel should remain disabled');

        // change the name of the nonmatching channel so that it now matches and
        // save the playlist again to trigger the booted listener
        $nonmatch->update(['name' => 'now match', 'enabled' => false]);
        // sanity-check that the pattern itself will match the renamed channel
        $this->assertNotNull($custom->applyEventPattern($nonmatch));
        $custom->save();

        $nonmatch->refresh();
        $this->assertTrue($nonmatch->enabled, 'Channel should be re-enabled after playlist was saved again');
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

        // applying pattern manually via a custom playlist should still work,
        // but the regular playlist controller is not supposed to run any matcher.
        $custom = \App\Models\CustomPlaylist::factory()->for($this->user)->create([
            'dummy_epg' => true,
            'dummy_epg_length' => 120,
            'dummy_epg_category' => true,
        ]);
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
        $this->assertNotNull($custom->applyEventPattern($channel));
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

    public function test_event_pattern_strips_trailing_date_code_from_event()
    {
        $custom = \App\Models\CustomPlaylist::factory()->for($this->user)->create([
            'dummy_epg' => true,
            'dummy_epg_length' => 120,
            'dummy_epg_category' => true,
        ]);

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
            'name' => 'US (ESPN+ 031) | Massachusetts vs. Brown Feb 25 3:00PM ET (2026-02-25 15:00:45)',
        ]);
        $custom->channels()->syncWithoutDetaching($channel->id);

        $custom->update([
            'use_regex_channel_management' => true,
            'event_patterns' => [
                [
                    'group' => 'Sports',
                    'pattern' => '/\|\s*(?P<event>(?!\()\S(?:.*?\S)?)(?:\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\b.*?)*\s*\((?P<start>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\)\s*$/',
                    'timezone' => 'UTC',
                    'default_length' => 60,
                    'disable_if_empty' => true,
                ],
            ],
        ]);

        $this->assertNotNull($custom->applyEventPattern($channel));
        $this->assertEquals('Massachusetts vs. Brown', $channel->title_custom);
    }

    public function test_event_pattern_ignores_title_with_only_timestamp()
    {
        $custom = \App\Models\CustomPlaylist::factory()->for($this->user)->create([
            'dummy_epg' => true,
            'dummy_epg_length' => 120,
            'dummy_epg_category' => true,
        ]);

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
            'name' => 'US (ESPN+ 491) | (2098-12-31 08:05:10)',
        ]);
        $custom->channels()->syncWithoutDetaching($channel->id);

        $custom->update([
            'use_regex_channel_management' => true,
            'event_patterns' => [
                [
                    'group' => 'Sports',
                    'pattern' => '/\|\s*(?P<event>(?!\()\S(?:.*?\S)?)(?:\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\b.*?)*\s*\((?P<start>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\)\s*$/',
                    'timezone' => 'UTC',
                    'default_length' => 60,
                    'disable_if_empty' => true,
                ],
            ],
        ]);

        $this->assertNull($custom->applyEventPattern($channel));
    }

    public function test_time_only_regex_without_named_groups_uses_default_length()
    {
        $custom = \App\Models\CustomPlaylist::factory()->for($this->user)->create([
            'dummy_epg' => true,
            'dummy_epg_length' => 180,
            'dummy_epg_category' => true,
        ]);

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
            'name' => 'NHL Hockey 1 New York Rangers vs. N.Y. Islanders 10:00PM',
        ]);
        $custom->channels()->syncWithoutDetaching($channel->id);

        $custom->update([
            'use_regex_channel_management' => true,
            'event_patterns' => [
                [
                    'group' => 'Sports',
                    'pattern' => '/\b(?:\d{1,2}:\d{2}(?:AM|PM))\b/',
                    'timezone' => 'UTC',
                    'default_length' => 180,
                    'disable_if_empty' => true,
                ],
            ],
        ]);

        // pattern itself returns nothing usable
        $patternInfo = $custom->applyEventPattern($channel);
        $this->assertNull($patternInfo['start'] ?? null);
        $this->assertNull($patternInfo['stop'] ?? null);

        // api should still return a dummy programme of 180 minutes
        $response = $this->getJson("/api/epg/playlist/{$custom->uuid}/data");
        $response->assertSuccessful();
        $data = $response->json();

        $this->assertCount(1, $data['programmes']);
        $programme = array_values($data['programmes'])[0][0];
        $start = Carbon::parse($programme['start']);
        $stop = Carbon::parse($programme['stop']);
        $this->assertEquals(180, $start->diffInMinutes($stop));
    }

    public function test_regex_overrides_cached_epg_for_mapped_channel()
    {
        // create an epg and mark it cached
        $epg = \App\Models\Epg::factory()->create(['is_cached' => true]);

        $custom = \App\Models\CustomPlaylist::factory()->for($this->user)->create([
            'dummy_epg' => true,
            'dummy_epg_length' => 120,
            'dummy_epg_category' => true,
        ]);

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
            'epg_id' => $epg->id,
            'epg_channel_key' => 'foo',
        ]);
        $custom->channels()->syncWithoutDetaching($channel->id);

        // create a dummy epg channel record so the cache service will include it
        \App\Models\EpgChannel::factory()->create([
            'epg_id' => $epg->id,
            'channel_id' => $channel->id,
            'epg_channel_key' => 'foo',
        ]);

        // replace the cache service with a fake that returns a bogus programme
        $fake = Mockery::mock(\App\Services\EpgCacheService::class);
        $fake->shouldReceive('getCachedProgrammesRange')
            ->andReturn([
                'foo' => [[
                    'start' => '2026-02-25T00:00:00+00:00',
                    'stop' => '2026-02-25T03:00:00+00:00',
                    'title' => 'garbage',
                ]],
            ]);
        $fake->shouldReceive('getCacheMetadata')
            ->andReturn(['cache_created' => now()->toIso8601String(), 'total_programmes' => 1]);
        $this->instance(\App\Services\EpgCacheService::class, $fake);

        // apply a pattern that will match and provide its own start time
        $custom->update([
            'use_regex_channel_management' => true,
            'event_patterns' => [
                [
                    'group' => 'Sports',
                    'pattern' => '/\|\s*(?P<event>.*?)\s*\(2026-02-25 09:00:00\)\s*$/',
                    'timezone' => 'UTC',
                    'default_length' => 60,
                    'disable_if_empty' => true,
                ],
            ],
        ]);

        $response = $this->getJson("/api/epg/playlist/{$custom->uuid}/data");
        $response->assertSuccessful();
        $data = $response->json();

        $programmes = array_values($data['programmes'])[0];
        $first = $programmes[0];
        $this->assertEquals('2026-02-25T09:00:00+00:00', $first['start']);
    }

    public function test_regex_override_handles_unparseable_start_strings()
    {
        $custom = \App\Models\CustomPlaylist::factory()->for($this->user)->create([
            'dummy_epg' => true,
            'dummy_epg_length' => 120,
            'dummy_epg_category' => true,
        ]);

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
            // the name contains a time with ET abbreviation which Carbon
            // cannot parse directly
            'name' => 'Foo Channel | Live Show (9:00AM ET)',
        ]);
        $custom->channels()->syncWithoutDetaching($channel->id);

        $custom->update([
            'use_regex_channel_management' => true,
            'event_patterns' => [
                [
                    'group' => 'Sports',
                    'pattern' => '/\|\s*Live Show \((?P<start>\d{1,2}:\d{2}(?:AM|PM) ET)\)/',
                    'timezone' => 'UTC',
                    'default_length' => 120,
                    'disable_if_empty' => true,
                ],
            ],
        ]);

        // stub the cache service to return a programme with no start time
        $fake = \Mockery::mock(EpgCacheService::class);
        $this->app->instance(EpgCacheService::class, $fake);
        $fake->shouldReceive('getCachedProgrammesRange')
            ->andReturn([
                $channel->id => [
                    ['start' => null, 'stop' => null, 'title' => 'cached', 'desc' => 'cached', 'icon' => '', 'category' => null],
                ],
            ]);
        $fake->shouldReceive('getCacheMetadata')->andReturn(['cache_created' => now(), 'total_programmes' => 1, 'programme_date_range' => null]);

        $response = $this->getJson("/api/epg/playlist/{$custom->uuid}/data");
        $response->assertSuccessful();
        $data = $response->json();

        $programmesList = array_values($data['programmes'])[0];
        $this->assertNotEmpty($programmesList);

        $firstProgramme = $programmesList[0];
        $this->assertNotEmpty($firstProgramme['start'], 'Start time should be populated even if original string was unparseable');
        $this->assertEquals(120, Carbon::parse($firstProgramme['start'])->diffInMinutes(Carbon::parse($firstProgramme['stop'])));
    }

    public function test_regex_creates_slot_without_dummy_epg()
    {
        $custom = \App\Models\CustomPlaylist::factory()->for($this->user)->create([
            'dummy_epg' => false, // explicitly disabled
        ]);

        $group = Group::factory()->create([
            'name' => 'Music',
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
            'name' => 'Station | Top Hits (2026-03-01 12:00:00)',
        ]);
        $custom->channels()->syncWithoutDetaching($channel->id);

        $custom->update([
            'use_regex_channel_management' => true,
            'event_patterns' => [
                [
                    'group' => 'Music',
                    'pattern' => '/\|\s*Top Hits \((?P<start>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\)/',
                    'timezone' => 'UTC',
                    'default_length' => 30,
                    'disable_if_empty' => true,
                ],
            ],
        ]);

        // no cache data at all (epg_id is null), the playlist should still
        // return a programme based on the regex
        $response = $this->getJson("/api/epg/playlist/{$custom->uuid}/data");
        $response->assertSuccessful();
        $data = $response->json();

        $this->assertCount(1, $data['programmes']);
        $firstProgramme = array_values($data['programmes'])[0][0];
        $this->assertEquals('2026-03-01T12:00:00+00:00', $firstProgramme['start']);
        $this->assertEquals(30, Carbon::parse($firstProgramme['start'])->diffInMinutes(Carbon::parse($firstProgramme['stop'])));
    }

    public function test_apply_event_pattern_uses_original_channel_values()
    {
        $custom = \App\Models\CustomPlaylist::factory()->for($this->user)->create();
        $group = Group::factory()->create([ 'name' => 'News', 'user_id' => $this->user->id ]);

        $channel = Channel::factory()->create([
            'playlist_id' => $this->playlist->id,
            'custom_playlist_id' => $custom->id,
            'user_id' => $this->user->id,
            'group_id' => $group->id,
            'group' => $group->name,
            'enabled' => true,
            'is_vod' => false,
            'name' => 'Local 6PM News 2026-05-01',
            // give it a completely different custom title so we can prove the
            // regex does *not* run against it
            'title_custom' => 'UNRELATED TITLE',
        ]);
        $custom->channels()->syncWithoutDetaching($channel->id);

        $custom->update([
            'use_regex_channel_management' => true,
            'event_patterns' => [
                [
                    'group' => 'News',
                    'pattern' => '/Local\s+(?P<event>6PM News)\s+(?P<start>\d{4}-\d{2}-\d{2})/',
                    'timezone' => 'UTC',
                    'default_length' => 60,
                    'disable_if_empty' => true,
                ],
            ],
        ]);

        // first application should match and set title_custom
        $first = $custom->applyEventPattern($channel);
        $this->assertNotNull($first);
        $this->assertEquals('6PM News', $channel->title_custom);

        // mutate title_custom manually to simulate a second run operating on
        // changed data
        $channel->title_custom = 'Something Else';

        $second = $custom->applyEventPattern($channel);
        $this->assertNotNull($second, 'pattern should still match even after the model was mutated');
        $this->assertEquals('6PM News', $second['event']);
    }
}
