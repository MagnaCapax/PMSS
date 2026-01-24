<?php
// #TODO Refactor this installer to use virtualenv instead of system-wide pip. (GH #125)
// #TODO Pin Python package versions explicitly; avoid unbounded upgrades. (GH #125)
// #TODO Replace passthru/backticks with runStep wrappers for consistent logging. (GH #125)
if (empty($debianVersion)) $debianVersion = (string) @file_get_contents('/etc/debian_version');

echo "#### Deluge install // update\n";

// Detect currently installed Deluge version if possible.
$currentVersion = '';
$out = @shell_exec('deluge-console --version 2>/dev/null');
if (is_string($out) && preg_match('/deluge\s+([0-9.]+)/i', $out, $m)) {
    $currentVersion = $m[1];
}

// Debian 10 uses a pip/build route for v2.0.5; make it idempotent.
$isDebian10 = (substr($debianVersion, 0, 2) === '10');
if ($isDebian10) {
    $targetVersion = '2.0.5';
    if ($currentVersion !== $targetVersion) {
        echo "\t*** Deluge pip install (target {$targetVersion})\n";
        passthru('pip install --upgrade twisted[tls] chardet mako pyxdg pillow slimit pygame certifi pyasn1==0.4.6 ');
        passthru('pip install --upgrade pillow');
        passthru('cd /tmp; rm -rf deluge-2*; wget https://ftp.osuosl.org/pub/deluge/source/2.0/deluge-2.0.5.tar.xz; tar -xvf deluge-2.0.5.tar.xz;');
        passthru('cd /tmp/deluge-2.0.5; python3 setup.py build; python setup.py install');
    } else {
        echo "\t*** Deluge already at target version ({$currentVersion}); skipping pip build\n";
    }
} else {
    // For supported releases, prefer apt but only when not installed.
    $installed = (trim((string) @shell_exec('dpkg -s deluged 2>/dev/null | grep -iE "^Status:.*installed$"')) !== '')
              && (trim((string) @shell_exec('dpkg -s deluge-web 2>/dev/null | grep -iE "^Status:.*installed$"')) !== '');
    if (!$installed) {
        passthru('apt-get install -y deluged deluge-web');
        passthru('systemctl disable deluged || true');
    } else {
        echo "\t*** Deluge packages already installed; skipping apt install\n";
    }
}

// Ensure convenience symlinks exist only once.
if (file_exists('/usr/bin/deluged') && !file_exists('/usr/local/bin/deluged')) {
    passthru('ln -s /usr/bin/deluge-web /usr/local/bin/deluge-web; ln -s /usr/bin/deluged /usr/local/bin/deluged');
}
