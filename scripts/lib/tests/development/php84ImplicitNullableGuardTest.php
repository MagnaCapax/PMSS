<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Guard first-party runtime code against PHP 8.4 implicit-nullable params.
 */
class Php84ImplicitNullableGuardTest extends TestCase
{
    public function testScriptsDoNotUseImplicitNullableParameterTypes(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $paths = [
            $repoRoot.'/scripts',
            $repoRoot.'/etc/skel/www',
        ];
        $violations = [];

        foreach ($paths as $path) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || substr($file->getFilename(), -4) !== '.php') {
                    continue;
                }

                $relativePath = substr($file->getPathname(), strlen($repoRoot) + 1);
                if (strpos($relativePath, 'scripts/lib/devristo/') === 0 || strpos($relativePath, 'scripts/lib/tests/') === 0) {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                if (preg_match('/function[^\n]*\((?:(?!\?)(?:array|callable|bool|int|float|string|iterable|object|self|parent|[A-Za-z_\\\\][A-Za-z0-9_\\\\]*))\s+\$[A-Za-z_][A-Za-z0-9_]*\s*=\s*null/', $source) === 1) {
                    $violations[] = $relativePath;
                }
            }
        }

        $this->assertEquals([], $violations, 'Implicit nullable parameter types are deprecated in PHP 8.4.');
    }
}
