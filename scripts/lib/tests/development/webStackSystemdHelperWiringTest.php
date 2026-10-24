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
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'scripts/util/update-step2.php',
            [
                "'update/runtime/processes.php'",
                'function pmssConfigureWebStack(): void',
                "pmssSystemdUnitActionIfPresent('lighttpd', 'Disabling lighttpd systemd service', 'disable');",
                "pmssSystemdUnitActionIfPresent('nginx', 'Enabling nginx systemd service', 'enable');",
                "foreach (['root', 'www-data'] as \$serviceUser)",
                'posix_getpwnam($serviceUser)',
                "if (\$serviceAccount === false)",
                "null, 10, (int) \$serviceAccount['uid']);",
            ],
            ['update-rc.d lighttpd', 'Disabling {$legacySvc} in sysvinit']
        );
    }
}
