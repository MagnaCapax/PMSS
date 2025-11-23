<?php
/** 
 * rTorrent class
 * 
 * rTorrent configuration generator & idempotency.
 * 
 * @author Aleksi Ursin
 * @copyright NuCode 2010-2014 - All Rights reserved.
 * @since 5/10/2010
 * @version 0.9.1
 */
class rtorrentConfig {
    // TODO(complexity-refactor): This class aggregates IO, template rendering,
    // and parameter normalization in one place. Split into small helpers:
    //  - pure config assembly (no IO)
    //  - filesystem IO (read/write, port reservation)
    //  - defaults/validation
    // Aim to reduce branching, add unit-style tests, and keep PHP 7.3.
	/**
	 * Local template/resource paths used when defaults are not injected.
	 */
	private const RESOURCE_CONFIG_PATH = '/etc/seedbox/config/rtorrent.resources.json';
	private const TEMPLATE_PATH = '/etc/seedbox/config/template.rtorrent.rc';
	protected $_resourceConfig;
	protected $_template;
	
		/**
		 * Build a configuration helper around the provided templates.
		 *
		 * When no resource configuration or template is supplied the class
		 * loads defaults from `/etc/seedbox/config`. The internal state is
		 * then validated to keep downstream callers resilient to missing keys.
		 *
		 * @param array       $resourceConfig Resource configuration overrides.
		 * @param string|null $template       Template contents for .rtorrent.rc.
		 *
		 * @return void
		 */
		function __construct($resourceConfig = array(), $template = null) {
		if (count($resourceConfig) == 0) {
			$resourceConfig = $this->loadDefaultResourceConfig();
		}
		if ($template == null or empty($template ) )
		    $template = $this->loadDefaultTemplate();
		
		$this->_template = $template;
		$this->_resourceConfig = $resourceConfig;

		$this->_checkResourceConfig();
	}
    
   
	
		/**
		 * Create a rendered rTorrent configuration based on the given inputs.
		 *
		 * Validates the payload, derives port allocations and memory-based
		 * tuning from the resources configuration, and returns both the final
		 * configuration text and the normalised settings that were applied.
		 *
		 * @param array $config Input settings such as RAM, DHT/PEX flags and ports.
		 *
		 * @return array Array with keys `configFile` and `config`.
		 *
		 * @throws Exception When required inputs are missing or invalid.
		 */
		public function createConfig($config = array()) {
		if (!is_array($config) || count($config) == 0) throw new Exception('createConfig requires an array with atleast RAM defined', 100);
		if (!isset($config['ram'])) throw new Exception('no ram defined for create config');
		
		if (!isset($config['scgiPort'])) $config['scgiPort'] = $this->_configPortPrivate('scgi', 4000, 24000);
		if (!isset($config['dhtPort']) or empty($config['dhtPort'])) $config['dhtPort'] = $this->_configPortPrivate('dht', 24001, 44000);
		if (!isset($config['listenPort']) or (empty($config['listenPort']))) $config['listenPort'] = $this->_configPortPrivate('listen', 44001, 64000);
		if ( !($config['dht'] == 'no' || $config['dht'] == 'yes' || $config['dht'] == 'auto') ) $dht = 'disabled';
		if ( !($config['pex'] == 'no' || $config['pex'] == 'yes' || $config['pex'] == 'auto') ) $pex = 'no';
			
		$resourceConfig = $this->_resourceConfig;
		$template = $this->_template;
		
		// Handle effects of RAM etc. we handle in terms of "ram blocks"
		$blocks = round(($config['ram'] / $resourceConfig['ramBlock']),2);
		$minimumPeers = ceil($resourceConfig['peers']['minimum'] * $blocks);
		$maximumPeers = floor($resourceConfig['peers']['maximum'] * $blocks);
		$uploadSlots = floor( $resourceConfig['uploadSlots'] * $blocks );
		
		
		$configFile = str_replace('##minimumPeers', $minimumPeers, $template);
		$configFile = str_replace('##maximumPeers', $maximumPeers, $configFile);
        $configFile = str_replace('##uploadSlotsGlobal', $uploadSlots * 6, $configFile);
		$configFile = str_replace('##uploadSlots', $uploadSlots, $configFile);
		$configFile = str_replace('##scgiPort', $config['scgiPort'], $configFile);
        $configFile = str_replace('##dhtPort', $config['dhtPort'], $configFile);
        $configFile = str_replace('##listenPort', $config['listenPort'], $configFile);
		$configFile = str_replace('##pex', $config['pex'], $configFile);
		$configFile = str_replace('##dht', $config['dht'], $configFile);
		$configFile = str_replace('##memoryMax', $config['ram'] . 'M', $configFile);
	
		// Config for localnets, add preferred ipv4 filtering if defined
		if (is_readable('/etc/seedbox/config/localnet')) {
			$configFile .= "\nipv4_filter.load = /etc/seedbox/config/localnet, preferred";
			shell_exec("chmod 664 /etc/seedbox/config/localnet;");
		}

        
		$this->_log('info', 'Configuration file: ' . $configFile);
		return array('configFile' => $configFile, 'config' => $config);
	}
	
