<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class resourceStatsCliCharacterizationTest extends TestCase
{
    public function testCliFlowSharesProcessorHelpers(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/cron/resourceStats.php', "exit(\$processor->runCli(\$argv, \$_SERVER['argv'][0]));");
        $this->pmssAssertRepoFileNotContainsString('scripts/cron/resourceStats.php', 'pmssCliUserArgSanitize(');
        $this->pmssAssertRepoFileNotContainsString('scripts/cron/resourceStats.php', "\$processor->spawnWorkers(\$_SERVER['argv'][0], \$users);");
        foreach (['scripts/lib/resources/processor.php', 'scripts/lib/traffic/processor.php'] as $path) {
            $this->pmssAssertRepoFileContainsString($path, 'pmssStatsProcessorRunCli(');
            $this->pmssAssertRepoFileContainsString($path, 'pmssPasswdFileHasUser(');
            $this->pmssAssertRepoFileNotContainsString($path, "preg_match('/^'.preg_quote(");
        }

        $this->pmssAssertRepoFileContainsString('scripts/lib/user/add/preflight.php', "pmssPasswdFileHasUser('/etc/passwd', \$userName)");
    }
}
