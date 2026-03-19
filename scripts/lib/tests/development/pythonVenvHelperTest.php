<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/apps/pythonVenv.php';

class PythonVenvHelperTest extends TestCase
{
    /**
     * @param array<string, string|null> $values
     */
    private function withEnv(array $values, callable $callback): void
    {
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = getenv($key);
            if ($value === null) {
                putenv($key);
                continue;
            }

            putenv($key.'='.$value);
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === false) {
                    putenv($key);
                    continue;
                }

                putenv($key.'='.$value);
            }
        }
    }

    public function testCustomMissingPythonWarningOverridesDefaultLabelMessage(): void
    {
        $messages = [];

        $this->withEnv(['PATH' => ''], function () use (&$messages): void {
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

        $this->withEnv(['PATH' => ''], function () use (&$messages): void {
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
