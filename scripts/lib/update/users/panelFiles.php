<?php
/** Operator-side delivery inventory; customer PHP keeps its local loader. @license GPL-3.0-only */

/** Share the twelve ADR 0099 parts while each delivery path retains its surrounding order. */
function pmssUserPanelHelperFiles(string $prefix = ''): array
{
    return array_map(static function (string $part) use ($prefix): string { return $prefix.'scriptsInc'.$part.'.php'; },
        ['Format', 'Files', 'Frontend', 'Bonus', 'Cgroup', 'Backup', 'RestoreTar', 'Restore', 'AppProcess', 'AppsHtml', 'AppsStatus', 'ActionJs']);
}
