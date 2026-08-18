<?php
/**
 * @domain user media stack panel — web installer status and launch helpers
 *
 * Helpers for the first-party welcome page media stack launcher.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/scriptsInc.php';

/**
 * Resolve a media-stack helper path relative to the tenant home.
 */
function pmssMediaStackPanelHomePath(string $home, string $suffix): string
{
    return pmssCustomerHomePath($home, $suffix);
}

/**
 * Return true when a directory exists and contains non-dot entries.
 */
function pmssMediaStackPanelDirectoryPopulated(string $path): bool
{
    $entries = @scandir($path);
    return is_array($entries) && count(array_diff($entries, array('.', '..'))) > 0;
}

/**
 * Return the installer memory floor used by both panel messaging and shell.
 */
function pmssMediaStackPanelMemoryMinimumBytes(): int
{
    return 1024 * 1024 * 1024;
}

/**
 * Treat kernel sentinel values above this as unlimited for pre-flight checks.
 */
function pmssMediaStackPanelMemoryUnlimitedBytes(): int
{
    return 1024 * 1024 * 1024 * 1024 * 1024;
}

/**
 * Read one cgroup memory limit file and ignore unlimited/sentinel values.
 */
function pmssMediaStackPanelMemoryLimitFileRead(string $path): ?int
{
    $bytes = pmssCustomerPositiveIntegerFileRead($path);
    return $bytes !== null && $bytes < pmssMediaStackPanelMemoryUnlimitedBytes() ? $bytes : null;
}

/**
 * Walk a cgroup path upward so parent slice limits are honored.
 *
 * @param array<int,string> $filenames
 * @return array<int,int>
 */
function pmssMediaStackPanelCgroupMemoryLimitsRead(string $root, string $relativePath, array $filenames): array
{
    $limits = array();
    $relativePath = '/'.ltrim($relativePath, '/');
    $root = rtrim($root, '/');

    while (true) {
        $dir = $root.$relativePath;
        foreach ($filenames as $filename) {
            $limit = pmssMediaStackPanelMemoryLimitFileRead($dir.'/'.$filename);
            if ($limit !== null) {
                $limits[] = $limit;
            }
        }

        if ($relativePath === '/') {
            break;
        }

        $parent = dirname($relativePath);
        $relativePath = ($parent === '.' || $parent === '') ? '/' : $parent;
    }

    return $limits;
}

/**
 * Detect the current account cgroup memory limit from v2 or v1 files.
 */
function pmssMediaStackPanelMemoryLimitBytesRead(): ?int
{
    $cgroupFile = getenv('PMSS_MEDIA_STACK_CGROUP_FILE');
    $cgroupRoot = getenv('PMSS_MEDIA_STACK_CGROUP_ROOT');
    $cgroupFile = is_string($cgroupFile) && $cgroupFile !== '' ? $cgroupFile : '/proc/self/cgroup';
    $cgroupRoot = is_string($cgroupRoot) && $cgroupRoot !== '' ? $cgroupRoot : '/sys/fs/cgroup';

    $limits = array();
    foreach (pmssCustomerCgroupSelfEntries($cgroupFile) as $entry) {
        if ($entry['controllers'] === array()) {
            $limits = array_merge($limits, pmssMediaStackPanelCgroupMemoryLimitsRead($cgroupRoot, $entry['path'], array('memory.high', 'memory.max')));
            continue;
        }

        if (in_array('memory', $entry['controllers'], true)) {
            $limits = array_merge($limits, pmssMediaStackPanelCgroupMemoryLimitsRead($cgroupRoot.'/memory', $entry['path'], array('memory.soft_limit_in_bytes', 'memory.limit_in_bytes')));
        }
    }

    return $limits === array() ? null : min($limits);
}

/**
 * Build the customer-facing memory pre-flight status.
 *
 * @return array{ok:bool,message:string,limitBytes:?int,minimumBytes:int}
 */
function pmssMediaStackPanelMemoryPreflightRead(): array
{
    $minimum = pmssMediaStackPanelMemoryMinimumBytes();
    $limit = pmssMediaStackPanelMemoryLimitBytesRead();
    if ($limit === null) {
        return array(
            'ok' => true,
            'message' => 'Account memory limit could not be detected; SSH install will perform the same check.',
            'limitBytes' => null,
            'minimumBytes' => $minimum,
        );
    }

    if ($limit >= $minimum) {
        return array(
            'ok' => true,
            'message' => 'Detected account memory limit: '.pmssFormatBytes($limit, 0, 2, true).'.',
            'limitBytes' => $limit,
            'minimumBytes' => $minimum,
        );
    }

    return array(
        'ok' => false,
        'message' => 'Media stack needs about '.pmssFormatBytes($minimum, 0, 2, true).' of memory; this account is limited to '.pmssFormatBytes($limit, 0, 2, true).'. Use SSH with install-media-stack.sh --force only if you accept the throttling risk.',
        'limitBytes' => $limit,
        'minimumBytes' => $minimum,
    );
}

