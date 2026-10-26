<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise remote-command startup without reading the test account's dotfiles. */
class SkeletonBashrcTest extends TestCase
{
    public function testNonInteractivePathAndSilentStartup(): void
    {
        $system = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        foreach ([
            [[], '/usr/bin:/bin', '/usr/bin:/bin'],
            [['bin'], '/usr/bin:/bin', '/usr/bin:/bin'],
            [['.bin', 'bin', '.local/bin'], '/usr/bin:/bin', '/usr/bin:/bin'],
            [['.local/bin'], '', ''],
            [['bin'], ':/extra:/extra::{home}/bin:', '/extra'],
            [[], '{home}/.bin:{home}/bin:{home}/.local/bin:/extra', '/extra'],
        ] as $case) {
            // A space in HOME also exercises quoting while the shell uses only builtins.
            $home = $this->pmssEnsureDir($this->pmssMakeTempDir('pmss-bashrc-').'/test home');
            foreach ($case[0] as $directory) {
                $this->pmssEnsureDir($home.'/'.$directory);
            }
            foreach (['.bashrc.custom', '.bashrc.user'] as $hook) {
                $this->pmssWriteFile($home.'/'.$hook, "printf 'unexpected hook output\\n'\n");
            }

            $path = str_replace('{home}', $home, $case[1]);
            $expected = $system.($case[2] === '' ? '' : ':'.$case[2]);
            foreach ($case[0] as $directory) {
                $expected .= ':'.$home.'/'.$directory;
            }
            $script = <<<'BASH'
unset PS1
umask 022
. "$1"
printf '%s\n' "$PATH"
umask
# None of the interactive setup, hooks or temporary helpers should survive.
if declare -F pmss_normalize_path passwordChange docker-help; then exit 1; fi
if alias ls 2>/dev/null; then exit 1; fi
[ -z "${DOCKER_HOST+x}" ]
BASH;
            $result = $this->pmssExecShellCommand(
                '/usr/bin/env -i HOME='.escapeshellarg($home).' PATH='.escapeshellarg($path)
                .' /bin/bash --noprofile --norc -c '.escapeshellarg($script).' bash '
                .escapeshellarg($this->pmssRepoPath('etc/skel/.bashrc'))
            );
            $this->assertSame(0, $result['rc']);
            $this->assertSame($expected."\n0077", $result['output']);
        }
    }
}
