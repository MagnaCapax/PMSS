<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/support/mail.php';

class SupportMailTest extends TestCase
{
    public function testMailEnvelopeBuildFallsBackWhenHostnameSanitizesEmpty(): void
    {
        $envelope = \pmssSupportMailEnvelopeBuild(
            ['hostname' => '!!!', 'username' => 'demo', 'billingId' => 7, 'body' => 'payload'],
            ['targetEmail' => 'support@example.com'],
            '/tmp/support-snapshot.txt'
        );

        $this->assertSame('support-command@pmss.local', $envelope['from']);
        $this->assertStringContainsString('From: support-command@pmss.local', $envelope['data']);
    }

    public function testMailSendRejectsEnvelopeWithMissingRecipient(): void
    {
        $caught = false;
        try {
            \pmssSupportMailSend(
                ['targetEmail' => 'support@example.com', 'smtpPort' => 25, 'connectTimeout' => 5],
                ['from' => 'sender@example.com', 'data' => 'payload'],
                static function (): void {
                    throw new \RuntimeException('transport must not run');
                }
            );
        } catch (\RuntimeException $exception) {
            $caught = true;
            $this->assertStringContainsString('Support mail envelope recipient is invalid.', $exception->getMessage());
        }

        $this->assertTrue($caught, 'mail send must reject envelopes without a recipient');
    }

    public function testMailSendRejectsEnvelopeWithControlCharacters(): void
    {
        $caught = false;
        try {
            \pmssSupportMailSend(
                ['targetEmail' => 'support@example.com', 'smtpPort' => 25, 'connectTimeout' => 5],
                ['from' => "sender@example.com\nX-Test: injected", 'to' => 'support@example.com', 'data' => 'payload'],
                static function (): void {
                    throw new \RuntimeException('transport must not run');
                }
            );
        } catch (\RuntimeException $exception) {
            $caught = true;
            $this->assertStringContainsString('Support mail envelope sender is invalid.', $exception->getMessage());
        }

        $this->assertTrue($caught, 'mail send must reject header injection attempts');
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

        $caught = false;
        try {
            \pmssSupportStreamWriteAll($stream, 'payload', 'closed stream');
        } catch (\RuntimeException $exception) {
            $caught = true;
            $this->assertStringContainsString('Unable to write closed stream.', $exception->getMessage());
        }

        $this->assertTrue($caught, 'closed streams must be rejected');
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

        $caught = false;
        try {
            \pmssSupportSmtpExpect($stream, [250]);
        } catch (\RuntimeException $exception) {
            $caught = true;
            $this->assertStringContainsString('Support SMTP error: 550 rejected', $exception->getMessage());
        }

        fclose($stream);
        $this->assertTrue($caught, 'unexpected SMTP codes must throw');
    }
}
