<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Preserve fallback side effects and empty/partial restore payload contracts behind the facade. */
class NetworkIptablesCharacterizationTest extends TestCase
{
    public function testFallbackStillValidatesBeforeAnyFlushAndPreservesCommandOrder(): void
    {
        $flushes = ['-F INPUT', '-F FORWARD', '-F OUTPUT', '-t nat -F POSTROUTING'];
        foreach ([
            [[], [], [], $flushes, []],
            [['-A INPUT -i ##IFACE## -j ACCEPT'], ['-A POSTROUTING -j MASQUERADE', '-t nat -A OUTPUT -j ACCEPT'], ['##IFACE##' => 'eth0'],
                array_merge($flushes, ['-A INPUT -i eth0 -j ACCEPT', '-t nat -A POSTROUTING -j MASQUERADE', '-t nat -A OUTPUT -j ACCEPT']), []],
            [['unsafe'], [], [], [], ['rejected unsafe rendered iptables rule: unsafe']],
            [['-A INPUT -j ACCEPT'], [], ['token' => null], [], ['rejected non-string iptables replacement']],
            [[['bad']], [], [], [], ['rejected non-string iptables rule: array']],
        ] as [$filter, $nat, $replacements, $expected, $logs]) {
            $script = <<<'PHP'
namespace NetworkFallbackFixture;
function exec($command, &$output, &$rc) { $GLOBALS['commands'][] = $command; $output = []; $rc = 0; }
function file_put_contents($path, $data, $flags) {
    $GLOBALS['logs'][] = substr($data, strpos($data, 'ERROR ') + 6, -1); return strlen($data);
}
PHP;
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/network/iptables.php', 'NetworkFallbackFixture', ['rules.php']);
            $script .= '$GLOBALS["commands"] = $GLOBALS["logs"] = []; ob_start(); networkApplyIptablesFallback(';
            $script .= var_export($filter, true).', '.var_export($nat, true).', '.var_export($replacements, true).');';
            $script .= 'echo json_encode(["commands" => $GLOBALS["commands"], "stdout" => ob_get_clean(), "logs" => $GLOBALS["logs"]]);';
            $commands = array_map(static function ($rule) { return '/sbin/iptables '.$rule; }, $expected);
            $stdout = implode('', array_map(static function ($command) { return 'Executing: '.$command."\n"; }, $commands));
            $this->assertSame(compact('commands', 'stdout', 'logs'), $this->pmssRunInlinePhpJson($script));
        }
    }

    public function testAtomicRestoreKeepsEmptyAndSingleTablePayloads(): void
    {
        foreach ([
            [[], [], null],
            [['-A OUTPUT -j ACCEPT'], [], "*filter\n:INPUT ACCEPT [0:0]\n:FORWARD ACCEPT [0:0]\n:OUTPUT ACCEPT [0:0]\n-A OUTPUT -j ACCEPT\nCOMMIT\n"],
            [[], ['-A POSTROUTING -j MASQUERADE'], "*nat\n:PREROUTING ACCEPT [0:0]\n:INPUT ACCEPT [0:0]\n:OUTPUT ACCEPT [0:0]\n:POSTROUTING ACCEPT [0:0]\n-A POSTROUTING -j MASQUERADE\nCOMMIT\n"],
        ] as [$filter, $nat, $expected]) {
            $script = <<<'PHP'
namespace NetworkRestoreFixture;
function pmssCreatePrivateTempFile($prefix) { $GLOBALS['allocated']++; return '/unused/iptables-fixture'; }
function pmssFileWriteComplete($path, $data) { $GLOBALS['bytes'] = $data; return true; }
function runCommand($command, $verbose, $logger) { $GLOBALS['restores']++; return 0; }
function unlink($path) { $GLOBALS['cleanups']++; return true; }
PHP;
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/network/iptables.php', 'NetworkRestoreFixture');
            $script .= '$GLOBALS["bytes"] = null; $GLOBALS["allocated"] = $GLOBALS["restores"] = $GLOBALS["cleanups"] = 0;';
            $script .= '$result = networkApplyIptablesAtomically('.var_export($filter, true).', '.var_export($nat, true).');';
            $script .= 'echo json_encode([$result, $GLOBALS["bytes"], $GLOBALS["allocated"], $GLOBALS["restores"], $GLOBALS["cleanups"]]);';
            $count = $expected === null ? 0 : 1;
            $this->assertSame([true, $expected, $count, $count, $count], $this->pmssRunInlinePhpJson($script));
        }
    }
}
