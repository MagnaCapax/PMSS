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
                static function (string $message) use (&$messages): void {
                    $messages[] = $message;
                },
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
                static function (string $message) use (&$messages): void {
                    $messages[] = $message;
                }
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
                static function (string $message) use (&$messages): void {
                    $messages[] = $message;
                }
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

        $this->pmssWithEnv(['PATH' => $pythonPath], function () use (&$messages, $venvDir, $cliBin, $linkPath): void {
            \pmssPythonVenvInstallCli(
                $venvDir,
                'pyLoad',
                [['Installing pyLoad (pyload-ng)', 'pyload-ng']],
                $cliBin,
                $linkPath,
                '[WARN] Skipping pyLoad setup: python3 missing from PATH',
                '[WARN] pyLoad binary missing after install',
                static function (string $message) use (&$messages): void {
                    $messages[] = $message;
                }
            );
        });

        $this->assertEquals([], $messages);
        $this->assertTrue(is_link($linkPath), 'Expected installer to create a CLI symlink');
        $this->assertEquals($cliBin, readlink($linkPath));
    }

    public function testInstallerRejectsUnsafeInstallStepsBeforeRunningCommands(): void
    {
        foreach ([
            [[['Installing bad package', 'package; rm -rf /']]],
            [[['Installing bad package', "package\nother"]]],
            [[['Installing bad package']]],
            [[[[], 'package']]],
            [[['Installing bad package', ['package']]]],
            [[['', 'package']]],
        ] as $installSteps) {
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
                static function (string $message) use (&$messages): void {
                    $messages[] = $message;
                }
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
                static function (string $message) use (&$messages): void {
                    $messages[] = $message;
                }
            );

            $this->assertEquals([$case[4]], $messages);
            $this->assertEquals([], $this->pmssProfileCommands());
        }
    }
}
