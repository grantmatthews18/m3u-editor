<?php

namespace App\Filament\Resources\CustomPlaylists\RelationManagers;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'channels';

    protected static ?string $label = 'Live Channels';

    protected static ?string $pluralLabel = 'Live Channels';

    protected static ?string $title = 'Live Channels';

    protected static ?string $navigationLabel = 'Live Channels';

    public function isReadOnly(): bool
    {
        $owner = $this->ownerRecord;

        return (bool) ($owner instanceof \App\Models\CustomPlaylist && $owner->usesRegexManagement());
    }

    public static function getTabComponent(Model $ownerRecord, string $pageClass): Tab
    {
        return Tab::make('Live Channels')
            ->badge($ownerRecord->channels()->where('is_vod', false)->count())
            ->icon('heroicon-m-film');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return ChannelResource::infolist($schema);
    }

    public function table(Table $table): Table
    {
        $ownerRecord = $this->ownerRecord;

        $groupColumn = SpatieTagsColumn::make('tags')
            ->label('Playlist Group')
            ->type($ownerRecord->uuid)
            ->toggleable()->searchable(query: function (Builder $query, string $search) use ($ownerRecord): Builder {
                return $query->whereHas('tags', function (Builder $query) use ($search, $ownerRecord) {
                    $query->where('tags.type', $ownerRecord->uuid);

                    // Cross-database compatible JSON search
                    $connection = $query->getConnection();
                    $driver = $connection->getDriverName();

                    switch ($driver) {
                        case 'pgsql':
                            // PostgreSQL uses ->> operator for JSON
                            $query->whereRaw('LOWER(tags.name->>\'$\') LIKE ?', ['%'.strtolower($search).'%']);
                            break;
                        case 'mysql':
                            // MySQL uses JSON_EXTRACT
                            $query->whereRaw('LOWER(JSON_EXTRACT(tags.name, "$")) LIKE ?', ['%'.strtolower($search).'%']);
                            break;
                        case 'sqlite':
                            // SQLite uses json_extract
                            $query->whereRaw('LOWER(json_extract(tags.name, "$")) LIKE ?', ['%'.strtolower($search).'%']);
                            break;
                        default:
                            // Fallback - try to search the JSON as text
                            $query->where(DB::raw('LOWER(CAST(tags.name AS TEXT))'), 'LIKE', '%'.strtolower($search).'%');
                            break;
                    }
                });
            })
            ->sortable(query: function (Builder $query, string $direction) use ($ownerRecord): Builder {
                $connection = $query->getConnection();
                $driver = $connection->getDriverName();

                // Build the ORDER BY clause based on database type
                $orderByClause = match ($driver) {
                    'pgsql' => 'tags.name->>\'$\'',
                    'mysql' => 'JSON_EXTRACT(tags.name, "$")',
                    'sqlite' => 'json_extract(tags.name, "$")',
                    default => 'CAST(tags.name AS TEXT)'
                };

                return $query
                    ->leftJoin('taggables', function ($join) {
                        $join->on('channels.id', '=', 'taggables.taggable_id')
                            ->where('taggables.taggable_type', '=', Channel::class);
                    })
                    ->leftJoin('tags', function ($join) use ($ownerRecord) {
                        $join->on('taggables.tag_id', '=', 'tags.id')
                            ->where('tags.type', '=', $ownerRecord->uuid);
                    })
                    ->orderByRaw("{$orderByClause} {$direction}")
                    ->select('channels.*', DB::raw("{$orderByClause} as tag_name_sort"))
                    ->distinct();
            });
        $defaultColumns = ChannelResource::getTableColumns(showGroup: true, showPlaylist: true);

        // if regex management is active, make the table completely non-interactive
        if ($ownerRecord instanceof \App\Models\CustomPlaylist && $ownerRecord->usesRegexManagement()) {
            // disable every column that supports the disabled modifier (primarily toggles)
            $defaultColumns = array_map(function ($col) {
                if (method_exists($col, 'disabled')) {
                    $col->disabled(true);
                }

                return $col;
            }, $defaultColumns);

            // also turn off any header/bulk/filter actions so the UI is visibly greyed out
            // note: older Filament versions may not support these helpers, so we rely on
            // isReadOnly which already disables most interactions; additional calls were
            // causing test failures and can be removed.
            // $table = $table
            //     ->disableHeaderActions()
            //     ->disableActions()
            //     ->disableBulkActions()
            //     ->disableFilters();
        }

        // before returning the table, build toolbar actions if the owner is not
        // in regex mode.  Keeping the toolbar around while read-only would allow
        // bulk edits / attaches despite the UI being otherwise disabled.
        $toolbarActions = [];
        if (! ($ownerRecord instanceof \App\Models\CustomPlaylist && $ownerRecord->usesRegexManagement())) {
            $toolbarActions = [
                AttachAction::make(),
                ...ChannelResource::getTableBulkActions(addToCustom: false),
            ];
        }

        return $table->persistFiltersInSession()
            ->columns($defaultColumns)
            ->filters([
                SelectFilter::make('group')
                    ->options(function () use ($ownerRecord) {
                        // groupTags returns a collection of stdClass records with a JSON
                        // `name` field.  Safely extract the english string value and
                        // use it for both key and label.
                        return $ownerRecord->groupTags()
                            ->get()
                            ->mapWithKeys(fn ($tag) => [
                                data_get($tag, 'name.en') => data_get($tag, 'name.en'),
                            ])
                            ->filter()
                            ->toArray();
                    }),
            ])
            ->recordActions([
                DetachAction::make()
                    ->color('danger')
                    ->button()->hiddenLabel()
                    ->icon('heroicon-o-x-circle')
                    ->action(function (Model $record) use ($ownerRecord): void {
                        $tags = $ownerRecord->groupTags()->get();
                        $record->detachTags($tags);
                        $ownerRecord->channels()->detach($record->id);
                    })
                    ->size('sm')
                    ->hidden(fn () => $ownerRecord->usesRegexManagement()),
                ...ChannelResource::getTableActions(),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions($toolbarActions);

        // end of table configuration
    }

    public function getTabs(): array
    {
        // Lets group the tabs by Custom Playlist tags
        $ownerRecord = $this->ownerRecord;
        $tags = $ownerRecord->tags()->where('type', $ownerRecord->uuid)->get();
        $tabs = $tags->map(
            fn ($tag) => Tab::make($tag->name)
                ->modifyQueryUsing(fn ($query) => $query->where('is_vod', false)->whereHas('tags', function ($tagQuery) use ($tag) {
                    $tagQuery->where('type', $tag->type)
                        ->where('name->en', $tag->name);
                }))
                ->badge($ownerRecord->channels()->where('is_vod', false)->withAnyTags([$tag], $tag->type)->count())
        )->toArray();

        // Add an "All" tab to show all channels
        array_unshift(
            $tabs,
            Tab::make('All')
                ->modifyQueryUsing(fn ($query) => $query->where('is_vod', false))
                ->badge($ownerRecord->channels()->where('is_vod', false)->count())
        );
        array_push(
            $tabs,
            Tab::make('Uncategorized')
                ->modifyQueryUsing(fn ($query) => $query->where('is_vod', false)->whereDoesntHave('tags', function ($tagQuery) use ($ownerRecord) {
                    $tagQuery->where('type', $ownerRecord->uuid);
                }))
                ->badge($ownerRecord->channels()->where('is_vod', false)->whereDoesntHave('tags', function ($tagQuery) use ($ownerRecord) {
                    $tagQuery->where('type', $ownerRecord->uuid);
                })->count())
        );

        return $tabs;
    }
}
