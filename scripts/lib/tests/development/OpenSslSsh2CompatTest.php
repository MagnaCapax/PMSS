<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/opensslSsh2Compat.php';

class OpenSslSsh2CompatTest extends TestCase
{
    public function testRepairCommandAndFailureTracesRemainStable(): void
    {
        $hold = ['function' => 'pmssHoldLibssl3ForPeclSsh2Compat'];
        $heal = ['function' => 'pmssHealOpensshServerIfMissing'];
        $downgrade = ['function' => 'pmssEnsureOpensshCompatibleWithHeldLibssl3'];
        $cases = [
            'hold dry' => $hold + ['dry' => true],
            'hold distro' => $hold + ['distro' => 11],
            'hold absent' => $hold + ['versions' => ['']],
            'hold unchanged' => $hold + ['held' => "libssl3\nopenssl\n"],
            'hold upgrade' => $hold + ['versions' => ['3.0.16', '3.0.17-1~deb12u2']],
            'hold downgrade' => $hold + ['versions' => ['3.0.18', '3.0.17-1~deb12u2']],
            'hold failed convergence' => $hold + ['versions' => ['3.0.18']],
            'hold upgrade failed' => $hold + ['versions' => ['3.0.16'], 'fail' => 'Upgrading'],
            'hold downgrade failed' => $hold + ['versions' => ['3.0.18'], 'fail' => 'Converging'],
            'hold failed query' => $hold + ['versions' => ['3.0.17-1~deb12u2', '']],
            'hold failed hold' => $hold + ['fail' => 'Holding'],
            'hold no listener' => $hold + ['versions' => ['3.0.18', '3.0.17-1~deb12u2'], 'noListener' => true],
            'heal healthy' => $heal + ['binary' => true],
            'heal missing' => $heal,
            'heal deleted' => $heal + ['binary' => true, 'deleted' => true, 'legacyMac' => true],
            'heal fallback' => $heal + ['binary' => true, 'deleted' => true, 'fallbackPid' => true],
            'heal mixed fallback' => $heal + ['binary' => true, 'deleted' => true, 'fallbackPid' => true, 'links' => ['/proc/43/exe' => '/usr/sbin/sshd']],
            'heal unreadable link' => $heal + ['binary' => true, 'fallbackPid' => true, 'links' => ['/proc/42/exe' => false, '/proc/43/exe' => false]],
            'heal empty fallback' => $heal + ['binary' => true, 'fallbackPid' => true, 'pids' => ''],
            'heal malformed fallback' => $heal + ['binary' => true, 'fallbackPid' => true, 'pids' => 'bad -42'],
            'heal no temp' => $heal + ['noTemp' => true],
            'heal download failed' => $heal + ['fail' => 'Downloading'],
            'heal incomplete' => $heal + ['debs' => ['openssh-server.deb']],
            'heal install failed' => $heal + ['fail' => 'Installing openssh'],
            'heal prerequisite failed' => $heal + ['fail' => 'Installing runit'],
            'heal hold failed' => $heal + ['fail' => 'Holding'],
            'heal invalid config' => $heal + ['invalidConfig' => true],
            'heal listener failed' => $heal + ['deleted' => true, 'noListener' => true],
            'heal template missing' => $heal + ['noTemplate' => true],
            'heal no runit' => $heal + ['debs' => ['openssh-server.deb', 'openssh-client.deb', 'openssh-sftp-server.deb']],
            'heal dry' => $heal + ['dry' => true],
            'heal distro' => $heal + ['distro' => 10],
            'downgrade success' => $downgrade,
            'downgrade dry' => $downgrade + ['dry' => true],
            'downgrade distro' => $downgrade + ['distro' => 13],
            'downgrade wrong ssl' => $downgrade + ['versions' => ['3.0.18']],
            'downgrade no temp' => $downgrade + ['noTemp' => true],
            'downgrade download failed' => $downgrade + ['fail' => 'Downloading'],
            'downgrade incomplete' => $downgrade + ['debs' => ['openssh-server.deb']],
            'downgrade install failed' => $downgrade + ['fail' => 'Installing openssh'],
            'downgrade unhold failed' => $downgrade + ['fail' => 'Unholding'],
            'downgrade invalid config' => $downgrade + ['invalidConfig' => true],
            'downgrade listener failed' => $downgrade + ['noListener' => true],
        ];
        // SHA-256 of the complete ordered I/O/log/exception trace from the pre-refactor source.
        $snapshots = [
            'hold dry' => '6a94b25ed85001143f6fbcda7bbda742bdc28fd8781280b81e66fa7848d82ffd',
            'hold distro' => 'a6b98a48314912a92f4dffc622c558d8c390e4717422ef4ea92dbb2c76525bbc',
            'hold absent' => 'd8d1718a0add93f147cbfce0e24f7edf653cd8a42563dff7ce02da0701dc0ac9',
            'hold unchanged' => '830ca4a531933f7fda4595e05c483f4a3bbafe1dbf5e45d79c1e19fc10c4b7cb',
            'hold upgrade' => '2a0436d2ad5a12634adcb3f1eec0ab704e77dba5f1c10be2d7efd4d5a81b6118',
            'hold downgrade' => 'e8e2e48492488fa99d260fc2d02505ec8f11657bc2577348d57df223f7623a2b',
            'hold failed convergence' => '1c24b1911d537e9ebb95330aae3eb021ec98d51b0bd1f81cd56a11b10a21b4a1',
            'hold upgrade failed' => 'b96131a7599fade3bfd98a19871d6ec037f7107eafa72df82d494ebfc0769c70',
            'hold downgrade failed' => '141e192c93fc994d6fa71497da86f62146192bb4c0ccc783153242a6c4fbb9d0',
            'hold failed query' => 'e085500e721e043a2650c0dee89f4a6df543890d74c741ee618e5ada2ba1e190',
            'hold failed hold' => '17d7e6407892954799c5a70d10900e11eb4782ac147b733046ab20153e4b2e19',
            'hold no listener' => 'f8551a4b764f8dcbd1c1729d8724406a77acf14df165c3a483752e16767bac56',
            'heal healthy' => '4656fc5bdf53bda3f7b2b415cc5f3d7d4f00e3be28df9d222ea661707095a4b6',
            'heal missing' => 'ec7e4e736310698c9bf5e4bfd4a9994d0f5d9fafd47e63f8db27e44dc720a332',
            'heal deleted' => '94598b4c36843d003e8f611922a95401a847312c2935e9a35499ea864a7d425c',
            'heal fallback' => '9a477de9de18168ab47089aa24543bff0015fc4e6736ec31bf80605b09eec077',
            'heal mixed fallback' => '6a2498ca213676ba4874ddcf76373cacec7b609a9b21be69dffd69e4da2363ce',
            'heal unreadable link' => '9a477de9de18168ab47089aa24543bff0015fc4e6736ec31bf80605b09eec077',
            'heal empty fallback' => 'c464a18981aba808cac015b932883758478b42fa098af6e8975500645eafaa17',
            'heal malformed fallback' => 'c464a18981aba808cac015b932883758478b42fa098af6e8975500645eafaa17',
            'heal no temp' => 'd785bf192d9924117912bc384b18e913ff79537a5f8d9f75cefba4a8d76f40c7',
            'heal download failed' => 'f4bb60d8b9e9271652a0fc700c5118c07fd32c275d2b52a4384e02fadc51845b',
            'heal incomplete' => '39814929428773b7030da6344c3ccb38584a936f7665f6e4a74b65443f1c558f',
            'heal install failed' => 'badd2d859acf0e9a4a4aa5eeaf207b78b4980be1be4af126dc1a01eb73a8d553',
            'heal prerequisite failed' => 'ec7e4e736310698c9bf5e4bfd4a9994d0f5d9fafd47e63f8db27e44dc720a332',
            'heal hold failed' => 'e65a596dbab343b9c114b437eaa500d853340d794944795970d0f05b00bbc84d',
            'heal invalid config' => '98f45cad0f6edd92918d6ab34f8f53053f43b6067c7814b69f23ad85cd2bd90f',
            'heal listener failed' => 'a7e4533b260e73e31dd710b61e72dd16c9218c8f56f70a9989ffc90eb6daaeb1',
            'heal template missing' => '7586737381facfdcc2f4f4b20cf11759e1dad05e1f3aa1af34b91cfd4b6aed58',
            'heal no runit' => 'b7611ca885b6b64c88c667ba247e7a66a3e1492518faff9c989399fe3d311b2f',
            'heal dry' => 'd8a68010248df04b7d37c585dc6d456e4d0ccb431e4751ccb57dcd181ee8342b',
            'heal distro' => '6c1d3521ab6891759010913aea353e7b790bfca1c101fbebdc0344f8ca72c3fe',
            'downgrade success' => 'ac1a0c29e0e13b3b3b786a4cc0586497a2b24b32f98513a3122e22720794dac4',
            'downgrade dry' => 'b04d95149997596d4768d3d84bbf3879a3ef6c63293eee03e4fa4e9c12e1ca25',
            'downgrade distro' => 'fbe5fc4eac16ef9f4b12edac036d491b10657a60f24c5c9fcfb5967d7269c60e',
            'downgrade wrong ssl' => '48399956613a61df6bb7896f5ea45da4a038c438f9af73a0ddfcae75ec097935',
            'downgrade no temp' => '3703ae174af7a4ab6a8ff4693215feb8d8882d18598a7e0aea2d9b732b7b84cd',
            'downgrade download failed' => 'a567d81fb6b576033b194b02aca61b0c0567c412c9093bf6b6594130c165d245',
            'downgrade incomplete' => '616f77645b801b258ebabbe88bec701442494ece904db4c680a6d03e49079846',
            'downgrade install failed' => '283265477acfc07f4c7ebce92bbeaff49c858600984144a0bca6da7dbb995d82',
            'downgrade unhold failed' => 'ac1a0c29e0e13b3b3b786a4cc0586497a2b24b32f98513a3122e22720794dac4',
            'downgrade invalid config' => '2a13a91055f04dac31a8673e00f3beb148518a8228aa5f4c00a5120bf6f5a11b',
            'downgrade listener failed' => 'd6eaa0a1327512053ca9cf4d394eafe4080dbc177667b5ac8b60b826daca9711',
        ];
        foreach ($cases as $name => $case) {
            $trace = $this->pmssRunRepoInlinePhpRequireJson('scripts/lib/tests/fixtures/opensslSsh2Trace.php', '', [
                'PMSS_OPENSSL_TRACE_CASE' => json_encode($case),
            ]);
            $this->assertSame($snapshots[$name], hash('sha256', json_encode($trace, JSON_UNESCAPED_SLASHES)),
                $name.' trace: '.json_encode($trace, JSON_UNESCAPED_SLASHES));
        }
    }

