#!/bin/bash
# Installs/updates the app in the Nextcloud AIO container.
#   ./deploy.sh            copy + enable (+ run app migrations if the version changed)
set -euo pipefail
cd "$(dirname "$0")"

CONTAINER=nextcloud-aio-nextcloud
TARGET=/var/www/html/custom_apps/immich_search
occ() { docker exec -u www-data "$CONTAINER" php occ "$@"; }

for f in $(find lib appinfo templates -name '*.php'); do
    docker exec -i "$CONTAINER" php -l >/dev/null < "$f" || { echo "Syntaxfehler: $f" >&2; exit 1; }
done

docker exec "$CONTAINER" rm -rf "$TARGET.new"
tar --exclude=.git --exclude=deploy.sh -cf - . | docker exec -i "$CONTAINER" sh -c "mkdir $TARGET.new && tar -xf - -C $TARGET.new"
docker exec "$CONTAINER" sh -c "chown -R www-data:www-data $TARGET.new && rm -rf $TARGET && mv $TARGET.new $TARGET"

new=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
old=$(occ config:app:get immich_search installed_version 2>/dev/null || true)
enabled=$(occ config:app:get immich_search enabled 2>/dev/null || true)

if [ "$enabled" != "yes" ]; then
    occ app:enable immich_search
elif [ "$new" != "$old" ]; then
    # A version bump needs the upgrade step, otherwise Nextcloud shows its update screen.
    # It switches maintenance mode on for a few seconds, so only run it when needed.
    occ upgrade --no-interaction >/dev/null 2>&1
fi
echo "immich_search $(occ config:app:get immich_search installed_version) aktiv"
