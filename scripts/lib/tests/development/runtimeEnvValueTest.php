<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime.php';

class RuntimeEnvValueTest extends TestCase
{
    public function testTextLinesPreserveRawFieldsAndBoundedRemainder(): void
    {
        foreach ([['', [''], -1], ["a\r\nb\nc", ['a', 'b', 'c'], -1], ["a\n\n", ['a', '', ''], -1], [" \t#0\n0\na\rb\0", [" \t#0", '0', "a\rb\0"], -1], ["cpu 1\r\ncpu0 2\r\ncpu1 3\n", ['cpu 1', "cpu0 2\r\ncpu1 3\n"], 2]] as [$raw, $expected, $limit]) { $this->assertSame($expected, \pmssTextLines($raw, $limit)); }
    }

    public function testUnsignedDecimalIntParserKeepsExactBoundaries(): void
    {
        foreach (['0' => 0, '000' => 0, '0012' => 12, (string) PHP_INT_MAX => PHP_INT_MAX, '000'.PHP_INT_MAX => PHP_INT_MAX] as $raw => $expected) {
            $this->assertSame($expected, \pmssUnsignedDecimalIntParse((string) $raw));
        }
        foreach (['', ' ', ' 12', '12 ', '-1', '+1', '1.0', '1e3', "1\0", "1\n", PHP_INT_MAX.'0', '000'.PHP_INT_MAX.'0'] as $raw) {
            $this->assertSame(null, \pmssUnsignedDecimalIntParse($raw));
        }
    }

    public function testDigitOverridesPreserveRawValidationAndIntegerCast(): void
    {
        foreach ([[null, null], ['', null], ['0', 0], ['0012', 12], [' 12', null], ['12 ', null], ['-1', null], ['+1', null], ['1.5', null], ['1e3', null], ["12\n", null], [str_repeat('9', 30), PHP_INT_MAX]] as [$value, $expected]) {
            $this->pmssWithEnv(['PMSS_COMMAND_TIMEOUT' => $value], function () use ($expected): void {
                $this->assertSame($expected, \pmssEnvReadDigits('PMSS_COMMAND_TIMEOUT'));
                $this->assertSame($expected ?? PMSS_COMMAND_TIMEOUT_DEFAULT, \pmssCommandTimeoutSeconds('true'));
            });
        }
    }
    public function testTrimmedOverridesPreserveZeroAndDefaultWhitespace(): void
    {
        foreach ([[null, ' default '], ['', ' default '], [" \t\r\n", ' default '], ['0', '0'], [' /path/ ', '/path/'], ["a\nb", "a\nb"]] as [$value, $expected]) {
            $this->pmssAssertEnvResolvedPath('PMSS_TEST_TRIMMED_ENV', $value, $expected, static function (): string { return \pmssEnvTrimmed('PMSS_TEST_TRIMMED_ENV', ' default '); });
        }
    }

    public function testConfigColumnsPreserveBoundedRemainders(): void
    {
        $this->assertSame(['42', 'dockerd --label a  b'], \pmssConfigLineColumns(" \t42\tdockerd --label a  b\n", 2, [], 2));
        $this->assertSame(['rss', "123\textra"], \pmssConfigLineColumns("rss 123\textra", 2, [], 2));
        $this->assertSame([], \pmssConfigLineColumns('rss', 2, [], 2));
        $this->assertSame([], \pmssConfigLineColumns('# comment', 0, ['#'], 2));
        $this->assertSame(['#', 'kept as data'], \pmssConfigLineColumns('# kept as data', 2, [], 2));
    }

    public function testConfigRowsPreserveKeysAndColumnPolicies(): void
    {
        $this->assertSame([], iterator_to_array(\pmssConfigRows(['', ' ', '# comment', 'short'], 2)));
        $this->assertSame([7 => ['a', 'b'], 12 => ['c', 'd']], iterator_to_array(\pmssConfigRows([3 => '', 7 => " a\tb\r\n", 9 => 'short', 12 => 'c d'], 2)));
        $this->assertSame(['row' => ['rss', "123\textra"]], iterator_to_array(\pmssConfigRows(['row' => "rss 123\textra"], 2, [], 2)));
        $this->assertSame([1 => ['#', 'data']], iterator_to_array(\pmssConfigRows(['#123', '# data'], 2, [])));
        $this->assertSame([2 => ['0']], iterator_to_array(\pmssConfigRows(['; comment', '# comment', '0'], 1, [';', '#'])));
        $this->assertSame([2 => ['0'], 3 => ['42'], 4 => ['1']], iterator_to_array(\pmssConfigRows([null, false, 0, 42, true], 1, [])));
        $this->assertSame(['kept' => ['#', 'data  # inline']], iterator_to_array(\pmssConfigRows(['skip' => ' ; comment ', 'kept' => " \t# data  # inline\r\n"], 2, [';'], 2)));
    }

    public function testConfigLinesPreserveKeysTextAndCommentPolicies(): void
    {
        foreach ([
            [[], ['#'], []],
            [['', " \t\r\n\0", ' # comment'], ['#'], []],
            [[3 => '', 7 => " a\tb  \r\n", 12 => '0'], ['#'], [7 => "a\tb", 12 => '0']],
            [['hash' => ' #0 stack trace ', 'row' => ' a  b # inline '], [], ['hash' => '#0 stack trace', 'row' => 'a  b # inline']],
            [['; comment', '// comment', '# data', 'value'], [';', '//', ''], [2 => '# data', 3 => 'value']],
            [[null, false, 0, 42, true], [], [2 => '0', 3 => '42', 4 => '1']],
        ] as [$lines, $prefixes, $expected]) {
            $this->assertSame($expected, iterator_to_array(\pmssConfigLines($lines, $prefixes)));
        }
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
