<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2LoggingBootstrapOrderTest extends TestCase
{
    public function testStandaloneLogmsgDefaultsBootstrapPrecedesFirstProfiledStep(): void
    {
        $this->pmssAssertRepoFileContract('scripts/util/update-step2.php', [
            'required' => [
                "require_once __DIR__.'/../lib/log.php';",
                "'base_name' => 'pmss-update'",
                "require_once __DIR__.'/../lib/update.php';",
                "pmssRunProfiledCallable('Acquiring update-step2 lock'",
            ],
            'forbidden' => [
                "if (!function_exists('logmsg')) {" => 'update-step2.php should rely on the shared log bootstrap instead of a local logmsg fallback',
                'logmsg'.'_default_logger' => 'update-step2.php should not keep a cached Logger object for legacy logmsg() anymore',
                'new Logger(' => 'update-step2.php should not instantiate Logger just to configure legacy logmsg()',
            ],
            'ordered' => [
                [
                    'needles' => [
                        "require_once __DIR__.'/../lib/log.php';",
                        "require_once __DIR__.'/../lib/update.php';",
                    ],
                    'orderPrefix' => 'Shared log bootstrap should load before update helpers near: ',
                ],
                [
                    'needles' => [
                        "'base_name' => 'pmss-update'",
                        "pmssRunProfiledCallable('Acquiring update-step2 lock'",
                    ],
                    'orderPrefix' => 'Shared logmsg defaults must precede profiled steps near: ',
                ],
            ],
        ]);
    }
}
