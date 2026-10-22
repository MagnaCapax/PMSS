<?php
/** Account settings for the customer-owned Configure page. */
require_once __DIR__.'/scriptsInc.php';
require_once __DIR__.'/panelSessionAuth.php';
if (is_file(__DIR__.'/pikkumiesPanel.php')) require_once __DIR__.'/pikkumiesPanel.php';
if (is_file(__DIR__.'/hermesAgent.php')) require_once __DIR__.'/hermesAgent.php';
if (is_file(__DIR__.'/configureAppOwnerSettings.php')) require_once __DIR__.'/configureAppOwnerSettings.php';

/** Save the two allowed display modes in a private account-owned file. */
function pmssPikkumiesInterfaceSave($home, $mode)
{
    if (!in_array($mode, array('simplified', 'power-user'), true)) return false;
    $base = $home.'/.config/pmss';
    $dir = $base.'/ui';
    if (is_link($base) || is_link($dir)
        || (!is_dir($base) && !@mkdir($base, 0700, true))
        || (!is_dir($dir) && !@mkdir($dir, 0700))) return false;
    @chmod($dir, 0700);
    return pmssConfigureFileWrite($dir.'/pikkumies-interface', $mode."\n");
}

/** Choose one vanilla Hermes window; the launcher starts only the chosen UI. */
function pmssHermesInterfaceSave($home, $mode)
{
    if (!in_array($mode, array('vanilla-hermes-web', 'vanilla-agentboard', 'agentboard-pikkumies'), true)) return false;
    if ($mode === 'agentboard-pikkumies'
        && !is_file($home.'/.mcxPikkumies/pikkumies/bin/pikkumies')) return false;
    $base = $home.'/.config/pmss';
    $dir = $base.'/ui';
    if (is_link($base) || is_link($dir)
        || (!is_dir($base) && !@mkdir($base, 0700, true))
        || (!is_dir($dir) && !@mkdir($dir, 0700))) return false;
    @chmod($dir, 0700);
    return pmssConfigureFileWrite($dir.'/hermes-interface', $mode."\n");
}

/** Read a bounded regular account file without following symlinks. */
function pmssConfigureFileRead($path, $limit)
{
    if (!is_file($path) || is_link($path) || @filesize($path) > $limit) return '';
    $value = @file_get_contents($path, false, null, 0, $limit + 1);
    return is_string($value) && strlen($value) <= $limit ? $value : '';
}

/** Reveal only the account-owned B login password after the panel password gate. */
function pmssPikkumiesHermesLoginRead($home)
{
    $path = $home.'/.mcxPikkumies/pikkumies/local/ui/hermes/credentials';
    clearstatcache(true, $path); // chmod() does not clear PHP's stat cache before 8.3
    if (!is_file($path) || is_link($path) || @fileowner($path) !== posix_geteuid()
        || (@fileperms($path) & 0077) !== 0 || @filesize($path) > 256) return '';
    $raw = @file_get_contents($path);
    return is_string($raw) && preg_match('/^HERMES_DASHBOARD_BASIC_AUTH_PASSWORD=([a-f0-9]{64})\nHERMES_DASHBOARD_BASIC_AUTH_SECRET=[a-f0-9]{64}\n$/D', $raw, $match)
        ? $match[1] : '';
}

/** Replace one account file atomically, with private permissions. */
function pmssConfigureFileWrite($path, $value)
{
    $dir = dirname($path);
    if (is_link($path) || (file_exists($path) && !is_file($path)) || !is_dir($dir)) return false;
    $tmp = @tempnam($dir, '.pmss-configure-');
    if (!is_string($tmp)) return false;
    $private = @chmod($tmp, 0600);
    $ok = $private && @file_put_contents($tmp, $value, LOCK_EX) === strlen($value)
        && !is_link($path) && @rename($tmp, $path);
    if (!$ok) @unlink($tmp);
    return $ok;
}

