<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime.php';

final class resourceStatsCliCharacterizationTest extends TestCase
{
    public function testCliFlowSharesProcessorHelpers(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/cron/resourceStats.php', 'pmssRunCliProcessorEntrypoint(__FILE__, new ResourceStatsProcessor(new resourceStatistics()))');
        $this->pmssAssertRepoFileContainsString('scripts/lib/runtime.php', 'function pmssRunCliProcessorEntrypoint(string $scriptPath, object $processor): void');
        $this->pmssAssertRepoFileNotContainsString('scripts/cron/resourceStats.php', 'pmssCliUserArgSanitize(');
        $this->pmssAssertRepoFileNotContainsString('scripts/cron/resourceStats.php', "\$processor->spawnWorkers(\$_SERVER['argv'][0], \$users);");
        foreach (['scripts/lib/resources/processor.php', 'scripts/lib/traffic/processor.php'] as $path) {
            $this->pmssAssertRepoFileContainsAllStrings($path, ['pmssStatsProcessorRunCli(', 'pmssPasswdFileHasUser(']);
            $this->pmssAssertRepoFileNotContainsString($path, "preg_match('/^'.preg_quote(");
        }

        $this->pmssAssertRepoFileContainsString('scripts/lib/user/add/preflight.php', "pmssPasswdFileHasUser('/etc/passwd', \$userName)");
    }

    public function testSharedStatsCliHelperKeepsUserAndSpawnContracts(): void
    {
        $processed = [];
        $spawned = [];

        ob_start();
        $userRc = \pmssStatsProcessorRunCli(
            ['resourceStats.php', 'alice!'],
            '/scripts/cron/resourceStats.php',
            static function (string $user): bool { return $user === 'alice'; },
            static function (string $user, array $compareTimes) use (&$processed): void { $processed = [$user, array_keys($compareTimes)]; },
            static function (): array { return ['alice', 'bob']; },
            static function (string $scriptPath, array $users) use (&$spawned): void { $spawned = [$scriptPath, $users]; }
        );
        $userOutput = ob_get_clean();

        $this->assertSame(0, $userRc);
        $this->assertSame('', $userOutput);
        $this->assertEquals(['alice', ['month', 'week', 'day', 'hour', '15min']], $processed);
        $this->assertEquals([], $spawned);

        ob_start();
        $spawnRc = \pmssStatsProcessorRunCli(
            ['resourceStats.php'],
            '/scripts/cron/resourceStats.php',
            static function (): bool { return true; },
            static function (): void {},
            static function (): array { return ['alice', 'bob']; },
            static function (string $scriptPath, array $users) use (&$spawned): void { $spawned = [$scriptPath, $users]; }
        );
        $spawnOutput = ob_get_clean();

        $this->assertSame(0, $spawnRc);
        $this->assertSame('', $spawnOutput);
        $this->assertEquals(['/scripts/cron/resourceStats.php', ['alice', 'bob']], $spawned);
    }

    public function testSharedStatsCliHelperKeepsInvalidUserOutput(): void
    {
        ob_start();
        $rc = \pmssStatsProcessorRunCli(
            ['resourceStats.php', 'bad!'],
            '/scripts/cron/resourceStats.php',
            static function (): bool { return false; },
            static function (): void {},
            static function (): array { return []; },
            static function (): void {}
        );
        $output = ob_get_clean();

        $this->assertSame(0, $rc);
        $this->assertSame("Invalid user specified: bad\n", $output);
    }
}
