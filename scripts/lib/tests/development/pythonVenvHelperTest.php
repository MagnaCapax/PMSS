<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/apps/pythonVenv.php';

class PythonVenvHelperTest extends TestCase
{
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

            $this->assertEquals([], $result);
        });

        $this->assertEquals(['[WARN] Skipping FlexGet install: python3 missing from PATH'], $messages);
    }

    public function testDefaultMissingPythonWarningStillUsesLabel(): void
    {
        $messages = [];

        $this->pmssWithEnv(['PATH' => ''], function () use (&$messages): void {
            $result = \pmssPythonVenvEnsure(
                '/tmp/pmss-python-venv-test-default',
                'acd_cli',
                static function (string $message) use (&$messages): void {
                    $messages[] = $message;
                }
            );

            $this->assertEquals([], $result);
        });

        $this->assertEquals(['[WARN] Skipping acd_cli setup: python3 missing'], $messages);
    }
}