    public function testOpenSshPackageSpecsPreserveInstallAndDownloadShapes(): void
    {
        $version = '1:9.2p1-2+deb12u7';

        $this->assertSame('openssh-server openssh-client openssh-sftp-server', \pmssOpenSslSsh2CompatOpenSshPackages());
        $this->assertSame('openssh-server=1:9.2p1-2+deb12u7 openssh-client=1:9.2p1-2+deb12u7 openssh-sftp-server=1:9.2p1-2+deb12u7', \pmssOpenSslSsh2CompatOpenSshPackages($version));
        $this->assertSame("openssh-server='1:9.2p1-2+deb12u7' openssh-client='1:9.2p1-2+deb12u7' openssh-sftp-server='1:9.2p1-2+deb12u7'", \pmssOpenSslSsh2CompatOpenSshPackages($version, true));
    }

    public function testPackageHoldMatchingRequiresExactLines(): void
    {
        $held = "libssl3\nopenssl\nnot-openssh-server\n";

        $this->assertTrue(\pmssOpenSslSsh2CompatPackageHeld($held, 'libssl3'));
        $this->assertTrue(\pmssOpenSslSsh2CompatPackageHeld($held, 'openssl'));
        $this->assertFalse(\pmssOpenSslSsh2CompatPackageHeld($held, 'openssh-server'));
    }

