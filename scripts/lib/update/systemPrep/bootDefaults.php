<?php
/**
 * Boot-default convergence helpers for update-step2 system preparation.
 *
 * Keeps /proc hidepid and GRUB default convergence separate from the broader
 * system-prep runtime so callers keep one stable public entrypoint.
 *
 * @license GPL-3.0-only
 */

function pmssBootDefaultsRequiredGrubOptions(string $grubOption, ?array $extraGrubOptions): array
{
    $options = [];
    foreach (array_merge([$grubOption], is_array($extraGrubOptions) ? $extraGrubOptions : []) as $option) {
        $option = trim((string) $option);
        if ($option !== '') $options[] = $option;
    }
    return array_values(array_unique($options));
}

function pmssBootDefaultsRequiredGrubSettings(?array $extraGrubSettings): array
{
    $settings = [];
    foreach (is_array($extraGrubSettings) ? $extraGrubSettings : [] as $key => $value) {
        $key = trim((string) $key);
        if ($key !== '' && preg_match('/^[A-Z0-9_]+$/', $key) === 1) $settings[$key] = trim((string) $value);
    }
    return $settings;
}

function pmssBootDefaultsEnsureProcHidepid(string $fstabPath, callable $log): void
{
    $fstabChanged = false;
    $lines = pmssFstabLinesRead($fstabPath, $log, '/proc hidepid enforcement');
    if ($lines === null) return;

    $plan = pmssFstabMountOptionsEnsure($lines, '/proc', [], [], false, 'proc', ['hidepid=' => 'hidepid=2']);
    if ($plan === null) {
        $lines[] = "proc\t/proc\tproc\tdefaults,hidepid=2\t0\t0";
        $fstabChanged = true;
        $log('Added /proc mount with hidepid=2 to '.$fstabPath);
    } elseif ($plan['changed']) {
        $fstabChanged = true;
        $log('Updated /proc mount options in '.$fstabPath);
    }
    if (!$fstabChanged) return;

    pmssWriteManagedPathFileWithBackup($fstabPath, $lines, 'fstab', $log);
    runStep('Remounting /proc with hidepid=2', pmssBuildCommand('mount', ['-o', 'remount,hidepid=2', '/proc']));
}

function pmssBootDefaultsGrubValueUnquote(string $rawValue): string
{
    if (strlen($rawValue) < 2) return $rawValue;
    $quote = $rawValue[0];
    return (($quote === '"' || $quote === "'") && $rawValue[strlen($rawValue) - 1] === $quote) ? substr($rawValue, 1, -1) : $rawValue;
}

function pmssBootDefaultsEnsureGrubOptions(array &$lines, array $requiredGrubOptions, string $grubPath, callable $log): bool
{
    $changed = $found = false;
    foreach ($lines as $idx => $line) {
        if (!preg_match('/^(GRUB_CMDLINE_LINUX_DEFAULT|GRUB_CMDLINE_LINUX)=("|\')([^"\']*)("|\')/', $line, $match)) continue;
        $found = true;
        $currentOptions = pmssConfigLineColumns($match[3], 0, []);
        foreach ($requiredGrubOptions as $option) {
            if (!in_array($option, $currentOptions, true)) $currentOptions[] = $option;
        }
        $updatedValue = implode(' ', $currentOptions);
        if ($updatedValue !== $match[3]) {
            $lines[$idx] = $match[1].'='.$match[2].$updatedValue.$match[2];
            $changed = true;
            $log('Updated '.$match[1].' options in '.$grubPath);
        }
        break;
    }
    if ($found || $requiredGrubOptions === []) return $changed;

    $lines[] = 'GRUB_CMDLINE_LINUX_DEFAULT="'.implode(' ', $requiredGrubOptions).'"';
    $log('Added GRUB_CMDLINE_LINUX_DEFAULT to '.$grubPath);
    return true;
}

function pmssBootDefaultsEnsureGrubSettings(array &$lines, array $requiredGrubSettings, string $grubPath, callable $log): bool
{
    $changed = false;
    foreach ($requiredGrubSettings as $settingKey => $settingValue) {
        $settingFound = false;
        $settingPrefix = $settingKey.'=';
        foreach ($lines as $lineIdx => $line) {
            if (strpos($line, $settingPrefix) !== 0) continue;
            $settingFound = true;
            if (pmssBootDefaultsGrubValueUnquote(trim(substr($line, strlen($settingPrefix)))) !== $settingValue) {
                $lines[$lineIdx] = $settingKey.'="'.$settingValue.'"';
                $changed = true;
                $log('Updated '.$settingKey.' in '.$grubPath);
            }
            break;
        }
        if ($settingFound) continue;
        $lines[] = $settingKey.'="'.$settingValue.'"';
        $changed = true;
        $log('Added '.$settingKey.' to '.$grubPath);
    }
    return $changed;
}

function pmssBootDefaultsApplyGrubChanges(bool $grubChanged, string $grubPath, string $grubOption, callable $log): void
{
    if (!$grubChanged) return;
    if (pmssCommandPath('update-grub') !== '') {
        runStep('Updating GRUB configuration', 'update-grub');
    } else {
        $log('[WARN] update-grub not available; run update-grub after editing '.$grubPath);
    }
    $cmdline = @file_get_contents('/proc/cmdline');
    if ($grubOption !== '' && $cmdline !== false && strpos($cmdline, $grubOption) === false) {
        $log('[WARN] '.$grubOption.' will apply after reboot');
    }
}

