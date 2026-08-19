# Pelican Panel plugins

A workspace holding one folder per [Pelican Panel](https://pelican.dev) plugin. The root is a Composer project whose only job is to make the panel's classes resolvable, so an IDE gives you full completion for `App\*`, `Filament\*` and `Illuminate\*` the moment you open this folder.

| Plugin | What it does |
|---|---|
| [`reverse-proxy/`](reverse-proxy/) | Gives server ports real hostnames via Nginx Proxy Manager or NPMplus |
| [`sftp-helper/`](sftp-helper/) | Shows SFTP connection details at the top of the Files page |

## Setup

Everything runs in Docker — no PHP on the host required.

```bash
docker compose build
docker compose run --rm php composer install
```

That installs `pelican-dev/panel` (plus Laravel and Filament) into `vendor/`, pinned by `composer.lock` to a commit the plugin code has been checked against. `vendor/` lands on the host on purpose: that's what your IDE indexes for completion.

Two details worth knowing, both already handled:

- The panel isn't on Packagist, so `composer.json` points at its GitHub repository with `"no-api": true`. That makes Composer fetch it over git instead of the GitHub API, which would otherwise fail on an unauthenticated rate limit.
- The image adds `bcmath`, `intl` and `zip` on top of `php:8.4-cli` — everything the dependency tree actually requires — so no `--ignore-platform-reqs` appears anywhere here. If you add a plugin whose dependencies need more, `composer install` will name the extension and it goes in `docker/Dockerfile`.

### PHPStorm

Point the interpreter at the compose service and everything else follows:

**Settings → PHP → CLI Interpreter → `...` → Add → From Docker, Vagrant, VM, WSL, Remote… → Docker Compose**, configuration file `compose.yaml`, service `php`.

With that set, PHPStorm can also run the tools directly: **Settings → PHP → Quality Tools** for Pint, and **Test Frameworks** for PHPUnit, both using the same interpreter.

## Adding a plugin

Create the folder, then add its namespace to `autoload.psr-4` in the root `composer.json` and re-dump:

```json
"Vendor\\PluginName\\": "plugin-name/src/"
```

```bash
docker compose run --rm php composer dump-autoload
```

The folder name must match the `id` in that plugin's `plugin.json`. Nothing else needs updating — the analysis scripts and CI discover plugins by looking for `plugin.json`, and read the namespace from it.

## Checks

```bash
docker compose run --rm php composer pint:test    # style, report only
docker compose run --rm php composer pint         # style, fix
docker compose run --rm php composer analyse      # PHPStan level 6
docker compose run --rm php composer test         # PHPUnit
```

`analyse` and `test` take extra arguments, e.g.:

```bash
docker compose run --rm php composer test -- --filter NpmFamilyDriver
```

## Packaging for install

```bash
docker compose run --rm php composer build            # every plugin
docker compose run --rm php composer build -- reverse-proxy
```

Archives land in `dist/<id>-<version>.zip`, laid out with a single top-level `<id>/` folder. Before packaging, the builder checks the things the panel checks later — required manifest fields, `id` matching the folder name, `id` being a safe directory name, and no leftover `meta` section — so a broken archive fails here rather than at import. `tests/`, `vendor/` and IDE files are excluded.

Install it either way:

- **Panel UI** — *Admin → Plugins → Import*, upload the zip. The panel accepts a flat archive or a single top-level folder, and takes the install directory from `plugin.json`'s `id` rather than the file name, so renaming the zip is harmless.
- **CLI** — unzip into the panel's `plugins/` directory and run `php artisan p:plugin:install <id>`.

`php artisan p:plugin:uninstall <id>` reverses it, rolling back the plugin's migrations.

## Releasing

Tags are prefixed with the plugin id, because tags are repository-wide:

```bash
# bump the version in <plugin>/plugin.json first, then
git tag reverse-proxy-v1.1.0
git push origin reverse-proxy-v1.1.0
```

`.github/workflows/release.yml` then parses the tag, **refuses to continue if `plugin.json`'s version disagrees with it**, builds the archive, publishes a GitHub release with the zip attached, and records the release in `update.json`.

Two details about how this is arranged:

- **`update.json` is one shared feed.** Pelican looks for the plugin's own id as a top-level key (`Plugin::getUpdateData`), so a single file serves every plugin here. Inside each entry, keys are panel versions with `*` as the fallback — which is what lets an old panel be pinned to an older plugin build.
- **`url` and `update_url` are injected at build time** from `github.repository`, not committed. That keeps the repository slug in one place and lets a rename or fork keep working, and means a locally built zip does not advertise an update feed it was never released to.

GitHub's "Latest release" badge is also repository-wide, so it flips between unrelated plugins. Nothing here relies on it — the feed points at explicit versioned asset URLs.

### Why one repository rather than one per plugin

The shared parts are the expensive ones: the compose environment, the panel dev-dependency, CI, the Pint and PHPStan configuration, and `panel-setup.sh`. Splitting would duplicate all of that per plugin to solve what is mostly a Releases-page annoyance. Pelican's support for a multi-plugin update feed removes the one genuine blocker.

If a plugin ever warrants its own issue tracker and visibility, the usual answer is a CI subtree split — this repository stays the source of truth and pushes to a read-only per-plugin distribution repository. Choosing one repository now does not close that off.

### Updating an installed plugin

Importing a zip replaces the plugin's files with a rollback safety net, and does **not** compare versions — so re-importing the same version is fine. But import does not run migrations; only the panel's *Update* action does, via `installPlugin()`. That is the reason to set `update_url` at all: without it there is no way to deliver a schema change short of uninstalling, which drops the plugin's tables and its data.

The Update action is also hidden when the panel reports its version as `canary`, since both `isUpdateAvailable()` and `getDownloadUrlForUpdate()` return early for it. On a git-checkout panel, Import stays the only route.

### Why those two need a panel checkout

Pint only needs source, so it runs anywhere. PHPStan and PHPUnit don't: larastan boots a real Laravel application to understand Eloquent and facades, and the panel owns the PHPUnit harness. That can't happen from this workspace — with the panel installed into `vendor/`, its own dependencies are hoisted up to the root `vendor/`, so `base_path('vendor')` is empty, Laravel's package discovery finds nothing, and booting dies on `Target class [livewire.finder] does not exist`.

So `scripts/panel-setup.sh` maintains a throwaway panel checkout at `/panel` inside the container, copies the plugins into its `plugins/` directory, registers their namespaces and runs the tools from there. `composer analyse` and `composer test` call it for you; it's idempotent, and only the first run pays for the clone and install.

`/panel` is a named Docker volume rather than a folder on the host, specifically so your IDE doesn't index a second copy of Laravel and Filament and start reporting every framework class as multiply defined.

CI does the same thing on GitHub runners — see `.github/workflows/lint.yml`.

### Without Docker

If you'd rather use a local PHP 8.3+ and Composer, `composer install --ignore-platform-reqs` covers the IDE side, and `scripts/panel-setup.sh` works if you point it at your own paths:

```bash
WORKSPACE="$PWD" PANEL="$PWD/.panel" bash scripts/panel-setup.sh
cd .panel && vendor/bin/phpstan analyse --memory-limit=-1
```

`.panel/` is gitignored. Mark it **Excluded** in your IDE to avoid the duplicate-class problem described above.

## Installing a plugin into a real panel

```bash
cp -r reverse-proxy /path/to/panel/plugins/
cd /path/to/panel && php artisan p:plugin:install reverse-proxy
```