/**
 * Gate web installs to the first run so the wrapper never hangs on prompts.
 *
 * @return array{ok:bool,message:string}
 */
function pmssMediaStackPanelStartGateRead(string $home): array
{
    $memory = pmssMediaStackPanelMemoryPreflightRead();
    foreach (array(
        array(!is_file(pmssMediaStackPanelHomePath($home, 'install-media-stack.sh')), 'Media stack installer is missing from this account.'),
        array(!pmssFrontendShellExecAvailable(), 'PHP shell execution is unavailable on this host.'),
        array(!$memory['ok'], $memory['message']),
        array(pmssMediaStackPanelDirectoryPopulated(pmssMediaStackPanelHomePath($home, '.bin')), 'Web install is limited to the first run because existing ~/.bin content triggers interactive prompts.'),
        array(pmssMediaStackPanelDirectoryPopulated(pmssMediaStackPanelHomePath($home, '.config/jellyfin')), 'Web install is limited to the first run because existing Jellyfin data must be reviewed over SSH.'),
    ) as $gate) {
        if ($gate[0]) {
            return array('ok' => false, 'message' => $gate[1]);
        }
    }

    return array('ok' => true, 'message' => 'Ready to start the first-run media stack install.');
}

/**
 * Read the launcher pid file when present.
 */
function pmssMediaStackPanelPidRead(string $home): int
{
    $raw = @file_get_contents(pmssMediaStackPanelHomePath($home, '.install-media-stack-web.pid'));
    return is_string($raw) ? (int) trim($raw) : 0;
}

/**
 * Check whether a stored launcher pid still exists.
 */
function pmssMediaStackPanelPidRunning(int $pid): bool
{
    return $pid > 1 && ((function_exists('posix_kill') && @posix_kill($pid, 0)) || is_dir('/proc/'.$pid));
}

/**
 * Read a bounded tail from the installer log for status rendering.
 */
function pmssMediaStackPanelLogTailRead(string $home, int $maxBytes = 6000): string
{
    $path = pmssMediaStackPanelHomePath($home, '.install-media-stack.log');
    if (!is_file($path) || ($size = @filesize($path)) === false) {
        return '';
    }

    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        return '';
    }

    if ($size > $maxBytes) {
        @fseek($handle, -$maxBytes, SEEK_END);
    }

    $tail = stream_get_contents($handle);
    @fclose($handle);
    return is_string($tail) ? ltrim($tail) : '';
}

/** Read the operator-published runtime snapshot without crossing the customer tree. */
function pmssMediaStackPanelRuntimeStatusRead(string $home): ?array
{
    $path = pmssMediaStackPanelHomePath($home, '.media-stack-status.json');
    $status = pmssJsonFileReadAssoc($path);
    return is_array($status) && isset($status['apps']) && is_array($status['apps']) ? $status : null;
}

/** Convert the watchdog snapshot into bounded customer-facing app details. */
function pmssMediaStackPanelRuntimeDetailsRead(array $runtime): array
{
    $labels = array('sonarr' => 'Sonarr', 'radarr' => 'Radarr', 'prowlarr' => 'Prowlarr', 'sabnzbd' => 'SABnzbd', 'cloudplow' => 'Cloudplow', 'jellyfin' => 'Jellyfin', 'autobrr' => 'Autobrr');
    $details = array();
    foreach ($labels as $app => $label) {
        if (!isset($runtime['apps'][$app]) || !is_array($runtime['apps'][$app])) {
            continue;
        }
        $state = (string) ($runtime['apps'][$app]['state'] ?? 'unknown');
        $text = $state === 'running' ? 'running' : ($state === 'failed' ? 'failed repeatedly' : 'not running');
        $failures = (int) ($runtime['apps'][$app]['consecutiveFailures'] ?? 0);
        $suffix = $state === 'failed' ? ' ('.$failures.' consecutive failed checks).' : '.';
        $details[] = $label.': '.$text.$suffix;
    }
    return $details;
}

/**
 * Build the stable per-user URLs exposed by the installer.
 *
 * @return array<string,string>
 */
