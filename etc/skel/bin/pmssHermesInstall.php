<?php
/** Vanilla release install and account state convergence. */
require_once __DIR__.'/pmssHermesRuntime.php';

// Immutable upstream release and two executable supply-chain inputs.
const HERMES_TAG = 'v2026.9.24';
const HERMES_COMMIT = 'f97608f178d1ffeca59860195ab7da295f7c8e5f';
const HERMES_INSTALL_SH_SHA256 = '2017ddf0cc7bc6cfb70d40dc9fba1d916f47dbcccf5fe73bdee2cf93a11262af';
const HERMES_LOCK_JSON_SHA256 = '193a3a3703499eb7c4b4ce48d3ba1e9dae2be62a48315c1e7ea9545e8be4a067';
class PmssHermesCustomerTreeChanged extends RuntimeException {}

function pmssHermesPinMatches(string $head, string $installerHash, string $lockHash): bool
{
    return $head === HERMES_COMMIT
        && hash_equals(HERMES_INSTALL_SH_SHA256, $installerHash)
        && hash_equals(HERMES_LOCK_JSON_SHA256, $lockHash);
}

/** Refuse a modified customer checkout; update only one we previously pinned. */
function pmssHermesCheckoutPin(array $paths): void
{
    if (!is_dir($paths['tree'])) {
        list($rc) = pmssAgentRun(array('git', 'clone', '--depth', '1', '--branch', HERMES_TAG,
            'https://github.com/NousResearch/hermes-agent.git', $paths['tree']));
        if ($rc !== 0) throw new RuntimeException('Could not clone pinned Hermes release');
    } else {
        list($rc, $head) = pmssAgentRun(array('git', '-C', $paths['tree'], 'rev-parse', 'HEAD'));
        if ($rc !== 0) throw new RuntimeException('Hermes checkout is not a git repository');
        if (trim($head) !== HERMES_COMMIT) {
            $receipt = $paths['base'].'/pmss-hermes-commit';
            if (!is_file($receipt) || is_link($receipt) || trim((string) file_get_contents($receipt)) !== trim($head))
                throw new PmssHermesCustomerTreeChanged('Hermes checkout was changed by its owner; leaving it untouched');
            list($statusRc, $status) = pmssAgentRun(array('git', '-C', $paths['tree'], 'status', '--porcelain'));
            if ($statusRc !== 0 || trim($status) !== '') throw new PmssHermesCustomerTreeChanged('Hermes checkout has local changes');
            list($fetchRc) = pmssAgentRun(array('git', '-C', $paths['tree'], 'fetch', '--depth', '1', 'origin', HERMES_TAG));
            if ($fetchRc !== 0) throw new RuntimeException('Could not fetch pinned Hermes release');
            list($checkoutRc) = pmssAgentRun(array('git', '-C', $paths['tree'], 'checkout', '--detach', HERMES_COMMIT));
            if ($checkoutRc !== 0) throw new RuntimeException('Could not select pinned Hermes release');
        }
    }
    list($rc, $actual) = pmssAgentRun(array('git', '-C', $paths['tree'], 'rev-parse', 'HEAD'));
    list($statusRc, $status) = pmssAgentRun(array('git', '-C', $paths['tree'], 'status', '--porcelain'));
    if ($statusRc !== 0 || trim($status) !== '') throw new PmssHermesCustomerTreeChanged('Hermes checkout has local changes; leaving it untouched');
    if ($rc !== 0 || !pmssHermesPinMatches(trim($actual),
        (string) @hash_file('sha256', $paths['tree'].'/scripts/install.sh'),
        (string) @hash_file('sha256', $paths['tree'].'/package-lock.json')))
        throw new RuntimeException('Hermes pin verification failed');
    file_put_contents($paths['base'].'/pmss-hermes-commit', HERMES_COMMIT."\n", LOCK_EX);
}

/** Seed only a fresh provider choice; never replace an existing customer config. */
function pmssHermesProviderSeed(?array $credentials, bool $existingConfig): ?array
{
    return !$existingConfig && $credentials !== null ? $credentials : null;
}

/** Update only our marked key line, so removal of the marker opts out. */
function pmssHermesKeyReconcile(string $content, string $key): string
{
    $marker = '# pmss-hermes-virsilipas';
    if (strpos($content, $marker) === false) return $content;
    return preg_replace_callback('/^(HERMES_CUSTOM_[A-Z0-9_]+_API_KEY=)[^\r\n]* # pmss-hermes-virsilipas$/m',
        static function (array $match) use ($key, $marker): string { return $match[1].$key.' '.$marker; }, $content);
}