/** Validate and serialize editable custom frame rows, keeping comment lines. */
function pmssConfigureFramesBuild($rows, $existing)
{
    if (!is_array($rows) || count($rows) > 32) return null;
    $comments = '';
    // Existing comments belong to the customer and survive every edited row set.
    foreach (explode("\n", $existing) as $line) {
        if (isset($line[0]) && $line[0] === '#') $comments .= $line."\n";
        elseif (preg_match('/^[A-Za-z0-9_-]{1,40}\|\|\|\|-?\d{1,2}$/D', trim($line)) === 1) $comments .= trim($line)."\n";
    }
    $result = $comments;
    $index = 0;
    // Use server-assigned names so a submitted key cannot override a built-in tab.
    foreach ($rows as $row) {
        if (!is_array($row)) return null;
        $label = isset($row['label']) && is_string($row['label']) ? $row['label'] : '';
        $tooltip = isset($row['tooltip']) && is_string($row['tooltip']) ? $row['tooltip'] : '';
        $url = isset($row['url']) && is_string($row['url']) ? $row['url'] : '';
        $position = isset($row['position']) && is_string($row['position']) ? trim($row['position']) : '';
        if ($position !== '' && preg_match('/^-?\d{1,2}$/D', $position) !== 1) return null;
        if (strlen($label) < 1 || strlen($label) > 40 || strlen($tooltip) > 80 || $url === ''
            || strpbrk($label.$tooltip.$url, "|\r\n\0") !== false || strpos($url, '\\') !== false
            || !(preg_match('~^https?://[^[:space:]]+$~i', $url) === 1
                || ($url[0] !== '/' || substr($url, 0, 2) !== '//')
                    && strpos($url, '..') === false && preg_match('~^[^/:?#]+:~', $url) !== 1
                    && preg_match('~^[^[:space:]]+$~', $url) === 1)) return null;
        $result .= 'custom'.$index.'|'.$tooltip.'|'.$label.'|'.$url.($position !== '' ? '|'.(int) $position : '')."\n";
        $index++;
    }
    return strlen($result) <= 4096 ? $result : null;
}

/** Certificate requests use the same service-id eligibility as createWebPublicCerts. */
function pmssConfigureBillingIdRead($home)
{
    foreach (array('.billingServiceId', '.billingId') as $name) {
        $value = trim(pmssConfigureFileRead($home.'/'.$name, 64));
        if ($value !== '' && ctype_digit($value) && (int) $value > 0) return $value;
    }
    return '';
}

/** Read bounded customer settings without trusting symlinked state files. */
function pmssConfigureSettingsStateRead($home)
{
    $customFrames = pmssConfigureFileRead($home.'/.customFrames', 4096);
    $rows = array();
    foreach (explode("\n", $customFrames) as $line) {
        if ($line === '' || $line[0] === '#') continue;
        $parts = explode('|', $line);
        if (count($parts) >= 4 && $parts[3] !== '') $rows[] = array('tooltip' => $parts[1], 'label' => $parts[2], 'url' => $parts[3],
            'position' => isset($parts[4]) && preg_match('/^-?\d{1,2}$/D', trim($parts[4])) === 1 ? trim($parts[4]) : '');
    }
    $certMode = trim(pmssConfigureFileRead($home.'/.request-web-certs', 32));
    return array(
        'customRows' => $rows,
        'rtorrentCustom' => pmssConfigureFileRead($home.'/.rtorrent.rc.custom', 16384),
        'certMode' => in_array($certMode, array('default', 'username'), true) ? $certMode : '',
        'certFailed' => is_file($home.'/.request-web-certs.failed') && !is_link($home.'/.request-web-certs.failed'),
    );
}

