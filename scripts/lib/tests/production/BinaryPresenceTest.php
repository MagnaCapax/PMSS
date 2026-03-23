<?php
namespace PMSS\Tests\Production;

use PMSS\Tests\TestCase;

require_once __DIR__.'/../common/TestCase.php';

class BinaryPresenceTest extends TestCase
{
    private array $binaries = [
        'flexget' => '/usr/local/bin/flexget',
        'pyload'  => '/usr/local/bin/pyload',
    ];

    public function testSymlinksExist(): void
    {
        foreach ($this->binaries as $label => $path) {
            $this->assertTrue(is_link($path) || is_file($path), sprintf('%s binary missing at %s', $label, $path));
        }
    }

    public function testBinariesAreExecutable(): void
    {
        foreach ($this->binaries as $label => $path) {
            $this->assertTrue(is_executable($path), sprintf('%s binary is not executable (%s)', $label, $path));
        }
    }
}
