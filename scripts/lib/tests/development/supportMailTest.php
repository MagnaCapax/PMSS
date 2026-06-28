<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/support/mail.php';

class SupportMailTest extends TestCase
{
    public function testMailEnvelopeBuildFallsBackWhenHostnameSanitizesEmpty(): void
    {
        $envelope = \pmssSupportMailEnvelopeBuild(
            ['hostname' => '!!!', 'username' => 'demo', 'billingServiceId' => 7, 'billingClientId' => 3, 'body' => 'payload'],
            ['targetEmail' => 'support@example.com'],
            '/tmp/support-snapshot.txt'
        );

        $this->assertSame('support-command@pmss.local', $envelope['from']);
        $this->assertStringContainsString('From: support-command@pmss.local', $envelope['data']);
        $this->assertStringContainsString('X-PMSS-Billing-Service-Id: 7', $envelope['data']);
        $this->assertStringContainsString('X-PMSS-Billing-Client-Id: 3', $envelope['data']);
    }

    public function testMailSendRejectsEnvelopeWithMissingRecipient(): void
    {
        $this->assertThrowsRuntime(
            static function (): void {
                \pmssSupportMailSend(
                    ['targetEmail' => 'support@example.com', 'smtpPort' => 25, 'connectTimeout' => 5],
                    ['from' => 'sender@example.com', 'data' => 'payload'],
                    static function (): void {
                        throw new \RuntimeException('transport must not run');
                    }
                );
            },
            'Support mail envelope recipient is invalid.'
        );
    }

    public function testMailSendRejectsEnvelopeWithControlCharacters(): void
    {
        $this->assertThrowsRuntime(
            static function (): void {
                \pmssSupportMailSend(
                    ['targetEmail' => 'support@example.com', 'smtpPort' => 25, 'connectTimeout' => 5],
                    ['from' => "sender@example.com\nX-Test: injected", 'to' => 'support@example.com', 'data' => 'payload'],
                    static function (): void {
                        throw new \RuntimeException('transport must not run');
                    }
                );
            },
            'Support mail envelope sender is invalid.'
        );
    }

    public function testMailLoadsStreamWriterWithoutDiagnostics(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/support/mail.php');

        $this->assertStringContainsString("require_once __DIR__.'/stream.php';", $source);
        $this->assertStringNotContainsString("require_once __DIR__.'/diagnostics.php';", $source);
    }

    public function testStreamWriteAllWritesFullPayload(): void
    {
        $stream = fopen('php://temp', 'w+');
        \pmssSupportStreamWriteAll($stream, 'hello world', 'test payload');
        rewind($stream);

        $this->assertSame('hello world', stream_get_contents($stream));
        fclose($stream);
    }

    public function testStreamWriteAllAllowsEmptyPayload(): void
    {
        $stream = fopen('php://temp', 'w+');
        \pmssSupportStreamWriteAll($stream, '', 'empty payload');
        rewind($stream);

        $this->assertSame('', stream_get_contents($stream));
        fclose($stream);
    }

    public function testStreamWriteAllRejectsClosedStream(): void
    {
        $stream = fopen('php://temp', 'w+');
        fclose($stream);

        $this->assertThrowsRuntime(static function () use ($stream): void {
            \pmssSupportStreamWriteAll($stream, 'payload', 'closed stream');
        }, 'Unable to write closed stream.');
    }

    public function testSmtpExpectAcceptsMultilineReplies(): void
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "250-first line\r\n250 second line\r\n");
        rewind($stream);

        \pmssSupportSmtpExpect($stream, [250]);

        fclose($stream);
    }

    public function testSmtpExpectRejectsUnexpectedCode(): void
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "550 rejected\r\n");
        rewind($stream);

        $this->assertThrowsRuntime(static function () use ($stream): void {
            \pmssSupportSmtpExpect($stream, [250]);
        }, 'Support SMTP error: 550 rejected');
        fclose($stream);
    }
}
