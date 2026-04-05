<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class resourceStatsCliCharacterizationTest extends TestCase
{
    public function testCronEntrypointDelegatesCliFlowToProcessorRunCli(): void
    {
        $source = $this->pmssReadRepoFile('scripts/cron/resourceStats.php');

        $this->assertStringContainsString("exit(\$processor->runCli(\$argv, \$_SERVER['argv'][0]));", $source);
        $this->pmssAssertStringNotContainsString("if ((\$user = \$processor->detectWorkerUser(\$argv)) !== null)", $source);
        $this->pmssAssertStringNotContainsString("\$processor->spawnWorkers(\$_SERVER['argv'][0], \$users);", $source);
    }
}
