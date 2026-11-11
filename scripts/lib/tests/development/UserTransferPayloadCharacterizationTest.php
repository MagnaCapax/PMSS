<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/userTransfer.php';
require_once dirname(__DIR__, 2).'/testing/filesystem.php';

/** Payload snapshots only: never execute SSH, rsync, Expect, Docker or namespace tools. */
class UserTransferPayloadCharacterizationTest extends TestCase
{
    private const CONFIG = ['localUser' => 'fixtur01', 'remoteUser' => 'fixtur02', 'hostname' => 'transfer.example'];
    private const HEADER = "#!/bin/bash\nset -e\n";
    private const SSH = "ssh -F /dev/null -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o ConnectTimeout=20 -o NumberOfPasswordPrompts=1 -l 'fixtur02' 'transfer.example' ";

    public function testFacadeKeepsOneOwnerForEachPayloadResponsibility(): void
    {
        foreach ([
            'scripts/lib/userTransfer/remoteScripts.php' => 'pmssUserTransferScratchPaths',
            'scripts/lib/userTransfer/sshScripts.php' => 'pmssUserTransferBuildAuthProbe',
            'scripts/lib/userTransfer/rsyncScripts.php' => 'pmssUserTransferBuildRsyncMain',
            'scripts/lib/userTransfer/dockerScripts.php' => 'pmssUserTransferBuildDockerQuiesce',
            'scripts/lib/userTransfer/receiverNamespace.php' => 'pmssUserTransferIdMap',
            'scripts/lib/userTransfer/homeStats.php' => 'pmssUserTransferBuildRemoteSizeProbe',
            'scripts/lib/userTransfer/repeatSafety.php' => 'pmssUserTransferAssertSourceNotOlder',
        ] as $file => $function) {
            $this->assertSame(realpath($this->pmssRepoPath($file)), (new \ReflectionFunction($function))->getFileName());
        }
    }

    public function testPublicProbeBytes(): void
    {
        $off = 'umask 077; local="$HOME/.mcxPikkumies/pikkumies/local"; '
            .'if [ -d "$local" ]; then touch -- "$local/eternals.off"; fi';
        $hold = 'if [ -x "$HOME/.mcxPikkumies/pikkumies/bin/pikkumies" ] && [ -f /etc/seedbox/config/pikkumiesEternals.enabled ]; then '
            .'"$HOME/.mcxPikkumies/pikkumies/bin/pikkumies" eternal hold --until 1800000000; fi'
            .'; if [ -f "$HOME/.hermes/pmss-install.status" ] && [ -x "$HOME/bin/install-hermes" ]; then "$HOME/bin/install-hermes" stop; fi';
        foreach ([
            [\pmssUserTransferBuildAuthProbe(self::CONFIG), '/bin/true', "\n"],
            [\pmssUserTransferBuildNamespaceProbe(self::CONFIG), 'unshare --map-auto --map-root-user true', " >/dev/null 2>&1\n"],
            [\pmssUserTransferBuildEternalSourceCommand(self::CONFIG, null), 'rm -f -- "$HOME/.mcxPikkumies/pikkumies/local/eternals.hold"', "\n"],
            [\pmssUserTransferBuildEternalSourceOffCommand(self::CONFIG), $off, "\n"],
            [\pmssUserTransferBuildEternalSourceCommand(self::CONFIG, 1800000000), $hold, "\n"],
        ] as [$actual, $command, $suffix]) {
            $this->assertSame(self::HEADER.self::SSH.escapeshellarg($command).$suffix, $actual);
        }
    }

    public function testSharedFramingPreservesCallerPolicyAndQuotedBytes(): void
    {
        foreach ([['', "\n", false], ['printf "a b"', '', false],
            ["printf '%s' 'quoted'", " >/dev/null 2>&1\n", false],
            ['docker ps', "\n", true], ["printf 'first\nsecond'", '', true],
        ] as [$command, $suffix, $private]) {
            $this->assertSame(self::HEADER.($private ? "umask 077\n" : '').self::SSH.escapeshellarg($command).$suffix,
                \pmssUserTransferBuildSshProbeScript(self::CONFIG, $command, $suffix, $private));
        }
    }

    public function testDockerPipelineAndPrivateStartBytes(): void
    {
        $remote = 'R=/run/user/$(id -u); S=$R/docker.sock; '
            .'[ -S "$S" ] && command -v docker >/dev/null 2>&1 || exit 0; '
            .'ids=$(DOCKER_HOST=unix://$S docker ps -q --no-trunc | head -n 500) || exit 0; '
            .'[ -n "$ids" ] || exit 0; printf "\n"; printf "%s\n" "$ids"; '
            .'DOCKER_HOST=unix://$S docker stop -t 30 $ids >/dev/null';
        $this->assertSame(self::HEADER."umask 077\n".self::SSH.escapeshellarg($remote)
            ." | head -c 65536 > '/fixture/docker ids'\n".'[ "${PIPESTATUS[*]}" = "0 0" ]'."\n",
            \pmssUserTransferBuildDockerQuiesce(self::CONFIG, '/fixture/docker ids'));
        $id = str_repeat('a', 64);
        $remote = "DOCKER_HOST=unix:///run/user/$(id -u)/docker.sock docker start '".$id."' >/dev/null 2>&1";
        $this->assertSame(self::HEADER."umask 077\n".self::SSH.escapeshellarg($remote)."\n",
            \pmssUserTransferBuildDockerStartScript(self::CONFIG, [$id]));
    }

