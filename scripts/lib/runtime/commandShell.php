<?php
/** Shell command construction; loaded by environment.php. @license GPL-3.0-only */

function pmssCommandArgvShellQuote(array $argv): string { return implode(' ', array_map(static function ($arg): string { return escapeshellarg((string) $arg); }, $argv)); }

/**
 * Build a shell-safe argv-style command string from a program and arguments.
 */
function pmssBuildCommand(string $program, array $args = []): string
{
    return escapeshellcmd($program).(empty($args) ? '' : ' '.pmssCommandArgvShellQuote($args));
}

function pmssBuildUserShellCommand(string $username, string $command, string $shell = ''): string { return $shell === '' ? 'su '.escapeshellarg($username).' -c '.escapeshellarg($command) : 'su -s '.escapeshellarg($shell).' -c '.escapeshellarg($command).' '.escapeshellarg($username); }

/** Avoid su's logind session; isolate the operator TTY and environment from the account process. */
function pmssBuildUserShellNoPamCommand(string $username, string $command): string
{
    static $supportsPty = null;
    if ($supportsPty === null) {
        $help = @shell_exec('runuser --help 2>&1');
        $supportsPty = is_string($help) && strpos($help, '--pty') !== false;
        error_log('PMSS no-PAM runuser mode: '.($supportsPty ? 'pty' : 'setsid'));
    }
    $runner = $supportsPty ? 'runuser --pty' : 'setsid runuser';
    return 'env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin '.$runner.' -u '.escapeshellarg($username).' -- sh -c '.escapeshellarg($command).' </dev/null';
}
