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
    }

    public function testSupportsSpaceSeparatedLongValues(): void
    {
        $parsed = \pmssParseCliTokens(['script.php', '--limit', '64']);
        $this->assertEquals('64', \pmssCliOption($parsed, 'limit', 'l'));
    }

    public function testSupportsSpaceSeparatedShortValues(): void
    {
        $parsed = \pmssParseCliTokens(['script.php', '-l', '64']);
        $this->assertEquals('64', \pmssCliOption($parsed, 'limit', 'l'));
    }

    public function testSupportsShortOptionWithAttachedValue(): void
    {
        $parsed = \pmssParseCliTokens(['script.php', '-l64']);
        $this->assertEquals('64', \pmssCliOption($parsed, 'limit', 'l'));
    }

    public function testDoesNotConsumeFollowingOptionAsValue(): void
    {
        $parsed = \pmssParseCliTokens(['script.php', '--limit', '--json', '-l', '-v']);
        $this->assertTrue(\pmssCliOption($parsed, 'limit', 'l'));
        $this->assertTrue(\pmssCliOption($parsed, 'json'));
        $this->assertTrue(\pmssCliOption($parsed, 'v'));
    }

    public function testDeclaredLongValueOptionConsumesDashedToken(): void
    {
        $parsed = \pmssParseCliTokens(['script.php', '--json', '-h'], ['json']);
        $this->assertEquals('-h', \pmssCliOption($parsed, 'json'));
        $this->assertTrue(\pmssCliOption($parsed, 'help', 'h', false) === false);
    }

    public function testDeclaredShortValueOptionConsumesDashedToken(): void
    {
        $parsed = \pmssParseCliTokens(['script.php', '-j', '--help'], ['j']);
        $this->assertEquals('--help', \pmssCliOption($parsed, 'json', 'j'));
        $this->assertTrue(\pmssCliOption($parsed, 'help', 'h', false) === false);
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
}
