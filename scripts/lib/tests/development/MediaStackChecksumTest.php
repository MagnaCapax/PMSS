<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Download integrity fixtures for etc/skel/install-media-stack.sh; no network or app execution. */
class MediaStackChecksumTest extends TestCase
{
    public function testGenericEndpointsResolveServedNamesAndKeepSafeFallbacks(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/install-media-stack.sh');
        $function = $this->pmssExtractShellFunction($source, 'fetch_verified_archive');
        foreach ([
            ["HTTP/1.1 200 OK\r\nContent-Disposition: attachment; filename=\"Sonarr.main.4.0.tar.gz\"\r\n", 'Sonarr.main.4.0.tar.gz'],
            ["  HTTP/1.1 200 OK\n  content-disposition: attachment; filename=Radarr.master.5.0.tar.gz\n", 'Radarr.master.5.0.tar.gz'],
            ["HTTP/2 200\nCONTENT-DISPOSITION: attachment; FILENAME=\"Prowlarr.master.2.0.tar.gz\"\n", 'Prowlarr.master.2.0.tar.gz'],
            ["HTTP/2 302\nLocation: /intermediate\nHTTP/2 302\nLocation: https://example.invalid/Radarr.5.0.tar.gz?token=fixture\nHTTP/2 200\n", 'Radarr.5.0.tar.gz'],
            ["HTTP/2 302\nContent-Disposition: attachment; filename=wrong.tar.gz\nLocation: /Sonarr.4.0.tar.gz\nHTTP/2 200\n", 'Sonarr.4.0.tar.gz'],
            ["HTTP/2 302\nLocation: /redirect.tar.gz\nHTTP/2 200\nContent-Disposition: attachment; filename=served.tar.gz\n", 'served.tar.gz'],
            ['', 'updatefile'],
            ["Content-Disposition: attachment; filename=\"../../unsafe.tar.gz\"\n", 'updatefile'],
            ["Location: /download?name=fixture.tar.gz\n", 'updatefile'],
        ] as $case) {
            $output = $this->pmssRunShellHarness("#!/usr/bin/env bash\nset -euo pipefail\nDRY_RUN=0\n"
                .'fetch() { HEADERS_FILE="$3"; printf "%s" '.escapeshellarg($case[0]).' > "$3"; }'."\n"
                .'verify_checksum() { printf "id:%s\\n" "$2"; }'."\n"
                ."log_err() { echo \"\$*\" >&2; }\n".$function."\n"
                .'fetch_verified_archive https://example.invalid/updatefile?os=linux fixture.tar.gz fixture'."\n"
                .'[[ ! -e "$HEADERS_FILE" ]]'."\n");
            $this->assertSame('id:'.$case[1], trim($output));
        }
    }

    public function testBothDownloadClientsCaptureHeadersAndPreserveTransferFailures(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/install-media-stack.sh');
        $fetch = $this->pmssExtractShellFunction($source, 'fetch');
        $headers = $this->pmssMakeTempFile('pmss-checksum-headers-');
        foreach (['wget', 'curl'] as $transport) {
            foreach ([0, 7] as $rc) {
                $output = $this->pmssRunShellHarness("#!/usr/bin/env bash\nset -euo pipefail\nDRY_RUN=0\n"
                    .'TRANSPORT='.escapeshellarg($transport).' TRANSFER_RC='.$rc."\n"
                    .'command() { if [[ "$*" == "-v wget" ]]; then [[ "$TRANSPORT" == wget ]]; else builtin command "$@"; fi; }'."\n"
                    .'log_info() { :; }'."\n"
                    .'wget() { [[ "$*" == *--server-response* ]] || exit 9; echo "Location: /fixture.tar.gz" >&2; return "$TRANSFER_RC"; }'."\n"
                    .'curl() { [[ "$2" == -D ]] || exit 9; echo "Location: /fixture.tar.gz" > "$3"; return "$TRANSFER_RC"; }'."\n"
                    .$fetch."\nif fetch https://example.invalid/latest unused.tar.gz ".escapeshellarg($headers)
                    .'; then echo rc:0; else echo "rc:$?"; fi'."\n");
                $this->assertStringContainsString('rc:'.$rc, $output);
                $this->assertSame("Location: /fixture.tar.gz\n", file_get_contents($headers));
            }
        }
    }

    public function testMissingArchivesFailExceptDuringDryRuns(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/install-media-stack.sh');
        $verify = $this->pmssExtractShellFunction($source, 'verify_checksum');
        $missing = $this->pmssMakeTempDir('pmss-checksum-missing-').'/archive.tar.gz';
        foreach ([0, 1] as $dryRun) {
            foreach ([0, 1] as $strict) {
                $output = $this->pmssRunShellHarness("#!/usr/bin/env bash\nset -euo pipefail\n"
                    .'DRY_RUN='.$dryRun.' STRICT_CHECKSUMS='.$strict."\n"
                    .'log_err() { echo "$*" >&2; }'."\n".$verify."\nif verify_checksum "
                    .escapeshellarg($missing).' fixture.tar.gz; then echo accepted; else echo rejected; fi'."\n");
                $this->assertStringContainsString($dryRun ? 'accepted' : 'rejected', $output);
            }
        }
    }

