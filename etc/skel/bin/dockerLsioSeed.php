<?php
/** First-run API recipes; no host listener is used until verification succeeds. */
require_once __DIR__.'/dockerLsioSeedHttp.php';

/** One table owns API versions, initialization steps and unauthenticated probes. */
function pmssDockerLsioSeedCatalog(): array
{
    return [
        'jellyfin' => ['kind' => 'jellyfin', 'ready' => '/Startup/Configuration',
            'init' => '/Startup/User', 'complete' => '/Startup/Complete',
            'login' => '/Users/AuthenticateByName', 'probe' => '/Users'],
        'radarr' => ['kind' => 'servarr', 'ready' => '/api/v3/config/host', 'probe' => '/api/v3/system/status'],
        'sonarr' => ['kind' => 'servarr', 'ready' => '/api/v3/config/host', 'probe' => '/api/v3/system/status'],
        'prowlarr' => ['kind' => 'servarr', 'ready' => '/api/v1/config/host', 'probe' => '/api/v1/system/status'],
    ];
}

/** Seed through the documented startup/host-config APIs, then prove refusal. */
function pmssDockerLsioSeedLogin(string $app, array $spec, array $recipe, array $credentials): bool
{
    $base = 'http://127.0.0.1:'.$spec['containerPort'];
    $headers = [];
    $ready = null;
    if (!pmssDockerLsioSeedWait(static function () use ($app, $base, $recipe, &$headers, &$ready): bool {
        if ($recipe['kind'] === 'servarr') {
            $xml = pmssDockerLsioSeedCapture(['docker', 'exec', $app, 'cat', '/config/config.xml']);
            if (!is_string($xml) || !preg_match('/<ApiKey>([a-fA-F0-9]{32})<\/ApiKey>/', $xml, $key)) {
                return false;
            }
            $headers = ['X-Api-Key: '.$key[1]];
        }
        $ready = pmssDockerLsioSeedHttp($app, $base.$recipe['ready'], 'GET', null, $headers);
        return $ready['code'] === 200;
    })) {
        return false;
    }
    if ($recipe['kind'] === 'jellyfin') {
        if (pmssDockerLsioSeedHttp($app, $base.$recipe['init'])['code'] !== 200
            || pmssDockerLsioSeedHttp($app, $base.$recipe['init'], 'POST', [
                'Name' => $credentials['USER'], 'Password' => $credentials['PASS'],
            ])['code'] !== 204
            || pmssDockerLsioSeedHttp($app, $base.$recipe['complete'], 'POST')['code'] !== 204) {
            return false;
        }
        $login = pmssDockerLsioSeedHttp($app, $base.$recipe['login'], 'POST', [
            'Username' => $credentials['USER'], 'Pw' => $credentials['PASS'],
        ], ['Authorization: MediaBrowser Client="PMSS", Device="installer", DeviceId="pmss-lsio", Version="1"']);
        $token = json_decode($login['body'], true)['AccessToken'] ?? null;
        if ($login['code'] !== 200 || !is_string($token) || !preg_match('/^[a-zA-Z0-9]+$/', $token)) {
            return false;
        }
        $users = pmssDockerLsioSeedHttp($app, $base.$recipe['probe'], 'GET', null, ['X-Emby-Token: '.$token]);
        $list = json_decode($users['body'], true);
        if ($users['code'] !== 200 || !is_array($list) || count($list) !== 1
            || ($list[0]['Name'] ?? null) !== $credentials['USER']) {
            return false;
        }
    } else {
        $payload = json_decode($ready['body'], true);
        // Never replace an existing login, even if a supposedly fresh volume supplied one.
        if (!is_array($payload) || !empty($payload['username']) || !empty($payload['password'])) {
            return false;
        }
        $payload = array_replace($payload, ['id' => 1, 'authenticationMethod' => 'forms',
            'authenticationRequired' => 'enabled', 'username' => $credentials['USER'],
            'password' => $credentials['PASS'], 'passwordConfirmation' => $credentials['PASS']]);
        $put = pmssDockerLsioSeedHttp($app, $base.$recipe['ready'].'/1', 'PUT', $payload, $headers);
        if (!in_array($put['code'], [200, 202], true)) {
            return false;
        }
    }
    return in_array(pmssDockerLsioSeedHttp($app, $base.$recipe['probe'])['code'], [401, 403], true);
}

/** Refuse prior config/credentials rather than changing a previous installation. */
function pmssDockerLsioSeedFresh(array $spec): bool
{
    $config = $spec['mkdirPaths'][0];
    return !file_exists($spec['seedCredentialFile']) && !is_link($spec['seedCredentialFile'])
        && !is_link($config) && (!file_exists($config) || (is_dir($config) && scandir($config) === ['.', '..']));
}

/** Create privately, seed, verify, then recreate the same image and volumes. */
function pmssDockerLsioSeedInstall(string $app, array $spec, array $published, array $recipe): int
{
    $credentialFile = $spec['seedCredentialFile'];
    if (!pmssDockerInstallLsioWriteCredentialFile($credentialFile, '', '', 'transmission', true)) {
        return 1;
    }
    $credentials = parse_ini_file($credentialFile, false, INI_SCANNER_RAW);
    $private = pmssDockerLsioSeedPrivateCommand($published);
    if (pmssDockerInstallLsioPassthru($private) !== 0) {
        fwrite(STDERR, "Could not start {$app} for login setup; no port published.\n");
        return 1;
    }
    $image = trim((string) pmssDockerLsioSeedCapture(['docker', 'inspect', '--format', '{{.Image}}', $app]));
    if (!preg_match('/^sha256:[a-f0-9]{64}$/', $image) || !is_array($credentials)
        || !pmssDockerLsioSeedLogin($app, $spec, $recipe, $credentials)) {
        fwrite(STDERR, "Login setup failed for {$app}; no port published. Config and credentials retained; inspect the unpublished container before reinstalling.\n");
        return 1;
    }
    // A graceful stop flushes the config volume. Never remove that volume.
    if (pmssDockerInstallLsioCheck(['docker', 'stop', $app]) !== 0
        || pmssDockerInstallLsioCheck(['docker', 'rm', $app]) !== 0) {
        fwrite(STDERR, "Could not finish {$app} setup; no port published.\n");
        return 1;
    }
    $published[count($published) - 1] = $image;
    $rc = pmssDockerInstallLsioPassthru($published);
    if ($rc === 0) {
        echo 'Service credentials: '.$credentialFile.PHP_EOL;
    } else {
        // docker run can leave a stopped candidate behind; never retry publication automatically.
        fwrite(STDERR, "Could not publish {$app}; config and credentials retained.\n");
    }
    return $rc;
}

/** Remove only the host mapping and disable restarts during unfinished setup. */
function pmssDockerLsioSeedPrivateCommand(array $published): array
{
    $index = array_search('-p', $published, true);
    array_splice($published, $index, 2);
    $published[array_search('--restart', $published, true) + 1] = 'no';
    return $published;
}