/** Refresh only the PMSS-marked provider key and stop a stale process. */
function pmssHermesProviderKeyRefresh(array $paths): void
{
    $credentials = pmssAgentVirsilipasCredentials($paths['home']);
    $file = $paths['base'].'/.env';
    $receipt = $paths['base'].'/pmss-provider-endpoint';
    if ($credentials === null || !is_file($receipt) || is_link($receipt)
        || trim((string) @file_get_contents($receipt)) !== $credentials['endpoint']
        || !pmssAgentPrivateFileIsSafe($file, 65536)) return;
    $old = (string) file_get_contents($file);
    $new = pmssHermesKeyReconcile($old, $credentials['key']);
    if ($new === $old) return;
    $temp = tempnam($paths['base'], '.pmss-key-');
    if ($temp === false || !chmod($temp, 0600)
        || file_put_contents($temp, $new) !== strlen($new) || !rename($temp, $file))
        throw new RuntimeException('Could not refresh provider key');
    pmssHermesStop($paths);
}

/** Upstream uses the endpoint host:port as the custom key's identity. */
function pmssHermesCustomKeyEnv(string $endpoint): string
{
    $host = parse_url($endpoint, PHP_URL_HOST);
    $port = parse_url($endpoint, PHP_URL_PORT);
    $identity = strtoupper((string) $host.($port === null ? '' : ':'.$port));
    return 'HERMES_CUSTOM_'.trim((string) preg_replace('/[^A-Z0-9]+/', '_', $identity), '_').'_API_KEY';
}

function pmssHermesPrivateWrite(string $path, string $content): void
{
    if (is_link($path) || (file_exists($path) && !is_file($path))) throw new RuntimeException('Private state path is unsafe');
    $old = umask(0077);
    try {
        $file = @fopen($path, 'x');
        if ($file === false) throw new RuntimeException('Private state already exists');
        if (fwrite($file, $content) !== strlen($content)) { fclose($file); throw new RuntimeException('Private state write failed'); }
        fclose($file);
    } finally { umask($old); }
}

/** Publish password and signing secret only to a private account file. */
function pmssHermesCredentialsEnsure(array $paths): void
{
    if (file_exists($paths['env']) || is_link($paths['env'])) { pmssHermesEnv($paths, ''); return; }
    pmssHermesPrivateWrite($paths['env'], 'HERMES_DASHBOARD_BASIC_AUTH_PASSWORD='.bin2hex(random_bytes(32))."\n"
        .'HERMES_DASHBOARD_BASIC_AUTH_SECRET='.bin2hex(random_bytes(32))."\n");
}

/** Invalidate every C cookie without changing the customer's service password. */
function pmssHermesSessionRotate(array $paths): void
{
    if (!is_file($paths['env']) || is_link($paths['env'])) return;
    $current = pmssHermesEnv($paths, '');
    pmssHermesStop($paths);
    $content = 'HERMES_DASHBOARD_BASIC_AUTH_PASSWORD='.$current['HERMES_DASHBOARD_BASIC_AUTH_PASSWORD']."\n"
        .'HERMES_DASHBOARD_BASIC_AUTH_SECRET='.bin2hex(random_bytes(32))."\n";
    $temp = tempnam($paths['base'], '.pmss-session-');
    if ($temp === false || !chmod($temp, 0600) || file_put_contents($temp, $content) !== strlen($content)
        || !rename($temp, $paths['env'])) throw new RuntimeException('Could not rotate dashboard session');
}

/** Restore shell files byte-for-byte after upstream install.sh's PATH handling. */
function pmssHermesInstallStageAccepted(string $stage, int $rc, string $output, bool $complete): bool
{
    if ($rc === 0) return true;
    return $stage === 'products' && $complete && strpos($output, 'cannot update PATH in') !== false;
}

