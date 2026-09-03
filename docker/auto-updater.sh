#!/bin/sh
set -eu

exec php /usr/local/lib/loopdeck/auto-updater.php "$@"
