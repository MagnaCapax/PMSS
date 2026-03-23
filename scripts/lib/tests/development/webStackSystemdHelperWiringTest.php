<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Ensures web stack orchestration calls shared systemd helpers.
 */
class WebStackSystemdHelperWiringTest extends TestCase
{
    /**
     * Web stack should reference shared helper include and call sites.
     */
    public function testWebStackUsesSharedRuntimeSystemdActionHelperForServiceState(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/update-step2.php');

        $this->assertTrue(strpos($src, "require_once __DIR__.'/../lib/update/runtime/processes.php';") !== false);
        $this->assertTrue(strpos($src, 'function pmssConfigureWebStack(int $distroVersion): void') !== false);
        $this->assertTrue(strpos($src, "pmssSystemdUnitActionIfPresent('lighttpd', 'Disabling lighttpd systemd service', 'disable');") !== false);
        $this->assertTrue(strpos($src, "pmssSystemdUnitActionIfPresent('nginx', 'Enabling nginx systemd service', 'enable');") !== false);
    }
}