		/**
		 * Persist a rendered configuration to the user's `.rtorrent.rc` file.
		 * 
		 * Writes the supplied configuration string to `/home/<user>/.rtorrent.rc`
		 * after basic validation and logging. Existing files are created with
		 * safe permissions when missing and overwritten on success.
		 * 
		 * @param string $user   Target username whose home directory is updated.
		 * @param string $config Fully rendered configuration contents.
		 *
		 * @return bool True on successful write, false on failure.
		 *
		 * @throws Exception When the configuration or user name is empty.
		 */
		public function writeConfig($user, $config) {
	    if (empty($config)) throw new Exception('rtorrentConfig->writeConfig: Config cannot be empty!');
	    if (empty($user)) throw new Exception('rtorrentConfig->writeConfig: User cannot be empty!');

	    //$customConfigFile = "/home/{$user}/.rtorrent.rc.custom";
	    //if (file_exists($customConfigFile) && is_readable($customConfigFile))
	    //  $config = file_get_contents($customConfigFile) . "\n\n" . $config;
	    
		$file = '/home/' . $user . '/.rtorrent.rc';
		$this->_log('info', 'Writing .rtorrent.rc to: ' . $file . "\nContents:\n{$config}\n");
		if (!file_exists($file)) { touch($file); chmod($file, 0644); }
		if (is_writable($file)) {
			if (file_put_contents($file, $config)) return true;
				else return false;
		} else return false;
	}
	
		/**
		 * Apply configuration changes idempotently for the given user.
		 * 
		 * Reads the current `.rtorrent.rc` from the user’s home directory and
		 * compares it with the provided configuration. Only when the contents
		 * differ is the file rewritten through `writeConfig()`.
		 * 
		 * @param string $user   Target username whose configuration is checked.
		 * @param string $config Proposed configuration contents.
		 *
		 * @return bool|null True when a new config is written, false when the
		 *                   write fails, and null when no change was required.
		 */
		public function idempotentConfig($user, $config) {
		$file = '/home/' . $user . '/.rtorrent.rc';
		#TODO Check mtime + permissions first. if root and no "other" write permission + mtime exceeds 2-3 months, we can be 99.9% certain it's right
                $data = file_get_contents( $file );
		
		if ($data !== $config) return $this->writeConfig($user, $config);
	}
    
	    /**
	     * Read a user's configuration file via the high-level helper.
	     *
	     * Convenience wrapper that targets `/home/<user>/.rtorrent.rc` and
	     * delegates parsing to `readConfig()` while handling basic guards.
	     *
	     * @param string $user Username whose configuration should be read.
	     *
	     * @return array|false Parsed configuration array or false on failure.
	     */
	    public function readUserConfig($user) {
        if (!file_exists("/home/{$user}/.rtorrent.rc") or
            is_dir("/home/{$user}/.rtorrent.rc") ) return false;
        return $this->readConfig( "/home/{$user}/.rtorrent.rc" );
    }
    
