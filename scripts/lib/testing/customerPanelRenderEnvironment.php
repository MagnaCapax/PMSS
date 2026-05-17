<?php
/**
 * Synthetic filesystem setup for customer panel render tests.
 *
 * @license GPL-3.0-only
 */

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
    if (!is_dir($base)) {
        @mkdir($base, 0700, true);
    }

    $root = $base.'/run-'.bin2hex(random_bytes(8));
    @mkdir($root, 0700, true);
    return $root;
}

/** Remove only the temporary directory shape created by this harness. */
function pmssCustomerPanelRenderCleanup(string $runRoot): void
{
    $allowed = rtrim(sys_get_temp_dir(), '/').PMSS_CUSTOMER_PANEL_RENDER_BASE.'/';
    if (strpos($runRoot, $allowed) !== 0 || !is_dir($runRoot)) {
        return;
    }

    pmssCustomerPanelRenderRemoveTree($runRoot);
}

/** Recursively delete a temporary tree without shelling out. */
function pmssCustomerPanelRenderRemoveTree(string $path): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
            continue;
        }
        @unlink($item->getPathname());
    }
    @rmdir($path);
}

/** Copy the customer tree and seed the synthetic customer state files. */
function pmssCustomerPanelRenderPrepare(string $sourceWww, string $home, string $www, string $bootstrap): array
{
    if (!is_dir($sourceWww)) {
        return ['ok' => false, 'error' => 'missing customer source tree: '.$sourceWww];
    }

    foreach ([$home, $www, $home.'/.config/deluge', $home.'/.config', $home.'/.lighttpd'] as $dir) {
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'unable to create mock directory: '.$dir];
        }
    }

    if (!pmssCustomerPanelRenderCopyTree($sourceWww, $www)) {
        return ['ok' => false, 'error' => 'unable to copy customer source tree'];
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

/** Recursively copy the customer web skeleton into the mock home. */
function pmssCustomerPanelRenderCopyTree(string $source, string $destination): bool
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $sourcePath = $item->getPathname();
        $target = $destination.'/'.substr($sourcePath, strlen($source) + 1);
        if ($item->isLink()) {
            if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0700, true)) {
                return false;
            }
            $linkTarget = readlink($sourcePath);
            if (!is_string($linkTarget) || !@symlink($linkTarget, $target)) {
                return false;
            }
            continue;
        }
        if ($item->isDir()) {
            if (!is_dir($target) && !@mkdir($target, 0700, true)) {
                return false;
            }
            continue;
        }
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0700, true)) {
            return false;
        }
        if (!@copy($sourcePath, $target)) {
            return false;
        }
    }

    return true;
}
