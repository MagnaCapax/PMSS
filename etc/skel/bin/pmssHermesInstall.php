<?php
/** Vanilla release install and account state convergence. */
require_once __DIR__.'/pmssHermesRuntime.php';

// Immutable upstream release and two executable supply-chain inputs.
const HERMES_TAG = 'v0.22.7';
const HERMES_COMMIT = 'b200f5bc60e31588ba2cb60e288029c4a54ba5fa';
const HERMES_INSTALL_SH_SHA256 = 'abf338605dae6dd28d101673ac6aed7995c955745db46d47676bc591a7957209';
const HERMES_LOCK_JSON_SHA256 = 'cc006a0c37d86fa906164a5158207f8ad56aef4d4cd4b2aed7a6c58700070b8d';
class PmssHermesCustomerTreeChanged extends RuntimeException {}
/** Short code for the Configure card: files (gate refused) or quota (ran out during the install). */
class PmssHermesQuotaShort extends RuntimeException {}

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
        // v0.22.7's products stage publishes the launcher and builds the dashboard via upstream PM.
        foreach (array('venv', 'python-deps', 'config', 'products', 'complete') as $stage) {
            list($rc, $output) = pmssAgentRun(array('bash', $paths['tree'].'/scripts/install.sh', '--stage', $stage,
                '--commit', HERMES_COMMIT, '--dir', $paths['tree'], '--hermes-home', $paths['base'],
                '--non-interactive', '--skip-browser', '--skip-computer-use'), $env);
            $complete = is_file($paths['exe']) && is_file($paths['tree'].'/hermes_cli/web_dist/index.html');
            if (!pmssHermesInstallStageAccepted($stage, $rc, $output, $complete)) break;
        }
    } finally {
        foreach ($saved as $path => $bytes) {
            if ($bytes === null) { if (is_file($path) && !is_link($path)) unlink($path); }
            elseif (is_file($path) && !is_link($path) && file_get_contents($path) !== $bytes) file_put_contents($path, $bytes);
        }
    }
    $complete = is_file($paths['exe']) && is_file($paths['tree'].'/hermes_cli/web_dist/index.html');
    if (!pmssHermesInstallStageAccepted($stage, $rc, $output, $complete)) {
        if (preg_match('/EDQUOT|Disk quota exceeded|system error -122/', $output) === 1)
            throw new PmssHermesQuotaShort('Disk or file quota ran out during the Hermes install', 2);
        throw new RuntimeException('Upstream Hermes install failed at '.$stage);
    }
    if (!$complete) throw new RuntimeException('Hermes dashboard build is incomplete');
}

/**
 * v0.22.7 is built for the site root: Vite's preload helper turns each lazy page's dependency into "/"+path.
 * Under the panel prefix those requests 404 and the page stays blank (QA VM, 2026-11-04). The dashboard
 * already injects its prefix as window.__HERMES_BASE_PATH__, so the helper prepends it at run time. An
 * unrecognised build is refused rather than guessed at. web_dist is untracked, so the checkout stays clean.
 */
function pmssHermesDashboardPreloadPrefix(string $dist): void
{
    $helper = '/function\(([A-Za-z_$][\w$]*)\)\{return["`]\/["`]\+\1\}/';
    $prefixed = 'return(window.__HERMES_BASE_PATH__||"")+"/"+';
    $found = array();
    foreach (glob($dist.'/assets/*.js') ?: array() as $file) {
        if (is_link($file) || !is_file($file)) continue;
        $js = (string) file_get_contents($file);
        $count = preg_match_all($helper, $js) + substr_count($js, $prefixed);
        if ($count > 0) $found[$file] = array($count, $js);
    }
    if (count($found) !== 1 || reset($found)[0] !== 1)
        throw new RuntimeException('Hermes dashboard build has an unexpected asset loader');
    $file = key($found);
    $js = preg_replace($helper, 'function($1){'.$prefixed.'$1}', reset($found)[1], 1);
    $temp = substr_count($js, $prefixed) === 1 ? tempnam(dirname($file), '.pmss-') : false;
    if ($temp === false || file_put_contents($temp, $js) !== strlen($js)
        || !chmod($temp, fileperms($file) & 0777) || !rename($temp, $file)) {
        if ($temp !== false && is_file($temp)) unlink($temp);
        throw new RuntimeException('Could not prefix the Hermes dashboard asset loader');
    }
}
/** Configure the vanilla CLI through its supported config command. */
function pmssHermesConfigSet(array $paths, string $key, string $value): void
{
    list($rc) = pmssAgentRun(array($paths['exe'], 'config', 'set', $key, $value));
    if ($rc !== 0) throw new RuntimeException('Could not set Hermes dashboard configuration');
}

function pmssHermesInstall(array $paths, string $origin): void
{
    if ($origin !== pmssHermesPublicUrl($paths['home'])) throw new RuntimeException('The account panel URL is required');
    $port = pmssAgentReservedPort($paths['home'].'/.media-stack-port-hermes');
    if ($port === null || $port < 1024) throw new RuntimeException('No reserved Hermes port; run a PMSS user update first');
    list($quotaRc, $quotaOutput) = pmssAgentRun(array('quota', '-w', '-p', '-u'));
    $files = $quotaRc === 0 ? pmssAgentQuotaHeadroom($quotaOutput) : null;
    if ($files !== null && $files['used'] > $files['soft'] - HERMES_REQUIRED_FILES)
        throw new PmssHermesQuotaShort('Hermes needs '.number_format(HERMES_REQUIRED_FILES).' free file quota entries', 1);
    if (is_link($paths['base']) || is_link($paths['tree'])) throw new RuntimeException('Hermes state is unsafe');
    $existingConfig = is_file($paths['base'].'/config.yaml');
    $provider = pmssHermesProviderSeed(pmssAgentVirsilipasCredentials($paths['home']), $existingConfig);
    if (!is_dir($paths['base'])) mkdir($paths['base'], 0700);
    pmssHermesCheckoutPin($paths);
    // A previous dashboard still has its old public URL and imported code until restarted.
    pmssHermesStop($paths);
    pmssHermesUpstreamInstall($paths);
    pmssHermesDashboardPreloadPrefix($paths['tree'].'/hermes_cli/web_dist');
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
