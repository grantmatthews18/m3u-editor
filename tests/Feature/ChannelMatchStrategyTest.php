<?php

use App\Enums\ChannelMatchStrategy;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\User;
use App\Services\ChannelMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('ChannelMatchStrategy Enum', function () {
    it('has all expected strategies', function () {
        expect(ChannelMatchStrategy::cases())->toHaveCount(5);
        expect(ChannelMatchStrategy::StreamId->value)->toBe('stream_id');
        expect(ChannelMatchStrategy::NameGroup->value)->toBe('name_group');
        expect(ChannelMatchStrategy::TitleGroup->value)->toBe('title_group');
        expect(ChannelMatchStrategy::NameOnly->value)->toBe('name_only');
        expect(ChannelMatchStrategy::TitleOnly->value)->toBe('title_only');
    });

    it('generates correct source_id for StreamId strategy', function () {
        $channel = [
            'stream_id' => '12345',
            'name' => 'Test Channel',
            'title' => 'Test Title',
            'group' => 'Test Group',
        ];

        $sourceId = ChannelMatchStrategy::StreamId->generateSourceId($channel, 1);
        expect($sourceId)->toBe('12345');
    });

    it('generates correct source_id for NameGroup strategy', function () {
        $channel = [
            'stream_id' => '12345',
            'name' => 'Test Channel',
            'title' => 'Test Title',
            'group' => 'Test Group',
        ];

        $playlistId = 1;
        $sourceId = ChannelMatchStrategy::NameGroup->generateSourceId($channel, $playlistId);
        expect($sourceId)->toBe(md5('Test Channel'.'Test Group'.$playlistId));
    });

    it('generates correct source_id for TitleGroup strategy', function () {
        $channel = [
            'stream_id' => '12345',
            'name' => 'Test Channel',
            'title' => 'Test Title',
            'group' => 'Test Group',
        ];

        $playlistId = 1;
        $sourceId = ChannelMatchStrategy::TitleGroup->generateSourceId($channel, $playlistId);
        expect($sourceId)->toBe(md5('Test Title'.'Test Group'.$playlistId));
    });

    it('generates correct source_id for NameOnly strategy', function () {
        $channel = [
            'stream_id' => '12345',
            'name' => 'Test Channel',
            'title' => 'Test Title',
            'group' => 'Test Group',
        ];

        $playlistId = 1;
        $sourceId = ChannelMatchStrategy::NameOnly->generateSourceId($channel, $playlistId);
        expect($sourceId)->toBe(md5('Test Channel'.$playlistId));
    });

    it('generates correct source_id for TitleOnly strategy', function () {
        $channel = [
            'stream_id' => '12345',
            'name' => 'Test Channel',
            'title' => 'Test Title',
            'group' => 'Test Group',
        ];

        $playlistId = 1;
        $sourceId = ChannelMatchStrategy::TitleOnly->generateSourceId($channel, $playlistId);
        expect($sourceId)->toBe(md5('Test Title'.$playlistId));
    });
});

describe('Playlist Channel Match Strategies', function () {
    it('returns default stream_id strategy for xtream playlists', function () {
        $playlist = Playlist::factory()->create([
            'user_id' => $this->user->id,
            'xtream' => true,
            'channel_match_strategies' => null,
        ]);

        $strategies = $playlist->getEffectiveChannelMatchStrategies();

        expect($strategies)->toHaveCount(1);
        expect($strategies[0])->toBe(ChannelMatchStrategy::StreamId);
    });

    it('returns default name_group strategy for non-xtream playlists', function () {
        $playlist = Playlist::factory()->create([
            'user_id' => $this->user->id,
            'xtream' => false,
            'channel_match_strategies' => null,
        ]);

        $strategies = $playlist->getEffectiveChannelMatchStrategies();

        expect($strategies)->toHaveCount(1);
        expect($strategies[0])->toBe(ChannelMatchStrategy::NameGroup);
    });

    it('returns configured strategies when set', function () {
        $playlist = Playlist::factory()->create([
            'user_id' => $this->user->id,
            'channel_match_strategies' => ['stream_id', 'name_group', 'title_only'],
        ]);

        $strategies = $playlist->getEffectiveChannelMatchStrategies();

        expect($strategies)->toHaveCount(3);
        expect($strategies[0])->toBe(ChannelMatchStrategy::StreamId);
        expect($strategies[1])->toBe(ChannelMatchStrategy::NameGroup);
        expect($strategies[2])->toBe(ChannelMatchStrategy::TitleOnly);
    });
});

