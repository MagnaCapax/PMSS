<?php
/**
 * Customer panel render harness orchestration.
 *
 * The harness renders first-party customer panel pages under CLI PHP with a
 * synthetic customer home, catching runtime fatals that static scans miss.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/customerPanelRenderEnvironment.php';
require_once __DIR__.'/customerPanelRenderProcess.php';

/** Execute the customer panel render harness and emit JSON for CI. */
function pmssCustomerPanelRenderMain(): int
{
    $repoRoot = pmssCustomerPanelRenderRepoRoot();
    $sourceWww = $repoRoot.'/etc/skel/www';
    $runRoot = pmssCustomerPanelRenderTempRoot();
    $homeRoot = $runRoot.'/home';
    $home = $homeRoot.'/renderuser';
    $www = $home.'/www';
    $bootstrap = $runRoot.'/php-cli-bootstrap.php';

    register_shutdown_function('pmssCustomerPanelRenderCleanup', $runRoot);

    $setupOk = pmssCustomerPanelRenderPrepare($sourceWww, $home, $www, $bootstrap);
    $result = [
        'ok' => false,
        'environmentRoot' => $runRoot,
        'pages' => [],
        'errors' => [],
    ];

    if (!$setupOk['ok']) {
        $result['errors'][] = $setupOk['error'];
        fwrite(STDERR, "[customer-panel-render-harness] FAIL - ".$setupOk['error']."\n");
        echo json_encode($result, JSON_PRETTY_PRINT)."\n";
        return 1;
    }

    $allStdout = '';
    foreach (pmssCustomerPanelRenderExpectations() as $page => $expectation) {
        $pageResult = pmssCustomerPanelRenderPage($www, $bootstrap, $homeRoot, $home, $page, $expectation);
        $allStdout .= $pageResult['stdout'];
        foreach ($pageResult['errors'] as $error) {
            $result['errors'][] = $pageResult['page'].': '.$error;
        }
        unset($pageResult['stdout']);
        $result['pages'][] = $pageResult;
    }

    foreach (pmssCustomerPanelRenderRequiredMarkers() as $marker) {
        if (strpos($allStdout, $marker) === false) {
            $result['errors'][] = 'missing marker: '.$marker;
        }
    }

    $result['ok'] = $result['errors'] === [];
    fwrite(STDERR, $result['ok']
        ? "[customer-panel-render-harness] OK - ".count($result['pages'])." page(s) rendered cleanly\n"
        : "[customer-panel-render-harness] FAIL - ".count($result['errors'])." render issue(s)\n");
    if (!$result['ok']) {
        foreach ($result['errors'] as $error) {
            fwrite(STDERR, "  - ".$error."\n");
        }
    }
    echo json_encode($result, JSON_PRETTY_PRINT)."\n";
    return $result['ok'] ? 0 : 1;
}