function pmssMediaStackPanelUrlsBuild(string $username, string $hostname): array
{
    if ($username === '' || $hostname === '') {
        return array();
    }

    $base = 'https://'.$hostname.'/public-'.$username;
    return array(
        'Jellyfin' => $base.'/jellyfin/web/index.html',
        'Radarr' => $base.'/radarr/',
        'Sonarr' => $base.'/sonarr/',
        'Prowlarr' => $base.'/prowlarr/',
        'SABnzbd' => $base.'/sabnzbd/',
        'Autobrr' => $base.'/autobrr/',
    );
}

/** Return app labels whose installer markers are present in the customer home. */
function pmssMediaStackPanelExpectedAppLabelsRead(string $home): array
{
    // Mirror the watchdog's config-directory and binary-file presence signals.
    $apps = array(
        'Jellyfin' => array('.config/jellyfin', '.bin/jellyfin/jellyfin.dll'),
        'Radarr' => array('.config/radarr', '.bin/Radarr/Radarr.dll'),
        'Sonarr' => array('.config/sonarr', '.bin/Sonarr/Sonarr.dll'),
        'Prowlarr' => array('.config/prowlarr', '.bin/Prowlarr/Prowlarr.dll'),
        'SABnzbd' => array('.config/sabnzbd', '.bin/sabnzbd/sabnzbd/SABnzbd.py'),
    );
    $expected = array();
    foreach ($apps as $label => $markers) {
        if (is_dir(pmssMediaStackPanelHomePath($home, $markers[0]))
            || is_file(pmssMediaStackPanelHomePath($home, $markers[1]))) {
            $expected[$label] = true;
        }
    }

    // Autobrr markers may belong to a self-managed proxy at another path (#778).
    if (pmssMediaStackPanelProxyAppPresent($home, 'autobrr')) {
        $expected['Autobrr'] = true;
    }
    return $expected;
}

/** Return true when a customer proxy fragment exposes the named app path. */
function pmssMediaStackPanelProxyFragmentMentionsApp(string $fragment, string $app): bool
{
    $appPattern = preg_quote($app, '/');
    $pathPrefix = '(?:user-[^"\']+\/(?:apps\/)?|public-[^"\']+\/)?';
    return preg_match('/\^\/'.$pathPrefix.$appPattern.'(?:\(|\/|\$)/i', $fragment) === 1
        || preg_match('/["\']\/'.$pathPrefix.$appPattern.'(?:\/|["\'])/i', $fragment) === 1;
}

/** Return true when any readable customer proxy fragment exposes an app. */
function pmssMediaStackPanelProxyAppPresent(string $home, string $app): bool
{
    $files = glob(pmssMediaStackPanelHomePath($home, '.lighttpd/custom.d/*.conf'));
    foreach (is_array($files) ? $files : array() as $file) {
        $fragment = pmssCustomerFileRead($file);
        if (is_string($fragment) && pmssMediaStackPanelProxyFragmentMentionsApp($fragment, $app)) {
            return true;
        }
    }
    return false;
}

/** Build only URLs backed by an app marker or the Autobrr proxy fragment. */
function pmssMediaStackPanelUrlsRead(string $home, string $username, string $hostname): array
{
    return array_intersect_key(
        pmssMediaStackPanelUrlsBuild($username, $hostname),
        pmssMediaStackPanelExpectedAppLabelsRead($home)
    );
}

/**
 * Build the shell command that launches the installer in the background.
 */
function pmssMediaStackPanelStartCommandBuild(string $home, string $username): string
{
    $scriptPath = pmssMediaStackPanelHomePath($home, 'install-media-stack.sh');
    $pidPath = pmssMediaStackPanelHomePath($home, '.install-media-stack-web.pid');

    $innerCommand = 'cd '.escapeshellarg($home)
        .' && rm -f -- '.escapeshellarg($pidPath)
        .' && nohup /bin/bash '.escapeshellarg($scriptPath).' >/dev/null 2>&1 & echo $! > '.escapeshellarg($pidPath);

    return 'HOME='.escapeshellarg($home)
        .' USER='.escapeshellarg($username)
        .' LOGNAME='.escapeshellarg($username)
        .' /bin/bash -lc '.escapeshellarg($innerCommand);
}

/**
 * Describe the current web-installer state for rendering and polling.
 *
 * @return array<string,mixed>
 */
