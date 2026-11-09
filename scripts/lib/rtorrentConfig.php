<?php
/**
 * rTorrent configuration generator and idempotency helper.
 *
 * The legacy class name is a compatibility contract for provisioning scripts.
 * The class owns locked SCGI reservation and guarded configuration IO;
 * source acquisition, text transforms, rendering and allocation are separate.
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
require_once __DIR__.'/lighttpd/userFileWrite.php';
require_once __DIR__.'/rtorrentPortReservations.php';
require_once __DIR__.'/rtorrent/configDefaults.php';
require_once __DIR__.'/rtorrent/configText.php';
require_once __DIR__.'/rtorrent/configRender.php';
require_once __DIR__.'/rtorrent/portReservation.php';

class rtorrentConfig
{
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
        $this->_resourceConfig = pmssRtorrentConfigResources($resourceConfig);
        $this->_template = pmssRtorrentConfigTemplate($template);
    }
    /**
     * Create rendered rTorrent config text and return normalized inputs.
     * @param array $config Input settings such as RAM, flags, and ports.
     * @return array Rendered config text plus normalized input settings.
     */
    public function createConfig($config = array())
    {
        if (!is_array($config) || empty($config)) {
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
            $configFile .= "\n".PMSS_RTORRENT_LOCALNET;
        }
        return array('configFile' => $configFile, 'config' => $config);
    }
    /**
     * Replace the pre-2026-10-21 trailing localnet line in an existing account config.
     * @param string $user Target username.
     * @return bool|null Write result, or null when the config does not end with the legacy line.
     */
    public function migrateLegacyLocalnetLine($user)
    {
        return $this->migrateConfig($user, 'pmssRtorrentConfigLocalnetReplace');
    }
    /**
     * Repair only the exact legacy encoding directive in an existing managed config.
     * @param string $user Target username.
     * @return bool|null Write result, or null when no legacy directive is present.
     */
    public function migrateLegacyEncodingLine($user)
    {
        return $this->migrateConfig($user, 'pmssRtorrentConfigEncodingReplace');
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
        $homeDir = dirname($file);
        return is_dir($homeDir)
            && !is_link($homeDir)
            && pmssPathTargetIsSafe($homeDir, true)
            && pmssPathTargetIsSafe($file, false, true)
            && (!file_exists($file) || is_writable($file))
            && pmssReplaceUserFilePreservingMetadata($file, $config, 0644);
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
        return pmssRtorrentConfigParse($configRaw);
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

    /** Reserve the missing SCGI port under the reconciler's shared lock. */
    private function configWithPortDefaults(array $config): array
    {
        if (isset($config['scgiPort'])) {
            return $config;
        }

        $lock = pmssLockFileAcquire($this->portReservationLockPath(), false, 'c', true);
        if ($lock === false) {
            throw new RuntimeException('Unable to acquire rTorrent port reservation lock');
        }

        try {
            $spec = pmssRtorrentPortReservationSpecs()['scgi'];
            $config['scgiPort'] = $this->_configPortPrivate('scgi', $spec['min'], $spec['max']);
            return $config;
        } finally {
            pmssLockHandleRelease($lock);
        }
    }

    /** Share read/compare/write ownership across exact managed-line migrations. */
    private function migrateConfig($user, callable $transform)
    {
        $data = pmssReadRegularFileContents($this->userConfigFilePath($user));
        if ($data === null) return null;
        $updated = $transform($data);
        return $updated !== $data ? $this->writeConfig($user, $updated) : null;
    }

    private function userConfigFilePath($user): string
    {
        if (!pmssRtorrentPortReservationUsernameIsValid($user)) {
            throw new InvalidArgumentException('rtorrentConfig requires a valid PMSS username');
        }

        $homeRoot = getenv('PMSS_HOME_DIR');
        $homeRoot = is_string($homeRoot) && trim($homeRoot) !== '' ? rtrim($homeRoot, '/') : '/home';
        return $homeRoot.'/'.$user.'/.rtorrent.rc';
    }
}
