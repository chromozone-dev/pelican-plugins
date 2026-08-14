<?php

/*
 * Registers each plugin's PSR-4 namespace in the surrounding panel's
 * composer.json, so PHPStan and PHPUnit can resolve plugin classes.
 *
 * At runtime the panel does this itself, from the database, in
 * PluginService::loadPlugins(). Static analysis has no database and no
 * installed plugins, so the mapping has to be written explicitly.
 *
 * Run from the panel root, after copying plugins into its plugins/ directory:
 *
 *     cd .panel && php ../scripts/register-plugin-namespaces.php
 */

$composerPath = getcwd() . '/composer.json';

if (!is_file($composerPath)) {
    fwrite(STDERR, 'No composer.json in ' . getcwd() . " - run this from the panel root.\n");
    exit(1);
}

$composer = json_decode((string) file_get_contents($composerPath), true);

if (!is_array($composer)) {
    fwrite(STDERR, "Could not parse $composerPath.\n");
    exit(1);
}

$manifests = glob(getcwd() . '/plugins/*/plugin.json') ?: [];
$registered = 0;

foreach ($manifests as $manifestPath) {
    $manifest = json_decode((string) file_get_contents($manifestPath), true);

    if (!is_array($manifest) || empty($manifest['namespace']) || empty($manifest['id'])) {
        fwrite(STDERR, "Skipping $manifestPath: missing id or namespace.\n");

        continue;
    }

    $namespace = rtrim((string) $manifest['namespace'], '\\') . '\\';
    $path = 'plugins/' . $manifest['id'] . '/src/';

    $composer['autoload-dev']['psr-4'][$namespace] = $path;
    $registered++;

    echo "registered $namespace => $path\n";
}

if ($registered === 0) {
    fwrite(STDERR, 'No plugin manifests found under ' . getcwd() . "/plugins/.\n");
    exit(1);
}

file_put_contents(
    $composerPath,
    json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
);

echo "updated composer.json ($registered plugin" . ($registered === 1 ? '' : 's') . ")\n";