    public function testRsyncCommandBytesBeforeReceiverWrapping(): void
    {
        // Export the real builder; only the receiver wrapper is replaced with a string recorder.
        eval(\pmssTestingFunctionExport('pmssUserTransferBuildRsyncCommand', 'PMSS\\Tests\\TransferPayloadFixture'));
        $ssh = "ssh -F /dev/null -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -l 'fixtur02'";
        foreach ([
            [[], [], " --usermap='2200:0' --groupmap='2300:0'", '', '/home/fixtur01/'],
            [['namespaceRsync' => true], [], " --rsync-path='unshare --map-auto --map-root-user rsync'", '', '/home/fixtur01/'],
            [['bwlimit' => 50], ['-R'], " --usermap='2200:0' --groupmap='2300:0'", " '-R' '--bwlimit=50'", '/home/fixtur01/'],
            [['into' => 'old-copy'], [], " --usermap='2200:0' --groupmap='2300:0'", " '--ignore-existing' '--compare-dest=/home/fixtur01/'", '/home/fixtur01/old-copy/'],
            [['namespaceRsync' => true, 'into' => 'old-copy', 'bwlimit' => 0], ['--ignore-missing-args'],
                " --rsync-path='unshare --map-auto --map-root-user rsync'",
                " '--ignore-missing-args' '--bwlimit=0' '--ignore-existing' '--compare-dest=/home/fixtur01/'", '/home/fixtur01/old-copy/'],
        ] as [$overrides, $options, $mapping, $expectedOptions, $destination]) {
            $cfg = $overrides + self::CONFIG + ['receiver' => ['sourceUid' => 2200, 'sourceGid' => 2300]];
            $expected = self::HEADER.'rsync -aHAX --numeric-ids --stats --one-file-system'.$mapping
                ." --filter='-x user.rsync.*' --filter='-x! user.*'".$expectedOptions.' -e '.escapeshellarg($ssh)
                ." --exclude='www' --exclude='session' 'fixtur02@transfer.example:/home/fixtur02/' ".escapeshellarg($destination)."\n";
            $this->assertSame($expected, \PMSS\Tests\TransferPayloadFixture\pmssUserTransferBuildRsyncCommand(
                $cfg, ['/home/fixtur02/'], ['www', 'session'], $options));
        }
    }

    public function testIdMapRangeConsumptionBytesAndUnmodifiedInputs(): void
    {
        foreach ([
            [[], [], "0 3000 1\n"],
            [[[110000, 2]], [[200000, 2]], "0 3000 1\n110000 200000 2\n"],
            [[[110000, 4]], [[200000, 2], [300000, 2]], "0 3000 1\n110000 200000 2\n110002 300000 2\n"],
            [[[120000, 2], [110000, 1]], [[300000, 2], [200000, 2]], "0 3000 1\n110000 200000 1\n120000 200001 1\n120001 300000 1\n"],
            [[[110000, 1], [120000, 1]], [[200000, 2]], "0 3000 1\n110000 200000 1\n120000 200001 1\n"],
            [[[110000, 1]], [[200000, 0], [300000, 1]], "0 3000 1\n110000 200000 0\n110000 300000 1\n"],
            [[['0110000', 1]], [['0200000', 2]], "0 3000 1\n0110000 200000 1\n"],
        ] as [$source, $target, $expected]) {
            $before = [$source, $target];
            $this->assertSame($expected, \pmssUserTransferIdMap($source, $target, 3000));
            $this->assertSame($before, [$source, $target]);
        }
    }

    public function testIdMapFailureMessagesAndCodes(): void
    {
        foreach ([
            [[[110000, 1]], [], 'Target subordinate ID allocation is too small'],
            [[[110000, 3]], [[200000, 2]], 'Target subordinate ID allocation is too small'],
            [[], [[200000, 2], [200001, 2]], 'Target subordinate ID allocation overlaps'],
            [[], [[2999, 2]], 'Target subordinate ID allocation overlaps'],
            [[[110000, 1]], [[3000, 1]], 'Target subordinate ID allocation overlaps'],
        ] as [$source, $target, $message]) {
            try {
                \pmssUserTransferIdMap($source, $target, 3000);
                $this->fail('Expected an ID-map refusal');
            } catch (\RuntimeException $exception) {
                $this->assertSame($message, $exception->getMessage());
                $this->assertSame(1, $exception->getCode());
            }
        }
    }
}

namespace PMSS\Tests\TransferPayloadFixture;

/** Expose command bytes without executing or duplicating the receiver implementation. */
function pmssUserTransferBuildReceiver(array $cfg, string $rsync): string
{
    return $rsync."\n";
}
