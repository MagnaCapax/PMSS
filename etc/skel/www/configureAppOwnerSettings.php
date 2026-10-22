<?php
/** Owner settings forms for toggle apps (slskd today), run as the account; only the launch shell sources the owner file. */
require_once __DIR__.'/toggleAppRuntime.php';

/** Keep the owner path aligned with the shared inert catalog. */
function pmssSlskdEnvPath($home)
{
    return $home.'/'.pmssToggleAppCustomerCatalog()['slskd']['ownerEnv'];
}

/** POSIX single quoting is locale independent and never evaluates shell syntax. */
function pmssSlskdEnvQuote($value)
{
    return "'".str_replace("'", "'\\''", $value)."'";
}

/** Validate complete settings before any writes, then serialize in fixed order. */
function pmssSlskdEnvBuild(array $values)
{
    $contents = '';
    foreach (array('SLSKD_SLSK_USERNAME', 'SLSKD_SLSK_PASSWORD', 'SLSKD_SHARED_DIR') as $key) {
        $value = $values[$key] ?? null;
        if (!is_string($value) || strlen($value) > 4096 || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $value)) return null;
        if ($key === 'SLSKD_SLSK_USERNAME') {
            $value = trim($value);
            if ($value === '' || strlen($value) > 64) return null;
        } elseif ($key === 'SLSKD_SLSK_PASSWORD') {
            if ($value === '' || strlen($value) > 256) return null;
        } elseif ($value !== '') {
            $folders = explode(';', $value);
            if (count($folders) > 32) return null;
            foreach ($folders as $folder) {
                if ($folder !== '' && ($folder[0] !== '/' || !is_dir($folder))) return null;
            }
        }
        if ($value !== '') $contents .= $key.'='.pmssSlskdEnvQuote($value)."\n";
    }
    return strlen($contents) <= 4096 ? $contents : null;
}

/** Decode only canonical assignments, never source or patch arbitrary owner code. */
function pmssSlskdEnvParse($contents)
{
    if (!is_string($contents) || $contents === '' || strlen($contents) > 4096
        || substr($contents, -1) !== "\n" || preg_match('//u', $contents) !== 1) return null;
    $values = array('SLSKD_SLSK_USERNAME' => '', 'SLSKD_SLSK_PASSWORD' => '', 'SLSKD_SHARED_DIR' => '');
    $canonical = '';
    foreach (explode("\n", substr($contents, 0, -1)) as $line) {
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2 || !array_key_exists($parts[0], $values) || $values[$parts[0]] !== '') return null;
        $value = str_replace("'\\''", "'", substr($parts[1], 1, -1));
        if ($value === '' || preg_match('/[\x00-\x1f\x7f]/', $value)
            || pmssSlskdEnvQuote($value) !== $parts[1]) return null;
        $values[$parts[0]] = $value;
    }
    foreach ($values as $key => $value) {
        if ($value !== '') $canonical .= $key.'='.pmssSlskdEnvQuote($value)."\n";
    }
    return $canonical === $contents && trim($values['SLSKD_SLSK_USERNAME']) === $values['SLSKD_SLSK_USERNAME']
        && strlen($values['SLSKD_SLSK_USERNAME']) >= 1 && strlen($values['SLSKD_SLSK_USERNAME']) <= 64
        && strlen($values['SLSKD_SLSK_PASSWORD']) >= 1 && strlen($values['SLSKD_SLSK_PASSWORD']) <= 256 ? $values : null;
}

/** Prefill public fields only; the password must never leave this helper. */
function pmssSlskdSettingsState($home)
{
    $path = pmssSlskdEnvPath($home);
    $safe = !is_link($home.'/.config') && !is_link(dirname($path)) && !is_link($path);
    $values = $safe ? pmssSlskdEnvParse(pmssConfigureFileRead($path, 4096)) : null;
    return array('configured' => $values !== null,
        'custom' => $values === null && (file_exists($path) || is_link($path)),
        'username' => $values['SLSKD_SLSK_USERNAME'] ?? '', 'sharedDir' => $values['SLSKD_SHARED_DIR'] ?? '',
        'passwordSet' => $values !== null && $values['SLSKD_SLSK_PASSWORD'] !== '');
}

