#!/usr/bin/env bash
# diagnostics helper for regex/EPG problems
# Usage: ./scripts/epg-debug.sh <playlist_id> <channel_id>
# Run this from inside the container (workspace root) with the appropriate IDs.

set -euo pipefail

if [[ $# -ne 2 ]]; then
    echo "Usage: $0 <playlist_id> <channel_id>"
    exit 1
fi

PL_ID=$1
CH_ID=$2

echo "Clearing caches and compiled data..."
php artisan cache:clear
php artisan route:clear
php artisan config:clear
php artisan view:clear

echo
echo "==== last 20 lines of laravel.log ===="
tail -n20 storage/logs/laravel.log || true

echo
echo "==== applyEventPattern output ===="
php artisan tinker --execute="\
    \$pl = App\\Models\\CustomPlaylist::find($PL_ID); \
    \$ch = App\\Models\\Channel::find($CH_ID); \
    dd(\$pl->applyEventPattern(\$ch));
"

echo
echo "==== generated XML for playlist (direct) ===="
php artisan tinker --execute="\
    \$pl = App\\Models\\CustomPlaylist::find($PL_ID); \
    \$ctrl = app(App\\Http\\Controllers\\EpgGenerateController::class); \
    \$m = new ReflectionMethod(\$ctrl, 'generate'); \
    \$m->setAccessible(true); \
    ob_start(); \
    \$m->invoke(\$ctrl, \$pl); \
    \$xml = ob_get_clean(); \
    echo \$xml; \
"

echo
echo "Finished diagnostics.  Review the output above for clues."