	    /**
	     * Read and parse a `.rtorrent.rc` style configuration file.
	     *
	     * Skips empty lines and comments, splits `key = value` style entries,
	     * and returns a simple associative array of configuration settings.
	     *
	     * @param string $file Absolute path to the configuration file.
	     *
	     * @return array|false Parsed configuration array or false on failure.
	     */
	    public function readConfig($file) {
        if (!file_exists($file) or
            is_dir($file)) return false;
            
        $configRaw = file_get_contents($file);
        if (empty($configRaw) or $configRaw == false) return false;
        
        $configLines = explode("\n", $configRaw);
        $config = array();
        foreach ($configLines AS $thisLine) {
            $thisLine = trim($thisLine);
            if (empty($thisLine)) continue;
            
            if ($thisLine[0] == '#' or
                $thisLine[0] == '/') continue;
            
            $elements = explode('=', $thisLine);
            if ( count($elements) != 2 ) continue;
            
            $config[ trim($elements[0]) ] = trim( $elements[1] );
        }
        
        return $config;
    
    }
    
    protected function _configPortPrivate($type, $rangeStart = 2000, $rangeEnd = 65000) {
        // Use persistent location for reserved ports
        $directoryBase = '/var/lib/pmss/ports';
        $directoryType = $directoryBase . '/' . $type;
        
        if (!is_dir($directoryBase)) {
            if (!@mkdir($directoryBase, 0755, true) && !is_dir($directoryBase)) {
                throw new RuntimeException('Unable to create port reservation base directory: '.$directoryBase);
            }
        }
        if (!is_dir($directoryType)) {
            if (!@mkdir($directoryType, 0755, true) && !is_dir($directoryType)) {
                throw new RuntimeException('Unable to create port reservation directory: '.$directoryType);
            }
        }

        do {
            $port = round(rand($rangeStart, $rangeEnd));
        } while (file_exists($directoryType . '/' . $port));

        if (@touch($directoryType . '/' . $port) === false) {
            throw new RuntimeException('Unable to reserve port file: '.$directoryType.'/'.$port);
        }

        return $port;
        
        
    }
    
	
	/**
	 * Check config
	 * 
	 * Checks resource config template. This also defines config options for reference
	 * 
	 */
	protected function _checkResourceConfig() {
		// Legacy helper keeps downstream code resilient if config misses fields.
		
		$config = &$this->_resourceConfig;

			// We use ram blocks for calculations, ram defined in Mb
		if (!isset($config['ramBlock'])) $config['ramBlock'] = 250;
		
		if (!isset($config['peers'])) {
			// These are set "per ram block"
			$config['peers'] = array(
				'minimum' => 6,
				'maximum' => 32
			);
		}
		
		if (!isset($config['uploadSlots'])) {
			$config['uploadSlots'] = 7;
		}
	}

	/**
	 * Load the default resource configuration JSON from disk.
	 */
	private function loadDefaultResourceConfig(): array
	{
		$path = self::RESOURCE_CONFIG_PATH;
		$contents = @file_get_contents($path);
		if ($contents === false) {
			throw new RuntimeException('Unable to read rTorrent resource config: ' . $path);
		}
		$data = json_decode($contents, true);
		if (!is_array($data)) {
			throw new RuntimeException('Invalid rTorrent resource config JSON in ' . $path);
		}
		return $data;
	}

	/**
	 * Load the default rTorrent template from disk.
	 */
	private function loadDefaultTemplate(): string
	{
		$path = self::TEMPLATE_PATH;
		$contents = @file_get_contents($path);
		if ($contents === false) {
			throw new RuntimeException('Unable to read rTorrent template: ' . $path);
		}
		return $contents;
	}
	
	protected function _log($level, $message) {
	    if (class_exists('pmLogger')) {
	        
	    } else {
	        //echo '[' . date('Y-m-d H:i:s') . '](' . $level . ') - ' . $message . "\n";
	    }
	}
}
