<?php

/*
 * Builds installable plugin archives.
 *
 *     php scripts/build-plugin.php              # every plugin in the workspace
 *     php scripts/build-plugin.php reverse-proxy
 *
 * Archives land in dist/<id>-<version>.zip with a single top-level <id>/ folder.
 * Pelican's importer accepts either that or a flat archive, and derives the
 * install directory from plugin.json's `id` rather than the file name - but the
 * folder layout is also what you want if you unzip straight into plugins/.
 *
 * When PLUGINS_REPOSITORY is set (the release workflow sets it from
 * github.repository), `url` and `update_url` are stamped into the manifest
 * inside the archive. They are injected rather than committed so the repository
 * slug lives in exactly one place and survives a rename or a fork - and so a
 * locally built zip does not advertise an update feed it was never released to.
 */

const EXCLUDED = [
    '.git',
    '.github',
    '.idea',
    'node_modules',
    'vendor',
    'dist',
    // Dev-only: the panel never loads it, and it references the panel's own test
    // harness, which does not exist in a production install.
    'tests',
];

const EXCLUDED_FILES = [
    '.DS_Store',
    'Thumbs.db',
    '.gitignore',
    '.gitattributes',
];

const REQUIRED_FIELDS = ['id', 'name', 'author', 'category', 'namespace', 'class'];

$root = dirname(__DIR__);
$requested = array_slice($argv, 1);

$plugins = [];

foreach (glob($root . '/*/plugin.json') ?: [] as $manifestPath) {
    $dir = dirname($manifestPath);
    $plugins[basename($dir)] = $dir;
}

if ($requested !== []) {
    $unknown = array_diff($requested, array_keys($plugins));

    if ($unknown !== []) {
        fwrite(STDERR, 'Unknown plugin(s): ' . implode(', ', $unknown) . "\n");
        exit(1);
    }

    $plugins = array_intersect_key($plugins, array_flip($requested));
}

if ($plugins === []) {
    fwrite(STDERR, "No plugins found (looked for */plugin.json).\n");
    exit(1);
}

$distDir = $root . '/dist';

if (!is_dir($distDir) && !mkdir($distDir, 0o755, true)) {
    fwrite(STDERR, "Could not create $distDir.\n");
    exit(1);
}

$failed = false;

foreach ($plugins as $id => $dir) {
    try {
        echo build($id, $dir, $distDir), "\n";
    } catch (RuntimeException $exception) {
        fwrite(STDERR, "$id: " . $exception->getMessage() . "\n");
        $failed = true;
    }
}

exit($failed ? 1 : 0);

/** @throws RuntimeException */
function build(string $id, string $dir, string $distDir): string
{
    $manifest = json_decode((string) file_get_contents($dir . '/plugin.json'), true);

    if (!is_array($manifest)) {
        throw new RuntimeException('plugin.json is not valid JSON.');
    }

    foreach (REQUIRED_FIELDS as $field) {
        if (blank_value($manifest[$field] ?? null)) {
            throw new RuntimeException("plugin.json is missing required field \"$field\".");
        }
    }

    // The panel installs to plugins/<id> and later expects that directory to
    // equal the id, so a mismatch here becomes a broken install.
    if ($manifest['id'] !== $id) {
        throw new RuntimeException("plugin.json id \"{$manifest['id']}\" does not match its folder name \"$id\".");
    }

    // Mirrors the panel's own check, which rejects anything that could escape the
    // plugins directory when used as a path.
    if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $id)) {
        throw new RuntimeException("id \"$id\" is not a valid plugin directory name.");
    }

    if (array_key_exists('meta', $manifest)) {
        throw new RuntimeException('plugin.json still has a "meta" section; it is added by a local install and must be removed before packaging.');
    }

    $version = (string) ($manifest['version'] ?? '0.0.0');
    $zipPath = $distDir . '/' . $id . '-' . $version . '.zip';

    @unlink($zipPath);

    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Could not create $zipPath.");
    }

    $count = 0;
    $repository = trim((string) getenv('PLUGINS_REPOSITORY'));

    foreach (collectFiles($dir) as $absolute) {
        $relative = str_replace('\\', '/', substr($absolute, strlen($dir) + 1));

        // The manifest is rewritten in the archive rather than on disk, so the
        // working tree is never mutated by a build.
        if ($relative === 'plugin.json' && $repository !== '') {
            $zip->addFromString($id . '/plugin.json', releaseManifest($manifest, $id, $repository));
            $count++;

            continue;
        }

        $zip->addFile($absolute, $id . '/' . $relative);
        $count++;
    }

    if ($count === 0) {
        $zip->close();
        throw new RuntimeException('No files to package.');
    }

    $zip->close();

    return sprintf(
        '%s  %d files, %s KiB  ->  dist/%s',
        str_pad($id, 20),
        $count,
        number_format(filesize($zipPath) / 1024, 1),
        basename($zipPath),
    );
}

/**
 * Adds the published locations to a manifest. update_url points at a single
 * update.json shared by every plugin in the repository - Pelican looks for the
 * plugin's own id as a top-level key, so one feed serves them all.
 *
 * @param  array<string, mixed>  $manifest
 */
function releaseManifest(array $manifest, string $id, string $repository): string
{
    $manifest['url'] = "https://github.com/$repository/tree/main/$id";
    $manifest['update_url'] = "https://raw.githubusercontent.com/$repository/main/update.json";

    return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

/** @return list<string> */
function collectFiles(string $dir): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $file) {
                $name = $file->getFilename();

                if ($file->isDir()) {
                    return !in_array($name, EXCLUDED, true);
                }

                return !in_array($name, EXCLUDED_FILES, true) && !str_ends_with($name, '.zip');
            },
        ),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

function blank_value(mixed $value): bool
{
    return $value === null || $value === '' || $value === [];
}
