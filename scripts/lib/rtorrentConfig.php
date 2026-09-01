<?php
/**
 * rTorrent configuration generator and idempotency helper.
 *
 * The legacy class name is a compatibility contract for provisioning scripts.
 * Internally the render path is split into normalization, sizing, and IO
 * helpers so callers keep the same API without carrying one large method.
 *
 * @author Aleksi Ursin
 * @copyright NuCode 2010-2014 - All Rights reserved.
 * @since 5/10/2010
 * @version 0.9.1
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/log.php';
require_once __DIR__.'/runtime/filesystem.php';
require_once __DIR__.'/rtorrentPortReservations.php';

class rtorrentConfig
{
    private const RESOURCE_CONFIG_PATH = '/etc/seedbox/config/rtorrent.resources.json';
    private const TEMPLATE_PATH = '/etc/seedbox/config/template.rtorrent.rc';
    private const TEMPLATE_OVERRIDE_PATH = '/etc/seedbox/config/template.rtorrentrc';
    private const DEFAULT_RESOURCE_CONFIG = ['ramBlock' => 250, 'peers' => ['minimum' => 6, 'maximum' => 32], 'uploadSlots' => 7];
    protected $_resourceConfig;
    protected $_template;
    /**
     * Build a configuration helper around injected templates or defaults.
     * @param array       $resourceConfig Resource configuration overrides.
     * @param string|null $template       Template contents for .rtorrent.rc.
     * @return void
     */
    public function __construct($resourceConfig = array(), $template = null)
    {
        $this->_resourceConfig = count($resourceConfig) === 0
            ? $this->loadDefaultResourceConfig()
            : $resourceConfig;
        $this->_template = is_string($template) && trim($template) !== ''
            ? $template
            : $this->loadDefaultTemplate();

        $this->_resourceConfig += self::DEFAULT_RESOURCE_CONFIG;
    }
    /**
     * Create rendered rTorrent config text and return normalized inputs.
     * @param array $config Input settings such as RAM, flags, and ports.
     * @return array Rendered config text plus normalized input settings.
     */
    public function createConfig($config = array())
    {
        if (!is_array($config) || count($config) == 0) {
            throw new Exception('createConfig requires an array with atleast RAM defined', 100);
        }
        if (!isset($config['ram'])) {
            throw new Exception('no ram defined for create config');
        }

        $config = $this->configWithPortDefaults($config);
        return array('configFile' => $this->renderConfigFile($config), 'config' => $config);
    }
    /**
     * Persist rendered configuration text to the user's `.rtorrent.rc` file.
     * @param string $user   Target username whose home directory is updated.
     * @param string $config Fully rendered configuration contents.
     * @return bool True when the file write succeeds.
     */
    public function writeConfig($user, $config)
    {
        if (empty($config)) {
            throw new Exception('rtorrentConfig->writeConfig: Config cannot be empty!');
        }
        if (empty($user)) {
            throw new Exception('rtorrentConfig->writeConfig: User cannot be empty!');
        }
        $file = $this->userConfigFilePath($user);
        if (!$this->userConfigFileTargetIsSafe($file)) {
            return false;
        }
        if (!file_exists($file)) {
            if (@touch($file) === false || !$this->userConfigFileTargetIsSafe($file)) {
                return false;
            }
            @chmod($file, 0644);
        }
        return is_writable($file) && @file_put_contents($file, $config) !== false;
    }
    /**
     * Rewrite a user's configuration only when the contents differ.
     * @param string $user   Target username whose configuration is checked.
     * @param string $config Proposed configuration contents.
     * @return bool|null Write result, or null when no change was required.
     */
    public function idempotentConfig($user, $config)
    {
        $file = $this->userConfigFilePath($user);
        $data = (is_file($file) && !is_link($file)) ? @file_get_contents($file) : false;
        return $data !== $config ? $this->writeConfig($user, $config) : null;
    }
    /**
     * Read a user's configuration file through the shared parser.
     * @param string $user Username whose configuration should be read.
     * @return array|false Parsed configuration array or false on failure.
     */
    public function readUserConfig($user)
    {
        if (!$this->userConfigUsernameIsSafe($user)) {
            return false;
        }
        $file = $this->userConfigFilePath($user);
        return (!is_file($file) || is_link($file)) ? false : $this->readConfig($file);
    }
    /**
     * Parse simple `key = value` lines from an rTorrent config file.
     * @param string $file Absolute path to the configuration file.
     * @return array|false Parsed configuration array or false on failure.
     */
    public function readConfig($file)
    {
        if (!is_file($file) || is_link($file)) {
            return false;
        }
        $configRaw = @file_get_contents($file);
        if (!is_string($configRaw) || $configRaw === '') {
            return false;
        }
        $config = array();
        foreach (explode("\n", $configRaw) as $thisLine) {
            $thisLine = trim($thisLine);
            if (empty($thisLine) || $thisLine[0] == '#' || $thisLine[0] == '/') {
                continue;
            }
            $elements = explode('=', $thisLine);
            if (count($elements) != 2) {
                continue;
            }
            $config[trim($elements[0])] = trim($elements[1]);
        }
        return $config;
    }
    protected function _configPortPrivate($type, $rangeStart = 2000, $rangeEnd = 65000)
    {
        $type = $this->normalizePortReservationType($type);
        [$rangeStart, $rangeEnd] = $this->normalizePortReservationRange($rangeStart, $rangeEnd);

        $directoryBase = $this->portReservationBaseDir();
        $directoryType = $directoryBase.'/'.$type;

        $this->ensureDirectory($directoryBase, 'Unable to create port reservation base directory: ');
        $this->ensureDirectory($directoryType, 'Unable to create port reservation directory: ');

        $rangeSize = $rangeEnd - $rangeStart + 1;
        $attempts = min(max($rangeSize * 2, 16), 4096);
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $port = rand($rangeStart, $rangeEnd);
            $reserved = $this->tryReservePortFile($directoryType, $port);
            if ($reserved === true) {
                return $port;
            }
            if ($reserved === null) {
                throw new RuntimeException('Unable to reserve port file: '.$directoryType.'/'.$port);
            }
        }

        for ($port = $rangeStart; $port <= $rangeEnd; $port++) {
            $reserved = $this->tryReservePortFile($directoryType, $port);
            if ($reserved === true) {
                return $port;
            }
            if ($reserved === null) {
                throw new RuntimeException('Unable to reserve port file: '.$directoryType.'/'.$port);
            }
        }

        throw new RuntimeException('No available rTorrent '.$type.' port reservation slots');
    }

    /** Return the root directory used for legacy rTorrent port reservations. */
    protected function portReservationBaseDir(): string
    {
        return '/var/lib/pmss/ports';
    }

    /** Return the shared lock guarding reservation creation and cleanup. */
    protected function portReservationLockPath(): string
    {
        return pmssRtorrentPortReservationLockPath();
    }

    /** Validate the reservation namespace before deriving filesystem paths. */
    private function normalizePortReservationType($type): string
    {
        $type = (string) $type;
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $type) !== 1) {
            throw new InvalidArgumentException('Invalid rTorrent port reservation type');
        }
        return $type;
    }

    /** Normalize and bound the port range before any reservation attempts. */
    private function normalizePortReservationRange($rangeStart, $rangeEnd): array
    {
        $rangeStart = filter_var($rangeStart, FILTER_VALIDATE_INT);
        $rangeEnd = filter_var($rangeEnd, FILTER_VALIDATE_INT);
        if ($rangeStart === false || $rangeEnd === false || $rangeStart < 1 || $rangeEnd > 65535 || $rangeStart > $rangeEnd) {
            throw new InvalidArgumentException('Invalid rTorrent port reservation range');
        }

        return [(int) $rangeStart, (int) $rangeEnd];
    }

    /**
     * Create one reservation file without following pre-existing paths.
     *
     * Returns true when reserved, false when occupied, and null on write error.
     */
    private function tryReservePortFile(string $directoryType, int $port): ?bool
    {
        $path = $directoryType.'/'.$port;
        if (is_link($path)) {
            return false;
        }

        $handle = @fopen($path, 'x');
        if ($handle === false) {
            return (file_exists($path) || is_link($path)) ? false : null;
        }

        @fclose($handle);
        if (!is_file($path) || is_link($path)) {
            @unlink($path);
            return null;
        }

        return true;
    }
    private function configWithPortDefaults(array $config): array
    {
        $reserve = array(
            'scgi' => !isset($config['scgiPort']),
            'dht' => !isset($config['dhtPort']) || empty($config['dhtPort']),
            'listen' => !isset($config['listenPort']) || empty($config['listenPort']),
        );
        if (!in_array(true, $reserve, true)) {
            return $config;
        }

        $lock = pmssLockFileAcquire($this->portReservationLockPath(), false, 'c', true);
        if ($lock === false) {
            throw new RuntimeException('Unable to acquire rTorrent port reservation lock');
        }

        $reserved = array();
        try {
            try {
                foreach (pmssRtorrentPortReservationSpecs() as $type => $spec) {
                    if (!$reserve[$type]) {
                        continue;
                    }
                    $port = $this->_configPortPrivate($type, $spec['min'], $spec['max']);
                    $config[$type.'Port'] = $port;
                    $reserved[] = array($type, $port);
                }
                return $config;
            } catch (Throwable $exception) {
                foreach (array_reverse($reserved) as $reservation) {
                    if (!pmssRtorrentPortReservationMarkerRemove($this->portReservationBaseDir(), $reservation[0], $reservation[1])) {
                        logmsg('[WARN] Failed to roll back rTorrent '.$reservation[0].' port reservation');
                    }
                }
                throw $exception;
            }
        } finally {
            pmssLockHandleRelease($lock);
        }
    }

    private function renderConfigFile(array $config): string
    {
        $sizing = $this->resourceSizing($config);
        $uploadThrottleLine = '';
        if (isset($config['uploadThrottle']) && is_numeric($config['uploadThrottle'])) {
            $uploadThrottle = (int) $config['uploadThrottle'];
            $uploadThrottleLine = $uploadThrottle > 0 ? 'throttle.global_up.max_rate.set = '.$uploadThrottle : '';
        }
        $replacements = [
            '##minimumPeers' => $sizing['minimumPeers'],
            '##maximumPeers' => $sizing['maximumPeers'],
            '##uploadSlotsGlobal' => $sizing['uploadSlots'] * 6,
            '##uploadSlots' => $sizing['uploadSlots'],
            '##uploadThrottleLine' => $uploadThrottleLine,
            '##scgiPort' => $config['scgiPort'],
            '##dhtPort' => $config['dhtPort'],
            '##listenPort' => $config['listenPort'],
            '##pex' => $config['pex'],
            '##dht' => $config['dht'],
            '##memoryMax' => $sizing['piecesMemoryMiB'].'M',
        ];
        $configFile = str_replace(array_keys($replacements), array_values($replacements), $this->_template);
        return $this->appendLocalnetFilter($configFile);
    }

    private function resourceSizing(array $config): array
    {
        $resourceConfig = $this->_resourceConfig;
        $blocks = round(($config['ram'] / $resourceConfig['ramBlock']), 2);
        $uploadSlots = floor($resourceConfig['uploadSlots'] * $blocks);

        return [
            'minimumPeers' => ceil($resourceConfig['peers']['minimum'] * $blocks),
            'maximumPeers' => floor($resourceConfig['peers']['maximum'] * $blocks),
            'uploadSlots' => $uploadSlots,
            'piecesMemoryMiB' => $this->piecesMemoryMiB((int) $config['ram']),
        ];
    }

    private function piecesMemoryMiB(int $ramMiB): int
    {
        $ramMiB = max(0, $ramMiB);
        $gapMiB = max(250, min(1000, (int) floor($ramMiB * 0.25)));
        return max(170, $ramMiB - $gapMiB);
    }

    private function appendLocalnetFilter(string $configFile): string
    {
        if (!is_readable('/etc/seedbox/config/localnet')) {
            return $configFile;
        }
        @chmod('/etc/seedbox/config/localnet', 0664);
        return $configFile."\nipv4_filter.load = /etc/seedbox/config/localnet, preferred";
    }

    private function ensureDirectory(string $directory, string $errorPrefix): void
    {
        if (!pmssDirEnsureExists($directory, 0755)) {
            throw new RuntimeException($errorPrefix.$directory);
        }
    }
    private function loadDefaultResourceConfig(): array
    {
        $path = self::RESOURCE_CONFIG_PATH;
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read rTorrent resource config: '.$path);
        }
        if (($data = pmssJsonDecodeAssoc($contents)) === null) {
            throw new RuntimeException('Invalid rTorrent resource config JSON in '.$path);
        }
        return $data;
    }
    private function loadDefaultTemplate(): string
    {
        foreach ([self::TEMPLATE_OVERRIDE_PATH, self::TEMPLATE_PATH] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $contents = @file_get_contents($path);
            if ($contents !== false && trim($contents) !== '') {
                return $contents;
            }
        }
        throw new RuntimeException('Unable to read rTorrent template: '.self::TEMPLATE_PATH);
    }

    private function userConfigFilePath($user): string
    {
        if (!$this->userConfigUsernameIsSafe($user)) {
            throw new InvalidArgumentException('rtorrentConfig requires a valid PMSS username');
        }

        $homeRoot = getenv('PMSS_HOME_DIR');
        $homeRoot = is_string($homeRoot) && trim($homeRoot) !== '' ? rtrim($homeRoot, '/') : '/home';
        if ($homeRoot === '') {
            $homeRoot = '/';
        }
        $prefix = $homeRoot === '/' ? '' : $homeRoot;
        return $prefix.'/'.$user.'/.rtorrent.rc';
    }

    private function userConfigFileTargetIsSafe(string $file): bool
    {
        $homeDir = dirname($file);
        return is_dir($homeDir)
            && !is_link($homeDir)
            && pmssPathTargetIsSafe($homeDir, true)
            && pmssPathTargetIsSafe($file, false, true);
    }

    private function userConfigUsernameIsSafe($user): bool
    {
        if (!is_string($user)) {
            return false;
        }
        if (function_exists('pmssValidateUsername')) {
            return pmssValidateUsername($user);
        }

        // Keep this leaf writer independent of the full user lifecycle
        // bootstrap; the fallback mirrors pmssUsernameIsValid().
        $normalized = strtolower(trim($user));
        return $user !== ''
            && $normalized === $user
            && preg_match('/^[a-z][a-z0-9]{0,7}$/D', $normalized) === 1;
    }
}
