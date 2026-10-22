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
        $paths = [
            'scripts',
            'etc/skel/www',
        ];
        $violations = [];

        foreach ($paths as $path) {
            foreach ($this->pmssRepoFiles($path, '/\.php$/D') as $relativePath => $file) {
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
