<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChannelLogoType;
use App\Enums\PlaylistChannelId;
use App\Facades\PlaylistFacade;
use App\Http\Controllers\Controller;
use App\Http\Controllers\LogoProxyController;
use App\Http\Controllers\PlaylistGenerateController;
use App\Models\Epg;
use App\Models\Playlist;
use App\Models\StreamProfile;
use App\Services\EpgCacheService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EpgApiController extends Controller
{
    /**
     * Get EPG data for viewing with pagination support
     *
     * @return JsonResponse
     */
    public function getData(string $uuid, Request $request)
    {
        // $epg = Epg::where('uuid', $uuid)->firstOrFail();
        $epg = Epg::where('uuid', $uuid)->firstOrFail();

        // Pagination parameters
        $page = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 50);
        $offset = max(0, ($page - 1) * $perPage);
        $search = $request->get('search', null);

        // Get parsed date range
        $dateRange = $this->parseDateRange($request);
        $startDate = Carbon::parse($dateRange['start']);
        $endDate = Carbon::parse($dateRange['end']);

        Log::debug('EPG API Request', [
            'uuid' => $uuid,
            'page' => $page,
            'per_page' => $perPage,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        try {
            // Check if cache exists and is valid
            if (! $epg->is_cached) {
                return response()->json([
                    'error' => 'Failed to retrieve EPG cache. Please try generating the EPG cache.',
                    'suggestion' => 'Try using the "Generate Cache" button to regenerate the data.',
                ], 500);
            }

            // Use database EpgChannel records for consistent ordering (similar to playlist view)
            $epgChannels = $epg->channels()
                ->orderBy('name')  // Consistent alphabetical ordering
                ->orderBy('channel_id')  // Secondary sort by channel ID
                ->when($search, function ($queryBuilder) use ($search) {
                    $search = Str::lower($search);

                    return $queryBuilder->where(function ($query) use ($search) {
                        $query->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                            ->orWhereRaw('LOWER(display_name) LIKE ?', ['%'.$search.'%']);
                    });
                })
                ->limit($perPage)
                ->offset($offset)
                ->get();

            // Get the channel IDs from database records to fetch cache data
            $channelIds = $epgChannels->pluck('channel_id')->toArray();

            // Get cached channel data for the requested date range and channels
            // $cacheService = new EpgCacheService;
            $cacheService = app(EpgCacheService::class);

            // Build ordered channels array using database order
            $channels = [];
            $channelIndex = $offset;
            foreach ($epgChannels as $epgChannel) {
                $channelId = $epgChannel->channel_id;
                $channels[$channelId] = [
                    'id' => $channelId,
                    'database_id' => $epgChannel->id, // Add the actual database ID for editing
                    'display_name' => $epgChannel->display_name ?? $epgChannel->name ?? $channelId,
                    'icon' => $epgChannel->icon ?? url('/placeholder.png'),
                    'lang' => $epgChannel->lang ?? 'en',
                    'sort_index' => $channelIndex++,
                ];
            }

            // Get cached programmes for the requested date range and channels
            $programmes = $cacheService->getCachedProgrammesRange(
                $epg,
                $startDate,
                $endDate,
                $channelIds
            );

            // Get cache metadata
            $metadata = $cacheService->getCacheMetadata($epg);

            // Create pagination info using database count for accuracy
            $totalChannels = $epg->channels()->when($search, function ($queryBuilder) use ($search) {
                $search = Str::lower($search);

                return $queryBuilder->where(function ($query) use ($search) {
                    $query->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                        ->orWhereRaw('LOWER(display_name) LIKE ?', ['%'.$search.'%']);
                });
            })->count();
            $pagination = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_channels' => $totalChannels,
                'returned_channels' => count($channels),
                'has_more' => (($page - 1) * $perPage + $perPage) < $totalChannels,
                'next_page' => (($page - 1) * $perPage + $perPage) < $totalChannels ? $page + 1 : null,
            ];

            // Get cached programmes for the requested date range and channels
            $programmes = $cacheService->getCachedProgrammesRange(
                $epg,
                $startDate,
                $endDate,
                $channelIds
            );

            // Get cache metadata
            $metadata = $cacheService->getCacheMetadata($epg);

            // Create pagination info using database count for accuracy
            $totalChannels = $epg->channels()->when($search, function ($queryBuilder) use ($search) {
                $search = Str::lower($search);

                return $queryBuilder->where(function ($query) use ($search) {
                    $query->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                        ->orWhereRaw('LOWER(display_name) LIKE ?', ['%'.$search.'%']);
                });
            })->count();
            $pagination = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_channels' => $totalChannels,
                'returned_channels' => count($channels),
                'has_more' => (($page - 1) * $perPage + $perPage) < $totalChannels,
                'next_page' => (($page - 1) * $perPage + $perPage) < $totalChannels ? $page + 1 : null,
            ];

            // Create final response structure
            $responseChannels = [];
            foreach ($channels as $channelId => $channelData) {
                $responseChannels[$channelId] = [
                    'id' => $channelId,
                    'database_id' => $channelData['database_id'] ?? null,
                    'display_name' => $channelData['display_name'],
                    'icon' => $channelData['icon'],
                    'lang' => $channelData['lang'] ?? 'en',
                    'sort_index' => $channelData['sort_index'] ?? 0,
                    'programmes' => $programmes[$channelId] ?? [],
                ];
            }

            return response()->json([
                'epg' => [
                    'id' => $epg->id,
                    'name' => $epg->name,
                    'uuid' => $epg->uuid,
                ],
                'date_range' => [
                    'start' => $startDate,
                    'end' => $endDate,
                ],
                'pagination' => $pagination,
                'channels' => $responseChannels,
                'programmes' => $programmes,
                'cache_info' => [
                    'cached' => true,
                    'cache_created' => $metadata['cache_created'] ?? null,
                    'total_programmes' => $metadata['total_programmes'] ?? 0,
                    'programme_date_range' => $metadata['programme_date_range'] ?? null,
                ],
            ]);
        } catch (Exception $e) {
            Log::error("Error retrieving EPG data for {$epg->name}: {$e->getMessage()}");

            return response()->json([
                'error' => 'Failed to retrieve EPG data: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get EPG data for a specific playlist with pagination support
     *
     * @param  string  $uuid  Playlist UUID
     * @return JsonResponse
     */
    public function getDataForPlaylist(string $uuid, Request $request)
    {
        // Find the playlist
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Handle network playlists - they have networks with programmes instead of channels with EPG
        if ($playlist instanceof \App\Models\Playlist && $playlist->is_network_playlist) {
            return $this->getDataForNetworkPlaylist($playlist, $request);
        }

        $cacheService = app(EpgCacheService::class);

        // Pagination parameters
        $page = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 50);
        $skip = max(0, ($page - 1) * $perPage);
        $search = $request->get('search', null);
        $vod = (bool) $request->get('vod', false);

        // Get parsed date range
        $dateRange = $this->parseDateRange($request);
        // parseDateRange returns Y-m-d strings, convert to Carbon for later logic
        $startDate = Carbon::parse($dateRange['start']);
        $endDate = Carbon::parse($dateRange['end']);

        // Debug logging
        Log::debug('EPG API Request for Playlist', [
            'playlist_uuid' => $uuid,
            'playlist_name' => $playlist->name,
            'page' => $page,
            'per_page' => $perPage,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        try {
            // query the playlist channels with the same filters used elsewhere
            $playlistChannels = PlaylistGenerateController::getChannelQuery($playlist)
                ->when($search, function ($queryBuilder) use ($search) {
                    $search = Str::lower($search);

                    return $queryBuilder->where(function ($query) use ($search) {
                        $query->whereRaw('LOWER(channels.name) LIKE ?', ['%'.$search.'%'])
                            ->orWhereRaw('LOWER(channels.name_custom) LIKE ?', ['%'.$search.'%'])
                            ->orWhereRaw('LOWER(channels.title) LIKE ?', ['%'.$search.'%'])
                            ->orWhereRaw('LOWER(channels.title_custom) LIKE ?', ['%'.$search.'%']);
                    });
                })
                ->limit($perPage)
                ->offset($skip)
                ->cursor();

            $settings = app(GeneralSettings::class);
            $vodProfile = $playlist->vodStreamProfile;
            $liveProfile = $playlist->streamProfile;

            if (! $vodProfile) {
                $vodProfileId = $settings->default_vod_stream_profile_id ?? null;
                $vodProfile = $vodProfileId ? StreamProfile::find($vodProfileId) : null;
            }
            if (! $liveProfile) {
                $liveProfileId = $settings->default_stream_profile_id ?? null;
                $liveProfile = $liveProfileId ? StreamProfile::find($liveProfileId) : null;
            }

            $proxyEnabled = $playlist->enable_proxy;
            $logoProxyEnabled = $playlist->enable_logo_proxy;

            $channelNumber = $playlist->auto_channel_increment ? $playlist->channel_start - 1 : 0;
            $idChannelBy = $playlist->id_channel_by;
            $dummyEpgEnabled = $playlist->dummy_epg;
            $dummyEpgLength = (int) ($playlist->dummy_epg_length ?? 120);

            // collect mapping information
            $epgChannelMap = [];
            $epgIds = [];
            $channels = [];
            $patternInfoMap = [];
            $dummyEpgChannels = [];
            $channelSortIndex = $skip;

            foreach ($playlistChannels as $channel) {
                $epgId = $channel->epg_id ?? null;
                if ($epgId !== null) {
                    $epgIds[$epgId] = true;
                }

                $channelNo = $channel->channel;
                if (! $channelNo && ($playlist->auto_channel_increment || $idChannelBy === PlaylistChannelId::Number)) {
                    $channelNo = ++$channelNumber;
                }

                $patternInfo = null;
                if ($playlist instanceof \App\Models\CustomPlaylist && $playlist->usesRegexManagement()) {
                    $patternInfo = $playlist->applyEventPattern($channel);
                    if ($patternInfo && ! empty($patternInfo['event'])) {
                        // Update display values immediately so that dummy EPG entries
                        // use the parsed event name instead of the raw channel name.
                        $channel->title_custom = $patternInfo['event'];
                    }
                    // skip disabled channels before doing any additional work
                    // but keep any channel which has an EPG mapping; disabling
                    // would prevent us from overriding the cache later.
                    if (! $channel->enabled && ! $channel->epg_id) {
                        continue;
                    }
                }

                $channelKey = $channelNo ?: $channel->id;
                $patternInfoMap[$channelKey] = $patternInfo;

                // determine logo
                $logo = url('/placeholder.png');
                if ($channel->logo) {
                    $logo = $channel->logo;
                } elseif ($channel->logo_type === ChannelLogoType::Epg && ($channel->epg_icon || $channel->epg_icon_custom)) {
                    $logo = $channel->epg_icon_custom ?? $channel->epg_icon ?? '';
                } elseif ($channel->logo_type === ChannelLogoType::Channel && ($channel->logo || $channel->logo_internal)) {
                    $logo = $channel->logo ?? $channel->logo_internal ?? '';
                    $logo = filter_var($logo, FILTER_VALIDATE_URL) ? $logo : url('/placeholder.png');
                }
                if ($logoProxyEnabled) {
                    $logo = LogoProxyController::generateProxyUrl($logo, internal: true);
                }

                $channels[$channelKey] = [
                    'database_id' => $channel->id,
                    'display_name' => $channel->title_custom ?? $channel->title,
                    'icon' => $logo ?? '',
                    'lang' => $channel->lang ?? 'en',
                    'sort_index' => $channelSortIndex++,
                    'group' => $channel->group ?? $channel->group_internal,
                    'include_category' => $playlist->dummy_epg_category,
                ];

                // build epg mapping for later cache lookup
                $epgChannelMap[$epgId][$channel->epg_channel_key][] = [
                    'playlist_channel_id' => $channelKey,
                ];

                // track channels that might require dummy programmes.
                // include all regex-pattern-matched channels (not just when dummy
                // EPG is globally enabled) so that event-only patterns (with no
                // captured start time) still produce at least a programme slot.
                if (is_null($epgId) && ($dummyEpgEnabled || ! empty($patternInfo))) {
                    $dummyEpgChannels[$channelKey] = [
                        'title' => $channels[$channelKey]['display_name'],
                        'icon' => $logo,
                        'group' => $channels[$channelKey]['group'],
                        'include_category' => $channels[$channelKey]['include_category'],
                    ];
                }
            }

            // retrieve cached programmes per EPG
            $programmes = [];
            $metadata = ['cache_created' => null, 'total_programmes' => 0, 'programme_date_range' => null];
            foreach (array_keys($epgIds) as $eid) {
                $epg = Epg::find($eid);
                if (! $epg || ! $epg->is_cached) {
                    continue;
                }

                // collect all EPG channel ids that map to playlist channels
                $epgChannelIds = [];
                foreach ($epgChannelMap[$eid] ?? [] as $epgChannelId => $maps) {
                    $epgChannelIds[] = $epgChannelId;
                }

                if (count($epgChannelIds) === 0) {
                    continue;
                }

                $cached = $cacheService->getCachedProgrammesRange($epg, $startDate, $endDate, $epgChannelIds);
                $metadata = $cacheService->getCacheMetadata($epg);

                // translate to playlist channel keys, merging if necessary
                foreach ($cached as $epgCh => $progList) {
                    foreach ($epgChannelMap[$eid][$epgCh] ?? [] as $map) {
                        $key = $map['playlist_channel_id'];
                        if (! isset($programmes[$key])) {
                            $programmes[$key] = [];
                        }
                        $programmes[$key] = array_merge($programmes[$key], $progList);
                    }
                }
            }

            // pattern override for cached results
            $patterns = $playlist->event_patterns ?? [];
            foreach ($programmes as $chKey => &$list) {
                $info = $patternInfoMap[$chKey] ?? null;

                // if we have a match but Carbon failed to parse the provided
                // start string earlier, the pattern helper will still return an
                // array with a null 'start' but the original raw string in
                // 'start_str'. try another parse attempt here before giving up so
                // that simple timezone abbreviations or unusual formats still
                // produce a usable time.
                if ($info && empty($info['start']) && ! empty($info['start_str'])) {
                    try {
                        $info['start'] = Carbon::parse($info['start_str'], $info['timezone'] ?? 'UTC');
                    } catch (\Exception $e) {
                        // if that fails we can also try stripping trailing TZ
                        // abbreviations, similar to the helper above.
                        $clean = preg_replace('/\b[A-Z]{2,5}\b$/', '', $info['start_str']);
                        try {
                            $info['start'] = Carbon::parse(trim($clean), $info['timezone'] ?? 'UTC');
                        } catch (\Exception $e2) {
                            // nothing else we can do
                        }
                    }

                    if (! empty($info['start']) && empty($info['stop'])) {
                        $info['stop'] = $info['start']->copy()->addMinutes($dummyEpgLength);
                    }
                }

                // if there was no match OR the matched rule didn't provide a
                // usable start time, peek at the configured regex itself and
                // see if it contains a hardcoded ISO datetime that we can
                // extract. this covers cases where the pattern doesn't name a
                // capture but the timestamp is baked into the regex string.
                if ((! $info || empty($info['start'])) && is_array($patterns)) {
                    $channelGroup = $channels[$chKey]['group'] ?? null;
                    foreach ($patterns as $entry) {
                        $entryGroup = $entry['group'] ?? '';
                        if ($entryGroup !== '' && $entryGroup !== '*' && $entryGroup !== $channelGroup) {
                            continue;
                        }
                        $regex = $entry['pattern'] ?? '';
                        if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $regex, $m)) {
                            try {
                                $start = Carbon::parse($m[1], $entry['timezone'] ?? 'UTC');
                                $stop = $start->copy()->addMinutes($entry['default_length'] ?? $dummyEpgLength);
                                $info = ['start' => $start, 'stop' => $stop, 'event' => null];
                            } catch (\Exception $e) {
                                // ignore parse errors
                            }
                        }
                        break;
                    }
                }

                if ($info && ! empty($info['start'])) {
                    $newStart = $info['start']->toIso8601String();
                    $newStop = isset($info['stop']) ? $info['stop']->toIso8601String() : Carbon::parse($newStart)->addMinutes($dummyEpgLength)->toIso8601String();
                    if (count($list) > 0) {
                        $list[0]['start'] = $newStart;
                        $list[0]['stop'] = $newStop;
                        if (! empty($info['event'])) {
                            $list[0]['title'] = $info['event'];
                        }
                    } else {
                        $list[] = [
                            'start' => $newStart,
                            'stop' => $newStop,
                            'title' => $info['event'] ?? '',
                            'desc' => $info['event'] ?? '',
                            'icon' => $channels[$chKey]['icon'] ?? '',
                            'category' => $channels[$chKey]['include_category'] ? $channels[$chKey]['group'] : null,
                        ];
                    }
                }
            }
            unset($list);

            // generate dummy EPG entries for any paginated channels that still have no programmes
            if ($dummyEpgEnabled) {
                foreach ($channels as $chKey => $info) {
                    if (empty($programmes[$chKey])) {
                        $programmes[$chKey] = $this->generateDummyProgrammesForChannel(
                            $info,
                            $startDate,
                            $endDate,
                            $dummyEpgLength,
                            $patternInfoMap[$chKey] ?? null
                        );
                    }
                }
            }

            // ensure channels with a matched pattern still get at least one
            // programme slot even when there is no cache data and dummy epg is
            // disabled; without this, the API would return an empty list and the
            // frontend would fall back to displaying its own dummy epg.
            foreach ($channels as $chKey => $info) {
                if (! empty($programmes[$chKey])) {
                    continue;
                }
                $pi = $patternInfoMap[$chKey] ?? null;
                if (empty($pi)) {
                    continue;
                }

                if (! empty($pi['start'])) {
                    // Pattern provided an explicit start time — use it directly.
                    $start = $pi['start']->toIso8601String();
                    $stop = isset($pi['stop'])
                        ? $pi['stop']->toIso8601String()
                        : Carbon::parse($start)->addMinutes($dummyEpgLength)->toIso8601String();
                } else {
                    // Pattern matched (event-only, no time captured) — fall back to
                    // a dummy time-slot so the channel still appears in the guide.
                    $programmes[$chKey] = $this->generateDummyProgrammesForChannel(
                        $info,
                        $startDate,
                        $endDate,
                        $dummyEpgLength,
                        $pi
                    );

                    continue;
                }

                $programmes[$chKey] = [
                    [
                        'start' => $start,
                        'stop' => $stop,
                        'title' => $pi['event'] ?? '',
                        'desc' => $pi['event'] ?? '',
                        'icon' => $channels[$chKey]['icon'] ?? '',
                        'category' => $channels[$chKey]['include_category'] ? $channels[$chKey]['group'] : null,
                    ],
                ];
            }

            // determine total channel count for pagination (ignoring per-page filter)
            $totalChannels = PlaylistGenerateController::getChannelQuery($playlist)
                ->when($search, function ($queryBuilder) use ($search) {
                    $search = Str::lower($search);

                    return $queryBuilder->where(function ($query) use ($search) {
                        $query->whereRaw('LOWER(channels.name) LIKE ?', ['%'.$search.'%'])
                            ->orWhereRaw('LOWER(channels.name_custom) LIKE ?', ['%'.$search.'%'])
                            ->orWhereRaw('LOWER(channels.title) LIKE ?', ['%'.$search.'%'])
                            ->orWhereRaw('LOWER(channels.title_custom) LIKE ?', ['%'.$search.'%']);
                    });
                })
                ->count();

            $pagination = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_channels' => $totalChannels,
                'returned_channels' => count($channels),
                'has_more' => (($page - 1) * $perPage + $perPage) < $totalChannels,
                'next_page' => (($page - 1) * $perPage + $perPage) < $totalChannels ? $page + 1 : null,
            ];

            // build final response
            $responseChannels = [];
            foreach ($channels as $channelId => $channelData) {
                $responseChannels[$channelId] = [
                    'id' => $channelId,
                    'database_id' => $channelData['database_id'] ?? null,
                    'display_name' => $channelData['display_name'],
                    'icon' => $channelData['icon'],
                    'lang' => $channelData['lang'] ?? 'en',
                    'sort_index' => $channelData['sort_index'] ?? 0,
                    'programmes' => $programmes[$channelId] ?? [],
                ];
            }

            return response()->json([
                'epg' => isset($epg) ? [
                    'id' => $epg->id,
                    'name' => $epg->name,
                    'uuid' => $epg->uuid,
                ] : null,
                'playlist' => [
                    'id' => $playlist->id,
                    'name' => $playlist->name,
                    'uuid' => $playlist->uuid,
                    'type' => get_class($playlist),
                ],
                'date_range' => [
                    'start' => $startDate,
                    'end' => $endDate,
                ],
                'pagination' => $pagination,
                'channels' => $responseChannels,
                'programmes' => $programmes,
                'cache_info' => $metadata,
            ]);
        } catch (Exception $e) {
            // log the full exception including trace to help diagnose issues in tests
            Log::error("Error retrieving EPG data for playlist {$playlist->uuid}: {$e->getMessage()}", [
                'exception' => $e,
            ]);
            Log::error($e->getTraceAsString());

            // rethrow so PHPUnit can display the origin
            throw $e;
        }
    }

    /**
     * Get EPG data for a network playlist.
     * Networks act as channels, and their programmes provide the EPG schedule.
     */
    private function getDataForNetworkPlaylist(\App\Models\Playlist $playlist, Request $request)
    {
        // Pagination parameters
        $page = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 50);
        $skip = max(0, ($page - 1) * $perPage);
        $search = $request->get('search', null);

        // Get parsed date range
        $dateRange = $this->parseDateRange($request);
        $startDate = $dateRange['start'];
        $endDate = $dateRange['end'];

        Log::debug('EPG API Request for Network Playlist', [
            'playlist_uuid' => $playlist->uuid,
            'playlist_name' => $playlist->name,
            'page' => $page,
            'per_page' => $perPage,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        try {
            // Get enabled networks that output to this playlist
            $networksQuery = $playlist->networks()
                ->where('enabled', true)
                ->when($search, function ($query) use ($search) {
                    $search = Str::lower($search);

                    return $query->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%']);
                })
                ->orderBy('channel_number')
                ->orderBy('name');

            $totalChannels = $networksQuery->count();
            $networks = $networksQuery->skip($skip)->take($perPage)->get();

            // Build channel data from networks
            $channels = [];
            $programmes = [];

            foreach ($networks as $network) {
                $channelNo = $network->channel_number ?? $network->id;

                // Get the stream URL - use HLS if broadcasting, otherwise legacy endpoint
                $url = $network->stream_url;

                // Get network logo or placeholder
                $icon = $network->logo ?? url('/placeholder.png');

                // Calculate broadcast offset for EPG playhead alignment
                $broadcastOffset = null;
                if ($network->isBroadcasting() && $network->broadcast_started_at) {
                    // Calculate how many seconds the broadcast has been running
                    $broadcastElapsed = (int) $network->broadcast_started_at->diffInSeconds(now());
                    // The actual media position is initial_offset + time since broadcast started
                    $actualMediaPosition = ($network->broadcast_initial_offset ?? 0) + $broadcastElapsed;

                    $broadcastOffset = [
                        'started_at' => $network->broadcast_started_at->toIso8601String(),
                        'initial_offset' => $network->broadcast_initial_offset ?? 0,
                        'broadcast_elapsed' => $broadcastElapsed,
                        'actual_media_position' => $actualMediaPosition,
                    ];
                }

                // Build channel entry
                $channels[$channelNo] = [
                    'id' => $channelNo,
                    'database_id' => null, // $network->id,
                    'url' => $url,
                    'format' => 'hls', // Network streams are HLS
                    'tvg_id' => 'network_'.$network->id,
                    'display_name' => $network->name,
                    'title' => $network->name,
                    'channel_number' => $network->channel_number ?? $channelNo,
                    'group' => 'Networks',
                    'icon' => $icon,
                    'has_epg' => true, // Networks always have EPG from programmes
                    'epg_channel_id' => 'network_'.$network->id,
                    'tvg_shift' => 0,
                    'sort_index' => $channelNo,
                    'is_network' => true, // Flag to identify network channels
                    'is_broadcasting' => $network->isBroadcasting(),
                    'broadcast_offset' => $broadcastOffset, // For EPG playhead alignment
                ];

                // Get programmes for this network within the date range
                $startDateTime = Carbon::parse($startDate)->startOfDay();
                $endDateTime = Carbon::parse($endDate)->endOfDay();

                $networkProgrammes = $network->programmes()
                    ->where('end_time', '>=', $startDateTime)
                    ->where('start_time', '<=', $endDateTime)
                    ->orderBy('start_time')
                    ->get();

                // Convert to EPG programme format
                $channelProgrammes = [];
                foreach ($networkProgrammes as $programme) {
                    $content = $programme->contentable;
                    $title = $content?->title ?? $content?->name ?? 'Unknown Program';
                    $desc = $content?->overview ?? $content?->description ?? '';

                    // Get content icon if available
                    $programmeIcon = null;
                    if ($content) {
                        $programmeIcon = $content->poster ?? $content->logo ?? null;
                    }

                    $channelProgrammes[] = [
                        'start' => $programme->start_time->toIso8601String(),
                        'stop' => $programme->end_time->toIso8601String(),
                        'title' => $title,
                        'desc' => $desc,
                        'icon' => $programmeIcon,
                        'category' => 'Network Content',
                    ];
                }

                $programmes[$channelNo] = $channelProgrammes;
            }

            // Create pagination info
            $pagination = [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_channels' => $totalChannels,
                'returned_channels' => count($channels),
                'has_more' => ($skip + $perPage) < $totalChannels,
                'next_page' => ($skip + $perPage) < $totalChannels ? $page + 1 : null,
            ];

            return response()->json([
                'playlist' => [
                    'id' => $playlist->id,
                    'name' => $playlist->name,
                    'uuid' => $playlist->uuid,
                    'type' => get_class($playlist),
                    'is_network_playlist' => true,
                ],
                'date_range' => [
                    'start' => $startDate,
                    'end' => $endDate,
                ],
                'pagination' => $pagination,
                'channels' => $channels,
                'programmes' => $programmes,
                'cache_info' => [
                    'cached' => false, // Network programmes are fetched live
                    'epg_count' => 0,
                    'channels_with_epg' => count($channels), // All networks have EPG
                ],
            ]);
        } catch (Exception $e) {
            Log::error("Error retrieving EPG data for network playlist {$playlist->name}: {$e->getMessage()}");

            return response()->json([
                'error' => 'Failed to retrieve EPG data: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Parse and validate date range from request
     *
     * @return array{start: string, end: string} Array with 'start' and 'end' date strings in Y-m-d format
     */
    private function parseDateRange(Request $request): array
    {
        // Date parameters - parse once and reuse Carbon instances
        $startDateInput = $request->get('start_date', Carbon::now()->format('Y-m-d'));
        $endDateInput = $request->get('end_date', $startDateInput);
        $startDateCarbon = Carbon::parse($startDateInput);
        $endDateCarbon = Carbon::parse($endDateInput);

        // Swap dates if start is after end
        if ($startDateCarbon->gt($endDateCarbon)) {
            [$startDateCarbon, $endDateCarbon] = [$endDateCarbon, $startDateCarbon];
        }

        // If starte and end date are the same, add some buffer before/after for programme overlap
        if ($startDateCarbon->gte($endDateCarbon)) {
            $startDateCarbon->subDay();
            $endDateCarbon->addDay();
        }

        return [
            'start' => $startDateCarbon->format('Y-m-d'),
            'end' => $endDateCarbon->format('Y-m-d'),
        ];
    }

    /**
     * Generate dummy programmes for a channel for the EPG.
     * This is used when there are no actual programmes available in the cache.
     *
     * @param  array  $channelInfo  Channel information array (must contain display_name, icon, group, include_category)
     * @param  \Carbon\Carbon  $startDate  Start date for the programme schedule
     * @param  \Carbon\Carbon  $endDate  End date for the programme schedule
     * @param  int  $duration  Duration in minutes for the dummy programme
     * @param  array|null  $patternInfo  Optional pattern information for title and timing
     * @return array Array of programme information arrays
     */
    private function generateDummyProgrammesForChannel(array $channelInfo, Carbon $startDate, Carbon $endDate, int $length, ?array $patternInfo = null): array
    {
        $programmes = [];

        $title = $channelInfo['display_name'] ?? '';

        // If pattern gave us explicit start/stop times, return just that one entry
        if (! empty($patternInfo['start'])) {
            $start = $patternInfo['start']->copy();
            $stop = ! empty($patternInfo['stop']) ? $patternInfo['stop']->copy() : $start->copy()->addMinutes($length);

            $programmes[] = [
                'start' => $start->toIso8601String(),
                'stop' => $stop->toIso8601String(),
                'title' => $title,
                'desc' => $title,
                'icon' => $channelInfo['icon'] ?? '',
                'category' => $channelInfo['include_category'] ? $channelInfo['group'] : null,
            ];

            return $programmes;
        }

        $current = $startDate->copy()->startOf('day');
        $end = $endDate->copy()->endOf('day');

        while ($current->lte($end)) {
            $start = $current->copy();
            $stop = $start->copy()->addMinutes($length);

            $programmes[] = [
                'start' => $start->toIso8601String(),
                'stop' => $stop->toIso8601String(),
                'title' => $title,
                'desc' => $title,
                'icon' => $channelInfo['icon'] ?? '',
                'category' => $channelInfo['include_category'] ? $channelInfo['group'] : null,
            ];

            $current->addMinutes($length);
        }

        return $programmes;
    }
}