    public function testDebFilteringPreservesDeterministicPrefixSelection(): void
    {
        $debs = [
            '/tmp/runit-helper_2.16.2_all.deb',
            '/tmp/openssh-client_1%3a9.2p1-2+deb12u7_amd64.deb',
            '/tmp/openssh-server_1%3a9.2p1-2+deb12u7_amd64.deb',
            '/tmp/other_1.0_amd64.deb',
        ];

        $this->assertSame([
            '/tmp/runit-helper_2.16.2_all.deb',
        ], \pmssOpenSslSsh2CompatDebsByPrefix($debs, 'runit-helper'));
        $this->assertSame([], \pmssOpenSslSsh2CompatDebsByPrefix($debs, 'missing'));
        $this->assertSame([
            '/tmp/openssh-client_1%3a9.2p1-2+deb12u7_amd64.deb',
            '/tmp/openssh-server_1%3a9.2p1-2+deb12u7_amd64.deb',
        ], \pmssOpenSslSsh2CompatDebsByPrefix($debs, 'openssh'));
    }

    public function testDpkgInstallCommandKeepsConfPreserveFlagsAndDebOrder(): void
    {
        $command = \pmssOpenSslSsh2CompatDpkgInstallCommand([
            '/tmp/openssh-client.deb',
            '/tmp/openssh-server.deb',
        ]);

        $this->assertStringContainsAllStrings(['dpkg --force-confdef --force-confold -i',
            "'/tmp/openssh-client.deb' '/tmp/openssh-server.deb'"], $command);
    }

    public function testVersionQueryCommandUsesPayloadOnlyDpkgFormat(): void
    {
        $this->assertSame("dpkg-query -W -f='\${Version}' 'libssl3' 2>/dev/null", \pmssOpenSslSsh2CompatVersionQueryCommand('libssl3'));
    }

    public function testDpkgBaselineIsTheOnlyDeclarativeCompatibilityAuthority(): void
    {
        $baseline = $this->pmssReadRepoFile('scripts/lib/update/dpkg/selections-debian12.txt');
        foreach (['libssl3:amd64', 'openssl', 'openssh-client', 'openssh-server', 'openssh-sftp-server'] as $package) {
            $this->assertStringContainsString($package."\thold\n", $baseline);
        }

        $source = $this->pmssReadRepoFile('scripts/lib/update/opensslSsh2Compat.php');
        $this->assertStringContainsAndOmitsStrings([], ['Pin-Priority', 'preferences.d/pmss-libssl3-openssh.pref'], $source);
    }
}