/** Save after the Configure password gate; preserve old bytes before replacement. */
function pmssSlskdSettingsSave($home, array $request)
{
    $failed = array('ok' => false, 'status' => 400, 'message' => 'Check the Soulseek fields and private settings paths. Nothing was saved.');
    $path = pmssSlskdEnvPath($home);
    foreach (array($home.'/.config', dirname($path), $path, $path.'.prev') as $entry) {
        if (is_link($entry)) return $failed;
    }
    if (file_exists($path) && (!is_file($path) || !is_readable($path) || @filesize($path) > 4096)) return $failed;
    $old = pmssConfigureFileRead($path, 4096);
    if (is_file($path) && (strlen($old) !== @filesize($path) || strpos($old, "\0") !== false)) return $failed;
    $stored = pmssSlskdEnvParse($old);
    $password = $request['soulseekPassword'] ?? null;
    if ($password === '' && $stored !== null) $password = $stored['SLSKD_SLSK_PASSWORD'];
    $contents = pmssSlskdEnvBuild(array('SLSKD_SLSK_USERNAME' => $request['username'] ?? null,
        'SLSKD_SLSK_PASSWORD' => $password,
        'SLSKD_SHARED_DIR' => array_key_exists('sharedDir', $request) ? $request['sharedDir'] : ''));
    // Check retained passwords too, in case the account password changed since save.
    if ($contents === null || pmssPanelSessionPasswordValid(pmssPanelSessionPaths($home),
        pmssPanelSessionUsername($home), $password)) return $failed;
    foreach (array($home.'/.config', dirname($path)) as $dir) {
        if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0700)) || !@chmod($dir, 0700)) return $failed;
    }
    if (is_file($path) && !pmssConfigureFileWrite($path.'.prev', $old)) return $failed;
    if (!pmssConfigureFileWrite($path, $contents)) return $failed;
    clearstatcache(true, $path);
    if (is_link($path) || (@fileperms($path) & 0777) !== 0600 || @fileowner($path) !== posix_geteuid()) {
        return array('ok' => false, 'status' => 500, 'message' => 'Soulseek settings were written, but private ownership could not be verified.');
    }
    // Match the Apps restart path without enabling an app that the owner stopped.
    $enabled = $home.'/.slskdEnable';
    if (is_file($enabled) && !is_link($enabled)) {
        pmssCustomerNativeStop(pmssCustomerManagedAppDefinitions()['slskd']['binaries']);
        if (!pmssToggleAppRestartSignal('slskd', $home)) {
            return array('ok' => false, 'status' => 500, 'message' => 'Soulseek settings saved, but restart failed. Restart slskd in Apps.');
        }
        return array('ok' => true, 'status' => 200, 'message' => 'Soulseek settings saved. slskd will restart shortly.');
    }
    return array('ok' => true, 'status' => 200, 'message' => 'Soulseek settings saved. Turn slskd on in Apps to connect.');
}

/** Configure actions this file owns; shared Configure code names no app (ADR 0101). */
function pmssAppOwnerSettingsActions()
{
    return array('slskdSettings');
}

/** Run an action from pmssAppOwnerSettingsActions(). */
function pmssAppOwnerSettingsAction($home, $action, array $request)
{
    return pmssSlskdSettingsSave($home, $request);
}

/** Configure cards for available apps with owner settings: id => label and HTML. */
function pmssAppOwnerSettingsCards($home, array $status)
{
    if (!isset($status['apps']['slskd'])) return array();
    $s = pmssSlskdSettingsState($home);
    $e = 'pmssCustomerHtmlAttr';
    $html = '<p>Set your Soulseek account here. Use a separate Soulseek password, never your PMSS account password.</p>'
        .($s['custom'] ? '<p>Your slskd.env has custom content. Saving replaces it; the previous version is kept as slskd.env.prev. Enter your Soulseek username and password again.</p>' : '')
        .'<label>Soulseek username <input id="configure-slskd-username" maxlength="64" autocomplete="off" value="'.$e($s['username']).'"></label>'
        .'<p>'.($s['passwordSet'] ? 'A Soulseek password is saved.' : 'No Soulseek password saved.').'</p>'
        .'<label>Soulseek password <input type="password" id="configure-slskd-soulseek-password" data-pmss-clear maxlength="256" autocomplete="new-password" placeholder="'
        .($s['passwordSet'] ? 'Leave empty to keep the saved password' : 'Enter a separate Soulseek password').'"></label>'
        .'<label>Shared folders <input id="configure-slskd-shares" value="'.$e($s['sharedDir']).'"></label>'
        .'<p>Optional: absolute paths, separated with ; (for example <code style="display:inline">'.$e($home.'/data/music').'</code>). These folders will be shared on the Soulseek network.</p>'
        .'<div class="configure-actions"><input type="password" id="configure-slskd-account-password" data-pmss-clear autocomplete="current-password" placeholder="Account password" aria-label="Account password">'
        .'<button type="button" onclick="var f={username:document.getElementById(\'configure-slskd-username\').value,soulseekPassword:document.getElementById(\'configure-slskd-soulseek-password\').value,sharedDir:document.getElementById(\'configure-slskd-shares\').value,password:document.getElementById(\'configure-slskd-account-password\').value};'
        .'if(f.soulseekPassword!==\'\'&amp;&amp;f.soulseekPassword===f.password){pmssConfigureNotice(\'Use a separate Soulseek password, never your PMSS account password.\',true);return;}'
        .'pmssConfigureSend(\'slskdSettings\',f,\'Save Soulseek settings and restart slskd if enabled? The folders you list will be shared on the Soulseek network.\')">Save Soulseek settings</button></div>';
    return array('slskd' => array('label' => 'Soulseek (slskd)', 'html' => $html));
}
