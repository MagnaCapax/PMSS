<?php
/**
 * rTorrent configuration generator and idempotency helper.
 *
 * The legacy class name is a compatibility contract for provisioning scripts.
 * The class owns defaults, the reservation transaction, and configuration IO;
 * template rendering and exclusive port allocation live in focused modules.
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
require_once __DIR__.'/rtorrent/configRender.php';
require_once __DIR__.'/rtorrent/portReservation.php';

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
        $configFile = pmssRtorrentConfigRender($this->_template, $config, $this->_resourceConfig);
        // Localnet publication remains host IO, outside the pure token renderer.
        if (is_readable('/etc/seedbox/config/localnet')) {
            @chmod('/etc/seedbox/config/localnet', 0664);
            $configFile .= "\nipv4_filter.load = /etc/seedbox/config/localnet, preferred";
        }
        return array('configFile' => $configFile, 'config' => $config);
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
        $data = pmssReadRegularFileContents($file);
        return $data !== $config ? $this->writeConfig($user, $config) : null;
    }
    /**
     * Read a user's configuration file through the shared parser.
     * @param string $user Username whose configuration should be read.
     * @return array|false Parsed configuration array or false on failure.
     */
    public function readUserConfig($user)
    {
        if (!pmssRtorrentPortReservationUsernameIsValid($user)) {
            return false;
        }
        return $this->readConfig($this->userConfigFilePath($user));
    }
    /**
     * Parse simple `key = value` lines from an rTorrent config file.
     * @param string $file Absolute path to the configuration file.
     * @return array|false Parsed configuration array or false on failure.
     */
    public function readConfig($file)
    {
        $configRaw = pmssReadRegularFileContents($file);
        if ($configRaw === null || $configRaw === '') {
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
    /** Preserve the subclass allocation hook while sharing one exclusive writer. */
    protected function _configPortPrivate($type, $rangeStart = 2000, $rangeEnd = 65000)
    {
        return pmssRtorrentPortReserve($this->portReservationBaseDir(), $type, $rangeStart, $rangeEnd);
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

    /** Acquire missing ports as one transaction and unwind only its own markers. */
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
        } finally {
            pmssLockHandleRelease($lock);
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
        if (!pmssRtorrentPortReservationUsernameIsValid($user)) {
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

}
