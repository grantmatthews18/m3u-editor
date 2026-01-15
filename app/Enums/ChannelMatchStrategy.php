<?php

namespace App\Enums;

enum ChannelMatchStrategy: string
{
    case StreamId = 'stream_id';
    case NameGroup = 'name_group';
    case TitleGroup = 'title_group';
    case NameOnly = 'name_only';
    case TitleOnly = 'title_only';

    public function getColor(): string
    {
        return match ($this) {
            self::StreamId => 'success',
            self::NameGroup => 'info',
            self::TitleGroup => 'info',
            self::NameOnly => 'warning',
            self::TitleOnly => 'warning',
        };
    }

    public function getLabel(): ?string
    {
        return match ($this) {
            self::StreamId => 'Stream ID (default for Xtream)',
            self::NameGroup => 'Name + Group',
            self::TitleGroup => 'Title + Group',
            self::NameOnly => 'Name Only',
            self::TitleOnly => 'Title Only',
        };
    }

    public function getDescription(): ?string
    {
        return match ($this) {
            self::StreamId => 'Match channels by their unique stream ID from the provider. This is the most reliable method when provider IDs remain consistent.',
            self::NameGroup => 'Match channels by combining name and group. Useful when stream IDs change but channel names stay the same within groups.',
            self::TitleGroup => 'Match channels by combining title and group. Similar to Name+Group but uses the display title instead.',
            self::NameOnly => 'Match channels by name only. Use with caution as different channels in different groups may have the same name.',
            self::TitleOnly => 'Match channels by title only. Use with caution as different channels may have the same title.',
        };
    }

    /**
     * Get the fields used for generating source_id based on this strategy.
     *
     * @return array<string>
     */
    public function getMatchFields(): array
    {
        return match ($this) {
            self::StreamId => ['stream_id'],
            self::NameGroup => ['name', 'group'],
            self::TitleGroup => ['title', 'group'],
            self::NameOnly => ['name'],
            self::TitleOnly => ['title'],
        };
    }

    /**
     * Generate a source_id for a channel based on this strategy.
     *
     * @param  array|object  $channel  The channel data
     * @param  int  $playlistId  The playlist ID
     * @return string The generated source_id
     */
    public function generateSourceId(array|object $channel, int $playlistId): string
    {
        $channel = is_object($channel) ? (array) $channel : $channel;

        return match ($this) {
            self::StreamId => (string) ($channel['stream_id'] ?? $channel['source_id'] ?? ''),
            self::NameGroup => md5(($channel['name'] ?? '').($channel['group'] ?? $channel['group_internal'] ?? '').$playlistId),
            self::TitleGroup => md5(($channel['title'] ?? '').($channel['group'] ?? $channel['group_internal'] ?? '').$playlistId),
            self::NameOnly => md5(($channel['name'] ?? '').$playlistId),
            self::TitleOnly => md5(($channel['title'] ?? '').$playlistId),
        };
    }
}
