#!/usr/bin/env bash

# Prepares /panel: a throwaway Pelican Panel checkout that PHPStan and PHPUnit
# run against. They cannot run from the workspace root - larastan boots a real
# Laravel application, and Laravel resolves its packages relative to its own
# root, which breaks when the panel sits inside vendor/. See README.md.
#
# Idempotent: clones and installs only what is missing, then re-syncs plugins.
# Meant to be run inside the compose `php` service.

set -euo pipefail

WORKSPACE="${WORKSPACE:-/workspace}"
PANEL="${PANEL:-/panel}"

if [ ! -d "$PANEL/.git" ]; then
    echo "==> cloning Pelican Panel into $PANEL"
    git clone --depth=1 https://github.com/pelican-dev/panel "$PANEL"
fi

cd "$PANEL"

if [ ! -d vendor ]; then
    echo "==> installing panel dependencies (this takes a few minutes once)"
    composer install --no-interaction --no-progress --no-scripts
fi

if [ ! -f .env ]; then
    echo "==> creating .env"
    cp .env.example .env
    php artisan key:generate
fi

echo "==> syncing plugins from $WORKSPACE"
mkdir -p plugins
for dir in "$WORKSPACE"/*/; do
    [ -f "$dir/plugin.json" ] || continue
    name="$(basename "$dir")"
    rm -rf "plugins/$name"
    cp -r "$dir" "plugins/$name"
    echo "    $name"
done

php "$WORKSPACE/scripts/register-plugin-namespaces.php"

# --no-scripts: the panel's post-autoload-dump hook runs `artisan
# filament:upgrade`, which needs a fully configured app and fails here.
composer dump-autoload --no-scripts --quiet

cp "$WORKSPACE/phpstan.neon" phpstan.neon

echo "==> syncing plugin tests"
for tests in plugins/*/tests/Unit/.; do
    [ -d "$tests" ] || continue
    cp -r "$tests" tests/Unit/
done

echo "==> ready"
