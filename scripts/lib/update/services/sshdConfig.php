<?php
/** SSH configuration transforms and guarded publication. @license GPL-3.0-only */

/**
 * Normalize sshd template content for older parsers that reject modern
 * list-append syntax or renamed key directives.
 */
function pmssSshdLegacyParserTemplateNormalize(string $config, ?callable $logger = null): string
{
    if (!is_array($lines = preg_split("/\r?\n/", $config))) {
        return $config;
    }
    $legacyDirectives = [
        'Ciphers' => 'Ciphers aes128-gcm@openssh.com,aes256-gcm@openssh.com,chacha20-poly1305@openssh.com,aes128-ctr,aes192-ctr,aes256-ctr,aes128-cbc,aes192-cbc,aes256-cbc',
        'KexAlgorithms' => 'KexAlgorithms curve25519-sha256@libssh.org,ecdh-sha2-nistp256,ecdh-sha2-nistp384,ecdh-sha2-nistp521,diffie-hellman-group-exchange-sha256,diffie-hellman-group-exchange-sha1,diffie-hellman-group14-sha1,diffie-hellman-group1-sha1',
        'MACs' => 'MACs hmac-sha2-512-etm@openssh.com,hmac-sha2-256-etm@openssh.com,umac-128-etm@openssh.com,hmac-sha2-512,hmac-sha2-256,hmac-sha1,hmac-sha1-96,hmac-md5,hmac-md5-96',
    ];
    $updatedLines = [];
    foreach ($lines as $line) {
        foreach ($legacyDirectives as $directive => $replacement) {
            if (preg_match('/^\s*'.preg_quote($directive, '/').'\s+/i', $line) === 1) {
                $updatedLines[] = $replacement;
                continue 2;
            }
        }
        if (preg_match('/^\s*(Host[kK]eyAlgorithms|PubkeyAcceptedKeyTypes)\s+/', $line) === 1) {
            if ($logger !== null) {
                $logger('[WARN] Legacy-parser fallback is commenting out security-affecting sshd directive: '.trim($line));
            }
            $updatedLines[] = '# '.$line;
            continue;
        }
        $updatedLines[] = $line;
    }
    // Replacements already in the output need no independent presence map.
    $updated = implode("\n", array_merge($updatedLines, array_diff($legacyDirectives, $updatedLines)));
    return ($updated !== '' && substr($updated, -1) !== "\n") ? $updated."\n" : $updated;
}

/** Normalize commented AuthorizedKeysFile directives into active directives. */
function pmssSshdAuthorizedKeysDirectiveNormalize(string $config): string
{
    return str_replace('#AuthorizedKeysFile', 'AuthorizedKeysFile', $config);
}

/** Persist a mutated sshd_config payload through the guarded managed-path flow. */
function pmssSshdConfigWriteUpdated(
    string $sshdConfig,
    string $config,
    string $updated,
    string $label,
    bool $writeManagedBackupCopy = false,
    string $backupCopyPath = '/etc/ssh/pmss.sshd_config'
): bool {
    pmssBackupCriticalConfig('sshd', $sshdConfig);
    $writeManagedBackupCopy && pmssWriteManagedPathFile($backupCopyPath, $config, 'sshd backup config', 'logMessage', 'root', 'root');
    return pmssWriteManagedPathFile($sshdConfig, $updated, $label, 'logMessage', 'root', 'root');
}

/** Build the sshd syntax validation command without depending on operator PATH. */
function pmssSshdValidationCommand(string $sshdPath = '/usr/sbin/sshd'): string
{
    return !is_executable($sshdPath)
        ? 'sshd -t'
        : ($sshdPath === '/usr/sbin/sshd' ? '/usr/sbin/sshd -t' : pmssBuildCommand($sshdPath, ['-t']));
}
