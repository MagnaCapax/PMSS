<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Metadata diagnostics for etc/skel/install-media-stack.sh without network access. */
class MediaStackMetadataFetchTest extends TestCase
{
    public function testCurlFailuresRetainTransportDetailsAndDiscardPartialBodies(): void
    {
        foreach ([[22, '403', 'request refused'], [28, '000', 'operation timed out'],
            [60, '000', 'certificate verification failed'], [6, '000', 'could not resolve host'],
            [22, '502', 'bad gateway'], [28, '200', 'body transfer timed out']] as $case) {
            foreach (['SABnzbd', 'Autobrr'] as $label) {
                $output = $this->fetchHarness('curl', $case[0], $case[1], 'partial metadata', $case[2], $label);
                $this->assertStringContainsAllStrings(['rc:'.$case[0], "body:\nerrors:", $case[2],
                    $label.' metadata lookup failed (curl exit '.$case[0].', HTTP '.$case[1].')'], $output);
                $this->assertStringNotContainsString('partial metadata', $output);
            }
        }
    }

    public function testCurlSuccessKeepsOnlyTheBodyOnStdout(): void
    {
        foreach (['{"tag_name":"fixture"}', "{\n  \"assets\": []\n}\n", ''] as $body) {
            $output = $this->fetchHarness('curl', 0, '200', $body, '');
            $this->assertSame("rc:0\nbody:".rtrim($body, "\n")."\nerrors:", $output);
        }
    }

    public function testWgetFallbackRetainsErrorsAndDiscardsFailedBodies(): void
    {
        foreach ([[0, 'metadata', ''], [8, 'partial metadata', 'ERROR 403: Forbidden'],
            [4, 'partial metadata', 'connection timed out']] as $case) {
            $output = $this->fetchHarness('wget', $case[0], '', $case[1], $case[2]);
            if ($case[0] === 0) {
                $this->assertSame("rc:0\nbody:metadata\nerrors:", $output);
            } else {
                $this->assertStringContainsAllStrings(['rc:'.$case[0], "body:\nerrors:", $case[2],
                    'SABnzbd metadata lookup failed (wget exit '.$case[0].')'], $output);
                $this->assertStringNotContainsString('partial metadata', $output);
            }
        }
    }

    public function testMissingClientsReportTheAffectedApp(): void
    {
        $output = $this->fetchHarness('none', 0, '', '', '');
        $this->assertStringContainsAllStrings(['rc:1', "body:\nerrors:",
            'SABnzbd metadata lookup failed (neither curl nor wget is available)'], $output);
    }

    public function testSabnzbdDistinguishesTransportFailureFromMissingSourceAsset(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/install-media-stack.sh');
        // Exercise the installer's actual resolution block without running install steps.
        $this->assertSame(1, preg_match('/^\t# SABnzbd \(GitHub API\)\n(.*?)^\t# Autobrr /ms', $source, $matches));
        $url = 'https://example.invalid/SABnzbd-fixture-src.tar.gz';
        $json = "{\n  \"tag_name\": \"fixture\",\n  \"browser_download_url\": \"".$url."\"\n}";
        foreach ([[$json, 0, true], [str_replace('-src', '-binary', $json), 0, false],
            ['{}', 0, false], ['', 0, false], [$json, 28, false]] as $case) {
            $output = $this->pmssRunShellHarness("#!/usr/bin/env bash\nset -euo pipefail\nOVR_SAB_URL=\n"
                .'fetch_text() { printf "%s" '.escapeshellarg($case[0]).'; return '.$case[1]."; }\n"
                .'log_err() { printf "%s\\n" "$*" >&2; }'."\n(\n".$matches[1]
                .'printf "resolved:%s|%s\\n" "$SABNZBD_VERSION" "$SABNZBD_URL"'."\n) || echo \"rc:\$?\"\n");
            if ($case[2]) {
                $this->assertSame('resolved:fixture|'.$url, trim($output));
            } else {
                $expected = $case[1] === 0 ? 'Could not resolve SABnzbd -src asset from the latest GitHub release'
                    : 'Could not resolve SABnzbd release metadata from GitHub';
                $this->assertStringContainsAllStrings(['rc:1', $expected], $output);
                $this->assertStringNotContainsString('resolved:', $output);
                if ($case[1] !== 0) {
                    $this->assertStringNotContainsString('Could not resolve SABnzbd -src asset', $output);
                }
            }
        }
    }

    private function fetchHarness(string $transport, int $rc, string $http, string $body, string $detail, string $label = 'SABnzbd'): string
    {
        $source = $this->pmssReadRepoFile('etc/skel/install-media-stack.sh');
        $script = "#!/usr/bin/env bash\nset -Eeuo pipefail\n";
        foreach (['TRANSPORT' => $transport, 'TRANSFER_RC' => (string) $rc, 'HTTP' => $http,
            'BODY' => $body, 'DETAIL' => $detail] as $name => $value) {
            $script .= $name.'='.escapeshellarg($value)."\n";
        }
        // Shell functions intercept both clients; command discovery cannot reach the network.
        $script .= <<<'SH'
command() {
    if [[ "$1" == -v && ( "$2" == curl || "$2" == wget ) ]]; then
        [[ "$2" == "$TRANSPORT" ]]
    else
        builtin command "$@"
    fi
}
curl() { printf '%s\n%s' "$BODY" "$HTTP"; printf '%s' "$DETAIL" >&2; return "$TRANSFER_RC"; }
wget() { printf '%s' "$BODY"; printf '%s' "$DETAIL" >&2; return "$TRANSFER_RC"; }
log_err() { printf '%s\n' "$*" >&2; }
SH;
        return $this->pmssRunShellHarness($script."\n".$this->pmssExtractShellFunction($source, 'fetch_text')."\n"
            .'if payload=$(fetch_text https://example.invalid/metadata '.escapeshellarg($label).' 2>"$0.stderr"); then rc=0; else rc=$?; fi'."\n"
            .'printf "rc:%s\\nbody:%s\\nerrors:" "$rc" "$payload"; cat "$0.stderr"; printf "\\n"'."\n");
    }
}
