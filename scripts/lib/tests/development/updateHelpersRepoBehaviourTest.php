<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/updateBootstrapShim.php';
require_once dirname(__DIR__, 2).'/update/apt.php';
require_once dirname(__DIR__, 2).'/update/repositories.php';
require_once dirname(__DIR__, 2).'/update/runtime/commands.php';

class UpdateHelpersRepoBehaviourTest extends TestCase
{
    public function testUpdateAptSourcesLogsUnsupportedInputs(): void
    {
        foreach ([
            ['arch', 1, [], 'Unsupported distro: arch'],
            ['ubuntu', 22, [], 'Ubuntu is not supported yet'],
            ['debian', 19, $this->pmssDebianRepoTemplates(), 'Unsupported Debian version'],
        ] as [$name, $version, $repos, $message]) {
            $logs = [];
            \pmssUpdateAptSources($name, $version, '', $repos, $this->pmssMakeArrayLogger($logs));
            $this->pmssAssertMessagesContain($logs, $message);
        }
    }

    public function testUpdateAptSourcesBusterWritesTemplate(): void
    {
        $template = "deb http://mirror.example buster main\n";
        $this->pmssWithTempAptSources('initial', function (string $target) use ($template): void {
            \pmssUpdateAptSources('debian', 10, sha1('initial'), $this->pmssDebianRepoTemplates([
                'buster' => $template,
            ]), function (): void {});
            $this->assertEquals($template, file_get_contents($target));
        });
    }

    public function testUpdateAptSourcesCreatesBackupOnRewrite(): void
    {
        $this->pmssWithTempAptSources('alpha', function (string $target): void {
            $first = "deb http://mirror.example bullseye main\n";
            $second = "deb http://mirror.example bullseye contrib\n";
            \pmssUpdateAptSources('debian', 11, sha1('alpha'), $this->pmssDebianRepoTemplates([
                'bullseye' => $first,
            ]), function (): void {});
            \pmssUpdateAptSources('debian', 11, sha1($first), $this->pmssDebianRepoTemplates([
                'bullseye' => $second,
            ]), function (): void {});
            $this->assertEquals($second, file_get_contents($target));
            $this->assertEquals($first, file_get_contents($target.'.pmss-backup'));
        });
    }

    public function testUpdateAptSourcesSkipsRewriteWhenHashesMatch(): void
    {
        $template = "deb http://mirror.example bullseye main\n";
        $hash = sha1($template);
        $logs = [];
        \pmssUpdateAptSources('debian', 11, $hash, $this->pmssDebianRepoTemplates([
            'bullseye' => $template,
        ]), $this->pmssMakeArrayLogger($logs));
        $this->pmssAssertMessagesContain($logs, 'already correct');
    }

    public function testUpdateAptSourcesEmptyRepositoriesSkipsWrites(): void
    {
        $this->pmssWithTempAptSources('baseline', function (string $target): void {
            \pmssUpdateAptSources('debian', 11, sha1('baseline'), $this->pmssDebianRepoTemplates(), function (): void {});
            $this->assertEquals('baseline', file_get_contents($target));
        });
    }

    public function testUpdateAptSourcesLoggerReceivesMultipleEntries(): void
    {
        $template = "deb http://mirror.example trixie main\n";
        $logs = [];
        \pmssUpdateAptSources('debian', 13, '', $this->pmssDebianRepoTemplates([
            'trixie' => $template,
        ]), $this->pmssMakeArrayLogger($logs));
        $this->assertTrue(count($logs) >= 1);
    }

    public function testSafeWriteSourcesUsesAptSourcesOverride(): void
    {
        $this->pmssWithTempAptSources('example', function (string $file): void {
            $this->assertTrue(\pmssSafeWriteSources('updated', 'UnitTest', null));
            $this->assertEquals('updated', (string) file_get_contents($file));
        });
    }

    public function testRepositoryUpdatePlanLoadsTemplates(): void
    {
        $template = "deb http://mirror.example bullseye main\n";
        $this->pmssWithRepoTemplates(['bullseye' => $template], function () use ($template): void {
            $plan = \pmssRepositoryUpdatePlan('debian', 11, function (): void {});
            $this->assertEquals('update', $plan['mode']);
            $this->assertEquals($template, $plan['templates']['bullseye']);
            $this->assertTrue(array_key_exists('buster', $plan['templates']));
        });
    }

    public function testRefreshRepositoriesAppliesTemplateForTrixie(): void
    {
        $template = "deb http://mirror.example trixie main\n";
        $this->pmssWithRepoTemplates(['trixie' => $template], function () use ($template): void {
            $this->pmssWithTempAptSources('legacy', function (string $target) use ($template): void {
                [, $logs] = $this->pmssArrayLoggerCapture(function (callable $logger): void {
                    $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function () use ($logger): void {
                        \pmssRefreshRepositories('debian', 13, $logger);
                    });
                });
                $this->assertEquals($template, file_get_contents($target));
                $this->pmssAssertMessagesContain($logs, 'Applied Debian Trixie repository config');
            });
        });
    }

    public function testAptCmdPrefixesArguments(): void
    {
        $cmd = \aptCmd('install -y');
        $this->assertTrue(strpos($cmd, 'apt-get') !== false);
        $this->assertStringContainsAllStrings(['DEBIAN_FRONTEND=noninteractive', 'APT_LISTCHANGES_FRONTEND=none', 'UCF_FORCE_CONFOLD=1', 'NEEDRESTART_MODE=a'], $cmd);
        $this->assertEquals('install -y', substr($cmd, -strlen('install -y')));
    }

    public function testRefreshRepositoriesSkipsWhenVersionUnknown(): void
    {
        $this->pmssWithTempAptSources('unchanged', function (string $target): void {
            [$plan, $logs] = $this->pmssArrayLoggerCapture(function (callable $logger): array {
                return \pmssRepositoryUpdatePlan('debian', 0, $logger);
            });
            $this->assertEquals('unchanged', file_get_contents($target));
            $this->assertEquals('reuse', $plan['mode']);
            $this->pmssAssertMessagesContain($logs, 'reusing existing sources');
        });
    }
}
