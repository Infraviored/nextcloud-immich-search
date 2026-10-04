#!/bin/bash
# Installs/updates the app in a dockerized Nextcloud (default: Nextcloud AIO).
#   ./deploy.sh                          CONTAINER=my-nextcloud ./deploy.sh
# Copies the release files, enables the app, and runs the upgrade step only when
# the version in appinfo/info.xml changed (it switches maintenance mode on briefly).
set -euo pipefail
cd "$(dirname "$0")"

CONTAINER=${CONTAINER:-nextcloud-aio-nextcloud}
APPS_DIR=${APPS_DIR:-/var/www/html/custom_apps}
TARGET=$APPS_DIR/immich_search
occ() { docker exec -u www-data "$CONTAINER" php occ "$@"; }

make -s dist >/dev/null
tarball=$(ls -t build/immich_search-*.tar.gz | head -1)

docker exec "$CONTAINER" rm -rf "$TARGET.new"
docker exec -i "$CONTAINER" sh -c "mkdir $TARGET.new && tar -xzf - -C $TARGET.new --strip-components=1" < "$tarball"
docker exec "$CONTAINER" sh -c "chown -R www-data:www-data $TARGET.new && rm -rf $TARGET && mv $TARGET.new $TARGET"

new=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
old=$(occ config:app:get immich_search installed_version 2>/dev/null || true)
enabled=$(occ config:app:get immich_search enabled 2>/dev/null || true)

if [ "$enabled" != "yes" ]; then
    occ app:enable immich_search
elif [ "$new" != "$old" ]; then
    occ upgrade --no-interaction >/dev/null 2>&1
fi
echo "immich_search $(occ config:app:get immich_search installed_version) active"
