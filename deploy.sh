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

make -s build >/dev/null

# Browsers cache the app's scripts and Nextcloud caches its routes by app version. If
# anything shipped changed but the version did not, bump the patch version, or users keep
# seeing the old interface and new routes answer 404.
stamp=$(find js l10n templates lib appinfo img -type f ! -name info.xml -print0 | sort -z | xargs -0 cat | sha256sum | cut -c1-16)
version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
last=$(cat build/.deployed 2>/dev/null || true)
if [ -n "$last" ] && [ "${last% *}" = "$version" ] && [ "${last#* }" != "$stamp" ]; then
    IFS=. read -r major minor patch <<< "$version"
    bumped="$major.$minor.$((patch + 1))"
    sed -i "s:<version>$version</version>:<version>$bumped</version>:" appinfo/info.xml
    echo "frontend changed: version $version -> $bumped"
    version=$bumped
fi

# A malformed version puts the whole Nextcloud into "update required" (503 for everyone).
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "invalid version '$version' in appinfo/info.xml" >&2; exit 1; }

make -s dist >/dev/null
tarball=build/immich_search-$version.tar.gz

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
mkdir -p build && echo "$version $stamp" > build/.deployed
echo "immich_search $(occ config:app:get immich_search installed_version) active"
