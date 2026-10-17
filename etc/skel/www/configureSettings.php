<?php
/** Account settings for the customer-owned Configure page. */
require_once __DIR__.'/scriptsInc.php';
require_once __DIR__.'/panelSessionAuth.php';

/** Read a bounded regular account file without following symlinks. */
function pmssConfigureFileRead($path, $limit)
{
    if (!is_file($path) || is_link($path) || @filesize($path) > $limit) return '';
    $value = @file_get_contents($path, false, null, 0, $limit + 1);
    return is_string($value) && strlen($value) <= $limit ? $value : '';
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
    }
    $result = $comments;
    $index = 0;
    // Use server-assigned names so a submitted key cannot override a built-in tab.
    foreach ($rows as $row) {
        if (!is_array($row)) return null;
        $label = isset($row['label']) && is_string($row['label']) ? $row['label'] : '';
        $tooltip = isset($row['tooltip']) && is_string($row['tooltip']) ? $row['tooltip'] : '';
        $url = isset($row['url']) && is_string($row['url']) ? $row['url'] : '';
        if (strlen($label) < 1 || strlen($label) > 40 || strlen($tooltip) > 80 || $url === ''
            || strpbrk($label.$tooltip.$url, "|\r\n\0") !== false || strpos($url, '\\') !== false
            || !(preg_match('~^https?://[^[:space:]]+$~i', $url) === 1
                || ($url[0] !== '/' || substr($url, 0, 2) !== '//')
                    && strpos($url, '..') === false && preg_match('~^[^/:?#]+:~', $url) !== 1
                    && preg_match('~^[^[:space:]]+$~', $url) === 1)) return null;
        $result .= 'custom'.$index.'|'.$tooltip.'|'.$label.'|'.$url."\n";
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
        $parts = explode('|', $line, 4);
        if (count($parts) === 4) $rows[] = array('tooltip' => $parts[1], 'label' => $parts[2], 'url' => $parts[3]);
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
function pmssConfigureActionApply($home, $action, $request)
{
    $paths = pmssPanelSessionPaths($home);
    // These actions can run account commands or publish the username, so they need the account password.
    $highImpact = in_array($action, array('customFrames', 'rtorrentCustom', 'rtorrentRestore', 'webCertsUsername'), true);
    if ($highImpact && (!isset($request['password']) || !is_string($request['password'])
        || !pmssPanelSessionPasswordValid($paths, pmssPanelSessionUsername($home), $request['password']))) {
        $result = array('ok' => false, 'status' => 403, 'message' => 'The password you entered was not accepted. Nothing was changed.');
    } else {
        $result = pmssConfigureActionRun($home, $action, $request);
    }
    pmssPanelSessionLog($paths, 'configure action='.preg_replace('/[^a-zA-Z]/', '', (string) $action)
        .' status='.($result['ok'] ? 'ok' : 'fail'));
    return $result;
}

/** Keep each action bounded to its own account files and existing helpers. */
function pmssConfigureActionRun($home, $action, $request)
{
    $ok = false;
    $message = 'The setting could not be saved.';
    if ($action === 'customFrames') {
        $path = $home.'/.customFrames';
        // Refuse oversized existing files rather than silently dropping rows.
        $value = is_file($path) && @filesize($path) > 4096 ? null
            : pmssConfigureFramesBuild(isset($request['rows']) ? $request['rows'] : null,
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
