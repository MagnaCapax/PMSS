<?php
/**
 * Library helper: Generator.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** MOTD generator (class-based). */

require_once __DIR__.'/model.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/../runtime.php';

class Motd
{
    /** Placeholder catalog: template token => model key plus optional ANSI color. */
    private const MOTD_FIELDS = [
        '%HOSTNAME%' => ['host', '1;36'], '%SERVER_IP%' => ['ip', '32'], '%SERVER_CPU%' => ['cpu', '37'], '%SERVER_RAM%' => ['ram', '36'],
        '%SERVER_STORAGE%' => ['storage', '35'], '%PMSS_VERSION%' => ['pmssVersion', '1;34'], '%UPDATE_DATE%' => ['updateDate'], '%APT_LAST_UPDATE%' => ['aptLastUpdate'],
        '%UPTIME%' => ['uptime'], '%KERNEL_VERSION%' => ['kernel', '34'], '%NETWORK_SPEED%' => ['netSpeed'], '%WIREGUARD_STATUS%' => ['wgStatus'],
        '%OPENVPN_STATUS%' => ['ovpnStatus'], '%DISTRO%' => ['distro', '1;35'],
    ];

    /**
     * Controller: generate + write MOTD from the template.
     *
     * MVC split:
     * - Model: pmssMotdModelCollect() gathers system inputs (shell-outs, files).
     * - View:  renderMotdTemplate() formats + substitutes placeholders (pure).
     * - Ctrl:  motdGenerate() orchestrates IO + writes output.
     */
    public static function motdGenerate(): void
    {
        $tplPath = pmssResolvePathFromEnv('PMSS_MOTD_TEMPLATE_PATH', '/etc/seedbox/config/template.motd');
        $outPath = pmssResolvePathFromEnv('PMSS_MOTD_OUTPUT_PATH', '/etc/motd');
        $tpl = @file_get_contents($tplPath);
        if (!is_string($tpl)) {
            return;
        }

        $model = pmssMotdModelCollect();
        $colorEnabled = getenv('PMSS_MOTD_COLOR');
        $rendered = self::renderMotdTemplate(
            $tpl,
            $model,
            ($colorEnabled === false || $colorEnabled === '') ? true : pmssEnvValueIsTruthy($colorEnabled)
        );
        pmssWriteManagedFile($outPath, $rendered, 'root', 'root', 0644);

        // Align PAM motd behavior so users see MOTD once (and non-root can read it).
        if ($outPath === '/etc/motd') {
            self::motdSyncPamDynamic($rendered);
        }
    }

    /**
     * View: render the MOTD template from a pre-collected model.
     *
     * This function is pure: it must not perform shell-outs or read files.
     *
     * @param array<string, string> $model
     */
    public static function renderMotdTemplate(string $template, array $model, bool $colorEnabled): string
    {
        $repl = [];
        foreach (self::MOTD_FIELDS as $placeholder => $field) {
            $value = isset($model[$field[0]]) ? (string) $model[$field[0]] : '';
            $repl[$placeholder] = $colorEnabled && isset($field[1]) ? self::c($value, $field[1]) : $value;
        }

        if ($colorEnabled) {
            $netSpeed = trim($repl['%NETWORK_SPEED%']);
            // Resolved means "starts with a digit". The old allow-list compared against
            // 'unknown' and 'n/a' only, so ethtool's literal "Unknown!" on a virtio NIC
            // slipped through and was rendered green, as if it were a detected speed.
            $repl['%NETWORK_SPEED%'] = pmssMotdNetworkSpeedIsResolved($netSpeed)
                ? self::c($netSpeed, '32') // green when detected or provisioned
                : self::c('Unknown', '33'); // yellow when neither probe nor config answers
        }

        $rendered = strtr($template, $repl);
        $patched = preg_replace('/^\s*Runtime Version:.*$/m', '', $rendered);
        $rendered = is_string($patched) ? $patched : $rendered;

        $storageWarn = isset($model['storageWarn']) ? (string) $model['storageWarn'] : '';
        if ($storageWarn !== '') {
            $rendered .= "\n\e[33mStorage WARN:\e[0m ".$storageWarn."\n";
        }

        return $rendered;
    }

    /**
     * Mirror the rendered MOTD into /run/motd.dynamic when PAM is configured for it.
     */
    private static function motdSyncPamDynamic(string $rendered): void
    {
        $data = @file_get_contents('/etc/pam.d/sshd');
        $usesDynamic = false;
        $usesStatic = false;
        if (is_string($data)) {
            foreach (preg_split('/\r?\n/', $data) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (strpos($line, 'pam_motd.so') === false) {
                    continue;
                }
                if (strpos($line, 'motd=/run/motd.dynamic') !== false) {
                    $usesDynamic = true;
                    continue;
                }
                $usesStatic = true;
            }
        }
        if ($usesDynamic) {
            pmssWriteManagedFile('/run/motd.dynamic', $usesStatic ? '' : $rendered, 'root', 'root', 0644);
        }
    }

    private static function c(string $text, string $code): string
    {
        return "\e[{$code}m{$text}\e[0m";
    }
}
