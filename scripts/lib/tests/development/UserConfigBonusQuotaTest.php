<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/bonusQuota.php';
require_once dirname(__DIR__, 2).'/user/userConfigCli.php';
require_once dirname(__DIR__, 2).'/cli/optionParser.php';

/** Additional-disk reconfigure contracts; execution belongs on the testing machine. */
class UserConfigBonusQuotaTest extends TestCase
{
    public function testSharedParserPreservesStrictIntegerBoundaries(): void
    {
        foreach ([null, '0', '25', (string) PHP_INT_MAX] as $raw) {
            $this->assertSame($raw === null ? null : (int) $raw, \pmssAddUserBonusQuotaGiBParse($raw));
        }
        foreach ([true, false, 25, 1.5, [], '', '-1', '+1', '01', '1.5', '1e3', ' 1', "1\n", "1\0", (string) PHP_INT_MAX.'0'] as $raw) {
            $this->assertThrows(\InvalidArgumentException::class, static function () use ($raw): void {
                \pmssAddUserBonusQuotaGiBParse($raw);
            });
        }
    }

    public function testBonusOptionSupportsBothCliValueForms(): void
    {
        foreach ([['--bonus-quota-gib=25'], ['--bonus-quota-gib', '25']] as $option) {
            $parsed = \pmssParseCliTokens(array_merge(['userConfig.php', 'fixtur01', '1024', '200'], $option), ['bonus-quota-gib']);
            $this->assertSame(['fixtur01', '1024', '200'], $parsed['arguments']);
            $this->assertSame(25, \pmssAddUserBonusQuotaGiBParse(\pmssCliOption($parsed, 'bonus-quota-gib')));
        }
    }

    public function testMalformedOptionFailsBeforeAccountLookup(): void
    {
        // No account or service is needed: validation must stop before lookup or mutation.
        foreach ([['--bonus-quota-gib'], ['--bonus-quota-gib=-1'], ['--bonus-quota-gib', '-1'], ['--bonus-quota-gib=1.5'], ['--bonus-quota-gib='], ['--bonus-quota-gib=999999999999999999999999']] as $option) {
            $capture = $this->pmssRunRepoPhpScriptCommandWithTempStderr('scripts/util/userConfig.php',
                array_merge(['fixtur01', '1024', '200'], $option));
            $this->pmssAssertCommandFailsToStderr($capture['result'], $capture['stderrPath'], "Invalid --bonus-quota-gib value\n");
        }
    }

    public function testReconfigurePublishesThroughTheSharedWriterAfterStoreSuccess(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/userConfig.php', [
            "require_once __DIR__.'/../lib/user/bonusQuota.php';",
            "'docker-enabled', 'bonus-quota-gib'",
            '|| $bonusQuotaGiB !== null',
            "\$user['bonusQuotaGiB'] = \$bonusQuotaGiB;",
            'if (!pmssAddUserBonusQuotaPersist($user, $expectedHome))',
            'Warning: failed to persist bonus quota display copy',
        ]);
        $this->assertOrderedStrings([
            "pmssAddUserBonusQuotaGiBParse(pmssCliOption(\$parsed, 'bonus-quota-gib'))",
            "pmssPasswdEntryLookup(\$user['name'])",
            "if (!\$store->persist(\$user['name'], \$payload))",
            'pmssCliExitWithStderr("Error: failed to persist user config for ',
            'pmssAddUserBonusQuotaPersist($user, $expectedHome)',
            'userApplyTrafficLimit($user)',
        ], $this->pmssReadRepoExecutableSource('scripts/util/userConfig.php'));
        // Both legacy include paths must still expose the same helpers after extraction.
        foreach (['cli.php', 'postProvision.php'] as $file) {
            $this->pmssAssertRepoFileContainsString('scripts/lib/user/add/'.$file,
                "require_once __DIR__.'/../bonusQuota.php';");
        }
    }

    public function testUsageExplainsDisplayOnlyAndZeroSemantics(): void
    {
        $this->assertStringContainsAllStrings(['--bonus-quota-gib=BONUS_QUOTA_GiB',
            'already included in the total', '0 leaves it unchanged'], \pmssUserConfigCliUsage());
    }
}
