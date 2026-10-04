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

if occ app:list --enabled --output=json | grep -q '"immich_search"'; then
    # A version bump in appinfo/info.xml needs the upgrade step, otherwise Nextcloud
    # shows the update screen on the next page load.
    occ upgrade --no-interaction >/dev/null || true
else
    occ app:enable immich_search
fi
occ app:list --output=json | python3 -c 'import json,sys; print("immich_search", json.load(sys.stdin)["enabled"].get("immich_search", "NICHT aktiv"))'
