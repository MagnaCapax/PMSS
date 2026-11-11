<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise etc/skel/.bashrc password changes without invoking account tools. */
class SkeletonPasswordChangeTest extends TestCase
{
    public function testPasswordConversationsAndFailureGates(): void
    {
        $password = 'NewPassw0rdFixture9';
        $current = ' Current\\Pass%Fixture9 ';
        $cases = [
            'user' => [],
            'root' => ['uid' => '0', 'input' => ''],
            'create' => ['existing' => false],
            'generated' => ['args' => [], 'password' => 'Ab2Cd3Ef4Gh5Ij6K'],
            'length' => ['args' => ['18'], 'password' => 'Ab2Cd3Ef4Gh5Ij6Kl7'],
            'minimum length' => ['args' => ['1'], 'password' => 'Ab2Cd3Ef4Gh5'],
            'passwd failure' => ['passwdRc' => 1, 'web' => false, 'rc' => 1],
            'htpasswd failure' => ['htpasswdRc' => 1, 'rc' => 1, 'error' => 'Unix password changed'],
            'EOF' => ['input' => '', 'unix' => false, 'web' => false, 'rc' => 1],
            'missing htpasswd' => ['missing' => true, 'unix' => false, 'web' => false, 'rc' => 1],
            '72 bytes' => ['args' => ['Aa2'.str_repeat('x', 69)]],
        ];
        // Keep every existing validation rule reachable, including generated candidates.
        foreach (['Aa2', 'Aa2 whitespace', str_repeat('a', 12).'2', str_repeat('A', 12).'2',
            'UpperLowerOnly', 'Aa2'.str_repeat('x', 70), 'Aa2'.str_repeat('é', 35)] as $invalid) {
            $cases['invalid '.$invalid] = ['args' => [$invalid], 'unix' => false, 'web' => false, 'rc' => 1];
        }
        $cases['invalid generated'] = [
            'args' => [], 'seed' => str_repeat('a', 30), 'unix' => false, 'web' => false, 'rc' => 1,
        ];

        $functions = $this->pmssExtractShellFunctions($this->pmssReadRepoFile('etc/skel/.bashrc'), [
            'passwordChange', 'pmssPasswordValidate',
        ]);
        foreach ($cases as $name => $case) {
            $args = $case['args'] ?? [$password];
            $expected = $case['password'] ?? ($args[0] ?? $password);
            $home = $this->pmssMakeTempDir('pmss-password-change-', 0700);
            $this->pmssEnsureDir($home.'/.lighttpd');
            if ($case['existing'] ?? true) {
                $this->pmssWriteFile($home.'/.lighttpd/.htpasswd', "fixtur01:fixture-hash\n");
            }
            $this->pmssWriteFile($home.'/input', $case['input'] ?? $current."\n");
            $script = $functions."\n".<<<'BASH'
# Empty PATH and shell stubs prevent calls to real passwd or htpasswd binaries.
whoami() { if [[ "$TEST_UID" == 0 ]]; then printf 'root\n'; else printf 'fixtur01\n'; fi; }
id() { printf '%s\n' "$TEST_UID"; }
tr() {
    if [[ "$1" == -dc ]]; then printf '%s' "$TEST_SEED"; else /usr/bin/tr "$@"; fi
}
head() { /usr/bin/head "$@"; }
passwd() {
    printf '%s\n' "$@" > "$HOME/passwd.args"
    /bin/cat > "$HOME/passwd.input"
    return "$PASSWD_RC"
}
if [[ "$MISSING_HTPASSWD" != 1 ]]; then
    htpasswd() {
        printf '%s\n' "$@" > "$HOME/htpasswd.args"
        /bin/cat > "$HOME/htpasswd.input"
        umask > "$HOME/htpasswd.umask"
        return "$HTPASSWD_RC"
    }
fi
umask 022
passwordChange "$@" < "$HOME/input"
BASH;
            $result = $this->pmssExecArgvCommand(array_merge([
                '/usr/bin/env', '-i', 'HOME='.$home, 'PATH=', 'LC_ALL=C.UTF-8',
                'TEST_UID='.($case['uid'] ?? '1001'),
                'TEST_SEED='.($case['seed'] ?? 'Ab2Cd3Ef4Gh5Ij6Kl7Mn8Op9Qr0St1Uv2Wx3Yz4'),
                'PASSWD_RC='.($case['passwdRc'] ?? 0), 'HTPASSWD_RC='.($case['htpasswdRc'] ?? 0),
                'MISSING_HTPASSWD='.(int) ($case['missing'] ?? false),
                '/bin/bash', '--noprofile', '--norc', '-c', $script, 'bash',
            ], $args));
            $this->assertSame($case['rc'] ?? 0, $result['rc'], $name.': '.$result['output']);
            $this->assertStringNotContainsString($current, $result['output'], $name);
            $this->assertSame($case['unix'] ?? true, is_file($home.'/passwd.input'), $name);
            $this->assertSame($case['web'] ?? true, is_file($home.'/htpasswd.input'), $name);
            $user = ($case['uid'] ?? '1001') === '0' ? 'root' : 'fixtur01';
            if ($case['unix'] ?? true) {
                $prefix = $user === 'root' ? '' : $current."\n";
                $this->assertSame($prefix.$expected."\n".$expected."\n", file_get_contents($home.'/passwd.input'), $name);
                $this->assertSame($user."\n", file_get_contents($home.'/passwd.args'), $name);
            }
            if ($case['web'] ?? true) {
                $create = ($case['existing'] ?? true) ? '' : "-c\n";
                $this->assertSame("-i\n-B\n".$create.$home."/.lighttpd/.htpasswd\n".$user."\n",
                    file_get_contents($home.'/htpasswd.args'), $name);
                $this->assertSame($expected."\n", file_get_contents($home.'/htpasswd.input'), $name);
                $this->assertSame("0077\n", file_get_contents($home.'/htpasswd.umask'), $name);
            }
            if (($case['rc'] ?? 0) === 0) {
                $this->assertStringContainsString('New password is: '.$expected, $result['output'], $name);
            } else {
                $this->assertStringNotContainsString('New password is:', $result['output'], $name);
                $this->assertStringNotContainsString($expected, $result['output'], $name);
            }
            if (isset($case['error'])) {
                $this->assertStringContainsString($case['error'], $result['output'], $name);
            }
        }
    }
}
