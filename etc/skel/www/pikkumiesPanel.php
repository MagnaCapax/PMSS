<?php
/** Customer-tree state shared by the top frame, Welcome, and Configure. */

/** The PHP worker identity, never a request field, selects the account. */
function pmssPikkumiesPanelAccount()
{
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
    $entry = $uid >= 0 && function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
    return is_array($entry) ? array((string) ($entry['dir'] ?? ''), (string) ($entry['name'] ?? '')) : array('', '');
}

/** Use only the root-owned public billing identity used by the launcher. */
function pmssPikkumiesPanelStateRead($home, $name, $marker = '/etc/seedbox/config/pikkumiesEternals.enabled',
    $billingRoot = '/var/lib/pmss/public/billing', $ownerUid = 0)
{
    $empty = array('visible' => false, 'origin' => '', 'mode' => 'simplified', 'off' => false);
    if ($home === '' || !preg_match('/^[A-Za-z0-9._-]+$/D', $name)
        || !is_file($marker) || is_link($marker)) return $empty;
    $billing = $billingRoot.'/'.$name;
    $stat = @lstat($billing);
    $id = is_array($stat) && ($stat['mode'] & 0170000) === 0100000
        && $stat['uid'] === $ownerUid && $stat['nlink'] === 1
        ? trim((string) @file_get_contents($billing, false, null, 0, 64)) : '';
    $root = $home.'/.mcxPikkumies/pikkumies';
    $binary = $root.'/bin/pikkumies';
    $local = $root.'/local';
    if (!preg_match('/^[1-9][0-9]*$/D', $id) || !is_file($binary)
        || !is_executable($binary) || !is_dir($local)) return $empty;
    $off = is_file($local.'/eternals.off') || is_link($local.'/eternals.off');
    $modeFile = $home.'/.config/pmss/ui/pikkumies-interface';
    $mode = is_file($modeFile) && !is_link($modeFile)
        && trim((string) @file_get_contents($modeFile, false, null, 0, 32)) === 'power-user'
        ? 'power-user' : 'simplified';
    return array('visible' => !$off,
        'origin' => 'https://'.substr(hash('sha256', 'mcx.fi:service:'.ltrim($id, '0')), 0, 16).'.mcx.fi:844',
        'mode' => $mode, 'off' => $off);
}

/**
 * Configure shows the AI agents section only where it can work: Pikkumies is eligible, or the host carries the
 * Eternals marker that Hermes needs. Without the marker the Hermes install has no service address and always fails.
 */
function pmssAgentsSectionVisible(array $pikkumiesState, $home, $marker = '/etc/seedbox/config/pikkumiesEternals.enabled')
{
    if (($pikkumiesState['origin'] ?? '') !== '') return true;
    return is_file($marker) && !is_link($marker) && is_file($home.'/bin/install-hermes');
}
