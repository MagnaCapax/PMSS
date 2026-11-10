<?php
/** Direct support SMTP exchange and bounded reply reading. @license GPL-3.0-only */
require_once __DIR__.'/stream.php';
require_once __DIR__.'/envelope.php';

/**
 * Deliver the support message directly to the recipient MX via SMTP.
 */
function pmssSupportMailSendViaSmtp(string $host, int $port, int $timeout, array $envelope): void
{
    $envelope = pmssSupportMailEnvelopeNormalize($envelope);
    if ($host === '') {
        throw new RuntimeException('Support relay host is not configured.');
    }

    $errno = 0;
    $errstr = '';
    $stream = @stream_socket_client('tcp://'.$host.':'.$port, $errno, $errstr, $timeout);
    if (!pmssStreamHandleIsOpen($stream)) {
        throw new RuntimeException('Support SMTP connection failed: '.$errstr);
    }

    try {
        stream_set_timeout($stream, $timeout);
        pmssSupportSmtpExpect($stream, [220]);
        $ehloHost = pmssSupportMailHostTokenNormalize((string) (gethostname() ?: 'localhost'), 'localhost');
        // Every exchange writes fully before checking its own reply; failures stop the dialogue.
        foreach ([
            ['EHLO '.$ehloHost, [250], 'support SMTP command'],
            ['MAIL FROM:<'.$envelope['from'].'>', [250], 'support SMTP command'],
            ['RCPT TO:<'.$envelope['to'].'>', [250, 251], 'support SMTP command'],
            ['DATA', [354], 'support SMTP command'],
            [str_replace("\n.", "\n..", $envelope['data'])."\r\n.", [250], 'support SMTP message body'],
            ['QUIT', [221], 'support SMTP quit command'],
        ] as [$command, $expectedCodes, $context]) {
            pmssSupportStreamWriteAll($stream, $command."\r\n", $context);
            pmssSupportSmtpExpect($stream, $expectedCodes);
        }
    } finally {
        fclose($stream);
    }
}

/**
 * Read one SMTP response, including multiline replies, and validate it.
 *
 * @param array<int,int> $expectedCodes
 */
function pmssSupportSmtpExpect($stream, array $expectedCodes): void
{
    $replyLines = 0;
    do {
        // A peer that keeps sending continuation lines must not hold delivery forever.
        if (++$replyLines > 1000) {
            throw new RuntimeException('Support SMTP response has too many lines.');
        }
        $chunk = fgets($stream);
        if (!is_string($chunk)) {
            $meta = pmssStreamHandleIsOpen($stream) ? stream_get_meta_data($stream) : [];
            throw new RuntimeException(!empty($meta['timed_out'])
                ? 'Support SMTP server timed out.' : 'Support SMTP server closed the connection unexpectedly.');
        }
        $line = rtrim($chunk, "\r\n");
    } while (strlen($line) >= 4 && $line[3] === '-');

    $code = (int) substr($line, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Support SMTP error: '.$line);
    }
}
