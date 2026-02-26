<?php

namespace App\Models;

use App\Enums\PlaylistChannelId;
use App\Traits\ShortUrlTrait;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Tags\HasTags;

class CustomPlaylist extends Model
{
    use HasFactory;
    use HasTags;
    use ShortUrlTrait;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'dummy_epg' => 'boolean',
        'short_urls' => 'array',
        'proxy_options' => 'array',
        'short_urls_enabled' => 'boolean',
        'include_series_in_m3u' => 'boolean',
        'include_networks_in_m3u' => 'boolean',
        'include_vod_in_m3u' => 'boolean',
        'custom_headers' => 'array',
        'strict_live_ts' => 'boolean',
        'use_sticky_session' => 'boolean',
        'id_channel_by' => PlaylistChannelId::class,
        'event_patterns' => 'array', // group -> pattern config
        'use_regex_channel_management' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function streamProfile(): BelongsTo
    {
        return $this->belongsTo(StreamProfile::class);
    }

    public function vodStreamProfile(): BelongsTo
    {
        return $this->belongsTo(StreamProfile::class, 'vod_stream_profile_id');
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'channel_custom_playlist');
    }

    public function enabled_channels(): BelongsToMany
    {
        return $this->channels()
            ->where('enabled', true);
    }

    public function series(): BelongsToMany
    {
        return $this->belongsToMany(Series::class, 'series_custom_playlist');
    }

    public function enabled_series(): BelongsToMany
    {
        return $this->series()
            ->where('enabled', true);
    }

    public function live_channels(): BelongsToMany
    {
        return $this->channels()
            ->where('is_vod', false);
    }

    public function enabled_live_channels(): BelongsToMany
    {
        return $this->live_channels()
            ->where('enabled', true);
    }

    public function vod_channels(): BelongsToMany
    {
        return $this->channels()
            ->where('is_vod', true);
    }

    public function enabled_vod_channels(): BelongsToMany
    {
        return $this->vod_channels()
            ->where('enabled', true);
    }

    public function customChannels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function groups(): MorphToMany
    {
        return $this->groupTags();
    }

    public function groupTags(): MorphToMany
    {
        return $this->morphToMany(\Spatie\Tags\Tag::class, 'taggable')
            ->where('type', $this->uuid);
    }

    public function categories(): MorphToMany
    {
        return $this->categoryTags();
    }

    public function categoryTags(): MorphToMany
    {
        return $this->morphToMany(\Spatie\Tags\Tag::class, 'taggable')
            ->where('type', $this->uuid.'-category');
    }

    // public function playlists(): HasManyThrough
    // {
    //     return $this->hasManyThrough(
    //         Playlist::class,
    //         CustomPlaylistPivot::class,
    //         'custom_playlist_id',
    //         'channel_id',
    //         'id',
    //         'channel_id'
    //     );
    // }

    public function playlistAuths(): MorphToMany
    {
        return $this->morphToMany(PlaylistAuth::class, 'authenticatable');
    }

    public function postProcesses(): MorphToMany
    {
        return $this->morphToMany(PostProcess::class, 'processable');
    }

    public function getAutoSortAttribute(): bool
    {
        return true;
    }

    /**
     * Get all unique source playlists that have channels assigned to this custom playlist.
     * This is useful for determining which provider credentials need to be configured
     * when creating a Playlist Alias.
     */
    public function getSourcePlaylists(): \Illuminate\Database\Eloquent\Collection
    {
        $playlistIds = $this->channels()
            ->whereNotNull('playlist_id')
            ->distinct()
            ->pluck('playlist_id');

        return Playlist::whereIn('id', $playlistIds)->get();
    }

    /**
     * Get source playlists with their xtream config info for alias configuration.
     * Returns an array of playlists with their URL base for matching.
     *
     * @return array<int, array{id: int, name: string, url: string|null}>
     */
    public function getSourcePlaylistsForAlias(): array
    {
        return $this->getSourcePlaylists()
            ->map(function (Playlist $playlist) {
                $url = $playlist->xtream_config['url'] ?? null;

                return [
                    'id' => $playlist->id,
                    'name' => $playlist->name,
                    'url' => $url ? rtrim($url, '/') : null,
                ];
            })
            ->filter(fn ($config) => $config['url'] !== null)
            ->toArray();
    }

    /**
     * Check if any source playlists have provider profiles enabled.
     * When this returns true, proxy mode should be required for proper connection pooling.
     */
    public function hasPooledSourcePlaylists(): bool
    {
        return $this->channels()
            ->whereNotNull('playlist_id')
            ->whereHas('playlist', function ($query) {
                $query->where('profiles_enabled', true);
            })
            ->exists();
    }

    /**
     * Get source playlists that have provider profiles enabled.
     */
    public function getPooledSourcePlaylists(): \Illuminate\Database\Eloquent\Collection
    {
        $playlistIds = $this->channels()
            ->whereNotNull('playlist_id')
            ->distinct()
            ->pluck('playlist_id');

        return Playlist::whereIn('id', $playlistIds)
            ->where('profiles_enabled', true)
            ->get();
    }

    public function enableProxy(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value) {
                    // Check playlist user has access to proxy features
                    if (! $this->user?->canUseProxy()) {
                        return false;
                    }
                }

                return $value;
            }
        );
    }

    /**
     * Retrieve the event pattern configuration for a given channel group.
     *
     * The repeater stores its data as a numeric array where each entry is an
     * associative array containing a "group" key, so we must look through the
     * list rather than indexing directly by group name.
     */
    public function getEventPatternForGroup(?string $group): ?array
    {
        $patterns = $this->event_patterns ?? [];
        if (! is_array($patterns)) {
            return null;
        }

        // first, try to find an entry whose 'group' field matches exactly
        foreach ($patterns as $entry) {
            if (isset($entry['group']) && $entry['group'] === $group) {
                return $entry;
            }
        }

        // if there is only one pattern defined, assume it applies to everything
        if (count($patterns) === 1) {
            return $patterns[0];
        }

        // check for a wildcard/empty pattern
        foreach ($patterns as $entry) {
            if (isset($entry['group']) && in_array($entry['group'], ['', '*'], true)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Attempt to apply the configured event pattern to the provided channel.
     *
     * This will optionally update the channel title and/or disable the channel
     * depending on the pattern configuration. Returns an array containing
     * parsed event data (event, start, stop) when a match is found, or null
     * otherwise.
     */
    public function applyEventPattern(Channel $channel): ?array
    {
        // determine the channel's group for the purposes of pattern matching.  we
        // mimic the logic used in PlaylistGenerateController so that the name the
        // user sees in the playlist (stored in `group` or `custom_group_name`) is
        // the same string that we attempt to match against.
        $group = $channel->group ?? '';
        if (! empty($channel->custom_group_name)) {
            $groupName = json_decode($channel->custom_group_name, true);
            if (is_array($groupName)) {
                $group = $groupName['en'] ?? $groupName[array_key_first($groupName)] ?? $group;
            }
        }

        $patterns = $this->event_patterns ?? [];
        if (! is_array($patterns)) {
            return null;
        }

        $config = null;
        $matches = [];

        // we'll keep track of the first regex that actually matched at all in
        // case none of the matching rules produce any named captures; that way a
        // plain "/match/" rule still works when there isn't a more specific
        // extractor further down the list.
        $firstMatchConfig = null;
        $firstMatchResults = [];

        $text = $channel->name_custom ?? $channel->name ?? $channel->title_custom ?? $channel->title ?? '';

        foreach ($patterns as $entry) {
            $entryGroup = $entry['group'] ?? '';
            if ($entryGroup !== $group && $entryGroup !== '' && $entryGroup !== '*') {
                continue;
            }

            $regex = $entry['pattern'] ?? '';
            if (@preg_match($regex, '') === false) {
                continue;
            }

            if (preg_match($regex, $text, $tempMatches)) {
                if (is_null($firstMatchConfig)) {
                    $firstMatchConfig = $entry;
                    $firstMatchResults = $tempMatches;
                }

                // if this pattern gave us anything useful, pick it immediately
                if (! empty($tempMatches['event']) || ! empty($tempMatches['start']) || ! empty($tempMatches['end'])) {
                    $config = $entry;
                    $matches = $tempMatches;
                    break;
                }
            }
        }

        // if we didn't break out with a capturing rule, fall back to the first
        // matching rule we saw (if any)
        if (is_null($config) && $firstMatchConfig) {
            $config = $firstMatchConfig;
            $matches = $firstMatchResults;
        }

        if (is_null($config)) {
            // nothing matched at all – apply disable_if_empty from any
            // applicable entry and bail out.
            foreach ($patterns as $entry) {
                $entryGroup = $entry['group'] ?? '';
                if ($entryGroup !== $group && $entryGroup !== '' && $entryGroup !== '*') {
                    continue;
                }

                if (! empty($entry['disable_if_empty'])) {
                    $channel->update(['enabled' => false]);
                    break;
                }
            }

            return null;
        }

        // at this point we have a configuration that matched the text (either
        // capturing or not).  the old logic re‑enabled the channel if
        // disable_if_empty was set on the chosen rule.
        if (! empty($config['disable_if_empty'])) {
            $channel->update(['enabled' => true]);
        }

        $event = $matches['event'] ?? null;
        $startStr = $matches['start'] ?? null;
        $endStr = $matches['end'] ?? null;

        $timezone = $config['timezone'] ?? null;
        $defaultLength = (int) ($config['default_length'] ?? 120);

        $start = null;
        $stop = null;

        try {
            if ($startStr) {
                $start = Carbon::parse($startStr, $timezone);
            }
        } catch (\Exception $e) {
            $start = null;
        }

        if ($endStr) {
            try {
                $stop = Carbon::parse($endStr, $timezone);
            } catch (\Exception $e) {
                $stop = null;
            }
        }

        if (! $stop && $start) {
            $stop = $start->copy()->addMinutes($defaultLength);
        }

        if ($event) {
            $channel->update(['title_custom' => $event]);
        }

        return [
            'event' => $event,
            'start' => $start,
            'stop' => $stop,
        ];
    }

    /**
     * Determine whether this playlist should use regex-based management.
     */
    public function usesRegexManagement(): bool
    {
        return (bool) $this->use_regex_channel_management;
    }

    /**
     * Boot the model and hook into the saved event so that regex channel
     * management can be reapplied whenever the playlist is modified.  This is
     * necessary because the channel list is read-only in the UI, so the only
     * way to re-enable previously-disabled channels is to recalc the pattern
     * when the playlist itself is saved (e.g. when the output settings form is
     * submitted).
     */
    protected static function booted(): void
    {
        // use the "saving" event rather than "saved" so that the recalculation
        // occurs even if no playlist attributes are actually dirty.  Filament
        // will happily hit the save button without changing anything, and the
        // expectation is that regex-managed channels are recalculated on every
        // save operation.
        static::saving(function (self $playlist): void {
            if (! $playlist->use_regex_channel_management) {
                return;
            }

            // iterate all attached channels and re-run the matcher; the helper
            // takes care of toggling the enabled flag based on the pattern
            $playlist->channels()->get()->each(function (Channel $channel) use ($playlist) {
                $playlist->applyEventPattern($channel);
            });
        });
    }
}