describe('ChannelMatchService', function () {
    it('generates source_id using primary strategy', function () {
        $playlist = Playlist::factory()->create([
            'user_id' => $this->user->id,
            'channel_match_strategies' => ['name_group', 'stream_id'],
        ]);

        $channel = [
            'stream_id' => '12345',
            'name' => 'Test Channel',
            'title' => 'Test Title',
            'group' => 'Test Group',
        ];

        $sourceId = ChannelMatchService::generateSourceId($channel, $playlist);

        // Should use name_group as primary strategy
        expect($sourceId)->toBe(md5('Test Channel'.'Test Group'.$playlist->id));
    });

    it('generates all source_ids for all configured strategies', function () {
        $playlist = Playlist::factory()->create([
            'user_id' => $this->user->id,
            'channel_match_strategies' => ['stream_id', 'name_group'],
        ]);

        $channel = [
            'stream_id' => '12345',
            'name' => 'Test Channel',
            'title' => 'Test Title',
            'group' => 'Test Group',
        ];

        $sourceIds = ChannelMatchService::generateAllSourceIds($channel, $playlist);

        expect($sourceIds)->toHaveKeys(['stream_id', 'name_group']);
        expect($sourceIds['stream_id'])->toBe('12345');
        expect($sourceIds['name_group'])->toBe(md5('Test Channel'.'Test Group'.$playlist->id));
    });

    it('migrates custom playlist associations from removed to new channels', function () {
        $playlist = Playlist::factory()->create([
            'user_id' => $this->user->id,
            'channel_match_strategies' => ['name_group', 'stream_id'],
            'preserve_custom_playlist_associations' => true,
        ]);

        // Create custom playlist
        $customPlaylist = CustomPlaylist::factory()->create([
            'user_id' => $this->user->id,
        ]);

        // Create "old" channel (will be removed) with custom playlist association
        $oldChannel = Channel::factory()->create([
            'playlist_id' => $playlist->id,
            'user_id' => $this->user->id,
            'source_id' => 'old-source-id',
            'stream_id' => '12345',
            'name' => 'Test Channel',
            'title' => 'Test Title',
            'group' => 'Sports',
            'group_internal' => 'Sports',
            'import_batch_no' => 'old-batch',
        ]);
        $oldChannel->customPlaylists()->attach($customPlaylist->id);

        // Create "new" channel (same name/group, different source_id)
        $newChannel = Channel::factory()->create([
            'playlist_id' => $playlist->id,
            'user_id' => $this->user->id,
            'source_id' => 'new-source-id',
            'stream_id' => '67890', // Different stream_id
            'name' => 'Test Channel', // Same name
            'title' => 'Test Title',
            'group' => 'Sports', // Same group
            'group_internal' => 'Sports',
            'import_batch_no' => 'new-batch',
        ]);

        // Simulate the removed channels query
        $removedChannels = Channel::where([
            ['playlist_id', $playlist->id],
            ['import_batch_no', 'old-batch'],
        ]);

        // Run migration
        $stats = ChannelMatchService::migrateCustomPlaylistAssociations(
            $playlist,
            $removedChannels,
            'new-batch'
        );

        // Verify migration happened
        expect($stats['channels_matched'])->toBe(1);
        expect($stats['associations_migrated'])->toBe(1);

        // Verify new channel has the custom playlist association
        $newChannel->refresh();
        expect($newChannel->customPlaylists)->toHaveCount(1);
        expect($newChannel->customPlaylists->first()->id)->toBe($customPlaylist->id);
    });

    it('does not migrate when preserve_custom_playlist_associations is disabled', function () {
        $playlist = Playlist::factory()->create([
            'user_id' => $this->user->id,
            'channel_match_strategies' => ['name_group'],
            'preserve_custom_playlist_associations' => false,
        ]);

        $customPlaylist = CustomPlaylist::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $oldChannel = Channel::factory()->create([
            'playlist_id' => $playlist->id,
            'user_id' => $this->user->id,
            'source_id' => 'old-source-id',
            'name' => 'Test Channel',
            'group' => 'Sports',
            'import_batch_no' => 'old-batch',
        ]);
        $oldChannel->customPlaylists()->attach($customPlaylist->id);

        $newChannel = Channel::factory()->create([
            'playlist_id' => $playlist->id,
            'user_id' => $this->user->id,
            'source_id' => 'new-source-id',
            'name' => 'Test Channel',
            'group' => 'Sports',
            'import_batch_no' => 'new-batch',
        ]);

        $removedChannels = Channel::where([
            ['playlist_id', $playlist->id],
            ['import_batch_no', 'old-batch'],
        ]);

        $stats = ChannelMatchService::migrateCustomPlaylistAssociations(
            $playlist,
            $removedChannels,
            'new-batch'
        );

        // Should skip migration
        expect($stats['associations_migrated'])->toBe(0);

        // New channel should not have the association
        $newChannel->refresh();
        expect($newChannel->customPlaylists)->toHaveCount(0);
    });

    it('matches channels using fallback strategies when primary fails', function () {
        $playlist = Playlist::factory()->create([
            'user_id' => $this->user->id,
            'channel_match_strategies' => ['stream_id', 'name_group'], // stream_id first, then name_group
            'preserve_custom_playlist_associations' => true,
        ]);

        $customPlaylist = CustomPlaylist::factory()->create([
            'user_id' => $this->user->id,
        ]);

        // Old channel
        $oldChannel = Channel::factory()->create([
            'playlist_id' => $playlist->id,
            'user_id' => $this->user->id,
            'source_id' => 'old-source-id',
            'stream_id' => '12345',
            'name' => 'Test Channel',
            'group' => 'Sports',
            'group_internal' => 'Sports',
            'import_batch_no' => 'old-batch',
        ]);
        $oldChannel->customPlaylists()->attach($customPlaylist->id);

        // New channel with DIFFERENT stream_id but SAME name/group
        $newChannel = Channel::factory()->create([
            'playlist_id' => $playlist->id,
            'user_id' => $this->user->id,
            'source_id' => 'new-source-id',
            'stream_id' => '99999', // Different stream_id
            'name' => 'Test Channel', // Same name
            'group' => 'Sports', // Same group
            'group_internal' => 'Sports',
            'import_batch_no' => 'new-batch',
        ]);

        $removedChannels = Channel::where([
            ['playlist_id', $playlist->id],
            ['import_batch_no', 'old-batch'],
        ]);

        $stats = ChannelMatchService::migrateCustomPlaylistAssociations(
            $playlist,
            $removedChannels,
            'new-batch'
        );

        // Should match using name_group fallback strategy
        expect($stats['channels_matched'])->toBe(1);
        expect($stats['associations_migrated'])->toBe(1);

        $newChannel->refresh();
        expect($newChannel->customPlaylists)->toHaveCount(1);
    });
});
