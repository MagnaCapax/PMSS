<?php
/** Customer slskd toggle; the root watchdog prepares config before launching. */
require_once __DIR__.'/scriptsInc.php';
require_once __DIR__.'/toggleAppRuntime.php';

pmssFrontendPostActionRequired();
pmssFrontendToggleAction('../.slskdEnable', static function (): void {
    pmssToggleAppStateWrite('slskd', is_file('../.pmss/apps/slskd.secured') ? 'stopped' : 'not secured', dirname(__DIR__));
}, static function (): void {
    pmssCustomerNativeStop(pmssCustomerManagedAppDefinitions()['slskd']['binaries']);
    pmssToggleAppStateWrite('slskd', 'stopped', dirname(__DIR__));
}, static function (): void {
    pmssCustomerNativeStop(pmssCustomerManagedAppDefinitions()['slskd']['binaries']);
    pmssToggleAppRestartSignal('slskd', dirname(__DIR__));
});
