<?php
/**
 * Boot-time defaults previously applied during install.sh.
 *
 * @license GPL-3.0-only
 */

    /**
     * Ensure /proc hidepid=2 and legacy grub cmdline defaults stay applied.
     *
     * Extra grub cmdline options and explicit grub settings are optional so
     * callers can enable stricter post-upgrade boot diagnostics when needed.
     */
    function pmssEnsureBootDefaults(
        ?callable $logger = null,
        ?string $fstabPath = null,
        ?string $grubPath = null,
        ?string $grubOption = null,
        ?array $extraGrubOptions = null,
        ?array $extraGrubSettings = null
    ): void
    {
        $log = pmssSelectLogger($logger);
        $fstabPath = $fstabPath ?? '/etc/fstab';
        $grubPath = $grubPath ?? '/etc/default/grub';
        $grubOption = $grubOption ?? 'systemd.unified_cgroup_hierarchy=0';

        $requiredGrubOptions = [];
        $grubOption = trim((string) $grubOption);
        if ($grubOption !== '') {
            $requiredGrubOptions[] = $grubOption;
        }
        if (is_array($extraGrubOptions)) {
            foreach ($extraGrubOptions as $option) {
                $option = trim((string) $option);
                if ($option !== '') {
                    $requiredGrubOptions[] = $option;
                }
            }
        }
        $requiredGrubOptions = array_values(array_unique($requiredGrubOptions));

        $requiredGrubSettings = [];
        if (is_array($extraGrubSettings)) {
            foreach ($extraGrubSettings as $key => $value) {
                $key = trim((string) $key);
                if ($key === '' || preg_match('/^[A-Z0-9_]+$/', $key) !== 1) {
                    continue;
                }
                $requiredGrubSettings[$key] = trim((string) $value);
            }
        }

        $writeWithBackup = static function (string $path, array $contentLines, string $label) use ($log): void {
            $backup = $path.'.pmss-backup-'.date('YmdHis');
            if (!@copy($path, $backup)) {
                $log('[WARN] Unable to create '.$label.' backup at '.$backup);
            }
            if (@file_put_contents($path, implode(PHP_EOL, $contentLines).PHP_EOL) === false) {
                $log('[WARN] Failed writing updated '.$path);
            }
        };

        // /proc hidepid baseline.
        $fstabChanged = false;
        if (is_readable($fstabPath) && ($lines = file($fstabPath, FILE_IGNORE_NEW_LINES)) !== false) {
            // Locate existing /proc entry when present.
            $found = false;
            foreach ($lines as $idx => $line) {
                $trim = trim($line);
                if ($trim === '' || $trim[0] === '#') {
                    continue;
                }
                $columns = preg_split('/\s+/', $trim);
                if (count($columns) < 4 || $columns[1] !== '/proc' || $columns[2] !== 'proc') {
                    continue;
                }
                $found = true;
                // Normalize hidepid option to enforce the legacy value.
                $options = $columns[3] === '' || $columns[3] === '-' ? 'defaults' : $columns[3];
                $parts = array_values(array_filter(explode(',', $options), 'strlen'));
                $new = [];
                $hidepid = false;
                foreach ($parts as $opt) {
                    if (strpos($opt, 'hidepid=') === 0) {
                        if (!$hidepid) {
                            $new[] = 'hidepid=2';
                            $hidepid = true;
                        }
                        continue;
                    }
                    $new[] = $opt;
                }
                if (!$hidepid) {
                    $new[] = 'hidepid=2';
                }
                $updated = implode(',', $new);
                if ($updated !== $columns[3]) {
                    $columns[3] = $updated;
                    $lines[$idx] = implode("\t", $columns);
                    $fstabChanged = true;
                    $log('Updated /proc mount options in '.$fstabPath);
                }
                break;
            }
            if (!$found) {
                $lines[] = "proc\t/proc\tproc\tdefaults,hidepid=2\t0\t0";
                $fstabChanged = true;
                $log('Added /proc mount with hidepid=2 to '.$fstabPath);
            }
            // Write fstab only when content changed, with a backup.
            if ($fstabChanged) {
                $writeWithBackup($fstabPath, $lines, 'fstab');

                // Remount /proc after fstab changes so the runtime view matches.
                $command = pmssBuildCommand('mount', ['-o', 'remount,hidepid=2', '/proc']);
                runStep('Remounting /proc with hidepid=2', $command);
            }
        } else {
            $log('[WARN] '.$fstabPath.' not readable; skipping /proc hidepid enforcement');
        }

        // Grub cmdline baseline.
        $grubChanged = false;
        if (is_readable($grubPath) && ($lines = file($grubPath, FILE_IGNORE_NEW_LINES)) !== false) {
            // Update the first GRUB_CMDLINE entry or add a new one.
            $found = false;
            foreach ($lines as $idx => $line) {
                if (!preg_match('/^(GRUB_CMDLINE_LINUX_DEFAULT|GRUB_CMDLINE_LINUX)=("|\')([^"\']*)("|\')/', $line, $match)) {
                    continue;
                }
                $found = true;
                $key = $match[1];
                $quote = $match[2];
                $value = $match[3];

                $currentOptions = preg_split('/\s+/', trim($value)) ?: [];
                $currentOptions = array_values(array_filter($currentOptions, 'strlen'));
                foreach ($requiredGrubOptions as $option) {
                    if (!in_array($option, $currentOptions, true)) {
                        $currentOptions[] = $option;
                    }
                }
                $updatedValue = implode(' ', $currentOptions);

                if ($updatedValue !== $value) {
                    $lines[$idx] = $key.'='.$quote.$updatedValue.$quote;
                    $grubChanged = true;
                    $log('Updated '.$key.' options in '.$grubPath);
                }
                break;
            }
            if (!$found && !empty($requiredGrubOptions)) {
                $lines[] = 'GRUB_CMDLINE_LINUX_DEFAULT="'.implode(' ', $requiredGrubOptions).'"';
                $grubChanged = true;
                $log('Added GRUB_CMDLINE_LINUX_DEFAULT to '.$grubPath);
            }

            // Ensure additional grub key/value settings (e.g. serial console)
            // are present with explicit values.
            foreach ($requiredGrubSettings as $settingKey => $settingValue) {
                $settingFound = false;
                $settingPrefix = $settingKey.'=';
                foreach ($lines as $lineIdx => $line) {
                    if (strpos($line, $settingPrefix) !== 0) {
                        continue;
                    }
                    $settingFound = true;
                    $rawValue = trim(substr($line, strlen($settingPrefix)));
                    if (strlen($rawValue) >= 2) {
                        $first = $rawValue[0];
                        $last = $rawValue[strlen($rawValue) - 1];
                        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                            $rawValue = substr($rawValue, 1, -1);
                        }
                    }
                    if ($rawValue !== $settingValue) {
                        $lines[$lineIdx] = $settingKey.'="'.$settingValue.'"';
                        $grubChanged = true;
                        $log('Updated '.$settingKey.' in '.$grubPath);
                    }
                    break;
                }
                if (!$settingFound) {
                    $lines[] = $settingKey.'="'.$settingValue.'"';
                    $grubChanged = true;
                    $log('Added '.$settingKey.' to '.$grubPath);
                }
            }

            // Persist grub updates only when modified, keeping a backup.
            if ($grubChanged) {
                $writeWithBackup($grubPath, $lines, 'grub');
            }
        } else {
            $log('[WARN] '.$grubPath.' not readable; skipping grub cmdline update');
        }
        // Apply grub changes when possible and warn about reboot.
        if ($grubChanged) {
            $hasUpdateGrub = trim((string) @shell_exec('command -v update-grub 2>/dev/null')) !== '';
            if ($hasUpdateGrub) {
                runStep('Updating GRUB configuration', 'update-grub');
            } else {
                $log('[WARN] update-grub not available; run update-grub after editing '.$grubPath);
            }
            $cmdline = @file_get_contents('/proc/cmdline');
            if ($grubOption !== '' && $cmdline !== false && strpos($cmdline, $grubOption) === false) {
                $log('[WARN] '.$grubOption.' will apply after reboot');
            }
        }
    }
