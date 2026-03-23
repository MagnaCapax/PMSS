<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime.php';

class RuntimeEnvValueTest extends TestCase
{
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