/**
 * Probe whether a resolver answers a DNS query from this host (UDP/53).
 *
 * Used as a reachability guard before switching resolv.conf: a minimal raw
 * DNS A-query for a stable public name; true when any reply is received.
 */
function pmssBootDefaultsResolverAnswers(string $server, int $timeoutSec = 2): bool
{
    if (filter_var($server, FILTER_VALIDATE_IP) === false) return false;
    $sock = @fsockopen('udp://'.$server, 53, $errno, $errstr, $timeoutSec);
    if ($sock === false) return false;
    @stream_set_timeout($sock, $timeoutSec);
    $qname = '';
    foreach (explode('.', 'deb.debian.org') as $piece) { $qname .= chr(strlen($piece)).$piece; }
    $qname .= "\x00";
    // id=0x1234, RD set, 1 question, A/IN.
    $query = "\x12\x34\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00".$qname."\x00\x01\x00\x01";
    @fwrite($sock, $query);
    $resp = @fread($sock, 32);
    @fclose($sock);
    return is_string($resp) && strlen($resp) >= 12;
}

/**
 * Ensure /etc/resolv.conf uses Pulsed Media's own recursive resolvers rather
 * than inherited public/external DNS (privacy: customer DNS stays on PM infra,
 * not Google/Cloudflare). Self-healing on each update like the grub/hidepid
 * convergence above.
 *
 * Reachability-guarded — NEVER break a host that cannot reach the PM resolvers:
 * if none of them answer (e.g. a datacenter without a route to them), the
 * existing resolv.conf is left untouched. Idempotent: rewrites only on drift.
 */
function pmssBootDefaultsEnsureResolvConf(array $nameservers, callable $log, string $resolvPath = '/etc/resolv.conf'): void
{
    $nameservers = array_values(array_filter(
        array_map('trim', $nameservers),
        static function ($n) { return filter_var($n, FILTER_VALIDATE_IP) !== false; }
    ));
    if ($nameservers === []) return;

    $reachable = false;
    foreach ($nameservers as $ns) { if (pmssBootDefaultsResolverAnswers($ns)) { $reachable = true; break; } }
    if (!$reachable) {
        $log('[WARN] PM resolvers ('.implode(', ', $nameservers).') unreachable from this host; leaving '.$resolvPath.' unchanged');
        return;
    }

    $currentNs = [];
    $isLink = is_link($resolvPath);
    if (is_readable($resolvPath) && ($lines = @file($resolvPath, FILE_IGNORE_NEW_LINES)) !== false) {
        foreach ($lines as $line) {
            if (preg_match('/^\s*nameserver\s+(\S+)/', $line, $m)) $currentNs[] = $m[1];
        }
    }
    if (!$isLink && $currentNs === $nameservers) return; // already converged + static

    if ($isLink) pmssRemoveManagedPathFile($resolvPath, 'resolv.conf symlink', $log);

    $desired = ['# Managed by PMSS (pmssBootDefaultsEnsureResolvConf) — Pulsed Media resolvers.'];
    foreach ($nameservers as $ns) { $desired[] = 'nameserver '.$ns; }
    $desired[] = 'options single-request-reopen';
    if (pmssWriteManagedPathFileWithBackup($resolvPath, $desired, 'resolv.conf', $log, true)) {
        $log('Set '.$resolvPath.' to PM resolvers: '.implode(', ', $nameservers));
    }
}

/** Ensure /proc hidepid=2 and legacy grub cmdline defaults stay applied. */
function pmssEnsureBootDefaults(
    ?callable $logger = null,
    ?string $fstabPath = null,
    ?string $grubPath = null,
    ?string $grubOption = null,
    ?array $extraGrubOptions = null,
    ?array $extraGrubSettings = null
): void {
    $log = $logger ?: 'logMessage';
    $fstabPath = $fstabPath ?? '/etc/fstab';
    $grubPath = $grubPath ?? '/etc/default/grub';
    $grubOption = trim((string) ($grubOption ?? 'systemd.unified_cgroup_hierarchy=0'));

    pmssBootDefaultsEnsureProcHidepid($fstabPath, $log);
    pmssBootDefaultsEnsureResolvConf(['185.148.1.2', '185.148.1.3'], $log);
    $grubChanged = false;
    if (is_readable($grubPath) && ($lines = file($grubPath, FILE_IGNORE_NEW_LINES)) !== false) {
        $grubChanged = pmssBootDefaultsEnsureGrubOptions($lines, pmssBootDefaultsRequiredGrubOptions($grubOption, $extraGrubOptions), $grubPath, $log);
        $grubChanged = pmssBootDefaultsEnsureGrubSettings($lines, pmssBootDefaultsRequiredGrubSettings($extraGrubSettings), $grubPath, $log) || $grubChanged;
        if ($grubChanged) pmssWriteManagedPathFileWithBackup($grubPath, $lines, 'grub', $log);
    } else {
        $log('[WARN] '.$grubPath.' not readable; skipping grub cmdline update');
    }
    pmssBootDefaultsApplyGrubChanges($grubChanged, $grubPath, $grubOption, $log);
}
