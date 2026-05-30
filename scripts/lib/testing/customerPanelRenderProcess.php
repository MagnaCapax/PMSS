<?php
/**
 * Process execution and assertions for customer panel render tests.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/runtime.php';

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
    return pmssEnvFlagEnabled('PMSS_CUSTOMER_PANEL_RENDER_DISABLE_MARKERS')
        ? []
        : ['<h6>Announcements</h6>', 'Traffic limit:', 'RAM Info', 'ruTorrent'];
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
    $result = pmssCommandPipedCapture($command, $command, 20, 0, false, 'proc_open failed', 1, false, 'stream_select failed', $www, $env);
    $process = ['rc' => $result['rc'], 'stdout' => $result['stdout'], 'stderr' => $result['stderr'], 'timedOut' => $result['timed_out']];
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
