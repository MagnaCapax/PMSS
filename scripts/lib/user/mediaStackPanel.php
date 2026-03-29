<?php
/**
 * @domain user media stack panel — web installer status and launch helpers
 *
 * Helpers for the first-party welcome page media stack launcher.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Resolve the copied media stack installer path in the tenant home.
 */
function pmssMediaStackPanelScriptPath(string $home): string
{
    return rtrim($home, '/').'/install-media-stack.sh';
}

/**
 * Resolve the shared installer log path written by install-media-stack.sh.
 */
function pmssMediaStackPanelLogPath(string $home): string
{
    return rtrim($home, '/').'/.install-media-stack.log';
}

/**
 * Resolve the lightweight pid file used by the web launcher.
 */
function pmssMediaStackPanelPidPath(string $home): string
{
    return rtrim($home, '/').'/.install-media-stack-web.pid';
}

/**
 * Infer the current tenant username from the home directory path.
 */
function pmssMediaStackPanelCurrentUserRead(string $home): string
{
    return basename(rtrim($home, '/'));
}

/**
 * Return the current hostname for URL rendering.
 */
function pmssMediaStackPanelCurrentHostnameRead(): string
{
    $hostname = function_exists('gethostname') ? (string) gethostname() : '';
    if ($hostname !== '') {
        return $hostname;
    }

    return (string) php_uname('n');
}

/**
 * Check whether the launcher can execute shell commands from PHP.
 */
function pmssMediaStackPanelShellExecAvailable(): bool
{
    if (!function_exists('shell_exec')) {
        return false;
    }

    $disabled = (string) ini_get('disable_functions');
    if ($disabled === '') {
        return true;
    }

    return !in_array('shell_exec', array_map('trim', explode(',', $disabled)), true);
}

/**
 * Detect whether the media stack has already been installed for this tenant.
 */
function pmssMediaStackPanelInstalled(string $home): bool
{
    return is_file(rtrim($home, '/').'/.config/jellyfin/config/network.xml');
}

/**
 * Gate web installs to the first run so the wrapper never hangs on prompts.
 *
 * @return array{ok:bool,message:string}
 */
function pmssMediaStackPanelStartGateRead(string $home): array
{
    $scriptPath = pmssMediaStackPanelScriptPath($home);
    if (!is_file($scriptPath)) {
        return array('ok' => false, 'message' => 'Media stack installer is missing from this account.');
    }

    if (!pmssMediaStackPanelShellExecAvailable()) {
        return array('ok' => false, 'message' => 'PHP shell execution is unavailable on this host.');
    }

    $binPath = rtrim($home, '/').'/.bin';
    if (is_dir($binPath) && (($entries = @scandir($binPath)) !== false) && count(array_diff($entries, array('.', '..'))) > 0) {
        return array('ok' => false, 'message' => 'Web install is limited to the first run because existing ~/.bin content triggers interactive prompts.');
    }

    $jellyfinConfigPath = rtrim($home, '/').'/.config/jellyfin';
    if (is_dir($jellyfinConfigPath)
        && (($entries = @scandir($jellyfinConfigPath)) !== false)
        && count(array_diff($entries, array('.', '..'))) > 0) {
        return array('ok' => false, 'message' => 'Web install is limited to the first run because existing Jellyfin data must be reviewed over SSH.');
    }

    return array('ok' => true, 'message' => 'Ready to start the first-run media stack install.');
}

/**
 * Read the launcher pid file when present.
 */
function pmssMediaStackPanelPidRead(string $home): int
{
    $raw = @file_get_contents(pmssMediaStackPanelPidPath($home));
    return is_string($raw) ? (int) trim($raw) : 0;
}

/**
 * Check whether a stored launcher pid still exists.
 */
function pmssMediaStackPanelPidRunning(int $pid): bool
{
    if ($pid <= 1) {
        return false;
    }

    if (function_exists('posix_kill') && @posix_kill($pid, 0)) {
        return true;
    }

    return is_dir('/proc/'.$pid);
}

/**
 * Read a bounded tail from the installer log for status rendering.
 */
