<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class NetworkIptablesWriteSafetyTest extends TestCase
{
    public function testIncompleteWritesSkipRestoreAndRemoveTemporaryFile(): void
    {
        foreach (['false', 'zero', 'short'] as $mode) {
            $result = $this->applyFixture($mode);
            $this->assertSame(false, $result['result']);
            $this->assertSame([], $result['commands']);
            $this->assertSame(null, $result['exception']);
            $this->assertSame(false, $result['exists']);
            $this->assertSame(['unable to write iptables-restore temp file'], $result['logs']);
        }
    }

    public function testThrowablesPropagateUnchangedAfterCleanup(): void
    {
        foreach (['writeThrow', 'logThrow', 'commandThrow', 'commandError'] as $mode) {
            $result = $this->applyFixture($mode);
            $this->assertSame(null, $result['result']);
            $this->assertSame(true, $result['sameThrowable']);
            $this->assertSame($mode, $result['exception']);
            $this->assertSame(false, $result['exists']);
            $this->assertSame(strpos($mode, 'command') === 0 ? 1 : 0, count($result['commands']));
        }
    }

    public function testCompleteWritesPreserveRulesCommandAndResult(): void
    {
        foreach (['success', 'commandFail'] as $mode) {
            $result = $this->applyFixture($mode);
            $this->assertSame($mode === 'success', $result['result']);
            $this->assertSame(null, $result['exception']);
            $this->assertSame(false, $result['exists']);
            $this->assertSame([], $result['logs']);
            $this->assertSame(0600, $result['permissions']);
            $this->assertSame("*filter\n:INPUT ACCEPT [0:0]\n:FORWARD ACCEPT [0:0]\n:OUTPUT ACCEPT [0:0]\n".
                "-A OUTPUT -j ACCEPT\nCOMMIT\n*nat\n:PREROUTING ACCEPT [0:0]\n:INPUT ACCEPT [0:0]\n".
                ":OUTPUT ACCEPT [0:0]\n:POSTROUTING ACCEPT [0:0]\n-A POSTROUTING -j MASQUERADE\nCOMMIT\n",
                $result['bytes']);
            $this->assertSame([[sprintf('sh -c %s', escapeshellarg('iptables-restore < '.escapeshellarg($result['path']))),
                false, 'logMessage']], $result['commands']);
        }
    }

    private function applyFixture(string $mode): array
    {
        // Namespace stubs isolate the system boundary; temporary files and cleanup are real.
        $script = <<<'PHP'
namespace IptablesWriteFixture;
function pmssCreatePrivateTempFile($prefix) {
    return $GLOBALS['path'] = \pmssCreatePrivateTempFile($prefix);
}
function file_put_contents($path, $data, $flags = 0) {
    $mode = $GLOBALS['mode'];
    if ($path !== $GLOBALS['path']) {
        $GLOBALS['logs'][] = substr($data, strpos($data, 'ERROR ') + 6, -1);
        if ($mode === 'logThrow') throw $GLOBALS['throwable'];
        return strlen($data);
    }
    if ($mode === 'writeThrow') throw $GLOBALS['throwable'];
    if ($mode === 'false' || $mode === 'logThrow') return false;
    if ($mode === 'zero') return 0;
    return \file_put_contents($path, $mode === 'short' ? substr($data, 0, -1) : $data, $flags);
}
function runCommand($command, $verbose, $logger) {
    $GLOBALS['commands'][] = [$command, $verbose, $logger];
    $GLOBALS['bytes'] = \file_get_contents($GLOBALS['path']);
    $GLOBALS['permissions'] = fileperms($GLOBALS['path']) & 0777;
    if (strpos($GLOBALS['mode'], 'command') === 0 && $GLOBALS['mode'] !== 'commandFail') {
        throw $GLOBALS['throwable'];
    }
    return $GLOBALS['mode'] === 'commandFail' ? 7 : 0;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/network/iptables.php', 'IptablesWriteFixture');
        $script .= <<<'PHP'
$GLOBALS['mode'] = getenv('PMSS_TEST_IPTABLES_WRITE');
$GLOBALS['throwable'] = $GLOBALS['mode'] === 'commandError'
    ? new \Error($GLOBALS['mode']) : new \RuntimeException($GLOBALS['mode']);
$GLOBALS['path'] = null;
$GLOBALS['commands'] = $GLOBALS['logs'] = [];
$GLOBALS['bytes'] = $GLOBALS['permissions'] = null;
$result = $exception = null;
$sameThrowable = false;
try {
    $result = networkApplyIptablesAtomically(['-A OUTPUT -j ACCEPT'], ['-A POSTROUTING -j MASQUERADE']);
} catch (\Throwable $caught) {
    $exception = $caught->getMessage();
    $sameThrowable = $caught === $GLOBALS['throwable'];
}
clearstatcache();
echo json_encode(['result' => $result, 'exception' => $exception, 'sameThrowable' => $sameThrowable,
    'path' => $GLOBALS['path'], 'exists' => file_exists($GLOBALS['path']), 'logs' => $GLOBALS['logs'],
    'commands' => $GLOBALS['commands'], 'bytes' => $GLOBALS['bytes'], 'permissions' => $GLOBALS['permissions']]);
// Keep the fixture hermetic even if a regression leaks its temporary file.
@unlink($GLOBALS['path']);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_IPTABLES_WRITE' => $mode]);
    }
}
