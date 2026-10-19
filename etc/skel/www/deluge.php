<?php
require_once __DIR__.'/scriptsInc.php';
// Port enforcement is operator-side work; this customer endpoint only toggles daemons.
/** Lightweight frontend toggle for Deluge. */

pmssFrontendToggleAction(
    '../.delugeEnable',
    static function () {
        pmssCustomerTorrentClientToggleStart('deluge', static function () {
            pmssFrontendShellExec(pmssCustomerDelugeSystemCommand());
        });
    },
    static function () { pmssCustomerNativeStop(pmssCustomerManagedAppDefinitions()['Deluge']['binaries']); }
);
