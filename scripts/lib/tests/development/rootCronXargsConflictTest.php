<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RootCronXargsConflictTest extends TestCase
{
    public function testRootCronDoesNotMixXargsReplaceWithMaxArgs(): void
    {
        $path = 'etc/seedbox/config/root.cron';
        $contents = $this->pmssReadRepoFile($path);

        $this->assertFalse(
            preg_match('/\|xargs\s+-n1\s+-I\'\{1\}\'/m', $contents) === 1,
            'root.cron should not combine xargs max-args with replace mode in ionice or renice process scheduling lines'
        );
        $this->pmssAssertRepoFileSubstringCount(
            $path,
            "|xargs -I'{1}'",
            18,
            'root.cron should keep the 18 ionice or renice xargs process scheduling lines'
        );
    }
}
