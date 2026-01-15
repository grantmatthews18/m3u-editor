<?php

namespace App\Services;

use App\Enums\ChannelMatchStrategy;
use App\Models\Channel;
use App\Models\Playlist;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ChannelMatchService
{
    /**
     * Generate source_id for a channel based on the playlist's match strategies.
     * Uses the first (primary) strategy in the list.
     *
     * @param  array|object  $channel  The channel data
     * @param  Playlist  $playlist  The playlist
     * @return string The generated source_id
     */
    public static function generateSourceId(array|object $channel, Playlist $playlist): string
    {
        $strategies = $playlist->getEffectiveChannelMatchStrategies();
        $primaryStrategy = $strategies[0] ?? ChannelMatchStrategy::StreamId;

        return $primaryStrategy->generateSourceId($channel, $playlist->id);
    }

    /**
     * Generate multiple source_ids for a channel based on all configured strategies.
     * This is useful for finding matching channels across different strategies.
     *
     * @param  array|object  $channel  The channel data
     * @param  Playlist  $playlist  The playlist
     * @return array<string, string> Array of strategy => source_id
     */
    public static function generateAllSourceIds(array|object $channel, Playlist $playlist): array
    {
        $strategies = $playlist->getEffectiveChannelMatchStrategies();
        $sourceIds = [];

        foreach ($strategies as $strategy) {
            $sourceIds[$strategy->value] = $strategy->generateSourceId($channel, $playlist->id);
        }

        return $sourceIds;
    }

    /**
     * Migrate custom playlist associations from removed channels to new channels.
     * This preserves custom playlist memberships when channels are re-added with different source_ids.
     *
     * @param  Playlist  $playlist  The playlist being synced
     * @param  Collection  $removedChannels  Query builder for channels to be removed
     * @param  string  $batchNo  The current import batch number
     * @return array Statistics about the migration
     */
    public static function migrateCustomPlaylistAssociations(
        Playlist $playlist,
        $removedChannels,
        string $batchNo
    ): array {
        $stats = [
            'channels_checked' => 0,
            'associations_migrated' => 0,
            'channels_matched' => 0,
        ];

        // Skip if preservation is disabled
        if (! ($playlist->preserve_custom_playlist_associations ?? true)) {
            return $stats;
        }

        $strategies = $playlist->getEffectiveChannelMatchStrategies();

        // Get removed channels that have custom playlist associations
        $removedWithAssociations = $removedChannels->clone()
            ->whereHas('customPlaylists')
            ->with('customPlaylists')
            ->get();

        if ($removedWithAssociations->isEmpty()) {
            return $stats;
        }

        $stats['channels_checked'] = $removedWithAssociations->count();

        // Get all new channels (current batch) for potential matching
        $newChannels = Channel::where([
            ['playlist_id', $playlist->id],
            ['import_batch_no', $batchNo],
        ])->get();

        if ($newChannels->isEmpty()) {
            return $stats;
        }

        // Index new channels by various match keys for fast lookup
        $newChannelIndexes = self::buildChannelIndexes($newChannels, $playlist);

        foreach ($removedWithAssociations as $removedChannel) {
            // Try to find a matching new channel using each strategy in priority order
            $matchedChannel = self::findMatchingChannel(
                $removedChannel,
                $newChannelIndexes,
                $strategies,
                $playlist
            );

            if ($matchedChannel) {
                // Migrate the custom playlist associations
                $customPlaylistIds = $removedChannel->customPlaylists->pluck('id')->toArray();

                if (! empty($customPlaylistIds)) {
                    // Use syncWithoutDetaching to add associations without removing existing ones
                    $matchedChannel->customPlaylists()->syncWithoutDetaching($customPlaylistIds);

                    $stats['associations_migrated'] += count($customPlaylistIds);
                    $stats['channels_matched']++;

                    Log::info('Migrated custom playlist associations', [
                        'removed_channel_id' => $removedChannel->id,
                        'removed_channel_title' => $removedChannel->title,
                        'new_channel_id' => $matchedChannel->id,
                        'new_channel_title' => $matchedChannel->title,
                        'custom_playlist_ids' => $customPlaylistIds,
                    ]);
                }
            }
        }

        return $stats;
    }

    /**
     * Build indexes for fast channel lookup by different match strategies.
     *
     * @return array<string, array<string, Channel>>
     */
    private static function buildChannelIndexes(Collection $channels, Playlist $playlist): array
    {
        $indexes = [
            'stream_id' => [],
            'name_group' => [],
            'title_group' => [],
            'name_only' => [],
            'title_only' => [],
        ];

        foreach ($channels as $channel) {
            // Index by stream_id
            if (! empty($channel->stream_id)) {
                $indexes['stream_id'][$channel->stream_id] = $channel;
            }

            // Index by name + group
            $nameGroupKey = md5(($channel->name ?? '').(self::getChannelGroup($channel)).$playlist->id);
            $indexes['name_group'][$nameGroupKey] = $channel;

            // Index by title + group
            $titleGroupKey = md5(($channel->title ?? '').(self::getChannelGroup($channel)).$playlist->id);
            $indexes['title_group'][$titleGroupKey] = $channel;

            // Index by name only
            $nameOnlyKey = md5(($channel->name ?? '').$playlist->id);
            $indexes['name_only'][$nameOnlyKey] = $channel;

            // Index by title only
            $titleOnlyKey = md5(($channel->title ?? '').$playlist->id);
            $indexes['title_only'][$titleOnlyKey] = $channel;
        }

        return $indexes;
    }

    /**
     * Find a matching channel using the configured strategies in priority order.
     *
     * @param  Channel  $channel  The channel to find a match for
     * @param  array  $indexes  The pre-built channel indexes
     * @param  array<ChannelMatchStrategy>  $strategies  The match strategies in priority order
     */
    private static function findMatchingChannel(
        Channel $channel,
        array $indexes,
        array $strategies,
        Playlist $playlist
    ): ?Channel {
        foreach ($strategies as $strategy) {
            $match = match ($strategy) {
                ChannelMatchStrategy::StreamId => $indexes['stream_id'][$channel->stream_id] ?? null,
                ChannelMatchStrategy::NameGroup => $indexes['name_group'][md5(($channel->name ?? '').(self::getChannelGroup($channel)).$playlist->id)] ?? null,
                ChannelMatchStrategy::TitleGroup => $indexes['title_group'][md5(($channel->title ?? '').(self::getChannelGroup($channel)).$playlist->id)] ?? null,
                ChannelMatchStrategy::NameOnly => $indexes['name_only'][md5(($channel->name ?? '').$playlist->id)] ?? null,
                ChannelMatchStrategy::TitleOnly => $indexes['title_only'][md5(($channel->title ?? '').$playlist->id)] ?? null,
            };

            if ($match) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Get the group value for a channel, preferring group_internal over group.
     */
    private static function getChannelGroup(Channel $channel): string
    {
        return $channel->group_internal ?? $channel->group ?? '';
    }
}