function pmssMediaStackPanelLogTailRead(string $home, int $maxBytes = 6000): string
{
    $path = pmssMediaStackPanelLogPath($home);
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
    );
}

/**
 * Build the shell command that launches the installer in the background.
 */
function pmssMediaStackPanelStartCommandBuild(string $home, string $username): string
{
    $scriptPath = pmssMediaStackPanelScriptPath($home);
    $pidPath = pmssMediaStackPanelPidPath($home);

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
    $installed = pmssMediaStackPanelInstalled($home);
    $pid = pmssMediaStackPanelPidRead($home);
    $running = pmssMediaStackPanelPidRunning($pid);
    $logTail = pmssMediaStackPanelLogTailRead($home);
    $gate = pmssMediaStackPanelStartGateRead($home);
    $urls = ($installed || $logTail !== '') ? pmssMediaStackPanelUrlsBuild($username, $hostname) : array();

    if ($running) {
        return array(
            'state' => 'running',
            'message' => 'Media stack install is running. This can take several minutes.',
            'details' => array('The page refreshes status automatically while the installer runs.'),
            'tail' => $logTail,
            'urls' => $urls,
            'canStart' => false,
            'poll' => true,
        );
    }

    if ($installed) {
        return array(
            'state' => 'installed',
            'message' => 'Media stack is installed for this account.',
            'details' => array(
                'No password is pre-generated. Create the Jellyfin admin account in the first-run wizard.',
                'If you need a rerun or cleanup, use SSH because the installer becomes interactive once files already exist.',
            ),
            'tail' => $logTail,
            'urls' => $urls,
            'canStart' => false,
            'poll' => false,
        );
    }

    if ($logTail !== '') {
        return array(
            'state' => 'failed',
            'message' => 'A previous web install stopped before completion.',
            'details' => array(
                $gate['ok'] ? 'You can retry from the panel.' : $gate['message'],
                'Use SSH for deeper troubleshooting because the full installer log can be longer than the panel view.',
            ),
            'tail' => $logTail,
            'urls' => $urls,
            'canStart' => $gate['ok'],
            'poll' => false,
        );
    }

    if (!$gate['ok']) {
        return array(
            'state' => 'blocked',
            'message' => $gate['message'],
            'details' => array('This panel wrapper intentionally supports the first install only so it never stalls on interactive confirmation prompts.'),
            'tail' => '',
            'urls' => array(),
            'canStart' => false,
            'poll' => false,
        );
    }

    return array(
        'state' => 'ready',
        'message' => 'Install Jellyfin plus the bundled media helpers without SSH.',
        'details' => array(
            'The panel starts the existing install-media-stack.sh script from your home directory.',
            'The wrapper does not generate passwords. Jellyfin creates the admin account in its first-run wizard.',
        ),
        'tail' => '',
        'urls' => array(),
        'canStart' => true,
        'poll' => false,
    );
}

/**
 * Render the current media stack status as a small HTML fragment.
 */
function pmssMediaStackPanelHtmlBuild(array $status): string
{
    $html = '<div class="pmss-media-stack-box pmss-media-stack-state-'.htmlspecialchars((string) ($status['state'] ?? 'unknown'), ENT_QUOTES, 'UTF-8').'">';
    $html .= '<p><b>'.htmlspecialchars((string) ($status['message'] ?? ''), ENT_QUOTES, 'UTF-8').'</b></p>';

    foreach (($status['details'] ?? array()) as $detail) {
        $html .= '<p>'.htmlspecialchars((string) $detail, ENT_QUOTES, 'UTF-8').'</p>';
    }

    if (!empty($status['urls']) && is_array($status['urls'])) {
        $html .= '<ul>';
        foreach ($status['urls'] as $label => $url) {
            $html .= '<li><b>'.htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8').':</b> '
                .'<a href="'.htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8').'" target="_blank">'
                .htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8').'</a></li>';
        }
        $html .= '</ul>';
    }

    if (!empty($status['tail'])) {
        $html .= '<pre>'.htmlspecialchars((string) $status['tail'], ENT_QUOTES, 'UTF-8').'</pre>';
    }

    return $html.'</div>';
}
