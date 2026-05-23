<?php
/**
 * Synthetic filesystem setup for customer panel render tests.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/filesystem.php';

const PMSS_CUSTOMER_PANEL_RENDER_BASE = '/pmss-render-test';

/** Resolve the repository root, allowing tests to point at a fixture root. */
function pmssCustomerPanelRenderRepoRoot(): string
{
    $override = getenv('PMSS_CUSTOMER_PANEL_RENDER_ROOT');
    if (is_string($override) && trim($override) !== '') {
        return rtrim($override, '/');
    }

    return dirname(__DIR__, 3);
}

/** Create a randomised run root under /tmp with a stable PMSS prefix. */
function pmssCustomerPanelRenderTempRoot(): string
{
    $base = rtrim(sys_get_temp_dir(), '/').PMSS_CUSTOMER_PANEL_RENDER_BASE;
    pmssTestingEnsureDirectory($base, 0700);

    $root = $base.'/run-'.bin2hex(random_bytes(8));
    pmssTestingEnsureDirectory($root, 0700);
    return $root;
}

/** Remove only the temporary directory shape created by this harness. */
function pmssCustomerPanelRenderCleanup(string $runRoot): void
{
    $allowed = rtrim(sys_get_temp_dir(), '/').PMSS_CUSTOMER_PANEL_RENDER_BASE.'/';
    if (strpos($runRoot, $allowed) !== 0 || !is_dir($runRoot)) {
        return;
    }

    pmssTestingRemoveTree($runRoot);
}

/** Copy the customer tree and seed the synthetic customer state files. */
function pmssCustomerPanelRenderPrepare(string $sourceWww, string $home, string $www, string $bootstrap): array
{
    if (!is_dir($sourceWww)) {
        return ['ok' => false, 'error' => 'missing customer source tree: '.$sourceWww];
    }

    foreach ([$home, $www, $home.'/.config/deluge', $home.'/.lighttpd'] as $dir) {
        if (!pmssTestingEnsureDirectory($dir, 0700)) {
            return ['ok' => false, 'error' => 'unable to create mock directory: '.$dir];
        }
    }

    if (!pmssTestingCopyTree($sourceWww, $www, 0700)) {
        return ['ok' => false, 'error' => 'unable to copy customer source tree'];
    }
    $scriptsInc = dirname($sourceWww).'/.scriptsInc.php';
    if (is_file($scriptsInc) && !@copy($scriptsInc, $home.'/.scriptsInc.php')) {
        return ['ok' => false, 'error' => 'unable to copy customer helper include'];
    }

    $serializedTraffic = serialize([
        'raw' => ['month' => 15360, 'week' => 4096, 'day' => 512],
        'display' => ['month' => '15 GiB', 'week' => '4 GiB', 'day' => '512 MiB'],
        'daily' => ['2026-05-15' => 512, '2026-05-16' => 768],
    ]);
    $resourceData = serialize([
        'memory' => ['current' => 268435456, 'anon' => 134217728, 'file' => 67108864],
    ]);

    $files = [
        $home.'/.config/deluge/auth' => "localclient:test-deluge-password:10\n",
        $home.'/.config/deluge/web.conf' => "{\"pwd_sha1\":\"mock\"}\n",
        $home.'/.config/pmss-user.json' => "{\"ramMiB\":1024,\"trafficCapMbit\":100}\n",
        $home.'/.trafficLimit' => "100\n",
        $home.'/.bonusTraffic' => "25\n",
        $home.'/.bonusQuota' => "50\n",
        $home.'/.billingId' => "12345\n",
        $home.'/.throttle' => "100\n",
        $home.'/.trafficData' => $serializedTraffic,
        $home.'/.trafficDataIngress' => $serializedTraffic,
        $home.'/.resourceData' => $resourceData,
        $home.'/.rtorrent.rc' => "directory = ~/data\n",
        $home.'/.quota' => "Disk quotas for user renderuser (uid 1000):\nFilesystem blocks quota limit grace files quota limit grace\n/dev/mock 20971520 0 104857600 0 0 0 0\n",
        $bootstrap => "<?php\nparse_str((string) getenv('QUERY_STRING'), \$_GET);\n\$_SERVER['REQUEST_METHOD'] = getenv('REQUEST_METHOD') ?: 'GET';\n\$_SERVER['SERVER_NAME'] = getenv('SERVER_NAME') ?: 'render.test';\n\$_SERVER['DOCUMENT_ROOT'] = getcwd();\n",
    ];

    foreach ($files as $path => $content) {
        if (@file_put_contents($path, $content) === false) {
            return ['ok' => false, 'error' => 'unable to write mock file: '.$path];
        }
    }

    return ['ok' => true, 'error' => ''];
}
