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

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('root', 'pmss-nginx-chmod-');
    }

    public function testChmodGlobAppliesModeToMatchedFiles(): void
    {
        $first = $this->root.'/plain.conf';
        $second = $this->root.'/name with spaces.conf';
        file_put_contents($first, "one\n");
        file_put_contents($second, "two\n");
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
        $target = $this->root.'/target.conf';
        $other = $this->root.'/other.txt';
        file_put_contents($target, "target\n");
        file_put_contents($other, "other\n");
        @chmod($target, 0600);
        @chmod($other, 0666);

        \pmssCreateNginxConfigChmodGlob(0640, $this->root.'/*.conf');

        $this->assertEquals(0640, @fileperms($target) & 0777);
        $this->assertEquals(0666, @fileperms($other) & 0777);
    }
}
