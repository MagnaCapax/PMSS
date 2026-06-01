<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/cli/optionParser.php';

class CliHelperTest extends TestCase
{
    public function testParsesLongOptionsWithValues(): void
    {
        $parsed = \pmssParseCliTokens(['script.php', '--user=alice', '--limit=42', 'extra']);
        $this->assertEquals('alice', $parsed['options']['user'] ?? '');
        $this->assertEquals('42', $parsed['options']['limit'] ?? '');
        $this->assertEquals(['extra'], $parsed['arguments']);
    }

    public function testParsesCollapsedShortFlags(): void
    {
        $parsed = \pmssParseCliTokens(['script.php', '-hv']);
        $this->assertTrue(isset($parsed['options']['h']));
        $this->assertTrue(isset($parsed['options']['v']));
    }

    public function testCliOptionHelperPrefersLongThenShort(): void
    {
        $parsed = \pmssParseCliTokens(['script.php', '--user=alice', '-l', '50']);
        $value = \pmssCliOption($parsed, 'limit', 'l');
        $this->assertEquals('50', $value);
        $fallback = \pmssCliOption($parsed, 'user', 'u');
        $this->assertEquals('alice', $fallback);
        $defaulted = \pmssCliOption($parsed, 'missing', 'm', 'fallback');
        $this->assertEquals('fallback', $defaulted);
        $parsed = \pmssParseCliTokens(['script.php', '--json=0', '-p']);
        foreach ([['json', null], ['pretty', 'p']] as $case) {
            $this->assertTrue(\pmssCliOptionPresent($parsed, $case[0], $case[1]));
        }
        $this->assertFalse(\pmssCliOptionPresent($parsed, 'json', null, true));
        $parsed = \pmssParseCliTokens(['script.php', '--runtime', '--idle-util=70', '--empty=']);
        foreach ([['runtime', 60, 60], ['idle-util', 85, 70], ['empty', 85, 0]] as $case) {
            $this->assertEquals($case[2], \pmssCliOptionInt($parsed, $case[0], null, $case[1]));
        }
        $this->assertEquals([true, ['script.php', 'alice']], \pmssCliArgvDebugSplit(['script.php', '--debug', 'alice']));
    }

    public function testSupportsLongAndShortOptionValues(): void
    {
        foreach ([['script.php', '--limit', '64'], ['script.php', '-l', '64'], ['script.php', '-l64']] as $argv) {
            $parsed = \pmssParseCliTokens($argv);
            $this->assertEquals('64', \pmssCliOption($parsed, 'limit', 'l'));
        }
    }

    public function testDoesNotConsumeFollowingOptionAsValue(): void
    {
        $parsed = \pmssParseCliTokens(['script.php', '--limit', '--json', '-l', '-v']);
        $this->assertTrue(\pmssCliOption($parsed, 'limit', 'l'));
        $this->assertTrue(\pmssCliOption($parsed, 'json'));
        $this->assertTrue(\pmssCliOption($parsed, 'v'));
    }

    public function testDeclaredValueOptionConsumesDashedToken(): void
    {
        foreach ([
            [['script.php', '--json', '-h'], ['json'], null, '-h'],
            [['script.php', '-j', '--help'], ['j'], 'j', '--help'],
        ] as $case) {
            $parsed = \pmssParseCliTokens($case[0], $case[1]);
            $this->assertEquals($case[3], \pmssCliOption($parsed, 'json', $case[2]));
            $this->assertTrue(\pmssCliOption($parsed, 'help', 'h', false) === false);
        }
    }

    public function testDeclaredValueModeKeepsUndeclaredLongOptionsBoolean(): void
    {
        $parsed = \pmssParseCliTokens(['script.php', '--dry-run', 'alice', '--target', '-literal'], ['target']);
        $this->assertTrue(\pmssCliOption($parsed, 'dry-run'));
        $this->assertEquals(['alice'], $parsed['arguments']);
        $this->assertEquals('-literal', \pmssCliOption($parsed, 'target'));
    }

    public function testTreatsBareDashAsPositionalAndIgnoresBareDoubleDash(): void
    {
        $parsed = \pmssParseCliTokens(['script.php', '-', '--', 'extra']);
        $this->assertEquals(['-', 'extra'], $parsed['arguments']);
        $this->assertEquals([], $parsed['options']);
    }

    public function testParseCliTokensOrHelpEmitsHelpAndReturnsNull(): void
    {
        list($parsed, $output) = $this->pmssCaptureStdout(static function () {
            return \pmssParseCliTokensOrHelp(['script.php', '--help'], "Usage: script.php\n");
        });
        $this->assertSame(null, $parsed);
        $this->assertEquals("Usage: script.php\n", $output);
        $this->assertSame(['options' => ['json' => true], 'arguments' => []], \pmssParseCliTokensOrHelp(['script.php', '--json'], ''));
        $this->assertStringContainsString("  --help  Show this help.\n", \pmssCliHelpUsageOptions('script.php', [], 8, [], false));
    }
}
