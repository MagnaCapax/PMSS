<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Memory-only transport traces for support/smtp.php and support/sendmail.php. */
class SupportMailTransportCharacterizationTest extends TestCase
{
    public function testSmtpWireBytesReplyBoundariesAndCleanup(): void
    {
        $script = <<<'PHP'
namespace SupportSmtpFixture;
class RuntimeException extends \RuntimeException {}
function stream_socket_client($address, &$errno, &$error, $timeout) {
    $GLOBALS['open'] = [$address, $timeout];
    $error = 'fixture failure';
    return $GLOBALS['case'] === 'connect' ? false : ($GLOBALS['stream'] = \fopen('php://memory', 'w+'));
}
function pmssStreamHandleIsOpen($stream) { return is_resource($stream); }
function stream_set_timeout($stream, $timeout) { $GLOBALS['timeout'] = $timeout; }
function gethostname() { return 'fixture.invalid'; }
function fgets($stream) {
    ++$GLOBALS['reads'];
    if ($GLOBALS['case'] === 'read-throw') throw $GLOBALS['fault'];
    return array_shift($GLOBALS['replies']);
}
function stream_get_meta_data($stream) { return ['timed_out' => $GLOBALS['case'] === 'timeout']; }
function pmssSupportStreamWriteAll($stream, $bytes, $context) {
    $GLOBALS['writes'][] = [$bytes, $context];
    $at = count($GLOBALS['writes']);
    if (($GLOBALS['case'] === 'command-write' && $at === 1)
        || ($GLOBALS['case'] === 'body-write' && $at === 5)
        || ($GLOBALS['case'] === 'quit-write' && $at === 6)) throw $GLOBALS['fault'];
}
function fclose($stream) {
    \fclose($stream);
    ++$GLOBALS['closes'];
    if ($GLOBALS['case'] === 'close-throw') throw $GLOBALS['fault'];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/support/envelope.php', 'SupportSmtpFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/support/smtp.php', 'SupportSmtpFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (['ok', 'forwarded', 'multiline', 'host', 'connect', 'invalid', 'greeting', 'recipient', 'body', 'quit',
    'eof', 'timeout', 'command-write', 'body-write', 'quit-write', 'read-throw', 'close-throw'] as $case) {
    $GLOBALS['case'] = $case;
    $GLOBALS['stream'] = null;
    $GLOBALS['open'] = $GLOBALS['timeout'] = null;
    $GLOBALS['writes'] = [];
    $GLOBALS['reads'] = $GLOBALS['closes'] = 0;
    $GLOBALS['fault'] = new \Error('injected failure');
    $GLOBALS['replies'] = ["220 ready\r\n", "250 hello\r\n", "250 sender\r\n", "250 recipient\r\n",
        "354 send\r\n", "250 queued\r\n", "221 bye\r\n"];
    foreach (['greeting' => 0, 'recipient' => 3, 'body' => 5, 'quit' => 6] as $bad => $offset) {
        if ($case === $bad) $GLOBALS['replies'][$offset] = "550 refused\r\n";
    }
    if ($case === 'forwarded') $GLOBALS['replies'][3] = "251 forwarding\r\n";
    if ($case === 'multiline') array_splice($GLOBALS['replies'], 1, 0, ["250-features\r\n"]);
    if ($case === 'eof' || $case === 'timeout') $GLOBALS['replies'] = [false];
    $caught = null;
    try {
        pmssSupportMailSendViaSmtp($case === 'host' ? '' : 'relay.example.invalid', 2525, 7,
            ['from' => $case === 'invalid' ? "bad\nsender" : 'sender@example.invalid',
                'to' => 'support@example.invalid', 'data' => "Header: value\r\n\r\n.first\r\n..second\n.\r\nend"]);
    } catch (\Throwable $error) { $caught = $error; }
    $rows[$case] = [$caught === null ? null : $caught->getMessage(),
        $caught === null || $caught->getMessage() !== 'injected failure' || $caught === $GLOBALS['fault'],
        is_resource($GLOBALS['stream']), $GLOBALS['open'], $GLOBALS['timeout'],
        $GLOBALS['writes'], $GLOBALS['reads'], $GLOBALS['closes']];
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $wire = [["EHLO fixture.invalid\r\n", 'support SMTP command'],
            ["MAIL FROM:<sender@example.invalid>\r\n", 'support SMTP command'],
            ["RCPT TO:<support@example.invalid>\r\n", 'support SMTP command'],
            ["DATA\r\n", 'support SMTP command'],
            ["Header: value\r\n\r\n..first\r\n...second\n..\r\nend\r\n.\r\n", 'support SMTP message body'],
            ["QUIT\r\n", 'support SMTP quit command']];
        foreach (['ok' => [6, 7], 'forwarded' => [6, 7], 'multiline' => [6, 8],
            'greeting' => [0, 1], 'recipient' => [3, 4], 'body' => [5, 6], 'quit' => [6, 7],
            'eof' => [0, 1], 'timeout' => [0, 1], 'command-write' => [1, 1],
            'body-write' => [5, 5], 'quit-write' => [6, 6], 'read-throw' => [0, 1], 'close-throw' => [6, 7]] as $case => [$writes, $reads]) {
            $message = in_array($case, ['ok', 'forwarded', 'multiline'], true) ? null
                : (in_array($case, ['greeting', 'recipient', 'body', 'quit'], true) ? 'Support SMTP error: 550 refused'
                    : ($case === 'eof' ? 'Support SMTP server closed the connection unexpectedly.'
                        : ($case === 'timeout' ? 'Support SMTP server timed out.' : 'injected failure')));
            $this->assertSame([$message, true, false, ['tcp://relay.example.invalid:2525', 7], 7,
                array_slice($wire, 0, $writes), $reads, 1], $rows[$case], $case);
        }
        foreach (['host' => 'Support relay host is not configured.',
            'invalid' => 'Support mail envelope sender is invalid.'] as $case => $message) {
            $this->assertSame([$message, true, false, null, null, [], 0, 0], $rows[$case], $case);
        }
        $this->assertSame(['Support SMTP connection failed: fixture failure', true, false,
            ['tcp://relay.example.invalid:2525', 7], null, [], 0, 0], $rows['connect']);
    }

    public function testSendmailPipeOrderStatusesAndOriginalThrowables(): void
    {
        $script = <<<'PHP'
namespace SupportSendmailFixture;
class RuntimeException extends \RuntimeException {}
function pmssProcessPipeDescriptorSpec($mode) { return ['fixture', $mode]; }
function pmssStreamHandleIsOpen($handle) { return is_resource($handle); }
function proc_open($command, $spec, &$pipes) {
    $GLOBALS['command'] = [$command, $spec];
    if ($GLOBALS['case'] === 'open') return false;
    $pipes = [];
    foreach (['stdin', 'stdout', 'stderr'] as $name) {
        $pipes[] = $handle = \fopen('php://memory', 'w+');
        $GLOBALS['handles'][(int) $handle] = $name;
    }
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function fclose($handle) { $GLOBALS['events'][] = 'close '.$GLOBALS['handles'][(int) $handle]; \fclose($handle); }
function pmssProcessPipesClose($pipes) { foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); }
function proc_close($process) { $GLOBALS['events'][] = 'reap'; \fclose($process); return $GLOBALS['case'] === 'exit' ? 1 : 0; }
function pmssSupportStreamWriteAll($stream, $bytes, $context) {
    $GLOBALS['events'][] = ['write', $bytes, $context];
    if ($GLOBALS['case'] === 'write') throw $GLOBALS['fault'];
}
function stream_get_contents($stream) {
    $GLOBALS['events'][] = 'stderr';
    if ($GLOBALS['case'] === 'read') throw $GLOBALS['fault'];
    return $GLOBALS['case'] === 'read-false' ? false : " diagnostic \n";
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/support/envelope.php', 'SupportSendmailFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/support/sendmail.php', 'SupportSendmailFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (['ok', 'open', 'exit', 'write', 'read', 'read-false'] as $case) {
    $GLOBALS['case'] = $case;
    $GLOBALS['events'] = $GLOBALS['handles'] = [];
    $GLOBALS['process'] = null;
    $GLOBALS['fault'] = new \Error('injected failure');
    $caught = null;
    try { pmssSupportMailSendViaSendmail('/fixture/send mail',
        ['from' => 'sender@example.invalid', 'to' => 'support@example.invalid', 'data' => "message\n"]); }
    catch (\Throwable $error) { $caught = $error; }
    $rows[$case] = [$caught === null ? null : $caught->getMessage(),
        $caught === null || $caught->getMessage() !== 'injected failure' || $caught === $GLOBALS['fault'],
        is_resource($GLOBALS['process']), $GLOBALS['command'], $GLOBALS['events']];
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $command = ["'/fixture/send mail' -t -i", ['fixture', 'w']];
        $write = ['write', "message\n", 'support mail message to sendmail'];
        foreach (['ok', 'read-false', 'exit', 'write', 'read'] as $case) {
            $events = [$write];
            if ($case !== 'write') array_push($events, 'close stdin', 'stderr');
            else $events[] = 'close stdin';
            array_push($events, 'close stdout', 'close stderr', 'reap');
            $message = $case === 'exit' ? 'Support mail delivery failed: diagnostic'
                : (in_array($case, ['write', 'read'], true) ? 'injected failure' : null);
            $this->assertSame([$message, true, false, $command, $events], $rows[$case], $case);
        }
        $this->assertSame(['Unable to start sendmail transport.', true, false, $command, []], $rows['open']);
    }
}
