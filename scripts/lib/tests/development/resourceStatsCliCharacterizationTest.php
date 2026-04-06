<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class resourceStatsCliCharacterizationTest extends TestCase
{
    public function testCronEntrypointDelegatesCliFlowToProcessorRunCli(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/cron/resourceStats.php', "exit(\$processor->runCli(\$argv, \$_SERVER['argv'][0]));");
        $this->pmssAssertRepoFileNotContainsString('scripts/cron/resourceStats.php', 'pmssCliUserArgSanitize(');
        $this->pmssAssertRepoFileNotContainsString('scripts/cron/resourceStats.php', "\$processor->spawnWorkers(\$_SERVER['argv'][0], \$users);");
    }
}
