<?php
/** Deluge WebUI base-path transformation and venv publication preflight. */

require_once __DIR__.'/deluge/patchFile.php';

/** Twisted requires bytes for the legacy Deluge 2.0/2.1 base-path child. */
function pmssPatchDelugeWebBasePathBytes(string $path, bool $dryRun, callable $log): bool
{
    return pmssDelugePatchFile($path, $dryRun, $log, ['server.py', 'WebUI base path bytes', 'WebUI base path'], static function (array &$lines): bool {
        $before = "putChild(self.base.strip('/'), self.top_level)";
        $after = "putChild(self.base.strip('/').encode(), self.top_level)";
        $line = pmssDelugeLineSearch($lines, $before);
        if ($line === null) return pmssDelugeLineSearch($lines, $after) !== null;
        $lines[$line] = str_replace($before, $after, $lines[$line]);

        return true;
    });
}

/** Do not publish a venv when a required base-path repair fails. */
function pmssDelugeWebBasePathPatchVenv(string $versionDir, callable $log, callable $patch): bool
{
    foreach (glob($versionDir.'/lib/python3*/site-packages/deluge/ui/web/server.py') ?: [] as $path) {
        $lines = pmssDelugeReadPatchLines($path, $log, '[WARN] Unable to inspect Deluge WebUI base path in ');
        if ($lines === null) return false;
        // The patch's false result also means no change is needed in Deluge 2.2+.
        $needed = pmssDelugeLineSearch($lines, "putChild(self.base.strip('/'), self.top_level)") !== null;
        if (!$patch($path, false, $log) && $needed) return false;
    }
    return true;
}
