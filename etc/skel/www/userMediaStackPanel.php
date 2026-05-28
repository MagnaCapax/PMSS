<?php
/**
 * @domain user media stack panel — web installer status and launch helpers
 *
 * Helpers for the first-party welcome page media stack launcher.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../.scriptsInc.php';

/**
 * Resolve a media-stack helper path relative to the tenant home.
 */
function pmssMediaStackPanelHomePath(string $home, string $suffix): string
{
    return rtrim($home, '/').'/'.$suffix;
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
 * Gate web installs to the first run so the wrapper never hangs on prompts.
 *
 * @return array{ok:bool,message:string}
 */
function pmssMediaStackPanelStartGateRead(string $home): array
{
    $home = rtrim($home, '/');
    foreach (array(
        array(!is_file(pmssMediaStackPanelHomePath($home, 'install-media-stack.sh')), 'Media stack installer is missing from this account.'),
        array(!pmssFrontendShellExecAvailable(), 'PHP shell execution is unavailable on this host.'),
        array(pmssMediaStackPanelDirectoryPopulated($home.'/.bin'), 'Web install is limited to the first run because existing ~/.bin content triggers interactive prompts.'),
        array(pmssMediaStackPanelDirectoryPopulated($home.'/.config/jellyfin'), 'Web install is limited to the first run because existing Jellyfin data must be reviewed over SSH.'),
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
    $installed = is_file(rtrim($home, '/').'/.config/jellyfin/config/network.xml');
    $pid = pmssMediaStackPanelPidRead($home);
    $running = pmssMediaStackPanelPidRunning($pid);
    $logTail = pmssMediaStackPanelLogTailRead($home);
    $gate = pmssMediaStackPanelStartGateRead($home);
    $urls = ($installed || $logTail !== '') ? pmssMediaStackPanelUrlsBuild($username, $hostname) : array();
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
        return array_merge($status, array(
            'state' => 'installed',
            'message' => 'Media stack is installed for this account.',
            'details' => array(
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
