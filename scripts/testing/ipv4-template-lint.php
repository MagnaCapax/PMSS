#!/usr/bin/env php
<?php
/**
 * ipv4-template-lint — fail-closed guard for ADR 0114 (PMSS hosts are IPv4-only).
 *
 * Shipped templates (etc/ ** /template.*) are what every new and every updated host runs.
 * An IPv6 listener or a dual-stack setting in one of them reaches the whole fleet on the
 * next update, so this lint fails CI instead.
 *
 * Rules (active lines only; a line whose first non-blank character is # or ; is a comment):
 *   F1  an IPv6 listen or bind literal: `[::]`, `ListenAddress ::`/`ListenAddress [`,
 *       `bind ::`, `listen [::`
 *   F2  dual-stack or IPv6-only address family: `AddressFamily any|inet6`,
 *       `UseIPv6 on`, `server.use-ipv6 = "enable"`
 *   F3  IPv6 turned on in the kernel: `disable_ipv6 = 0`, `net.ipv6.* forwarding = 1`
 *   F4  an OpenVPN server template with a protocol that is not IPv4-specific
 *       (`proto udp`, `tcp`, `udp6`, `tcp6`, `tcp-server`); use `udp4` or `tcp4`.
 *       The client template is the customer's device and keeps `proto udp`: the server
 *       name has no AAAA record and the server listens on IPv4 only.
 * Pins (a line that must stay active, so removing it fails CI):
 *   P1  template.rtorrent.rc: `network.bind_address.set = 0.0.0.0` and
 *       `catch = {"network.block.ipv6.set=yes"}`
 *   P2  template.sshd_config: `AddressFamily inet`
 *   P3  template.proftpd: `UseIPv6 off`
 *   P4  template.qbittorrent.conf: `Session\InterfaceAddress=0.0.0.0`
 *
 * This is a shape guard, not a parser for every application. Defaults that open IPv6
 * without any line in a template are found by the runtime check in ipv4RuntimeTest.php.
 *
 * Usage: php scripts/testing/ipv4-template-lint.php [--root PATH]
 *        php scripts/testing/ipv4-template-lint.php --arg=Ipv4TemplateLintTest
 * Exit:  0 clean, 1 violations, 2 usage error.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

const PMSS_IPV4_LINT_PINS = [
    'template.rtorrent.rc' => ['network.bind_address.set = 0.0.0.0', 'catch = {"network.block.ipv6.set=yes"}'],
    'template.sshd_config' => ['AddressFamily inet'],
    'template.proftpd'     => ['UseIPv6 off'],
    'template.qbittorrent.conf' => ['Session\InterfaceAddress=0.0.0.0'],
];

/** Rule hits for one active line of one template; [] when the line is clean. */
function pmssIpv4LintLine(string $base, string $line): array
{
    $t = trim($line);
    if ($t === '' || $t[0] === '#' || $t[0] === ';') return [];
    $hits = [];
    if (preg_match('/\[::\]|^ListenAddress\s+(::|\[)|^bind\s+::|\blisten\s+\[::/i', $t)) $hits[] = 'F1 IPv6 listen/bind literal';
    if (preg_match('/^AddressFamily\s+(any|inet6)\b|^UseIPv6\s+on\b|server\.use-ipv6\s*=\s*"?enable/i', $t)) $hits[] = 'F2 dual-stack or IPv6 address family';
    if (preg_match('/disable_ipv6\s*=\s*0\b|net\.ipv6\.\S*forwarding\s*=\s*1\b/i', $t)) $hits[] = 'F3 IPv6 enabled in the kernel';
    if (strpos($base, 'template.openvpn.server') === 0
        && preg_match('/^proto\s+(\S+)/i', $t, $m) && !in_array(strtolower($m[1]), ['udp4', 'tcp4', 'tcp4-server'], true)) {
        $hits[] = 'F4 OpenVPN server protocol is not IPv4-specific';
    }
    return $hits;
}

/** Violations for one template file's text, file-name relative. */
function pmssIpv4LintFile(string $rel, string $text): array
{
    $base = basename($rel);
    $out = [];
    $active = [];
    foreach (preg_split('/\r\n|\n/', $text) as $n => $line) {
        foreach (pmssIpv4LintLine($base, $line) as $hit) $out[] = $rel.':'.($n + 1).' '.$hit;
        $active[preg_replace('/\s+/', ' ', trim($line))] = true;
    }
    foreach (PMSS_IPV4_LINT_PINS[$base] ?? [] as $pin) {
        if (!isset($active[$pin])) $out[] = $rel.' P pinned line missing: '.$pin;
    }
    return $out;
}

/** Every etc/ template under $root, sorted; an empty set is a usage error, never a pass. */
function pmssIpv4LintTemplates(string $root): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/etc', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && strpos($f->getFilename(), 'template.') === 0) $files[] = substr($f->getPathname(), strlen($root) + 1);
    }
    sort($files);
    return $files;
}

