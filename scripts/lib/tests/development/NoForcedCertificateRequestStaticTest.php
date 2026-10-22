<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * ADR 0150: only the end user turns on a public certificate. The certificate request flag may appear only in the
 * opt-in writers and the root job that processes their requests; any other file naming it is a forced-request defect.
 */
final class NoForcedCertificateRequestStaticTest extends TestCase
{
    private const ALLOWED = [
        'etc/skel/www/configureSettings.php' => 'Configure HTTPS card, with its CT acknowledgement (ADR 0039)',
        'etc/skel/bin/createWebPublicCerts' => 'customer command with its typed confirmation (ADR 0039)',
        'scripts/cron/webPublicCertsProcess.php' => 'root job that issues the customer-requested certificate',
        'scripts/lib/user/webCertFlags.php' => 'flag writer used by the root job for .failed only',
    ];

    public function testOnlyOptInPathsNameTheCertificateRequestFlag(): void
    {
        $root = dirname(__DIR__, 4);
        $found = [];
        foreach (['etc', 'scripts'] as $directory) {
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($entries as $entry) {
                if (!$entry->isFile() || $entry->isLink()) continue;
                $path = substr($entry->getPathname(), strlen($root) + 1);
                if (strpos($path, 'scripts/lib/tests/') === 0) continue;
                if (strpos((string) file_get_contents($entry->getPathname()), 'request-web-certs') !== false) $found[] = $path;
            }
        }
        sort($found);
        $offenders = array_values(array_diff($found, array_keys(self::ALLOWED)));
        $this->assertSame([], $offenders, 'a certificate request outside the opt-in paths (ADR 0150): '.implode(', ', $offenders));
        foreach (array_keys(self::ALLOWED) as $path) {
            $this->assertTrue(in_array($path, $found, true), 'allowed path no longer names the flag; remove it from ALLOWED: '.$path);
        }
    }
}