/** Dispatch one settings change; callers enforce the POST/XHR transport guard. */
function pmssConfigureActionApply($home, $action, $request, $ops = array())
{
    $paths = pmssPanelSessionPaths($home);
    // These actions can run account commands or publish the username, so they need the account password.
    $highImpact = in_array($action, array('customFrames', 'rtorrentCustom', 'rtorrentRestore', 'webCertsUsername', 'torrentClientSwitch', 'hermesShowLogin', 'pikkumiesHermesShowLogin'), true)
        || (function_exists('pmssAppOwnerSettingsActions') && in_array($action, pmssAppOwnerSettingsActions(), true));
    if ($highImpact && (!isset($request['password']) || !is_string($request['password'])
        || !pmssPanelSessionPasswordValid($paths, pmssPanelSessionUsername($home), $request['password']))) {
        $result = array('ok' => false, 'status' => 403, 'message' => 'The password you entered was not accepted. Nothing was changed.');
    } else {
        $result = pmssConfigureActionRun($home, $action, $request, $ops);
    }
    pmssPanelSessionLog($paths, 'configure action='.preg_replace('/[^a-zA-Z]/', '', (string) $action)
        .' status='.($result['ok'] ? 'ok' : 'fail'));
    return $result;
}

/** Keep each action bounded to its own account files and existing helpers. */
function pmssConfigureActionRun($home, $action, $request, $ops = array())
{
    $ok = false;
    $message = 'The setting could not be saved.';
    if ($action === 'pikkumiesHermesShowLogin') {
        $name = pmssPanelSessionUsername($home);
        $state = function_exists('pmssPikkumiesPanelStateRead')
            ? pmssPikkumiesPanelStateRead($home, $name) : array('origin' => '');
        $password = $state['origin'] !== '' ? pmssPikkumiesHermesLoginRead($home) : '';
        return array('ok' => $password !== '', 'status' => $password !== '' ? 200 : 409,
            'message' => $password !== '' ? $password : 'Pikkumies Hermes login is unavailable.');
    } elseif (function_exists('pmssAppOwnerSettingsActions') && in_array($action, pmssAppOwnerSettingsActions(), true)) {
        return pmssAppOwnerSettingsAction($home, $action, $request);
    } elseif ($action === 'hermesInstall' || $action === 'hermesShowLogin') {
        if (!function_exists('pmssHermesPanelAction'))
            return array('ok' => false, 'status' => 503, 'message' => 'Hermes is unavailable on this host.');
        return pmssHermesPanelAction($home, $action === 'hermesInstall' ? 'install' : 'show-login', $request, $ops);
    } elseif ($action === 'hermesInterface') {
        $ok = pmssHermesInterfaceSave($home, $request['mode'] ?? null);
        return array('ok' => $ok, 'status' => $ok ? 200 : 400,
            'message' => $ok ? 'Saved.' : 'That did not go through. Try again.');
    } elseif ($action === 'pikkumiesInterface' || $action === 'pikkumiesEternal') {
        $name = pmssPanelSessionUsername($home);
        $state = function_exists('pmssPikkumiesPanelStateRead')
            ? pmssPikkumiesPanelStateRead($home, $name) : array('origin' => '');
        if ($state['origin'] === '') return array('ok' => false, 'status' => 403, 'message' => 'That did not go through. Try again.');
        if ($action === 'pikkumiesInterface') {
            $ok = pmssPikkumiesInterfaceSave($home, $request['mode'] ?? null);
        } else {
            $mode = $request['mode'] ?? null;
            $binary = $home.'/.mcxPikkumies/pikkumies/bin/pikkumies';
            if (in_array($mode, array('off', 'on'), true) && is_file($binary) && is_executable($binary)) {
                exec(escapeshellarg($binary).' eternal '.escapeshellarg($mode).' >/dev/null 2>&1', $output, $rc);
                $ok = $rc === 0;
            }
        }
        return array('ok' => $ok, 'status' => $ok ? 200 : 400,
            'message' => $ok ? 'Saved.' : 'That did not go through. Try again.');
    } elseif ($action === 'torrentClientSwitch') {
        if (!is_file(__DIR__.'/configureTorrentClients.php') || !is_file(__DIR__.'/torrentClientsResolve.php'))
            return array('ok' => false, 'status' => 503, 'message' => 'Version switching is unavailable on this host.');
        require_once __DIR__.'/configureTorrentClients.php';
        if (($request['client'] ?? null) === 'qbittorrent')
            return pmssConfigureTorrentClientSwitch($home, $request, $ops);
        if (in_array($request['client'] ?? null, array('deluge', 'transmission', 'rtorrent'), true)) {
            if (!is_file(__DIR__.'/configureTorrentClientsSwitch.php')
                || !is_file(__DIR__.'/configureTorrentClientsOther.php')
                || !is_file(__DIR__.'/transmissionLaunch.php'))
                return array('ok' => false, 'status' => 503, 'message' => 'Version switching is unavailable on this host.');
            require_once __DIR__.'/configureTorrentClientsSwitch.php';
            return pmssConfigureTorrentClientOtherSwitch($home, $request, $ops);
        }
        return array('ok' => false, 'status' => 400, 'message' => 'That torrent client is unavailable.');
    } elseif ($action === 'customFrames') {
        $path = $home.'/.customFrames';
        // Refuse oversized existing files rather than silently dropping rows.
        $value = is_file($path) && @filesize($path) > 4096 ? null
            // Removing the last custom tab sends no rows field at all (jQuery omits an empty array): an empty list.
            : pmssConfigureFramesBuild(isset($request['rows']) ? $request['rows'] : array(),
                pmssConfigureFileRead($path, 4096));
        $ok = $value !== null && pmssConfigureFileWrite($path, $value);
        $message = $ok ? 'Custom tabs saved. Reload the panel to see them.' : 'Check the custom tab fields and try again.';
    } elseif ($action === 'rtorrentCustom' || $action === 'rtorrentRestore') {
        $path = $home.'/.rtorrent.rc.custom';
        $previous = $home.'/.rtorrent.rc.custom.prev';
        $value = $action === 'rtorrentRestore' ? pmssConfigureFileRead($previous, 16384)
            : (isset($request['content']) && is_string($request['content']) ? $request['content'] : null);
        // The previous version is private and replaceable, including on restore.
        if ($value !== null && ($action !== 'rtorrentRestore' || is_file($previous))
            && (!is_file($path) || @filesize($path) <= 16384)
            && strlen($value) <= 16384 && strpos($value, "\0") === false
            && !is_link($path) && !is_link($previous)) {
            $old = pmssConfigureFileRead($path, 16384);
            $ok = pmssConfigureFileWrite($previous, $old)
                && pmssConfigureFileWrite($path, $value);
        }
        $message = $ok ? 'rTorrent settings saved. Restart rTorrent in Apps to apply them.' : $message;
    } elseif ($action === 'webCertsDefault' || $action === 'webCertsUsername') {
        if ($action === 'webCertsDefault' && pmssConfigureBillingIdRead($home) === '') {
            $message = 'This account has no service permalink yet, so there is nothing to certify without the username.';
        } elseif ($action === 'webCertsUsername' && empty($request['ctAcknowledged'])) {
            $message = 'Acknowledge the public Certificate Transparency listing first.';
        } else {
            $path = $home.'/.request-web-certs';
            $ok = pmssConfigureFileWrite($path, $action === 'webCertsUsername' ? "username\n" : "default\n");
            if ($ok) @unlink($home.'/.request-web-certs.failed');
            $message = $ok ? 'Request recorded. The certificate will normally be in place within a few minutes.' : $message;
        }
    } elseif ($action === 'restore') {
        $archivePath = isset($request['archivePath']) && is_string($request['archivePath']) ? $request['archivePath'] : '';
        $result = pmssCustomerBackupRestore($home, $archivePath);
        return array('ok' => !empty($result['ok']), 'status' => !empty($result['ok']) ? 200 : 400, 'message' => $result['message']);
    } elseif ($action === 'delugeRotate') {
        if (is_file(__DIR__.'/userPasswords.php')) require_once __DIR__.'/userPasswords.php';
        $password = function_exists('pmssDelugeServicePasswordRotate')
            ? pmssDelugeServicePasswordRotate(pmssPanelSessionUsername($home)) : '';
        $ok = $password !== '';
        $message = $ok ? 'Deluge password rotated. Clients using the old password need the new one.' : 'Deluge password rotation is unavailable on this host.';
    } else {
        $message = 'Unknown action.';
    }
    return array('ok' => $ok, 'status' => $ok ? 200 : 400, 'message' => $message);
}
