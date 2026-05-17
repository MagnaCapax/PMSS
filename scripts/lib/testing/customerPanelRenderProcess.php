<?php
/**
 * Process execution and assertions for customer panel render tests.
 *
 * @license GPL-3.0-only
 */

/** Return per-page byte thresholds and query strings. */
function pmssCustomerPanelRenderExpectations(): array
{
    $quota = urlencode(serialize([
        'hardLimit' => 107374182400,
        'totalSpace' => 80530636800,
        'usedBytes' => 21474836480,
    ]));

    $minBytesOverride = getenv('PMSS_CUSTOMER_PANEL_RENDER_MIN_BYTES');
    $minBytes = is_string($minBytesOverride) && ctype_digit($minBytesOverride) ? (int) $minBytesOverride : null;

    return [
        'welcome.php' => ['minBytes' => $minBytes ?? 10000, 'query' => 'quota='.$quota],
        'info.php' => ['minBytes' => $minBytes ?? 5000, 'query' => ''],
        'stats.php' => ['minBytes' => $minBytes ?? 5000, 'query' => ''],
    ];
}

/** Return required feature markers across all rendered output. */
function pmssCustomerPanelRenderRequiredMarkers(): array
{
    $disabled = getenv('PMSS_CUSTOMER_PANEL_RENDER_DISABLE_MARKERS');
    if ($disabled === '1') {
        return [];
    }

    return ['<h6>Announcements</h6>', 'Traffic limit:', 'RAM Info', 'ruTorrent'];
}

/** Render one customer panel page and classify runtime errors. */
function pmssCustomerPanelRenderPage(string $www, string $bootstrap, string $homeRoot, string $home, string $page, array $expectation): array
{
    $pagePath = $www.'/'.$page;
    $query = (string) ($expectation['query'] ?? '');
    $env = [
        'HOME' => $home,
        'USER' => 'renderuser',
        'PMSS_HOME_DIR' => $homeRoot,
        'PMSS_TEST_MODE' => '1',
        'REQUEST_METHOD' => 'GET',
        'QUERY_STRING' => $query,
        'SERVER_NAME' => 'render.test',
    ];

    $command = escapeshellarg(PHP_BINARY)
        .' -d allow_url_fopen=0'
        .' -d '.escapeshellarg('auto_prepend_file='.$bootstrap)
        .' '.escapeshellarg($pagePath);
    $process = pmssCustomerPanelRenderRunProcess($command, $www, $env, 20);
    $stdoutBytes = strlen($process['stdout']);
    $errors = [];

    if (!is_file($pagePath)) {
        $errors[] = 'missing page';
    }
    if ($process['timedOut']) {
        $errors[] = 'render timed out';
    }
    if ($process['rc'] !== 0) {
        $errors[] = 'exit code '.$process['rc'];
    }
    if (preg_match('/PHP (Fatal error|Warning|Notice)|Call to undefined function/i', $process['stderr']) === 1) {
        $errors[] = 'php_error: '.trim($process['stderr']);
    }
    if ($stdoutBytes < (int) $expectation['minBytes']) {
        $errors[] = 'stdout below minimum: '.$stdoutBytes.' < '.(int) $expectation['minBytes'];
    }

    return [
        'page' => $page,
        'rc' => $process['rc'],
        'stdoutBytes' => $stdoutBytes,
        'stderr' => $process['stderr'],
        'timedOut' => $process['timedOut'],
        'errors' => $errors,
        'stdout' => $process['stdout'],
    ];
}

/** Run a command with proc_open while capturing stdout/stderr and timeout state. */
function pmssCustomerPanelRenderRunProcess(string $command, string $cwd, array $env, int $timeoutSec): array
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open($command, $descriptor, $pipes, $cwd, $env);
    if (!is_resource($process)) {
        return ['rc' => 1, 'stdout' => '', 'stderr' => 'proc_open failed', 'timedOut' => false];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeoutSec;
    $timedOut = false;
    $exitCode = null;
    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = isset($status['exitcode']) ? (int) $status['exitcode'] : null;
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }
        usleep(10000);
    }

    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedRc = proc_close($process);
    $rc = $exitCode !== null && $exitCode >= 0 ? $exitCode : $closedRc;
    if ($timedOut && $rc === 0) {
        $rc = 124;
    }

    return ['rc' => $rc, 'stdout' => $stdout, 'stderr' => $stderr, 'timedOut' => $timedOut];
}
