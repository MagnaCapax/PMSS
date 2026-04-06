<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';
require_once dirname(__DIR__, 2).'/update/apt.php';
require_once dirname(__DIR__, 2).'/update/repositories.php';
require_once dirname(__DIR__, 2).'/update/runtime/commands.php';

class UpdateHelpersRepoBehaviourTest extends TestCase
{
    public function testUpdateAptSourcesUnsupportedDistroLogsMessage(): void
    {
        $logs = [];
        \updateAptSources('arch', 1, '', [], $this->pmssMakeArrayLogger($logs));
        $this->pmssAssertMessagesContain($logs, 'Unsupported distro: arch');
    }

    public function testUpdateAptSourcesUbuntuLogsMessage(): void
    {
        $logs = [];
        \updateAptSources('ubuntu', 22, '', [], $this->pmssMakeArrayLogger($logs));
        $this->pmssAssertMessagesContain($logs, 'Ubuntu is not supported yet');
    }

    public function testUpdateAptSourcesUnsupportedVersionLogs(): void
    {
        $logs = [];
        \updateAptSources('debian', 19, '', $this->pmssDebianRepoTemplates(), $this->pmssMakeArrayLogger($logs));
        $this->pmssAssertMessagesContain($logs, 'Unsupported Debian version');
    }

    public function testUpdateAptSourcesBusterWritesTemplate(): void
    {
        $template = "deb http://mirror.example buster main\n";
        $this->pmssWithTempAptSources('initial', function (string $target) use ($template): void {
            \updateAptSources('debian', 10, sha1('initial'), $this->pmssDebianRepoTemplates([
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
            \updateAptSources('debian', 11, sha1('alpha'), $this->pmssDebianRepoTemplates([
                'bullseye' => $first,
            ]), function (): void {});
            \updateAptSources('debian', 11, sha1($first), $this->pmssDebianRepoTemplates([
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
        \updateAptSources('debian', 11, $hash, $this->pmssDebianRepoTemplates([
            'bullseye' => $template,
        ]), $this->pmssMakeArrayLogger($logs));
        $this->pmssAssertMessagesContain($logs, 'already correct');
    }

    public function testUpdateAptSourcesEmptyRepositoriesSkipsWrites(): void
    {
        $this->pmssWithTempAptSources('baseline', function (string $target): void {
            \updateAptSources('debian', 11, sha1('baseline'), $this->pmssDebianRepoTemplates(), function (): void {});
            $this->assertEquals('baseline', file_get_contents($target));
        });
    }

    public function testUpdateAptSourcesLoggerReceivesMultipleEntries(): void
    {
        $template = "deb http://mirror.example trixie main\n";
        $logs = [];
        \updateAptSources('debian', 13, '', $this->pmssDebianRepoTemplates([
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
                $logs = [];
                $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function () use (&$logs): void {
                    \pmssRefreshRepositories('debian', 13, $this->pmssMakeArrayLogger($logs));
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
        $this->assertEquals('install -y', substr($cmd, -strlen('install -y')));
    }

    public function testRefreshRepositoriesSkipsWhenVersionUnknown(): void
    {
        $logs = [];
        $this->pmssWithTempAptSources('unchanged', function (string $target) use (&$logs): void {
            $plan = \pmssRepositoryUpdatePlan('debian', 0, function (string $msg) use (&$logs): void { $logs[] = $msg; });
            $this->assertEquals('unchanged', file_get_contents($target));
            $this->assertEquals('reuse', $plan['mode']);
            $this->pmssAssertMessagesContain($logs, 'reusing existing sources');
        });
    }
}
