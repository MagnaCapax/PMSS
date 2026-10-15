<?php
/** Customer Copyparty toggle; the root watchdog prepares config before launching. */
require_once __DIR__.'/scriptsInc.php';
require_once __DIR__.'/toggleAppRuntime.php';

pmssFrontendPostActionRequired();
pmssFrontendToggleAction('../.copypartyEnable', static function (): void {
    pmssToggleAppStateWrite('copyparty', is_file('../.pmss/apps/copyparty.secured') ? 'stopped' : 'not secured', dirname(__DIR__));
}, static function (): void {
    pmssCustomerNativeStop(pmssCustomerManagedAppDefinitions()['copyparty']['binaries']);
    pmssToggleAppStateWrite('copyparty', 'stopped', dirname(__DIR__));
}, static function (): void {
    pmssCustomerNativeStop(pmssCustomerManagedAppDefinitions()['copyparty']['binaries']);
    pmssToggleAppRestartSignal('copyparty', dirname(__DIR__));
});
