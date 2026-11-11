<?php
/** SSH transport, probe framing and Expect authentication payloads. @license GPL-3.0-only */


function pmssUserTransferBuildSshCommand(string $remoteUser, array $extraOptions = []): string
{
    // The receiver runs as the customer; ignore customer-controlled SSH config
    // so it cannot redirect the password prompt to a ProxyCommand.
    return 'ssh -F /dev/null '.implode(' ', array_merge([
        '-o Compression=no', '-o UserKnownHostsFile=/dev/null', '-o StrictHostKeyChecking=no',
    ], $extraOptions, ['-l '.escapeshellarg($remoteUser)]));
}

/** Build a probe command for validated transfer config; callers own script headers and redirection. */
function pmssUserTransferBuildSshProbeCommand(array $cfg, string $remoteCommand): string
{
    return pmssUserTransferBuildSshCommand($cfg['remoteUser'], ['-o ConnectTimeout=20', '-o NumberOfPasswordPrompts=1'])
        .' '.escapeshellarg($cfg['hostname']).' '.escapeshellarg($remoteCommand);
}

/** Frame one SSH probe; redirection and private-output policy stay at callers. */
function pmssUserTransferBuildSshProbeScript(array $cfg, string $remoteCommand, string $suffix = "\n", bool $private = false): string
{
    return "#!/bin/bash\nset -e\n".($private ? "umask 077\n" : '')
        .pmssUserTransferBuildSshProbeCommand($cfg, $remoteCommand).$suffix;
}

/** Source commands run under the authenticated account, never through remote root. */
function pmssUserTransferBuildEternalSourceCommand(array $cfg, ?int $until): string
{
    $binary = '"$HOME/.mcxPikkumies/pikkumies/bin/pikkumies"';
    $remote = $until === null
        ? 'rm -f -- "$HOME/.mcxPikkumies/pikkumies/local/eternals.hold"'
        : 'if [ -x '.$binary.' ] && [ -f /etc/seedbox/config/pikkumiesEternals.enabled ]; then '
            .$binary.' eternal hold --until '.(string) $until.'; fi'
            .'; if [ -f "$HOME/.hermes/pmss-install.status" ] && [ -x "$HOME/bin/install-hermes" ]; then '
            .'"$HOME/bin/install-hermes" stop; fi';
    return pmssUserTransferBuildSshProbeScript($cfg, $remote);
}

/** The source must stay cold after a successful hand-over, beyond hold expiry. */
function pmssUserTransferBuildEternalSourceOffCommand(array $cfg): string
{
    return pmssUserTransferBuildSshProbeScript($cfg, 'umask 077; local="$HOME/.mcxPikkumies/pikkumies/local"; '
        .'if [ -d "$local" ]; then touch -- "$local/eternals.off"; fi');
}

function pmssUserTransferBuildAuthProbe(array $cfg): string
{
    return pmssUserTransferBuildSshProbeScript($cfg, '/bin/true');
}

/** Probe the source as the authenticated account, with no account-controlled SSH config. */
function pmssUserTransferBuildNamespaceProbe(array $cfg): string
{
    return pmssUserTransferBuildSshProbeScript($cfg, 'unshare --map-auto --map-root-user true', " >/dev/null 2>&1\n");
}

/** Both account-owned namespace probes must succeed before changing rsync's ID space. */
function pmssUserTransferNamespaceRsyncAvailable(int $localRc, int $remoteRc): bool
{
    return $localRc === 0 && $remoteRc === 0;
}


function pmssUserTransferBuildExpectWrapper(): string
{
    return <<<'EXP'
#!/usr/bin/expect -f
set timeout -1

if {[llength $argv] != 1} {
    puts stderr "Usage: transfer.expect <command-path>"
    exit 2
}

if {![info exists env(PMSS_USER_TRANSFER_PASSWORD)]} {
    puts stderr "Missing env PMSS_USER_TRANSFER_PASSWORD"
    exit 2
}

set password $env(PMSS_USER_TRANSFER_PASSWORD)
set cmd [lindex $argv 0]

spawn -noecho $cmd
expect {
    -re "(?i)assword:" {
        send -- "$password\r"
        exp_continue
    }
    eof
}

set result [wait]
exit [lindex $result 3]
EXP;
}