function pmssHermesUpstreamInstall(array $paths): void
{
    $saved = array();
    foreach (array('.bashrc', '.profile', '.bash_profile', '.bash_login') as $name) {
        $path = $paths['home'].'/'.$name;
        $saved[$path] = is_file($path) && !is_link($path) ? file_get_contents($path) : null;
    }
    try {
        $env = getenv();
        $env['HERMES_HOME'] = $paths['base'];
        $env['HERMES_INSTALL_DIR'] = $paths['tree'];
        $rc = 0;
        $output = '';
        // The repository stage can update the checkout; this path deliberately omits it.
        // The prerequisites stage may install system packages; this installer owns only account state.
        foreach (array('venv', 'python-deps', 'node-deps', 'config', 'path', 'complete') as $stage) {
            list($rc, $output) = pmssAgentRun(array('bash', $paths['tree'].'/scripts/install.sh', '--stage', $stage,
                '--commit', HERMES_COMMIT, '--dir', $paths['tree'], '--hermes-home', $paths['base'],
                '--non-interactive', '--skip-browser', '--skip-computer-use'), $env);
            $complete = is_file($paths['exe']) && is_file($paths['tree'].'/hermes_cli/web_dist/index.html');
            if (!pmssHermesInstallStageAccepted($stage, $rc, $output, $complete)) break;
        }
        if ($rc === 0) {
            $npm = is_file($paths['base'].'/node/bin/npm') ? $paths['base'].'/node/bin/npm' : 'npm';
            $env['PATH'] = $paths['base'].'/node/bin:'.(getenv('PATH') ?: '/usr/bin:/bin');
            list($buildRc, $buildOutput) = pmssAgentRun(array($npm, 'run', 'build', '--workspace', 'web',
                '--prefix', $paths['tree']), $env);
            if ($buildRc !== 0) { $rc = $buildRc; $output = $buildOutput; $stage = 'web-build'; }
        }
    } finally {
        foreach ($saved as $path => $bytes) {
            if ($bytes === null) { if (is_file($path) && !is_link($path)) unlink($path); }
            elseif (is_file($path) && !is_link($path) && file_get_contents($path) !== $bytes) file_put_contents($path, $bytes);
        }
    }
    $complete = is_file($paths['exe']) && is_file($paths['tree'].'/hermes_cli/web_dist/index.html');
    if (!pmssHermesInstallStageAccepted($stage, $rc, $output, $complete)) throw new RuntimeException('Upstream Hermes install failed at '.$stage);
    if (!$complete) throw new RuntimeException('Hermes dashboard build is incomplete');
}

/** Configure the vanilla CLI through its supported config command. */
function pmssHermesConfigSet(array $paths, string $key, string $value): void
{
    list($rc) = pmssAgentRun(array($paths['exe'], 'config', 'set', $key, $value));
    if ($rc !== 0) throw new RuntimeException('Could not set Hermes dashboard configuration');
}

function pmssHermesInstall(array $paths, string $origin): void
{
    if (!preg_match('#^https://[0-9a-f]{16}\.mcx\.fi:846/$#D', $origin)) throw new RuntimeException('A valid service origin is required');
    $port = pmssAgentReservedPort($paths['home'].'/.media-stack-port-hermes');
    if ($port === null) throw new RuntimeException('No reserved Hermes port; run a PMSS user update first');
    list($quotaRc, $quotaOutput) = pmssAgentRun(array('quota', '-w', '-p', '-u'));
    $files = $quotaRc === 0 ? pmssAgentQuotaHeadroom($quotaOutput) : null;
    if ($files !== null && $files['used'] > $files['soft'] - 60000) throw new RuntimeException('Hermes needs 60,000 free file quota entries');
    if (is_link($paths['base']) || is_link($paths['tree'])) throw new RuntimeException('Hermes state is unsafe');
    $existingConfig = is_file($paths['base'].'/config.yaml');
    $provider = pmssHermesProviderSeed(pmssAgentVirsilipasCredentials($paths['home']), $existingConfig);
    if (!is_dir($paths['base'])) mkdir($paths['base'], 0700);
    pmssHermesCheckoutPin($paths);
    pmssHermesUpstreamInstall($paths);
    pmssHermesCredentialsEnsure($paths);
    $user = basename($paths['home']);
    pmssHermesConfigSet($paths, 'dashboard.public_url', $origin);
    pmssHermesConfigSet($paths, 'dashboard.basic_auth.username', $user);
    file_put_contents($paths['base'].'/pmss-dashboard.url', $origin."\n");
    if ($provider !== null) {
        pmssHermesConfigSet($paths, 'model.default', 'default');
        pmssHermesConfigSet($paths, 'model.provider', 'custom');
        pmssHermesConfigSet($paths, 'model.base_url', $provider['endpoint']);
        $keyEnv = pmssHermesCustomKeyEnv($provider['endpoint']);
        pmssHermesConfigSet($paths, 'model.api_key', '${'.$keyEnv.'}');
        $envPath = $paths['base'].'/.env';
        clearstatcache(true, $envPath);
        if (is_link($envPath) || (file_exists($envPath) && (!is_file($envPath)
            || (fileperms($envPath) & 0077) !== 0))) throw new RuntimeException('Hermes provider env is unsafe');
        if (!is_file($envPath)) pmssHermesPrivateWrite($envPath, '');
        $old = (string) file_get_contents($envPath);
        $line = $keyEnv.'='.$provider['key']." # pmss-hermes-virsilipas\n";
        $new = strpos($old, '# pmss-hermes-virsilipas') !== false
            ? pmssHermesKeyReconcile($old, $provider['key']) : rtrim($old, "\n")."\n".$line;
        if (file_put_contents($envPath, $new, LOCK_EX) !== strlen($new))
            throw new RuntimeException('Could not write provider env');
        $receipt = $paths['base'].'/pmss-provider-endpoint';
        if (!file_exists($receipt) && !is_link($receipt))
            pmssHermesPrivateWrite($receipt, $provider['endpoint']."\n");
    }
    @unlink($paths['disabled']);
}
