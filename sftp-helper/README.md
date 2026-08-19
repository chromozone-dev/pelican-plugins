# SFTP Helper

Puts the server's SFTP connection details at the top of the **Files** page instead of leaving them buried in Settings. Connecting to SFTP is something you almost always want to do right before uploading or editing files, not while looking at server limits.

Shows the same connection string, username and password note as the "SFTP Information" section on the Settings page, with the same copy buttons and "Connect to SFTP" hint action - it's the same data, just where you actually need it. The Settings page is untouched; this only adds the section to Files.

The connection string and hint action are folder-aware: browse into a subfolder and they point straight at it, matching the Files page's own toolbar "Connect to SFTP" button.

The section is collapsible (and remembers whether you collapsed it) and is hidden entirely for anyone without the `file.sftp` subuser permission, exactly like the Settings section.

## Install

Requires Pelican Panel **v1.0.0-beta36 or newer** (Filament 5 / the `registerCustomHeaderWidgets` extension point).

Build the archive from the workspace root with `composer build`, then either upload `dist/sftp-helper-<version>.zip` via *Admin → Plugins → Import*, or install it from the command line:

```bash
# unzip into your panel's plugins/ directory, then from the panel directory
php artisan p:plugin:install sftp-helper
```

No migrations, no settings, no config - it's a single read-only widget.
