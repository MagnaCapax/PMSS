<?php
/** Repair stale local pip launchers without replacing working local installs.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../../runtime/commands.php';

/** Back up missing-interpreter launchers only after the distro replacement works. */
function pmssPythonPipRepair(string $localBin = '/usr/local/bin', string $systemBin = '/usr/bin'): void
{
    foreach ([$localBin, $systemBin] as $directory) {
        if ($directory === '' || strpos($directory, "\0") !== false || is_link($directory)) {
            return;
        }
    }

    foreach (['pip', 'pip3'] as $name) {
        $local = rtrim($localBin, '/').'/'.$name;
        $system = rtrim($systemBin, '/').'/'.$name;
        if (is_link($local) || !is_file($local) || !is_executable($system)) {
            continue;
        }

        // Inspect bytes only: never execute the stale local launcher as root.
        $header = @file_get_contents($local, false, null, 0, 512);
        if (!is_string($header)
            || preg_match('~\A\#![ \t]*(/[^ \t\r\n\x00]+/python(?:[23](?:\.[0-9]+)*)?)(?:[ \t]|\r?\n|\z)~', $header, $match) !== 1
            || file_exists($match[1])
        ) {
            continue;
        }

        // Preserve the old launcher for recovery; an existing backup is never replaced.
        $backup = $local.'.pmss-disabled';
        if (file_exists($backup) || is_link($backup)) {
            logMessage('[WARN] Leaving stale '.$local.' in place: backup already exists');
            continue;
        }
        if (runStep('Checking distro '.$name.' before stale launcher repair', pmssBuildCommand($system, ['--version'])) !== 0) {
            continue;
        }
        if (runStep('Backing up stale local '.$name.' launcher', pmssBuildCommand('mv', ['-nT', '--', $local, $backup])) !== 0) {
            logMessage('[WARN] Failed to back up stale '.$local.' launcher');
        }
    }
}
