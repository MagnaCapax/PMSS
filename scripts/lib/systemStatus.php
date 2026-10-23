<?php
/** Shared status helpers for PMSS system probes.
 * @license GPL-3.0-only
 * @author PMSS Team
 */
declare(strict_types=1);

require_once __DIR__.'/runtime.php';
pmssRequireRelativeFiles(__DIR__.'/systemStatus', ['render.php', 'probes.php']);

/** Return the component report without running version or system-only probes. */
function pmssComponentStatusChecks(array $dependencies = []): array
{
    return pmssStatusChecks(isset($dependencies['probeSpecs']) ? $dependencies : pmssStatusContextResolve($dependencies), false);
}

/** Return the full system report with its trailing component projection. */
function pmssSystemStatusChecks(array $dependencies = []): array
{
    return pmssStatusChecks(pmssStatusContextResolve($dependencies), true);
}
/** Check localnet readability and parent traversal as one system-test result. */
function pmssSystemStatusLocalnetConfigCheck(callable $isFile, callable $isDir, callable $filePerms): array
{
    $localnetConfig = '/etc/seedbox/config/localnet';
    if (!$isFile($localnetConfig)) return pmssStatus('Seedbox localnet (config)', 'WARN', $localnetConfig.' missing');
    $issues = [];
    $configPerms = $filePerms($localnetConfig);
    if ($configPerms === false) $issues[] = 'unable to read localnet file permissions';
    elseif ((($configPerms & 0777) & 0004) === 0) $issues[] = sprintf('%s mode %o missing world-read (rtorrent users may not read filter)', $localnetConfig, $configPerms & 0777);
    foreach (['/etc/seedbox', '/etc/seedbox/config'] as $dir) {
        if (!$isDir($dir)) { $issues[] = $dir.' missing'; continue; }
        $dirPerms = $filePerms($dir);
        if ($dirPerms === false) { $issues[] = 'unable to read permissions for '.$dir; continue; }
        if ((($dirPerms & 0777) & 0001) === 0) $issues[] = sprintf('%s mode %o missing world-exec (users cannot traverse to localnet)', $dir, $dirPerms & 0777);
    }
    return pmssStatus('Seedbox localnet (config)', $issues === [] ? 'OK' : 'ERR', $issues === [] ? $localnetConfig.' readable via 0664 + traversable dirs' : implode('; ', $issues));
}

/** Check OpenVPN client artifacts without probing generated paths for invalid hostnames. */
function pmssSystemStatusOpenvpnClientArtifactCheck(callable $isFile, string $hostname): array
{
    $hostname = trim($hostname);
    if ($hostname === '') return pmssStatus('OpenVPN client artifacts', 'WARN', 'hostname unknown');
    if (!pmssHostnameIsValid($hostname)) return pmssStatus('OpenVPN client artifacts', 'WARN', 'hostname invalid');
    $fqdn = strpos($hostname, '.') !== false ? $hostname : $hostname.'.pulsedmedia.com';
    $slug = str_replace('.', '-', $fqdn);
    $artifacts = ['/home/openvpn-'.$slug.'.ovpn', '/home/openvpn-'.$slug.'.crt'];
    $missing = array_values(array_map('basename', array_filter($artifacts, static function (string $path) use ($isFile): bool { return !$isFile($path); })));
    return $missing === []
        ? pmssStatus('OpenVPN client artifacts', 'OK', basename($artifacts[0]).', '.basename($artifacts[1]))
        : pmssStatus('OpenVPN client artifacts', 'WARN', 'missing: '.implode(', ', $missing));
}

/** Collect an ordered report, projecting shared observations only once. */
function pmssStatusChecks(array $context, bool $includeSystem): array
{
    $readFile = $context['readFile'];
    $isFile = $context['isFile'] ?? 'is_file';
    $codename = (string) $context['codename']; $sourcesPath = (string) $context['sourcesPath'];
    $probeViews = pmssStatusProbeViews($context['probeSpecs'], $context['runCommand'], $context['isExecutable'], $context['pathExists'], $includeSystem);
    $codenameCheck = pmssStatus('OS codename', $codename === '' ? 'WARN' : 'OK', $codename === '' ? 'VERSION_CODENAME missing' : $codename);
    $sourcesExist = $isFile($sourcesPath);
    $sources = $sourcesExist ? $readFile($sourcesPath) : '';
    $componentSourcesMatch = $sourcesExist && ($codename === '' || stripos($sources, $codename) !== false);

    $probeViews['component'] = array_merge([
        ['name' => 'os.codename'] + $codenameCheck,
        pmssStatus('apt.sources', $componentSourcesMatch ? 'OK' : 'WARN', !$sourcesExist ? 'missing sources.list' : ($componentSourcesMatch ? 'contains '.$codename : 'codename mismatch')),
    ], $probeViews['component']);
    if (!$includeSystem) return $probeViews['component'];

    $isDir = $context['isDir']; $isExecutable = $context['isExecutable'];
    $isLink = $context['isLink']; $readLink = $context['readLink']; $filePerms = $context['filePerms'];
    $checks = array_merge([$codenameCheck], $probeViews['system']);
    $checks[] = pmssSystemStatusLocalnetConfigCheck($isFile, $isDir, $filePerms);
    if ($sourcesExist && $codename !== '') {
        $checks[] = pmssStatus('Sources codename match', $componentSourcesMatch ? 'OK' : 'WARN', $componentSourcesMatch ? 'sources.list references '.$codename : sprintf('%s not present in sources.list', $codename));
    }
    $checks[] = pmssSystemStatusOpenvpnClientArtifactCheck($isFile, (string) $readFile('/etc/hostname'));
    $apps = ['flexget' => ['FlexGet', 'flexget'], 'pyload' => ['pyLoad', 'pyLoad']];
    foreach ($apps as $app => [$label]) {
        $path = '/opt/'.$app.'/bin/'.$app;
        $checks[] = pmssStatus('Virtualenv: '.$label.' binary', ($valid = $isFile($path) && $isExecutable($path)) ? 'OK' : 'WARN', $valid ? $path : $path.' missing or not executable');
    }
    foreach ($apps as $app => [$unused, $label]) {
        $label = 'CLI symlink: '.$label;
        $link = '/usr/local/bin/'.$app;
        $expected = '/opt/'.$app.'/bin/'.$app;
        if (!$isLink($link)) {
            $checks[] = pmssStatus($label, 'WARN', $isFile($link) ? sprintf('%s present but not a symlink', $link) : sprintf('%s missing', $link));
            continue;
        }
        $actual = $readLink($link);
        $checks[] = $actual === ''
            ? pmssStatus($label, 'WARN', sprintf('%s symlink target unreadable', $link))
            : pmssStatus($label, $actual === $expected ? 'OK' : 'WARN', $actual === $expected ? sprintf('%s -> %s', $link, $actual) : sprintf('%s -> %s (expected %s)', $link, $actual, $expected));
    }

    foreach ($probeViews['component'] as $entry) $checks[] = pmssStatus('Component: '.(string) $entry['name'], (string) $entry['status'], (string) ($entry['detail'] ?? ''));

    return $checks;
}
