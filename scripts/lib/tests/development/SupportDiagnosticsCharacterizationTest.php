<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Lock support/collect.php bytes and observation order without reading account files. */
class SupportDiagnosticsCharacterizationTest extends TestCase
{
    public function testIdentitySelectionRetainsEnvironmentPasswdAndFallbackPrecedence(): void
    {
        $script = <<<'PHP'
namespace SupportIdentityFixture;
function function_exists($name) { return true; }
function pmssResolvePathFromEnv($key, $default) { return $GLOBALS['root']; }
function getenv($name) { return $GLOBALS['env'][$name] ?? false; }
function posix_geteuid() { return 4242; }
function posix_getpwuid($uid) {
    $GLOBALS['events'][] = ['uid', $uid];
    return ['name' => $GLOBALS['case'] === 'invalid-passwd' ? '../invalid' : 'fixtur02'];
}
function posix_getpwnam($user) { $GLOBALS['events'][] = ['name', $user]; return ['dir' => '/passwd/home/']; }
function pmssPathTargetIsSafe($path, $directory) {
    $GLOBALS['events'][] = ['safe', $path, $directory];
    return $GLOBALS['case'] !== 'unsafe-all' && ($GLOBALS['case'] !== 'unsafe-home' || $path === '/passwd/home');
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/support/identity.php', 'SupportIdentityFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (['environment', 'unsafe-home', 'mismatched-home', 'unsafe-user', 'invalid-passwd', 'invalid-root', 'unsafe-all'] as $case) {
    $GLOBALS['case'] = $case;
    $GLOBALS['root'] = $case === 'invalid-root' ? 'relative' : '/fixture/';
    $GLOBALS['env'] = ['USER' => 'fixtur01', 'HOME' => '/fixture/fixtur01/'];
    if ($case === 'mismatched-home') $GLOBALS['env']['HOME'] = '/elsewhere';
    if ($case === 'unsafe-user' || $case === 'invalid-passwd') $GLOBALS['env']['USER'] = '../invalid';
    $GLOBALS['events'] = [];
    $rows[$case] = [pmssSupportIdentityRead(), $GLOBALS['events']];
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame([['username' => 'fixtur01', 'home' => '/fixture/fixtur01'],
            [['safe', '/fixture/fixtur01', true]]], $rows['environment']);
        $this->assertSame([['username' => 'fixtur01', 'home' => '/passwd/home'],
            [['safe', '/fixture/fixtur01', true], ['name', 'fixtur01'], ['safe', '/passwd/home', true]]], $rows['unsafe-home']);
        foreach (['mismatched-home', 'unsafe-user', 'invalid-root'] as $case) {
            $this->assertSame([['username' => 'fixtur02', 'home' => '/passwd/home'],
                [['uid', 4242], ['name', 'fixtur02'], ['safe', '/passwd/home', true]]], $rows[$case], $case);
        }
        $this->assertSame([['username' => '', 'home' => ''], [['uid', 4242]]], $rows['invalid-passwd']);
        $this->assertSame([['username' => 'fixtur01', 'home' => '/fixture/fixtur01'],
            [['safe', '/fixture/fixtur01', true], ['name', 'fixtur01'], ['safe', '/passwd/home', true]]], $rows['unsafe-all']);
    }

    public function testDiagnosticsRecordAndTextRetainTheirObservationOrder(): void
    {
        $script = <<<'PHP'
namespace SupportCollectFixture;
class InvalidArgumentException extends \InvalidArgumentException {}
function pmssSupportIdentityRead() {
    $GLOBALS['events'][] = 'identity';
    return ['username' => 'fixtur01', 'home' => '/fixture/fixtur01'];
}
function gethostname() { return 'fixture.invalid'; }
function pmssResolvePathFromEnv($key, $default) {
    $GLOBALS['events'][] = [$key, $default];
    return $key === 'PMSS_CONFIG_DIR' ? '/fixture/config' : '/fixture/version';
}
function pmssUserBillingIdentityRead($user, $key, $config) {
    $GLOBALS['events'][] = ['store', $user, $key, $config];
    return $GLOBALS['case'] === 'stored' ? ($key === 'billingServiceId' ? '42' : '99') : null;
}
function pmssSupportOwnBillingMarkerRead($home, $names) {
    $GLOBALS['events'][] = ['marker', $home, $names];
    return $GLOBALS['case'] === 'absent' ? null : (count($names) === 2 ? '0007' : '009');
}
function is_file($path) { return $GLOBALS['case'] !== 'absent'; }
function file_get_contents($path) { $GLOBALS['events'][] = ['read', $path]; return "  git/main@fixture\n"; }
function gmdate($format) { return '2001-02-03T04:05:06+00:00'; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/support/collect.php', 'SupportCollectFixture');
        $script .= <<<'PHP'
$results = [];
foreach (['stored', 'markers', 'absent'] as $case) {
    $GLOBALS['case'] = $case;
    $GLOBALS['events'] = [];
    $index = 0;
    $result = pmssSupportDiagnosticsBuild(" \r\nNeed help\rDetails\n ", function ($argv) use (&$index) {
        $GLOBALS['events'][] = ['command', $argv];
        ++$index;
        if ($index === 1) return ['rc' => 0, 'output' => "  up\n now  "];
        if ($index === 2) return ['rc' => 3, 'output' => '  '];
        return [];
    });
    $results[] = [$result, $GLOBALS['events']];
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        foreach (['stored', 'markers', 'absent'] as $index => $case) {
            $service = $case === 'stored' ? 42 : ($case === 'markers' ? 7 : 0);
            $client = $case === 'stored' ? 99 : ($case === 'markers' ? 9 : 0);
            $version = $case === 'absent' ? 'unknown' : 'git/main@fixture';
            $body = "# PMSS Support Request\n\nsubmitted_at=2001-02-03T04:05:06+00:00\n\n"
                ."username=fixtur01\n\nbilling_service_id={$service}\n\nbilling_client_id={$client}\n\n"
                ."hostname=fixture.invalid\n\npmss_version={$version}\n\n\n\n## message\n\nNeed help\nDetails\n\n\n\n"
                ."## uptime\nup\n now\n[exit status: 0]\n\n## disk\n[no output]\n[exit status: 3]\n\n"
                ."## rtorrent\n[no output]\n[exit status: 1]\n\n## deluged\n[no output]\n[exit status: 1]\n\n"
                ."## deluge-web\n[no output]\n[exit status: 1]\n\n## qbittorrent-nox\n[no output]\n[exit status: 1]\n\n";
            $this->assertSame(['message' => "Need help\nDetails", 'username' => 'fixtur01',
                'home' => '/fixture/fixtur01', 'hostname' => 'fixture.invalid',
                'billingServiceId' => $service, 'billingClientId' => $client,
                'pmssVersion' => $version, 'body' => $body], $results[$index][0], $case);
            $events = ['identity', ['PMSS_CONFIG_DIR', '/etc/seedbox/config'],
                ['store', 'fixtur01', 'billingServiceId', '/fixture/config']];
            if ($case !== 'stored') $events[] = ['marker', '/fixture/fixtur01', ['.billingServiceId', '.billingId']];
            $events[] = ['store', 'fixtur01', 'billingClientId', '/fixture/config'];
            if ($case !== 'stored') $events[] = ['marker', '/fixture/fixtur01', ['.billingClientId']];
            $events[] = ['PMSS_VERSION_FILE', '/fixture/config/version'];
            if ($case !== 'absent') $events[] = ['read', '/fixture/version'];
            foreach ([['uptime'], ['df', '-P', '-h', '/fixture/fixtur01'],
                ['pgrep', '-a', '-u', 'fixtur01', '-x', 'rtorrent'], ['pgrep', '-a', '-u', 'fixtur01', '-x', 'deluged'],
                ['pgrep', '-a', '-u', 'fixtur01', '-x', 'deluge-web'], ['pgrep', '-a', '-u', 'fixtur01', '-x', 'qbittorrent-nox']] as $command) {
                $events[] = ['command', $command];
            }
            $this->assertSame($events, $results[$index][1], $case);
        }
    }

    public function testEnvelopeBytesAndFallbacksStayTransportIndependent(): void
    {
        require_once dirname(__DIR__, 2).'/support/mail.php';
        $envelope = \pmssSupportMailEnvelopeBuild(['username' => 'fixtur01', 'hostname' => 'fixture.invalid',
            'billingServiceId' => '007', 'billingClientId' => 9, 'body' => "first\n.second\r\nlast"],
            ['targetEmail' => 'support@example.invalid'], '/fixture/request.txt');
        $this->assertSame(['from' => 'support-command@fixture.invalid', 'to' => 'support@example.invalid',
            'data' => "From: support-command@fixture.invalid\r\nTo: support@example.invalid\r\n"
                ."Subject: [PMSS Support] billing_service=7 user=fixtur01 host=fixture.invalid\r\n"
                ."X-PMSS-Username: fixtur01\r\nX-PMSS-Billing-Service-Id: 7\r\nX-PMSS-Billing-Client-Id: 9\r\n"
                ."X-PMSS-Hostname: fixture.invalid\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
                ."Snapshot: /fixture/request.txt\r\n\r\nfirst\r\n.second\r\r\nlast\r\n"], $envelope);
        // Existing envelope normalization trims addresses, preserves data bytes and drops extra fields.
        $this->assertSame(['from' => 'sender@example.invalid', 'to' => 'support@example.invalid', 'data' => '0'],
            \pmssSupportMailEnvelopeNormalize(['from' => ' sender@example.invalid ',
                'to' => "support@example.invalid\t", 'data' => '0', 'extra' => 'discarded']));
        foreach (['' => 'pmss.local', '!!!' => 'pmss.local', '..fixture host..' => 'fixture-host'] as $host => $expected) {
            $this->assertSame($expected, \pmssSupportMailHostTokenNormalize($host, 'pmss.local'));
        }
    }

    public function testMessageRefusalsKeepTheirExactBoundaries(): void
    {
        require_once dirname(__DIR__, 2).'/support/collect.php';
        $this->assertSame(str_repeat('x', 5000), \pmssSupportMessageNormalize(' '.str_repeat('x', 5000).' '));
        foreach ([[" \r\n ", 'Support message is required.'],
            ["hello\0there", 'Support message contains unsupported control characters.'],
            ["hello\x7f", 'Support message contains unsupported control characters.'],
            [str_repeat('x', 5001), 'Support message is too long.']] as [$input, $message]) {
            try {
                \pmssSupportMessageNormalize($input);
                $this->assertTrue(false, 'Expected message refusal');
            } catch (\InvalidArgumentException $error) {
                $this->assertSame($message, $error->getMessage());
            }
        }
    }
}