function pmssIpv4LintRun(string $root): int
{
    $files = pmssIpv4LintTemplates($root);
    if ($files === []) { fwrite(STDERR, "ipv4-template-lint: no etc/**/template.* files under {$root}\n"); return 2; }
    $seen = array_map('basename', $files);
    $v = [];
    foreach (array_keys(PMSS_IPV4_LINT_PINS) as $pinned) {
        if (!in_array($pinned, $seen, true)) $v[] = "etc/**/{$pinned} P pinned template missing";
    }
    foreach ($files as $rel) {
        $text = file_get_contents($root.'/'.$rel);
        if ($text === false) { $v[] = "{$rel} unreadable"; continue; }
        $v = array_merge($v, pmssIpv4LintFile($rel, $text));
    }
    if ($v !== []) {
        fwrite(STDERR, 'ipv4-template-lint: '.count($v)." violation(s) (ADR 0114)\n");
        foreach ($v as $line) fwrite(STDERR, "  {$line}\n");
        return 1;
    }
    echo 'ipv4-template-lint: OK ('.count($files)." templates)\n";
    return 0;
}

function pmssIpv4LintRequire(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('Ipv4TemplateLintTest: '.$message);
}

/** Line controls, then the real CLI on a private copy: clean exit 0, appended IPv6 listener exit 1. */
function pmssIpv4TemplateLintTest(string $root): void
{
    $bad = [['template.sshd_config', 'ListenAddress ::'], ['template.sshd_config', 'AddressFamily any'],
        ['template.nginx', '    listen [::]:80;'], ['template.proftpd', 'UseIPv6 on'],
        ['template.sysctl', 'net.ipv6.conf.all.forwarding = 1'], ['template.sysctl', 'net.ipv6.conf.all.disable_ipv6 = 0'],
        ['template.openvpn.server.config', 'proto udp'], ['template.openvpn.server.config', 'proto tcp6'],
        ['template.lighttpd', 'server.use-ipv6 = "enable"']];
    foreach ($bad as [$base, $line]) pmssIpv4LintRequire(pmssIpv4LintLine($base, $line) !== [], "missed: {$line}");
    $good = [['template.sshd_config', 'AddressFamily inet'], ['template.sshd_config', '# ListenAddress ::'],
        ['template.sshd_config', 'ListenAddress 0.0.0.0'], ['template.openvpn.server.config', 'proto udp4'],
        ['template.openvpn.client.config', 'proto udp'], ['template.proftpd', 'UseIPv6 off'],
        ['template.rtorrent.rc', 'catch = {"network.block.ipv6.set=yes"}'], ['template.x', '; listen [::]:80']];
    foreach ($good as [$base, $line]) pmssIpv4LintRequire(pmssIpv4LintLine($base, $line) === [], "false hit: {$line}");
    pmssIpv4LintRequire(pmssIpv4LintFile('etc/template.sshd_config', "ListenAddress 0.0.0.0\n") !== [], 'missing pin not reported');
    pmssIpv4LintRequire(pmssIpv4LintFile('etc/template.proftpd', "UseIPv6        off\n") === [], 'padded pin not matched');
    pmssIpv4LintRequire(pmssIpv4LintFile('etc/template.proftpd', "# UseIPv6 off\n") !== [], 'commented pin counted');

    $tmp = sys_get_temp_dir().'/pmss-ipv4-lint-'.bin2hex(random_bytes(6));
    if (!mkdir($tmp.'/etc', 0700, true)) throw new RuntimeException('Ipv4TemplateLintTest: cannot create fixture');
    try {
        foreach (pmssIpv4LintTemplates($root) as $rel) {
            $dst = $tmp.'/'.$rel;
            if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0700, true);
            copy($root.'/'.$rel, $dst);
        }
        $cli = PHP_BINARY.' '.escapeshellarg(__FILE__).' --root '.escapeshellarg($tmp).' 2>&1';
        exec($cli, $o1, $rc1);
        pmssIpv4LintRequire($rc1 === 0, 'clean copy failed with output '.implode(' | ', $o1));
        $sshd = array_values(array_filter(pmssIpv4LintTemplates($tmp), function ($rel) {
            return basename($rel) === 'template.sshd_config';
        }));
        pmssIpv4LintRequire($sshd !== [], 'fixture has no template.sshd_config');
        file_put_contents($tmp.'/'.$sshd[0], "ListenAddress ::\n", FILE_APPEND);
        exec($cli, $o2, $rc2);
        pmssIpv4LintRequire($rc2 === 1 && strpos(implode("\n", $o2), 'F1') !== false, 'appended IPv6 listener did not fail');
    } finally {
        exec('rm -rf '.escapeshellarg($tmp));
    }
    echo "Ipv4TemplateLintTest: OK\n";
}

$root = realpath(__DIR__.'/../..');
$test = false;
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--root' && isset($argv[$i + 1])) { $root = realpath($argv[++$i]); }
    elseif ($argv[$i] === '--arg=Ipv4TemplateLintTest') { $test = true; }
    else { fwrite(STDERR, "ipv4-template-lint: unknown argument {$argv[$i]}\n"); exit(2); }
}
if ($root === false || !is_dir($root.'/etc')) { fwrite(STDERR, "ipv4-template-lint: cannot resolve repo root\n"); exit(2); }
if ($test) {
    try { pmssIpv4TemplateLintTest($root); } catch (RuntimeException $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }
    exit(0);
}
exit(pmssIpv4LintRun($root));
