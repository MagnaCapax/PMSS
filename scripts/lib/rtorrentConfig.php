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
	protected $_resourceConfig;
	protected $_template;
	
	function __construct($resourceConfig = array(), $template = null) {
		if (count($resourceConfig) == 0) {
			$resourceConfig = unserialize(
				file_get_contents('http://pulsedmedia.com/remote/config/rtorrent.php')
			);			
		}
		if ($template == null or empty($template ) )
		    $template = file_get_contents('http://pulsedmedia.com/remote/config/20200207-rtorrentTemplate.txt');
		
		$this->_template = $template;
		$this->_resourceConfig = $resourceConfig;

		$this->_checkResourceConfig();
	}
    
   
	
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
	 * Write config
	 * 
	 * Writes the actual configuration to the file. Pass full .rtorrent.rc file
	 * 
	 * @param string $user
	 * @param string $config
	 * @throws Exception
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
	 * Idempotent config
	 * 
	 * Ensures idempotency by getting user config and comparing it to the config just created.
	 * If not identical, write new.
	 * 
	 * @param string $user
	 * @param string $config
	 */
	public function idempotentConfig($user, $config) {
		$file = '/home/' . $user . '/.rtorrent.rc';
		#TODO Check mtime + permissions first. if root and no "other" write permission + mtime exceeds 2-3 months, we can be 99.9% certain it's right
		$data = file_get_contets( $file );
		
		if ($data !== $config) return $this->writeConfig($user, $config);
	}
    
    /**
     * Read User Config
     *
     * Read a user's config file. Wrapper for readConfig.
     *
     * @param string $user
     */
    public function readUserConfig($user) {
        if (!file_exists("/home/{$user}/.rtorrent.rc") or
            is_dir("/home/{$user}/.rtorrent.rc") ) return false;
        return $this->readConfig( "/home/{$user}/.rtorrent.rc" );
    }
    
    /**
     * Read config
     *
     * This reads and parses .rtorrent.rc config file.
     *
     * @param string file
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
        $directoryBase = '/var/run/pmss/ports';
        $directoryType = '/' . $directoryBase . $type;
        
        if (!file_exists($directoryBase)) mkdir($directoryBase, 0755);
        if (!file_exists($directoryType)) mkdir($directoryType, 0755);
        
        $port = round(rand($rangeStart, $rangeEnd));
        
        if (file_exists($directoryType . '/' . $port)) $this->_configPortPrivate($type, $rangeStart, $rangeEnd);  // Highly doubtfull this will remain in infinite loop under normal conditions
        
        touch($directoryType . '/' . $port);
        
        return $port;
        
        
    }
    
	
	/**
	 * Check config
	 * 
	 * Checks resource config template. This also defines config options for reference
	 * 
	 */
	protected function _checkResourceConfig() {
        // This function basicly does nothing as the config is fetched from server
        
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
	
	protected function _log($level, $message) {
	    if (class_exists('pmLogger')) {
	        
	    } else {
	        //echo '[' . date('Y-m-d H:i:s') . '](' . $level . ') - ' . $message . "\n";
	    }
	}
}
