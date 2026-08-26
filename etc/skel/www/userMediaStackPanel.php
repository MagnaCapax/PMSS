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

/** Gate the one-shot recovery action to the existing installer-owned files. */
function pmssMediaStackPanelRecoveryGateRead(string $home): array
{
    $aliasPath = pmssMediaStackPanelHomePath($home, '.bashrc.custom');
    foreach (array(
        array(!is_file(pmssMediaStackPanelHomePath($home, 'install-media-stack.sh')), 'Media stack installer is missing from this account.'),
        array(!is_file($aliasPath) || is_link($aliasPath) || !is_readable($aliasPath), 'Media stack launch aliases are missing or unsafe.'),
        array(!pmssFrontendShellExecAvailable(), 'PHP shell execution is unavailable on this host.'),
    ) as $gate) {
        if ($gate[0]) {
            return array('ok' => false, 'message' => $gate[1]);
        }
    }

    return array('ok' => true, 'message' => 'Ready to start stopped media-stack apps.');
}

/** Require same-origin AJAX POST semantics for a state-changing panel action. */
function pmssMediaStackPanelRecoveryRequestAllowed(array $server): bool
{
    return ($server['REQUEST_METHOD'] ?? '') === 'POST'
        && strcasecmp((string) ($server['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0;
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
 * Return the web-exposed media-stack apps with their stable markers and URLs.
 *
 * @return array<string,array{label:string,urlPath:string,markers:array<int,array{type:string,path:string}>}>
 */
function pmssMediaStackPanelAppDefinitionsRead(): array
{
    return array(
        'jellyfin' => array(
            'label' => 'Jellyfin',
            'urlPath' => 'jellyfin/web/index.html',
            'markers' => array(
                array('type' => 'dir', 'path' => '.config/jellyfin'),
                array('type' => 'file', 'path' => '.bin/jellyfin/jellyfin.dll'),
            ),
        ),
        'radarr' => array(
            'label' => 'Radarr',
            'urlPath' => 'radarr/',
            'markers' => array(
                array('type' => 'dir', 'path' => '.config/radarr'),
                array('type' => 'file', 'path' => '.bin/Radarr/Radarr.dll'),
            ),
        ),
        'sonarr' => array(
            'label' => 'Sonarr',
            'urlPath' => 'sonarr/',
            'markers' => array(
                array('type' => 'dir', 'path' => '.config/sonarr'),
                array('type' => 'file', 'path' => '.bin/Sonarr/Sonarr.dll'),
            ),
        ),
        'prowlarr' => array(
            'label' => 'Prowlarr',
            'urlPath' => 'prowlarr/',
            'markers' => array(
                array('type' => 'dir', 'path' => '.config/prowlarr'),
                array('type' => 'file', 'path' => '.bin/Prowlarr/Prowlarr.dll'),
            ),
        ),
        'sabnzbd' => array(
            'label' => 'SABnzbd',
            'urlPath' => 'sabnzbd/',
            'markers' => array(
                array('type' => 'dir', 'path' => '.config/sabnzbd'),
                array('type' => 'file', 'path' => '.bin/sabnzbd/sabnzbd/SABnzbd.py'),
            ),
        ),
        'autobrr' => array(
            'label' => 'Autobrr',
            'urlPath' => 'autobrr/',
            'markers' => array(),
        ),
    );
}

/**
 * Build the stable per-user URLs exposed by the installer.
 *
 * @return array<string,string>
 */
function pmssMediaStackPanelUrlsBuild(string $username, string $hostname): array
{
    $urls = array();
    foreach (pmssMediaStackPanelUrlsByAppIdBuild($username, $hostname) as $app => $url) {
        $urls[pmssMediaStackPanelAppLabelRead($app)] = $url;
    }
    return $urls;
}

/**
 * Build URLs keyed by the internal app id used by action validation.
 *
 * @return array<string,string>
 */
function pmssMediaStackPanelUrlsByAppIdBuild(string $username, string $hostname): array
{
    if ($username === '' || $hostname === '') {
        return array();
    }

    $base = 'https://'.$hostname.'/public-'.$username.'/';
    $urls = array();
    foreach (pmssMediaStackPanelAppDefinitionsRead() as $app => $definition) {
        $urls[$app] = $base.$definition['urlPath'];
    }
    return $urls;
}

/** Return a fixed customer-facing label for an internal media-stack app id. */
function pmssMediaStackPanelAppLabelRead(string $app): string
{
    $definitions = pmssMediaStackPanelAppDefinitionsRead();
    return isset($definitions[$app]) ? $definitions[$app]['label'] : $app;
}

/** Return true when the app id is one of the hardcoded panel-action targets. */
function pmssMediaStackPanelAppIdAllowed(string $app): bool
{
    return isset(pmssMediaStackPanelAppDefinitionsRead()[$app]);
}

/**
 * Read installed web app ids from local markers without trusting request data.
 *
 * @return array<string,bool>
 */
function pmssMediaStackPanelExpectedAppIdsRead(string $home): array
{
    $expected = array();
    foreach (pmssMediaStackPanelAppDefinitionsRead() as $app => $definition) {
        foreach ($definition['markers'] as $marker) {
            $path = pmssMediaStackPanelHomePath($home, $marker['path']);
            if (($marker['type'] === 'dir' && is_dir($path))
                || ($marker['type'] === 'file' && is_file($path))) {
                $expected[$app] = true;
                break;
            }
        }
    }

    // Autobrr markers may belong to a self-managed proxy at another path (#778).
    if (pmssMediaStackPanelProxyAppPresent($home, 'autobrr')) {
        $expected['autobrr'] = true;
    }
    return $expected;
}

/** Return app labels whose installer markers are present in the customer home. */
function pmssMediaStackPanelExpectedAppLabelsRead(string $home): array
{
    $expected = array();
    foreach (pmssMediaStackPanelExpectedAppIdsRead($home) as $app => $_present) {
        $expected[pmssMediaStackPanelAppLabelRead($app)] = true;
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

/** Read one XML tag value from a local customer app config file. */
function pmssMediaStackPanelXmlTagValueRead(string $path, string $tag): ?string
{
    $raw = pmssCustomerFileRead($path);
    if (!is_string($raw)) {
        return null;
    }

    $tagPattern = preg_quote($tag, '/');
    if (preg_match('/<'.$tagPattern.'>([^<]*)<\/'.$tagPattern.'>/i', $raw, $matches) !== 1) {
        return null;
    }
    return trim($matches[1]);
}

/** Read one simple INI-style key from a local customer app config file. */
function pmssMediaStackPanelIniValueRead(string $path, string $key): ?string
{
    $raw = pmssCustomerFileRead($path);
    if (!is_string($raw)) {
        return null;
    }

    $keyPattern = preg_quote($key, '/');
    foreach (preg_split('/\r?\n/', $raw) ?: array() as $line) {
        if (preg_match('/^\s*'.$keyPattern.'\s*=\s*(.*?)\s*$/', (string) $line, $matches) === 1) {
            return trim($matches[1]);
        }
    }
    return null;
}

/** Read a scalar count from an Autobrr SQLite database without mutating it. */
function pmssMediaStackPanelSqliteCountRead(string $path, string $query): ?int
{
    if (!class_exists('SQLite3') || !is_file($path) || is_link($path) || !is_readable($path)) {
        return null;
    }

    try {
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $count = @$db->querySingle($query);
        $db->close();
    } catch (Throwable $e) {
        return null;
    }

    return is_numeric($count) ? (int) $count : null;
}

/** Return true when a Servarr config has the secure-by-default auth values. */
function pmssMediaStackPanelServarrAuthConfigured(string $home, string $app): bool
{
    $configPath = pmssMediaStackPanelHomePath($home, '.config/'.$app.'/config.xml');
    $method = strtolower((string) pmssMediaStackPanelXmlTagValueRead($configPath, 'AuthenticationMethod'));
    $required = strtolower((string) pmssMediaStackPanelXmlTagValueRead($configPath, 'AuthenticationRequired'));
    return in_array($method, array('forms', 'basic', 'external'), true) && $required === 'enabled';
}

/** Return true when the installed SABnzbd config contains app-level credentials. */
function pmssMediaStackPanelSabnzbdAuthConfigured(string $home): bool
{
    $configPath = pmssMediaStackPanelHomePath($home, '.config/sabnzbd/sabnzbd.ini');
    $username = pmssMediaStackPanelIniValueRead($configPath, 'username');
    $password = pmssMediaStackPanelIniValueRead($configPath, 'password');
    return is_string($username) && $username !== '' && is_string($password) && $password !== '';
}

/** Return true when Autobrr has completed onboarding with at least one user. */
function pmssMediaStackPanelAutobrrAuthConfigured(string $home): bool
{
    $count = pmssMediaStackPanelSqliteCountRead(
        pmssMediaStackPanelHomePath($home, '.config/autobrr/autobrr.db'),
        'SELECT COUNT(*) FROM users'
    );
    return $count !== null && $count > 0;
}

/** Return true when Jellyfin's first-run wizard has already created an admin. */
function pmssMediaStackPanelJellyfinAuthConfigured(string $home): bool
{
    $value = strtolower((string) pmssMediaStackPanelXmlTagValueRead(
        pmssMediaStackPanelHomePath($home, '.config/jellyfin/config/system.xml'),
        'IsStartupWizardCompleted'
    ));
    return in_array($value, array('true', '1'), true);
}

/** Detect app auth status from app-owned config files only. */
function pmssMediaStackPanelAppAuthConfigured(string $home, string $app): bool
{
    if (!pmssMediaStackPanelAppIdAllowed($app)) {
        return false;
    }

    if (in_array($app, array('radarr', 'sonarr', 'prowlarr'), true)) {
        return pmssMediaStackPanelServarrAuthConfigured($home, $app);
    }
    if ($app === 'sabnzbd') {
        return pmssMediaStackPanelSabnzbdAuthConfigured($home);
    }
    if ($app === 'autobrr') {
        return pmssMediaStackPanelAutobrrAuthConfigured($home);
    }
    if ($app === 'jellyfin') {
        return pmssMediaStackPanelJellyfinAuthConfigured($home);
    }
    return false;
}

/** Return whether the local app files are complete enough to apply default auth. */
function pmssMediaStackPanelAppSecurePrerequisitesRead(string $home, string $app): bool
{
    switch ($app) {
        case 'jellyfin':
            return is_file(pmssMediaStackPanelHomePath($home, '.config/jellyfin/config/network.xml'))
                && is_file(pmssMediaStackPanelHomePath($home, '.bin/jellyfin/jellyfin.dll'));
        case 'radarr':
            return is_file(pmssMediaStackPanelHomePath($home, '.config/radarr/config.xml'))
                && is_file(pmssMediaStackPanelHomePath($home, '.bin/Radarr/Radarr.dll'));
        case 'sonarr':
            return is_file(pmssMediaStackPanelHomePath($home, '.config/sonarr/config.xml'))
                && is_file(pmssMediaStackPanelHomePath($home, '.bin/Sonarr/Sonarr.dll'));
        case 'prowlarr':
            return is_file(pmssMediaStackPanelHomePath($home, '.config/prowlarr/config.xml'))
                && is_file(pmssMediaStackPanelHomePath($home, '.bin/Prowlarr/Prowlarr.dll'));
        case 'sabnzbd':
            return is_file(pmssMediaStackPanelHomePath($home, '.config/sabnzbd/sabnzbd.ini'))
                && is_file(pmssMediaStackPanelHomePath($home, '.bin/sabnzbd/sabnzbd/SABnzbd.py'));
        case 'autobrr':
            return is_dir(pmssMediaStackPanelHomePath($home, '.config/autobrr'))
                && is_file(pmssMediaStackPanelHomePath($home, '.bin/autobrr/autobrr'))
                && is_file(pmssMediaStackPanelHomePath($home, '.bin/autobrr/autobrrctl'));
    }
    return false;
}

/** Validate a secure-app action string against literal hardcoded actions. */
function pmssMediaStackPanelSecureActionAppIdRead(string $action): ?string
{
    foreach (pmssMediaStackPanelAppDefinitionsRead() as $app => $_definition) {
        if ($action === 'confirm-secure-'.$app) {
            return $app;
        }
    }
    return null;
}

/** Gate one app-scoped auth action to customer-owned media-stack files. */
function pmssMediaStackPanelSecureGateRead(string $home, string $app): array
{
    $scriptPath = pmssMediaStackPanelHomePath($home, 'install-media-stack.sh');
    $installedApps = pmssMediaStackPanelExpectedAppIdsRead($home);
    foreach (array(
        array(!pmssMediaStackPanelAppIdAllowed($app), 'Unknown media-stack app.'),
        array(!isset($installedApps[$app]), pmssMediaStackPanelAppLabelRead($app).' is not installed for this account.'),
        array(!pmssMediaStackPanelAppSecurePrerequisitesRead($home, $app), pmssMediaStackPanelAppLabelRead($app).' files are incomplete; use SSH for repair.'),
        array(!is_file($scriptPath) || is_link($scriptPath) || !is_readable($scriptPath), 'Media stack installer is missing from this account.'),
        array(!pmssFrontendShellExecAvailable(), 'PHP shell execution is unavailable on this host.'),
    ) as $gate) {
        if ($gate[0]) {
            return array('ok' => false, 'message' => $gate[1]);
        }
    }
    return array('ok' => true, 'message' => 'Ready to secure '.pmssMediaStackPanelAppLabelRead($app).'.');
}

/**
 * Build the single command primitive that delegates app auth to the installer.
 */
function pmssMediaStackPanelSecureCommandBuild(string $home, string $username, string $app): string
{
    if (!pmssMediaStackPanelAppIdAllowed($app)) {
        return '';
    }

    return 'HOME='.escapeshellarg($home)
        .' USER='.escapeshellarg($username)
        .' LOGNAME='.escapeshellarg($username)
        .' /bin/bash '.escapeshellarg(pmssMediaStackPanelHomePath($home, 'install-media-stack.sh'))
        .' '.escapeshellarg('--secure-app='.$app);
}

/**
 * Build Protected/Exposed rows for each installed web-exposed app.
 *
 * @return array<string,array<string,mixed>>
 */
function pmssMediaStackPanelSecurityStatusesRead(string $home, string $username, string $hostname): array
{
    $statuses = array();
    $urls = pmssMediaStackPanelUrlsByAppIdBuild($username, $hostname);
    foreach (pmssMediaStackPanelExpectedAppIdsRead($home) as $app => $_present) {
        $protected = pmssMediaStackPanelAppAuthConfigured($home, $app);
        $gate = pmssMediaStackPanelSecureGateRead($home, $app);
        $statuses[$app] = array(
            'id' => $app,
            'label' => pmssMediaStackPanelAppLabelRead($app),
            'url' => isset($urls[$app]) ? $urls[$app] : '',
            'status' => $protected ? 'Protected' : 'Exposed',
            'protected' => $protected,
            'canSecure' => !$protected && $gate['ok'],
            'action' => 'confirm-secure-'.$app,
            'message' => $gate['message'],
        );
    }
    return $statuses;
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

/** Build the fixed one-shot command used to relaunch absent tmux sessions. */
function pmssMediaStackPanelRecoveryCommandBuild(string $home, string $username): string
{
    $scriptPath = pmssMediaStackPanelHomePath($home, 'install-media-stack.sh');
    $successMarker = 'pmss-media-stack-started';

    return 'cd '.escapeshellarg($home)
        .' && HOME='.escapeshellarg($home)
        .' USER='.escapeshellarg($username)
        .' LOGNAME='.escapeshellarg($username)
        .' /bin/bash '.escapeshellarg($scriptPath).' --start-stopped >/dev/null 2>&1'
        .' && printf %s '.escapeshellarg($successMarker);
}

/**
 * Describe the current web-installer state for rendering and polling.
 *
 * @return array<string,mixed>
 */
function pmssMediaStackPanelStatusRead(string $home, string $username, string $hostname): array
{
    $installedApps = pmssMediaStackPanelExpectedAppIdsRead($home);
    $installed = $installedApps !== array()
        || is_file(pmssMediaStackPanelHomePath($home, '.config/jellyfin/config/network.xml'));
    $pid = pmssMediaStackPanelPidRead($home);
    $running = pmssMediaStackPanelPidRunning($pid);
    $logTail = pmssMediaStackPanelLogTailRead($home);
    $gate = pmssMediaStackPanelStartGateRead($home);
    $urls = ($installed || $logTail !== '') ? pmssMediaStackPanelUrlsRead($home, $username, $hostname) : array();
    $security = ($installed || $logTail !== '') ? pmssMediaStackPanelSecurityStatusesRead($home, $username, $hostname) : array();
    $status = array('tail' => $logTail, 'urls' => $urls, 'security' => $security, 'canStart' => false, 'canRestart' => false, 'poll' => false);

    if ($running) {
        return array_merge($status, array(
            'state' => 'running',
            'message' => 'Media stack install is running. This can take several minutes.',
            'details' => array('The page refreshes status automatically while the installer runs.'),
            'poll' => true,
        ));
    }

    if ($installed) {
        $recoveryGate = pmssMediaStackPanelRecoveryGateRead($home);
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
                    array($recoveryGate['ok']
                        ? 'Automatic crash-loop restart remains disabled; review the app log, then use Start stopped apps for one recovery attempt.'
                        : $recoveryGate['message'])
                ),
                'canRestart' => $recoveryGate['ok'],
            ));
        }

        return array_merge($status, array(
            'state' => 'installed',
            'message' => 'Media stack is installed for this account.',
            'details' => array(
                'Runtime status is not available yet; the host watchdog will publish it shortly.',
                'Use the app-level credentials in ~/.media-stack-credentials.txt; exposed apps can be secured from this panel.',
                'If you need a rerun or cleanup, use SSH because the installer becomes interactive once files already exist.',
                $recoveryGate['ok'] ? 'Use Start stopped apps for a one-time recovery after a host restart.' : $recoveryGate['message'],
            ),
            'canRestart' => $recoveryGate['ok'],
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
            'The installer writes generated app credentials to ~/.media-stack-credentials.txt.',
        ),
        'tail' => '',
        'urls' => array(),
        'security' => array(),
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

    if (!empty($status['security']) && is_array($status['security'])) {
        $html .= '<ul class="pmss-media-stack-apps">';
        foreach ($status['security'] as $app) {
            if (!is_array($app)) {
                continue;
            }

            $appId = (string) ($app['id'] ?? '');
            $isProtected = !empty($app['protected']);
            $stateClass = $isProtected ? 'protected' : 'exposed';
            $html .= '<li class="pmss-media-stack-auth-'.$stateClass.'">';
            $html .= '<b>'.pmssCustomerHtmlAttr($app['label'] ?? '').':</b> ';
            $html .= '<span class="pmss-media-stack-auth-badge pmss-media-stack-auth-badge-'.$stateClass.'">'
                .pmssCustomerHtmlAttr($app['status'] ?? '').'</span>';

            if (!empty($app['url'])) {
                $html .= ' <a href="'.pmssCustomerHtmlAttr($app['url']).'" target="_blank">'
                    .pmssCustomerHtmlAttr($app['url']).'</a>';
            }

            if (!$isProtected && !empty($app['canSecure']) && pmssMediaStackPanelAppIdAllowed($appId)) {
                $html .= ' <input type="button" class="pmss-media-stack-secure" value="Secure this app"'
                    .' onClick="pmssMediaStackSecureApp(this, \''.pmssCustomerHtmlAttr($appId).'\');" />';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';
    } elseif (!empty($status['urls']) && is_array($status['urls'])) {
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
