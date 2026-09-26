<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/apps/pythonVenv.php';

class PythonVenvHelperTest extends TestCase
{
    private function makePythonPath(): string
    {
        $stubPath = $this->pmssMakeExecutableStub(
            'python3',
            "#!/bin/sh\nexit 0\n",
            'pmss-python-path-'
        );

        $systemPath = getenv('PATH');
        if (!is_string($systemPath) || $systemPath === '') {
            return $stubPath;
        }

        return $stubPath.':'.$systemPath;
    }

    private function makeVenvPython(string $venvDir): void
    {
        $this->pmssWriteExecutableFiles($venvDir.'/bin', ['python' => "#!/bin/sh\nexit 0\n"]);
    }

    public function testCustomMissingPythonWarningOverridesDefaultLabelMessage(): void
    {
        $messages = [];

        $this->pmssWithEnv(['PATH' => ''], function () use (&$messages): void {
            $result = \pmssPythonVenvEnsure(
                '/tmp/pmss-python-venv-test-missing',
                'FlexGet',
                $this->pmssMakeArrayLogger($messages),
                '[WARN] Skipping FlexGet install: python3 missing from PATH'
            );

            $this->assertEquals('', $result);
        });

        $this->assertEquals(['[WARN] Skipping FlexGet install: python3 missing from PATH'], $messages);
    }

    public function testDefaultMissingPythonWarningStillUsesLabel(): void
    {
        $messages = [];

        $this->pmssWithEnv(['PATH' => ''], function () use (&$messages): void {
            $result = \pmssPythonVenvEnsure(
                '/tmp/pmss-python-venv-test-default',
                'pyLoad',
                $this->pmssMakeArrayLogger($messages)
            );

            $this->assertEquals('', $result);
        });

        $this->assertEquals(['[WARN] Skipping pyLoad setup: python3 missing'], $messages);
    }

    public function testInstallerLogsMissingCliWhenPackagesFinishWithoutBinary(): void
    {
        $messages = [];
        $venvDir = $this->pmssMakeTempDir('pmss-python-venv-missing-cli-');
        $this->makeVenvPython($venvDir);
        $pythonPath = $this->makePythonPath();
        $linkPath = $this->pmssMakeTempFile('pmss-flexget-link-');

        $this->pmssWithEnv(['PATH' => $pythonPath], function () use (&$messages, $venvDir, $linkPath): void {
            \pmssPythonVenvInstallCli(
                $venvDir,
                'FlexGet',
                [['Installing FlexGet', 'flexget']],
                $venvDir.'/bin/flexget',
                $linkPath,
                '[WARN] Skipping FlexGet install: python3 missing from PATH',
                '[WARN] FlexGet binary missing after install',
                $this->pmssMakeArrayLogger($messages)
            );
        });

        $this->assertEquals(['[WARN] FlexGet binary missing after install'], $messages);
    }

    public function testInstallerLinksCliWhenBinaryExists(): void
    {
        $messages = [];
        $venvDir = $this->pmssMakeTempDir('pmss-python-venv-link-cli-');
        $this->makeVenvPython($venvDir);
        $pythonPath = $this->makePythonPath();
        $cliBin = $venvDir.'/bin/pyload';
        $linkPath = $this->pmssMakeTempDir('pmss-python-link-dir-').'/pyload';
        $this->pmssWriteExecutableFile($cliBin, "#!/bin/sh\nexit 0\n");
        $this->pmssResetRuntimeProfile();

        $this->pmssWithEnv(['PATH' => $pythonPath], function () use (&$messages, $venvDir, $cliBin, $linkPath): void {
            \pmssPythonVenvInstallCli(
                $venvDir,
                'pyLoad',
                [['  Installing pyLoad (pyload-ng)  ', '  pyload-ng  ']],
                $cliBin,
                $linkPath,
                '[WARN] Skipping pyLoad setup: python3 missing from PATH',
                '[WARN] pyLoad binary missing after install',
                $this->pmssMakeArrayLogger($messages)
            );
        });

        $this->assertEquals([], $messages);
        $this->assertTrue(is_link($linkPath), 'Expected installer to create a CLI symlink');
        $this->assertEquals($cliBin, readlink($linkPath));
        $this->assertSame(
            \pmssBuildCommand($venvDir.'/bin/python', ['-m', 'pip', 'install', '--upgrade']).' pyload-ng',
            $this->pmssFindProfileCommand('Installing pyLoad (pyload-ng)')
        );
    }

    public function testFailedPackageInstallDoesNotPublishExistingCli(): void
    {
        $messages = [];
        $venvDir = $this->pmssMakeTempDir('pmss-python-venv-failed-install-');
        $this->pmssWriteExecutableFiles($venvDir.'/bin', [
            'python' => "#!/bin/sh\nif [ \"\$5\" = bad-package ]; then exit 23; fi\nexit 0\n",
            'pyload' => "#!/bin/sh\nexit 0\n",
        ]);
        $linkPath = $this->pmssMakeTempDir('pmss-python-failed-link-').'/pyload';
        $pythonPath = $this->makePythonPath();
        $this->pmssResetRuntimeProfile();

        $this->pmssWithEnv(['PATH' => $pythonPath], function () use (&$messages, $venvDir, $linkPath): void {
            \pmssPythonVenvInstallCli(
                $venvDir,
                'pyLoad',
                [['Installing first package', 'bad-package'], ['Installing second package', 'other-package']],
                $venvDir.'/bin/pyload',
                $linkPath,
                '[WARN] pyLoad setup: python3 missing',
                '[WARN] pyLoad binary missing after install',
                $this->pmssMakeArrayLogger($messages)
            );
        });

        $this->assertEquals(['[WARN] pyLoad package install failed; leaving CLI link unchanged'], $messages);
        $this->assertTrue(!file_exists($linkPath) && !is_link($linkPath));
        $this->assertTrue($this->pmssFindProfileCommand('Installing first package') !== null);
        $this->assertEquals(null, $this->pmssFindProfileCommand('Installing second package'));
    }

