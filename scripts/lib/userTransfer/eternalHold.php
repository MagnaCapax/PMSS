<?php
/** Source/target account holds keep Eternal state cold during the final pass. */

function pmssUserTransferTargetEternalCommand(string $user, ?int $until): string
{
    $binary = '"$HOME/.mcxPikkumies/pikkumies/bin/pikkumies"';
    $marker = '"$HOME/.mcxPikkumies/pikkumies/local/eternals.hold"';
    $accountCommand = $until === null
        ? 'rm -f -- '.$marker
        : 'if [ -x '.$binary.' ] && [ -f /etc/seedbox/config/pikkumiesEternals.enabled ]; then '
            .$binary.' eternal hold --until '.(string) $until.'; fi';
    return pmssBuildUserShellNoPamCommand($user, $accountCommand);
}
