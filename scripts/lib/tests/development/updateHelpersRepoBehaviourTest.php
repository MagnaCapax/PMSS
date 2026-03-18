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
        \updateAptSources('arch', 1, '', [], function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        });
        $this->assertTrue((bool)array_filter($logs, static function ($m) { return strpos($m, 'Unsupported distro: arch') !== false; }));
    }

    public function testUpdateAptSourcesUbuntuLogsMessage(): void
    {
        $logs = [];
        \updateAptSources('ubuntu', 22, '', [], function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        });
        $this->assertTrue((bool)array_filter($logs, static function ($m) { return strpos($m, 'Ubuntu is not supported yet') !== false; }));
    }

    public function testUpdateAptSourcesUnsupportedVersionLogs(): void
    {
        $logs = [];
        \updateAptSources('debian', 19, '', [
            'bullseye' => '', 'buster' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
        ], function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        });
        $this->assertTrue((bool)array_filter($logs, static function ($m) { return strpos($m, 'Unsupported Debian version') !== false; }));
    }

    public function testUpdateAptSourcesBusterWritesTemplate(): void
    {
        $template = "deb http://mirror.example buster main\n";
        $this->withTempSources('initial', function (string $target) use ($template): void {
            \updateAptSources('debian', 10, sha1('initial'), [
                'buster' => $template,
                'bullseye' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
            ], function (): void {});
            $this->assertEquals($template, file_get_contents($target));
        });
    }

    public function testUpdateAptSourcesCreatesBackupOnRewrite(): void
    {
        $this->withTempSources('alpha', function (string $target): void {
            $first = "deb http://mirror.example bullseye main\n";
            $second = "deb http://mirror.example bullseye contrib\n";
            \updateAptSources('debian', 11, sha1('alpha'), [
                'bullseye' => $first,
                'buster' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
            ], function (): void {});
            \updateAptSources('debian', 11, sha1($first), [
                'bullseye' => $second,
                'buster' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
            ], function (): void {});
            $this->assertEquals($second, file_get_contents($target));
            $this->assertEquals($first, file_get_contents($target.'.pmss-backup'));
        });
    }

    public function testUpdateAptSourcesSkipsRewriteWhenHashesMatch(): void
    {
        $template = "deb http://mirror.example bullseye main\n";
        $hash = sha1($template);
        $logs = [];
        \updateAptSources('debian', 11, $hash, [
            'bullseye' => $template,
            'buster' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
        ], function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        });
        $this->assertTrue((bool)array_filter($logs, static function ($m) { return strpos($m, 'already correct') !== false; }));
    }

    public function testUpdateAptSourcesEmptyRepositoriesSkipsWrites(): void
    {
        $this->withTempSources('baseline', function (string $target): void {
            \updateAptSources('debian', 11, sha1('baseline'), [
                'bullseye' => '',
                'buster' => '', 'jessie' => '', 'bookworm' => '', 'trixie' => '',
            ], function (): void {});
            $this->assertEquals('baseline', file_get_contents($target));
        });
    }

    public function testUpdateAptSourcesLoggerReceivesMultipleEntries(): void
    {
        $template = "deb http://mirror.example trixie main\n";
        $logs = [];
        \updateAptSources('debian', 13, '', [
            'trixie' => $template,
            'bookworm' => '', 'bullseye' => '', 'buster' => '', 'jessie' => '',
        ], function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        });
        $this->assertTrue(count($logs) >= 1);
    }

    public function testSafeWriteSourcesUsesAptSourcesOverride(): void
    {
        $this->withTempSources('example', function (string $file): void {
            $this->assertTrue(\pmssSafeWriteSources('updated', 'UnitTest', null));
            $this->assertEquals('updated', (string) file_get_contents($file));
        });
    }

    public function testRepositoryUpdatePlanLoadsTemplates(): void
    {
        $template = "deb http://mirror.example bullseye main\n";
        $this->withTempConfigTemplates(['bullseye' => $template], function () use ($template): void {
            $plan = \pmssRepositoryUpdatePlan('debian', 11, function (): void {});
            $this->assertEquals('update', $plan['mode']);
            $this->assertEquals($template, $plan['templates']['bullseye']);
            $this->assertTrue(array_key_exists('buster', $plan['templates']));
        });
    }

    public function testRefreshRepositoriesAppliesTemplateForTrixie(): void
    {
        $template = "deb http://mirror.example trixie main\n";
        $this->withTempConfigTemplates(['trixie' => $template], function () use ($template): void {
            $this->withTempSources('legacy', function (string $target) use ($template): void {
                $logs = [];
                putenv('PMSS_DRY_RUN=1');
                try {
                    \pmssRefreshRepositories('debian', 13, function (string $msg) use (&$logs): void {
                        $logs[] = $msg;
                    });
                } finally {
                    putenv('PMSS_DRY_RUN');
                }
                $this->assertEquals($template, file_get_contents($target));
                $this->assertTrue((bool)array_filter($logs, static function ($m) { return strpos($m, 'Applied Debian Trixie repository config') !== false; }));
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
        $this->withTempSources('unchanged', function (string $target) use (&$logs): void {
            $plan = \pmssRepositoryUpdatePlan('debian', 0, function (string $msg) use (&$logs): void { $logs[] = $msg; });
            $this->assertEquals('unchanged', file_get_contents($target));
            $this->assertEquals('reuse', $plan['mode']);
            $this->assertTrue((bool)array_filter($logs, static function ($m) { return strpos($m, 'reusing existing sources') !== false; }));
        });
    }

    private function withTempSources(string $content, callable $callback): void
    {
        $dir = sys_get_temp_dir().'/pmss-sources-'.bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create temp directory for test');
        }

        $path = $dir.'/sources.list';
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Unable to seed temp sources file');
        }
        @chmod($path, 0600);

        $previous = getenv('PMSS_APT_SOURCES_PATH');
        putenv('PMSS_APT_SOURCES_PATH='.$path);

        try {
            $callback($path);
        } finally {
            if ($previous === false) {
                putenv('PMSS_APT_SOURCES_PATH');
            } else {
                putenv('PMSS_APT_SOURCES_PATH='.$previous);
            }
            $backup = $path.'.pmss-backup';
            if (file_exists($backup)) {
                @unlink($backup);
            }
            @unlink($path);
            @rmdir($dir);
        }
    }

    private function withTempConfigTemplates(array $templates, callable $callback): void
    {
        $dir = sys_get_temp_dir().'/pmss-config-'.bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create temp config directory for test');
        }

        foreach ($templates as $codename => $content) {
            $path = $dir."/template.sources.$codename";
            if (file_put_contents($path, rtrim($content, "\n")."\n") === false) {
                throw new \RuntimeException('Unable to seed template '.$codename);
            }
            @chmod($path, 0600);
        }

        $previous = getenv('PMSS_CONFIG_DIR');
        putenv('PMSS_CONFIG_DIR='.$dir);

        try {
            $callback($dir);
        } finally {
            if ($previous === false) {
                putenv('PMSS_CONFIG_DIR');
            } else {
                putenv('PMSS_CONFIG_DIR='.$previous);
            }
            foreach ((glob($dir.'/template.sources.*') ?: []) as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }
}