    public function testFailedToolingUpgradeDoesNotPublishExistingCli(): void
    {
        $messages = [];
        $venvDir = $this->pmssMakeTempDir('pmss-python-venv-failed-tooling-');
        $this->pmssWriteExecutableFiles($venvDir.'/bin', [
            'python' => "#!/bin/sh\nif [ \"\$5\" = pip ]; then exit 23; fi\nexit 0\n",
            'pyload' => "#!/bin/sh\nexit 0\n",
        ]);
        $linkPath = $this->pmssMakeTempDir('pmss-python-tooling-link-').'/pyload';
        $pythonPath = $this->makePythonPath();
        $this->pmssResetRuntimeProfile();

        $this->pmssWithEnv(['PATH' => $pythonPath], function () use (&$messages, $venvDir, $linkPath): void {
            \pmssPythonVenvInstallCli(
                $venvDir,
                'pyLoad',
                [['Installing pyLoad', 'pyload-ng']],
                $venvDir.'/bin/pyload',
                $linkPath,
                '[WARN] pyLoad setup: python3 missing',
                '[WARN] pyLoad binary missing after install',
                $this->pmssMakeArrayLogger($messages)
            );
        });

        $this->assertEquals(['[WARN] pyLoad virtualenv tooling upgrade failed; skipping package install'], $messages);
        $this->assertTrue(!file_exists($linkPath) && !is_link($linkPath));
        $this->assertTrue($this->pmssFindProfileCommand('Upgrading pyLoad virtualenv tooling') !== null);
        $this->assertEquals(null, $this->pmssFindProfileCommand('Installing pyLoad'));
    }

    public function testInstallerRejectsUnsafeInstallStepsBeforeRunningCommands(): void
    {
        $cases = [
            [['Installing bad package', 'package; rm -rf /']],
            [['Installing bad package', "package\nother"]],
            [['Installing bad package']],
            [[[], 'package']],
            [['Installing bad package', ['package']]],
            [['', 'package']],
        ];
        // Exercise both fields before, inside, and after text, including bytes trim removes.
        foreach (["\0", "\t", "\n", "\r", "\x0B", "\x1F", "\x7F"] as $byte) {
            foreach ([0, 1] as $field) {
                foreach ([$byte.'package', 'pack'.$byte.'age', 'package'.$byte] as $value) {
                    $step = ['Installing package', 'package'];
                    $step[$field] = $value;
                    $cases[] = [['Installing valid package', 'package'], $step];
                }
            }
        }
        foreach ($cases as $installSteps) {
            $messages = [];
            $this->pmssResetRuntimeProfile();

            \pmssPythonVenvInstallCli(
                '/tmp/pmss-python-venv-invalid-step',
                'FlexGet',
                $installSteps,
                '/tmp/pmss-python-venv-invalid-step/bin/flexget',
                '/tmp/pmss-python-venv-invalid-link',
                '[WARN] Skipping FlexGet install: python3 missing from PATH',
                '[WARN] FlexGet binary missing after install',
                $this->pmssMakeArrayLogger($messages)
            );

            $this->assertEquals(['[WARN] Skipping FlexGet install: unsafe install step'], $messages);
            $this->assertEquals([], $this->pmssProfileCommands());
        }
    }

    public function testInstallerRejectsUnsafePathInputsBeforeRunningCommands(): void
    {
        foreach ([
            ['', 'FlexGet', '/tmp/cli', '/tmp/link', '[WARN] Skipping FlexGet virtualenv setup: unsafe venv path'],
            ["/tmp/venv\0bad", 'FlexGet', '/tmp/cli', '/tmp/link', '[WARN] Skipping FlexGet virtualenv setup: unsafe venv path'],
            ['/tmp/venv', 'FlexGet', '', '/tmp/link', '[WARN] Skipping FlexGet install: unsafe CLI path'],
            ['/tmp/venv', 'FlexGet', "/tmp/cli\0bad", '/tmp/link', '[WARN] Skipping FlexGet install: unsafe CLI path'],
            ['/tmp/venv', 'FlexGet', '/tmp/cli', '', '[WARN] Skipping FlexGet install: unsafe CLI path'],
            ['/tmp/venv', "Flex\nGet", '/tmp/cli', '/tmp/link', '[WARN] Skipping Python virtualenv setup: unsafe label'],
        ] as $case) {
            $messages = [];
            $this->pmssResetRuntimeProfile();

            \pmssPythonVenvInstallCli(
                $case[0],
                $case[1],
                [['Installing FlexGet', 'flexget']],
                $case[2],
                $case[3],
                '[WARN] Skipping FlexGet install: python3 missing from PATH',
                '[WARN] FlexGet binary missing after install',
                $this->pmssMakeArrayLogger($messages)
            );

            $this->assertEquals([$case[4]], $messages);
            $this->assertEquals([], $this->pmssProfileCommands());
        }
    }
}
