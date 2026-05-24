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

        $this->_checkResourceConfig();
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
        $file = '/home/'.$user.'/.rtorrent.rc';
        if (!file_exists($file)) {
            touch($file);
            chmod($file, 0644);
        }
        return is_writable($file) && file_put_contents($file, $config) !== false;
    }
    /**
     * Rewrite a user's configuration only when the contents differ.
     * @param string $user   Target username whose configuration is checked.
     * @param string $config Proposed configuration contents.
     * @return bool|null Write result, or null when no change was required.
     */
    public function idempotentConfig($user, $config)
    {
        $file = '/home/'.$user.'/.rtorrent.rc';
        $data = file_get_contents($file);
        return $data !== $config ? $this->writeConfig($user, $config) : null;
    }
    /**
     * Read a user's configuration file through the shared parser.
     * @param string $user Username whose configuration should be read.
     * @return array|false Parsed configuration array or false on failure.
     */
    public function readUserConfig($user)
    {
        $file = "/home/{$user}/.rtorrent.rc";
        return (!file_exists($file) || is_dir($file)) ? false : $this->readConfig($file);
    }
    /**
     * Parse simple `key = value` lines from an rTorrent config file.
     * @param string $file Absolute path to the configuration file.
     * @return array|false Parsed configuration array or false on failure.
     */
    public function readConfig($file)
    {
        if (!file_exists($file) || is_dir($file)) {
            return false;
        }
        $configRaw = file_get_contents($file);
        if (empty($configRaw) || $configRaw == false) {
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
        $directoryBase = '/var/lib/pmss/ports';
        $directoryType = $directoryBase.'/'.$type;

        $this->ensureDirectory($directoryBase, 'Unable to create port reservation base directory: ');
        $this->ensureDirectory($directoryType, 'Unable to create port reservation directory: ');

        do {
            $port = round(rand($rangeStart, $rangeEnd));
        } while (file_exists($directoryType.'/'.$port));

        if (@touch($directoryType.'/'.$port) === false) {
            throw new RuntimeException('Unable to reserve port file: '.$directoryType.'/'.$port);
        }
        return $port;
    }
    protected function _checkResourceConfig()
    {
        $this->_resourceConfig += self::DEFAULT_RESOURCE_CONFIG;
    }

    private function configWithPortDefaults(array $config): array
    {
        if (!isset($config['scgiPort'])) {
            $config['scgiPort'] = $this->_configPortPrivate('scgi', 4000, 24000);
        }
        if (!isset($config['dhtPort']) || empty($config['dhtPort'])) {
            $config['dhtPort'] = $this->_configPortPrivate('dht', 24001, 44000);
        }
        if (!isset($config['listenPort']) || empty($config['listenPort'])) {
            $config['listenPort'] = $this->_configPortPrivate('listen', 44001, 64000);
        }
        return $config;
    }

    private function renderConfigFile(array $config): string
    {
        $sizing = $this->resourceSizing($config);
        $replacements = [
            '##minimumPeers' => $sizing['minimumPeers'],
            '##maximumPeers' => $sizing['maximumPeers'],
            '##uploadSlotsGlobal' => $sizing['uploadSlots'] * 6,
            '##uploadSlots' => $sizing['uploadSlots'],
            '##uploadThrottleLine' => $this->uploadThrottleLine($config),
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

    private function uploadThrottleLine(array $config): string
    {
        if (!isset($config['uploadThrottle']) || !is_numeric($config['uploadThrottle'])) {
            return '';
        }
        $uploadThrottle = (int) $config['uploadThrottle'];
        return $uploadThrottle > 0 ? 'throttle.global_up.max_rate.set = '.$uploadThrottle : '';
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
        if (is_dir($directory)) {
            return;
        }
        if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
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
        $data = json_decode($contents, true);
        if (!is_array($data)) {
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
}
