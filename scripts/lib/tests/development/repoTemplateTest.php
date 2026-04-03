<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/repositories.php';
require_once dirname(__DIR__, 2).'/update/apt.php';

class RepoTemplateTest extends TestCase
{
    private $tmpSources;

    protected function setUp(): void
    {
        $this->tmpSources = $this->pmssMakeTempFile('pmss-sources-');
        putenv('PMSS_APT_SOURCES_PATH='.$this->tmpSources);
    }

    protected function tearDown(): void
    {
        putenv('PMSS_APT_SOURCES_PATH');
    }

    public function testRefreshRepositoriesSkipsWhenVersionUnknown(): void
    {
        $logs = [];
        $plan = pmssRepositoryUpdatePlan('debian', 0, function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        });
        $this->assertEquals('reuse', $plan['mode']);
        $this->assertTrue((bool)array_filter($logs, static function ($m) { return strpos($m, 'reusing existing sources') !== false; }));
    }

    public function testShippedTrixieTemplateIncludesNonFreeFirmware(): void
    {
        $path = $this->pmssRepoPath('etc/seedbox/config/template.sources.trixie');
        $this->assertTrue(is_file($path), 'Expected template.sources.trixie to exist');

        $data = $this->pmssReadRepoFile('etc/seedbox/config/template.sources.trixie');
        $this->assertTrue(strpos($data, 'non-free-firmware') !== false, 'Expected trixie repo template to include non-free-firmware');
    }

    public function testShippedTrixieTemplateIncludesSecurityUpdatesBackports(): void
    {
        $data = $this->pmssReadRepoFile('etc/seedbox/config/template.sources.trixie');
        $this->assertTrue(strpos($data, 'trixie-security') !== false, 'Expected trixie template to include security pocket');
        $this->assertTrue(strpos($data, 'trixie-updates') !== false, 'Expected trixie template to include updates pocket');
        $this->assertTrue(strpos($data, 'trixie-backports') !== false, 'Expected trixie template to include backports pocket');
    }

    public function testShippedTrixieTemplateUsesSuiteName(): void
    {
        $data = $this->pmssReadRepoFile('etc/seedbox/config/template.sources.trixie');
        $this->assertTrue(strpos($data, ' trixie ') !== false, 'Expected trixie template to reference the trixie suite');
    }
}
