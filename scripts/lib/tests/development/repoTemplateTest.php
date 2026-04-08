<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/repositories.php';
require_once dirname(__DIR__, 2).'/update/apt.php';

class RepoTemplateTest extends TestCase
{
    private $tmpSources;

    private const TRIXIE_TEMPLATE = 'etc/seedbox/config/template.sources.trixie';

    protected function setUp(): void
    {
        $this->tmpSources = $this->pmssMakeTempFile('pmss-sources-');
        $this->pmssTrackEnvOverrides(['PMSS_APT_SOURCES_PATH' => $this->tmpSources]);
    }

    public function testRefreshRepositoriesSkipsWhenVersionUnknown(): void
    {
        [$plan, $logs] = $this->pmssArrayLoggerCapture(function (callable $logger): array {
            return pmssRepositoryUpdatePlan('debian', 0, $logger);
        });

        $this->assertEquals('reuse', $plan['mode']);
        $this->pmssAssertMessagesContain($logs, 'reusing existing sources');
    }

    public function testShippedTrixieTemplateRetainsExpectedSuitesAndComponents(): void
    {
        $path = $this->pmssRepoPath(self::TRIXIE_TEMPLATE);
        $this->assertTrue(is_file($path), 'Expected template.sources.trixie to exist');

        $data = $this->pmssReadRepoFile(self::TRIXIE_TEMPLATE);
        foreach ([
            'non-free-firmware' => 'Expected trixie repo template to include non-free-firmware',
            'trixie-security' => 'Expected trixie template to include security pocket',
            'trixie-updates' => 'Expected trixie template to include updates pocket',
            'trixie-backports' => 'Expected trixie template to include backports pocket',
            ' trixie ' => 'Expected trixie template to reference the trixie suite',
        ] as $needle => $message) {
            $this->assertTrue(strpos($data, $needle) !== false, $message);
        }
    }
}
