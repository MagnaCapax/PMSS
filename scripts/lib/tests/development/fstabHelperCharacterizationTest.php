<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/fstab.php';

class FstabHelperCharacterizationTest extends TestCase
{
    public function testFstabLinesReadRejectsSymlinkedInput(): void
    {
        $target = $this->pmssMakeTempFile('pmss-fstab-target-');
        $link = $this->pmssMakeTempPath('pmss-fstab-link-');
        file_put_contents($target, "tmpfs /tmp tmpfs defaults 0 0\n");
        $this->pmssCreateSymlinkOrSkip($target, $link);

        $messages = $this->pmssArrayLoggerMessages(function (callable $logger) use ($link): void {
            $this->assertSame(null, \pmssFstabLinesRead($link, $logger, 'fstab characterization'));
        });

        $this->assertTrue($this->pmssMessagesContain($messages, 'not a regular file'), 'expected regular-file guard log');
    }

    public function testMountEntryReadMatchesRequestedFilesystemType(): void
    {
        $lines = [
            '# comment',
            'tmpfs /tmp ext4 defaults 0 0',
            'tmpfs /tmp tmpfs defaults 0 0',
        ];

        $entry = \pmssFstabMountEntryRead($lines, '/tmp', 'tmpfs');

        $this->assertSame(2, $entry['index']);
        $this->assertSame(['tmpfs', '/tmp', 'tmpfs', 'defaults', '0', '0'], $entry['columns']);
        $this->assertSame(null, \pmssFstabMountEntryRead($lines, '/tmp', 'xfs'));
    }

    public function testMountOptionsEnsureCollapsesPrefixedDuplicatesByDefault(): void
    {
        $lines = ['tmpfs /tmp tmpfs defaults,exec,size=1G,size=512M,mode=1777,mode=0755 0 0'];

        $plan = \pmssFstabMountOptionsEnsure($lines, '/tmp', ['noexec'], ['exec'], false, 'tmpfs', ['size=' => 'size=2G', 'mode=' => 'mode=1777']);

        $this->assertTrue($plan['changed']);
        $this->assertSame(['defaults', 'size=2G', 'mode=1777', 'noexec'], $plan['options']);
        $this->assertSame("tmpfs\t/tmp\ttmpfs\tdefaults,size=2G,mode=1777,noexec\t0\t0", $lines[0]);
    }

    public function testMountOptionsEnsureCanPreserveExtraPrefixedDuplicates(): void
    {
        $lines = ['tmpfs /tmp tmpfs defaults,exec,size=1G,size=512M 0 0'];

        $plan = \pmssFstabMountOptionsEnsure($lines, '/tmp', ['noexec'], ['exec'], false, 'tmpfs', ['size=' => 'size=2G'], false);

        $this->assertSame(['defaults', 'size=2G', 'size=512M', 'noexec'], $plan['options']);
        $this->assertSame("tmpfs\t/tmp\ttmpfs\tdefaults,size=2G,size=512M,noexec\t0\t0", $lines[0]);
    }

    public function testPlanChangeSuffixKeepsAddedAndRemovedOrderStable(): void
    {
        $this->assertSame('', \pmssFstabPlanChangeSuffix(['added' => [], 'removed' => []]));
        $this->assertSame(
            ' (added noexec, nosuid) (removed exec)',
            \pmssFstabPlanChangeSuffix(['added' => ['noexec', 'nosuid'], 'removed' => ['exec']])
        );
    }
}