function pmssMediaStackPanelStatusRead(string $home, string $username, string $hostname): array
{
    $installed = is_file(pmssMediaStackPanelHomePath($home, '.config/jellyfin/config/network.xml'));
    $pid = pmssMediaStackPanelPidRead($home);
    $running = pmssMediaStackPanelPidRunning($pid);
    $logTail = pmssMediaStackPanelLogTailRead($home);
    $gate = pmssMediaStackPanelStartGateRead($home);
    $urls = ($installed || $logTail !== '') ? pmssMediaStackPanelUrlsRead($home, $username, $hostname) : array();
    $status = array('tail' => $logTail, 'urls' => $urls, 'canStart' => false, 'poll' => false);

    if ($running) {
        return array_merge($status, array(
            'state' => 'running',
            'message' => 'Media stack install is running. This can take several minutes.',
            'details' => array('The page refreshes status automatically while the installer runs.'),
            'poll' => true,
        ));
    }

    if ($installed) {
        $runtime = pmssMediaStackPanelRuntimeStatusRead($home);
        if ($runtime !== null) {
            $runtimeState = (string) ($runtime['state'] ?? 'healthy');
            $panelState = $runtimeState === 'failed' ? 'failed' : ($runtimeState === 'degraded' ? 'degraded' : 'installed');
            $message = $runtimeState === 'failed'
                ? 'Media stack is installed, but one or more apps failed repeatedly.'
                : ($runtimeState === 'degraded'
                    ? 'Media stack is installed, but one or more apps are not running.'
                    : 'Media stack is installed and all managed apps are running.');
            return array_merge($status, array(
                'state' => $panelState,
                'message' => $message,
                'details' => array_merge(
                    pmssMediaStackPanelRuntimeDetailsRead($runtime),
                    array('Automatic restart is disabled after repeated failures; use the app log or SSH for diagnosis.')
                ),
            ));
        }

        return array_merge($status, array(
            'state' => 'installed',
            'message' => 'Media stack is installed for this account.',
            'details' => array(
                'Runtime status is not available yet; the host watchdog will publish it shortly.',
                'No password is pre-generated. Create the Jellyfin admin account in the first-run wizard.',
                'If you need a rerun or cleanup, use SSH because the installer becomes interactive once files already exist.',
            ),
        ));
    }

    if ($logTail !== '') {
        return array_merge($status, array(
            'state' => 'failed',
            'message' => 'A previous web install stopped before completion.',
            'details' => array(
                $gate['ok'] ? 'You can retry from the panel.' : $gate['message'],
                'Use SSH for deeper troubleshooting because the full installer log can be longer than the panel view.',
            ),
            'canStart' => $gate['ok'],
        ));
    }

    if (!$gate['ok']) {
        return array_merge($status, array(
            'state' => 'blocked',
            'message' => $gate['message'],
            'details' => array('This panel wrapper intentionally supports the first install only so it never stalls on interactive confirmation prompts.'),
            'tail' => '',
            'urls' => array(),
        ));
    }

    return array_merge($status, array(
        'state' => 'ready',
        'message' => 'Install Jellyfin plus the bundled media helpers without SSH.',
        'details' => array(
            'The panel starts the existing install-media-stack.sh script from your home directory.',
            'The wrapper does not generate passwords. Jellyfin creates the admin account in its first-run wizard.',
        ),
        'tail' => '',
        'urls' => array(),
        'canStart' => true,
    ));
}

/**
 * Render the current media stack status as a small HTML fragment.
 */
function pmssMediaStackPanelHtmlBuild(array $status): string
{
    $html = '<div class="pmss-media-stack-box pmss-media-stack-state-'.pmssCustomerHtmlAttr($status['state'] ?? 'unknown').'">';
    $html .= '<p><b>'.pmssCustomerHtmlAttr($status['message'] ?? '').'</b></p>';

    foreach (($status['details'] ?? array()) as $detail) {
        $html .= '<p>'.pmssCustomerHtmlAttr($detail).'</p>';
    }

    if (!empty($status['urls']) && is_array($status['urls'])) {
        $html .= '<ul>';
        foreach ($status['urls'] as $label => $url) {
            $html .= '<li><b>'.pmssCustomerHtmlAttr($label).':</b> '
                .'<a href="'.pmssCustomerHtmlAttr($url).'" target="_blank">'
                .pmssCustomerHtmlAttr($url).'</a></li>';
        }
        $html .= '</ul>';
    }

    if (!empty($status['tail'])) {
        $html .= '<pre>'.pmssCustomerHtmlAttr($status['tail']).'</pre>';
    }

    return $html.'</div>';
}
