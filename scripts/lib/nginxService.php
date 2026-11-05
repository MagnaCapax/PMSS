<?php
/**
 * Nginx service commands shared by config activation and updater recovery.
 *
 * @license GPL-3.0-only
 */

/**
 * Build a checked action without falling back around a running systemd manager.
 *
 * @param string $action One of start, restart or reload.
 * @param bool|null $systemd Manager selection; null detects the running manager.
 * @return string Shell command for the caller's existing logged execution path.
 */
function pmssNginxServiceCommand(string $action, ?bool $systemd = null): string
{
    if (!in_array($action, ['start', 'restart', 'reload'], true)) {
        throw new InvalidArgumentException('Unsupported nginx service action');
    }
    $systemd = $systemd ?? is_dir('/run/systemd/system');
    if (!$systemd) {
        return '/etc/init.d/nginx '.$action.($action === 'reload' ? ' || /etc/init.d/nginx start' : '');
    }

    // reload-or-restart starts an inactive unit; init-script reload can reach an orphan.
    $operation = $action === 'reload' ? 'reload-or-restart' : $action;
    return 'systemctl '.$operation.' nginx && systemctl is-active --quiet nginx'
        .' && pmssNginxMainPid=$(systemctl show nginx --property=MainPID --value)'
        .' && test "$pmssNginxMainPid" -gt 0';
}
