<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime.php';

class RuntimeEnvValueTest extends TestCase
{
    public function testConfigColumnsPreserveBoundedRemainders(): void
    {
        $this->assertSame(['42', 'dockerd --label a  b'], \pmssConfigLineColumns(" \t42\tdockerd --label a  b\n", 2, [], 2));
        $this->assertSame(['rss', "123\textra"], \pmssConfigLineColumns("rss 123\textra", 2, [], 2));
        $this->assertSame([], \pmssConfigLineColumns('rss', 2, [], 2));
        $this->assertSame([], \pmssConfigLineColumns('# comment', 0, ['#'], 2));
        $this->assertSame(['#', 'kept as data'], \pmssConfigLineColumns('# kept as data', 2, [], 2));
    }

    public function testNormalizationCharacterizationMatrix(): void
    {
        $cases = [
            [false, ''],
            ['', ''],
            ['  TRUE ', 'true'],
            [' On ', 'on'],
            [' no ', 'no'],
        ];

        foreach ($cases as $case) {
            list($input, $expected) = $case;
            $this->assertEquals($expected, \pmssEnvValueNormalized($input));
        }
    }

    public function testFalseyCharacterizationMatrix(): void
    {
        foreach ([false, '', '0', 'FALSE', ' no '] as $input) {
            $this->assertTrue(\pmssEnvValueIsFalsey($input), 'expected falsey for '.var_export($input, true));
        }
    }

    public function testTruthyCharacterizationMatrix(): void
    {
        foreach (['1', 'true', 'TRUE', 'yes', 'on'] as $input) {
            $this->assertTrue(\pmssEnvValueIsTruthy($input), 'expected truthy for '.var_export($input, true));
        }
    }
}
