<?php

/*
 * Records a released plugin version in update.json, the feed Pelican polls.
 *
 *     php scripts/update-manifest.php <plugin-id> <version> <owner/repo>
 *
 * Shape, which is Pelican's own (see Plugin::getUpdateData and
 * Plugin::isUpdateAvailable):
 *
 *     {
 *       "reverse-proxy": {
 *         "*": { "version": "1.1.0", "download_url": "https://.../x.zip" }
 *       }
 *     }
 *
 * The panel looks for the plugin's id as a top-level key, so a single feed serves
 * every plugin in the repository. Inside it, keys are panel versions with "*" as
 * the fallback, which is what lets an older panel be pinned to an older build.
 *
 * Only the named plugin is touched. Regenerating the whole file from the working
 * tree would advertise versions that were never released - a plugin sitting at
 * 1.2.0 in source but released at 1.1.0 would gain a download URL that 404s.
 */

const MANIFEST = 'update.json';

[$script, $id, $version, $repository] = array_pad($argv, 4, null);

if (blank_arg($id) || blank_arg($version) || blank_arg($repository)) {
    fwrite(STDERR, "Usage: php scripts/update-manifest.php <plugin-id> <version> <owner/repo>\n");
    exit(1);
}

$root = dirname(__DIR__);

if (!is_file("$root/$id/plugin.json")) {
    fwrite(STDERR, "No plugin found at $id/plugin.json.\n");
    exit(1);
}

$path = "$root/" . MANIFEST;
$feed = [];

if (is_file($path)) {
    $decoded = json_decode((string) file_get_contents($path), true);

    if (!is_array($decoded)) {
        fwrite(STDERR, MANIFEST . " exists but is not valid JSON; refusing to overwrite it.\n");
        exit(1);
    }

    $feed = $decoded;
}

// Tag naming has to match the release workflow, which builds it the same way.
$tag = "$id-v$version";

$feed[$id] = [
    '*' => [
        'version' => $version,
        'download_url' => "https://github.com/$repository/releases/download/$tag/$id-$version.zip",
    ],
];

ksort($feed);

file_put_contents(
    $path,
    json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
);

echo "recorded $id $version in " . MANIFEST . "\n";
echo '  ' . $feed[$id]['*']['download_url'] . "\n";

function blank_arg(?string $value): bool
{
    return $value === null || trim($value) === '';
}
