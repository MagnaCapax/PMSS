<?php
/** Shared customer action endpoint for catalogued secured toggle apps. */
require_once __DIR__.'/scriptsInc.php';
pmssFrontendPostActionRequired();
$catalog = require __DIR__.'/toggleAppCatalog.php';
$app = $pmssToggleAppId ?? ($_GET['app'] ?? '');
if (!is_string($app) || !isset($catalog[$app])) {
    http_response_code(404);
    exit;
}
require_once __DIR__.'/toggleAppRuntime.php';
$row = $catalog[$app];
pmssFrontendToggleAction('../'.$row['enable'], static function () use ($app, $row): void {
    pmssToggleAppStateWrite($app, is_file('../'.$row['securedMarker']) ? 'stopped' : 'not secured', dirname(__DIR__));
}, static function () use ($app): void {
    pmssCustomerNativeStop(pmssCustomerManagedAppDefinitions()[$app]['binaries']);
    pmssToggleAppStateWrite($app, 'stopped', dirname(__DIR__));
}, static function () use ($app): void {
    pmssCustomerNativeStop(pmssCustomerManagedAppDefinitions()[$app]['binaries']);
    pmssToggleAppRestartSignal($app, dirname(__DIR__));
});
