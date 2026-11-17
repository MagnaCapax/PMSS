<?php
/** Customer-tree state shared by the top frame, Welcome, and Configure. */

/** The PHP worker identity, never a request field, selects the account. */
function pmssPikkumiesPanelAccount()
{
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
    $entry = $uid >= 0 && function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
    return is_array($entry) ? array((string) ($entry['dir'] ?? ''), (string) ($entry['name'] ?? '')) : array('', '');
}

/** Account availability is independent of host release state and billing identity. */
function pmssPikkumiesPanelStateRead($home, $name, $host = null)
{
    $empty = array('visible' => false, 'origin' => '', 'mode' => 'simplified', 'off' => false);
    if ($home === '' || !preg_match('/^[A-Za-z0-9._-]+$/D', $name)) return $empty;
    if ($host === null) $host = strtolower(trim((string) @shell_exec('/bin/hostname -f')));
    if (!is_string($host) || preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/D', $host) !== 1) return $empty;
    $root = $home.'/.mcxPikkumies/pikkumies';
    $binary = $root.'/bin/pikkumies';
    $local = $root.'/local';
    if (!is_file($binary)
        || !is_executable($binary) || !is_dir($local)) return $empty;
    $off = is_file($local.'/eternals.off') || is_link($local.'/eternals.off');
    $modeFile = $home.'/.config/pmss/ui/pikkumies-interface';
    $mode = is_file($modeFile) && !is_link($modeFile)
        && trim((string) @file_get_contents($modeFile, false, null, 0, 32)) === 'power-user'
        ? 'power-user' : 'simplified';
    return array('visible' => !$off,
        'origin' => 'https://'.$host.'/user-'.$name.'/pikkumies/',
        'mode' => $mode, 'off' => $off);
}

/** Hermes uses the ordinary panel; Pikkumies keeps its separate eligibility policy. */
function pmssAgentsSectionVisible(array $pikkumiesState, $home)
{
    if (($pikkumiesState['origin'] ?? '') !== '') return true;
    return is_file($home.'/bin/install-hermes') && !is_link($home.'/bin/install-hermes');
}