    public function testNamedUrlsExplicitIdsAndDryRunsKeepTheirIds(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/install-media-stack.sh');
        $function = $this->pmssExtractShellFunction($source, 'fetch_verified_archive');
        foreach ([
            [0, 'https://example.invalid/fixed.tar.gz?token=fixture', '', 'fixed.tar.gz'],
            [0, 'https://example.invalid/latest', 'pinned.tar.gz', 'pinned.tar.gz'],
            [1, 'https://example.invalid/latest', '', 'latest'],
        ] as $case) {
            $output = $this->pmssRunShellHarness("#!/usr/bin/env bash\nset -euo pipefail\nDRY_RUN=".$case[0]."\n"
                .'fetch() { [[ -z "$3" ]] || exit 9; }'."\n"
                .'verify_checksum() { printf "id:%s\\n" "$2"; }'."\n"
                ."log_err() { echo \"\$*\" >&2; }\n".$function."\nfetch_verified_archive "
                .escapeshellarg($case[1]).' fixture.tar.gz fixture '.escapeshellarg($case[2])."\n");
            $this->assertSame('id:'.$case[3], trim($output));
        }
    }

    public function testStrictModeMissingPinsMismatchesAndUpstreamSha512(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/install-media-stack.sh');
        $functions = $this->pmssExtractShellFunctions($source, ['verify_checksum', 'checksum_summary', 'fetch_verified_archive']);
        $archive = $this->pmssMakeTempFile('pmss-checksum-archive-');
        $pins = $this->pmssMakeTempFile('pmss-checksum-pins-');
        $this->pmssWriteFile($archive, 'archive fixture');
        $sha256 = hash('sha256', 'archive fixture');
        $sha512 = hash('sha512', 'archive fixture');
        // Strictness is additive: a SHA256 mismatch still fails with a valid upstream SHA512.
        foreach ([
            [0, $sha256, '', true, false],
            [1, $sha256, '', true, false],
            [0, str_repeat('0', 64), '', false, false],
            [1, str_repeat('0', 64), $sha512, false, false],
            [0, '', '', true, true],
            [1, '', '', false, false],
            [0, null, '', true, true],
            [1, null, '', false, false],
            [1, '', $sha512, true, false],
            [1, null, $sha512, true, false],
            [1, '', str_repeat('0', 128), false, false],
        ] as $case) {
            $this->pmssWriteFile($pins, $case[1] === '' || $case[1] === null ? "# no pin\n" : $case[1]."  fixture.tar.gz\n");
            $output = $this->pmssRunShellHarness("#!/usr/bin/env bash\nset -euo pipefail\nDRY_RUN=0\nCHECKSUMS_SKIPPED=()\n"
                .'STRICT_CHECKSUMS='.$case[0]."\nCHECKSUMS_FILE=".escapeshellarg($case[1] === null ? $pins.'.missing' : $pins)."\n"
                .'fetch() { :; }; log_err() { echo "$*" >&2; }; log_ok() { :; }; log_warn() { echo "$*" >&2; }'."\n"
                .$functions."\n( trap checksum_summary EXIT\nfetch_verified_archive https://example.invalid/fixture.tar.gz "
                .escapeshellarg($archive).' fixture "" '.escapeshellarg($case[2])."\necho accepted\n) || echo rejected\n");
            $this->assertStringContainsString($case[3] ? 'accepted' : 'rejected', $output);
            if ($case[4]) {
                $this->assertSame(1, substr_count($output, 'Downloads without checksum verification (1):'));
                $this->assertStringContainsString('  fixture.tar.gz', $output);
            } else {
                $this->assertStringNotContainsString('Downloads without checksum verification', $output);
            }
        }
    }

    public function testSummaryRetainsAllSkippedIdsAndFailureExitStatus(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/install-media-stack.sh');
        $summary = $this->pmssExtractShellFunction($source, 'checksum_summary');
        $output = $this->pmssRunShellHarness("#!/usr/bin/env bash\nset -euo pipefail\n"
            .'log_warn() { echo "$*" >&2; }'."\n".$summary."\n"
            .'( CHECKSUMS_SKIPPED=(first.tar.gz second.tar.gz); trap checksum_summary EXIT; exit 7 ) || echo "rc:$?"'."\n"
            .'CHECKSUMS_SKIPPED=(); checksum_summary; echo completed'."\n");
        $this->assertStringContainsAllStrings(['(2):', '  first.tar.gz', '  second.tar.gz', 'rc:7', 'completed'], $output);
        $this->assertSame(1, substr_count($output, 'Downloads without checksum verification'));
        $this->assertStringContainsAllStrings(['--strict-checksums) STRICT_CHECKSUMS=1', 'trap checksum_summary EXIT'], $source);
    }
}
