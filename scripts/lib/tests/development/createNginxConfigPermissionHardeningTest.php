<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../nginxConfig/main.php';

/**
 * Covers chmod hardening helpers used by nginx config generation.
 */
class CreateNginxConfigPermissionHardeningTest extends TestCase
{
    /** @var string */
    private $root;

    protected function pmssTempDirFixtureArguments(): array { return ['root', 'pmss-nginx-chmod-']; }

    public function testChmodGlobAppliesModeToMatchedFiles(): void
    {
        $first = $this->pmssWriteFile($this->root.'/plain.conf', "one\n");
        $second = $this->pmssWriteFile($this->root.'/name with spaces.conf', "two\n");
        @chmod($first, 0666);
        @chmod($second, 0600);

        \pmssCreateNginxConfigChmodGlob(0640, $this->root.'/*.conf');

        $this->assertEquals(0640, @fileperms($first) & 0777);
        $this->assertEquals(0640, @fileperms($second) & 0777);
    }

    public function testChmodGlobIgnoresEmptyMatches(): void
    {
        \pmssCreateNginxConfigChmodGlob(0640, $this->root.'/missing-*.conf');
        $this->assertTrue(true);
    }

    public function testChmodGlobLeavesOtherFilesUntouched(): void
    {
        $target = $this->pmssWriteFile($this->root.'/target.conf', "target\n");
        $other = $this->pmssWriteFile($this->root.'/other.txt', "other\n");
        @chmod($target, 0600);
        @chmod($other, 0666);

        \pmssCreateNginxConfigChmodGlob(0640, $this->root.'/*.conf');

        $this->assertEquals(0640, @fileperms($target) & 0777);
        $this->assertEquals(0666, @fileperms($other) & 0777);
    }

    public function testChmodGlobRefusesSymlinkTargets(): void
    {
        $target = $this->pmssWriteFile($this->root.'/real-target.txt', "target\n");
        $link = $this->root.'/linked.conf';
        @chmod($target, 0600);
        if (!@symlink($target, $link)) {
            throw new SkipTest('symlink unavailable');
        }

        \pmssCreateNginxConfigChmodGlob(0640, $this->root.'/*.conf');

        $this->assertEquals(0600, @fileperms($target) & 0777);
    }
}
