<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/homeMarkerRegistry.php';

class HomeMarkerValueTest extends TestCase
{
    public function testMarkerValuesFitPhpIntegerWithoutSaturation(): void
    {
        foreach ([
            '0' => 0,
            '000' => 0,
            '1' => 1,
            '00042' => 42,
            (string) PHP_INT_MAX => PHP_INT_MAX,
            '000'.PHP_INT_MAX => PHP_INT_MAX,
        ] as $raw => $expected) {
            $this->assertSame($expected, \pmssHomeMarkerValueParse((string) $raw), (string) $raw);
        }

        foreach ([
            '', '-1', '+1', '1.0', ' 1', '1 ', "1\n", "1\0", '12x',
            str_repeat('9', 80), '000'.PHP_INT_MAX.'0',
        ] as $raw) {
            $this->assertSame(null, \pmssHomeMarkerValueParse($raw), $raw);
        }
    }
}
