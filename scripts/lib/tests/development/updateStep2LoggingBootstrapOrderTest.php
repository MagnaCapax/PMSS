<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2LoggingBootstrapOrderTest extends TestCase
{
    public function testStandaloneLogmsgDefaultsBootstrapPrecedesFirstProfiledStep(): void
    {
        $source = $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/util/update-step2.php', [
            "require_once __DIR__.'/../lib/log.php';",
            "'base_name' => 'pmss-update'",
            "require_once __DIR__.'/../lib/update.php';",
            "pmssRunProfiledCallable('Acquiring update-step2 lock'",
        ], [
            "if (!function_exists('logmsg')) {" => 'update-step2.php should rely on the shared log bootstrap instead of a local logmsg fallback',
            'logmsg'.'_default_logger' => 'update-step2.php should not keep a cached Logger object for legacy logmsg() anymore',
            'new Logger(' => 'update-step2.php should not instantiate Logger just to configure legacy logmsg()',
        ]);
        $this->assertOrderedStrings([
            "require_once __DIR__.'/../lib/log.php';",
            "require_once __DIR__.'/../lib/update.php';",
        ], $source, '', 'Shared log bootstrap should load before update helpers near: ');
        $this->assertOrderedStrings([
            "'base_name' => 'pmss-update'",
            "pmssRunProfiledCallable('Acquiring update-step2 lock'",
        ], $source, '', 'Shared logmsg defaults must precede profiled steps near: ');
    }
}
