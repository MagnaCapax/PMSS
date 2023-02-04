<?php
if (!file_exists('/etc/seedbox/config/api.localKey')) die('ERROR: API is not enabled.');
/*

function apiAuthorize($receivedAuthorization, $command, $arguments = array()) {
    $masterKey = apiConfig::$masterKey;
    $localKey = apiConfig::$localKey;
    $hostname = file_get_contents('/etc/hostname');
    $allowedHost = apiConfig::$allowedHost; // Which host is allowed to send us commands
    
    if (empty(apiConfig::$allowedHostIp))
        apiConfig::$allowedHostIp = gethostbyname( $allowedHost );  // Get the allowed host's IP from DNS
        
    $allowedHostIp = apiConfig::$allowedHostIp;
    
    if (is_array($arguments)) $serialized = serialize($arguments);
        else $serialized = $arguments;
        

    if ($_SERVER['REMOTE_ADDR'] != $allowedHostIp) die('ERROR: You are not allowed to access this API.');
    if (!empty($_SERVER['REMOTE_HOST']) &&
        !in_array($_SERVER['REMOTE_HOST'], array( $allowedHost, $allowedHostIp ) )
        ) die('ERROR: You are not allowed to access this API'); //  Potential DNS poisoning maybe? I don't know, remote host should match if remote addr matches
    
     
    $key = sha1( $masterKey . $hostname . $command . $serialized . $localKey );
    if ($key != $receivedAuthorization) die('ERROR: Key is not accepted');
    
    return true;

}
*/

class remoteServerApi {
    protected $_localKey;
    protected $_remoteKey;
    public static $debugMode;
    protected $_myHostname;
    protected $_server;
    protected $_authorizedIp;
    
    function __construct() {
        $this->_localKey = file_get_contents('/etc/seedbox/config/api.localKey');   // Authorization key for INCOMING requests
        $this->_remoteKey = file_get_contents('/etc/seedbox/config/api.remoteKey'); // When making calls TO server, or responding to a query this is the authorization key
        $this->_myHostname = trim( file_get_contents('/etc/hostname') );    // My hostname
        $this->_server = file_get_contents('/etc/seedbox/config/api.server');   // Central server to call upon
        
        $this->_authorizedIp = '127.0.0.1';  #TODO Input correct key
        
        $debugMode = file_get_contents('/etc/seedbox/config/api.debug');
        if ($debugMode == 'true') $this->_debugMode = true;
            else $this->_debugMode = false;
            
        if (!file_exists('/var/run/pmss')) {
            mkdir('/var/run/pmss');
            chmod('/var/run/pmss', 0700);
            if ($this->_debugMode == true) $this->_log('runtime', 'PMSS runtime directory was missing, created.');
        }
        
        if (!file_exists('/var/run/pmss/api')) {
            mkdir('/var/run/pmss/api');
            chmod('/var/run/pmss/api', 0760);
            if ($this->_debugMode == true) $this->_log('runtime', 'Jobs directory created and chmodded');
        }
    
    }
    
    function _authorizeCall($remoteIp, $command, $key, $params = array()) { // make the authorization key required for an incoming call
        if ($remoteIp != $this->_authorizedIp) return false;
        
        $authorization = $this->_localKey . $this->_myHostname . $remoteIp . $command;        
        if (count($params) > 0) $authorization .= serialize($params);
        $authorization = sha1($authorization);
        
        if ($authorization == $key) return true;
            else return false;
        
    }
    
    // Fetch jobs from the runtime directory
    function _getJobs() {
        $jobs = glob('/var/run/pmss/api/*.job');
        if (count($jobs) == 0) return array();
        // Does it need sorting so we get timebased?
        
        $jobQueue = array();
        $count = 0;
        foreach($jobs AS $thisJob) {
            $thisJobData = file_get_contents($thisJob);
            $jobQueue[$thisJob] = unserialize( $thisJobData );
            ++$count;
            if ($count == 5) return $jobQueue;  // Max 5 jobs per run!
        }
        
        return $jobQueue;
    }
    
    function _validateJobInput( $command, $key, $params = array() ) {   // Validate the job input to be correct
    
    
    }
    
    function executeJobs() {
        $jobs = $this->_getJobs();
        if (count($jobs) == 0) return true; // "All done" -> nothing to be done
        
        foreach ($jobs AS $thisJobFile => $thisJob) {
            unlink($thisJobFile);
            $thisJobUniqueId = "({$thisJob['remoteIp']}): {$thisJob['command']}/{$thisJob['key']}";
            $authorized = $this->authorizeCall(
                $thisJob['remoteIp'],
                $thisJob['command'],
                $thisJob['key'],
                $thisJob['params']
            );
            if ($authorized == true) $this->_log('jobs', "Authorized job {$thisJobUniqueId}");
                else {
                    $thisJobDataLog = serialize($thisJob);
                    $this->_log('jobs', "Authorization failed for job {$thisJobUniqueId}, data: {$thisJobDataLog}");
                    continue;   // Skip rest of processing
                }
            
            if ($this->_executeCall($thisJobUniqueId, $thisJob['command'], $thisJob['params']))
                $this->_log('jobs', "Execution of {$thisJobUniqueId} successfull");
                else $this->_log('jobs', "Execution of {$thisJobUniqueId} failed");
            
            //$this->_log('jobs', 'Handled job: ' . $thisJobUniqueId);
            
        }
    
    }
    
    protected function _executeCall($callId, $command, $parameters = array()) {
        /**** Map of commands
        Structure is:
        array(
            COMMANDNAME = Array(
                execute = script/command to execute
                params = array(
                    parameter name IN ORDER | always added if value present
                )
            )
        )
        ****/
        
        $commandMap = array(
            'addUser' => array('execute' => '/scripts/addUser.php', 'params' => array('username', 'password', 'memory', 'ram') ),
            'terminateUser' => array('execute' => '/scripts/terminateUser.php', 'params' => array('username', 'batch' => '--batch') ),
            'setupNetwork' => array('execute' => '/scripts/util/setupNetwork.php', 'params' => array()),
            'recreateUser' => array('execute' => '/scripts/recreateUser.php', 'params' => array('username', 'memory', 'ram') )
        );
        
        if (!isset( $commandMap[ $command ] ) ) {
            $this->_log('calls', "Job: {$callId} command {$command} does not exist.");
            return false;
        }
        
        $requiredParams = $commandMap[ $command ]['params'];
        $useParams = array();
        foreach($requiredParams AS $thisParam => $thisValue) {
            if (!empty($thisValue)) {
                $useParams[ $thisValue ];
                continue;   // "Forced" value, always present at this spot
            }
            if (!$parameters[ $thisParam ]) {
                $this->_lob('calls', "Job: {$callId} failed due to missing parameter {$thisParam}");
                return false;
            }
            $useParams[ $parameters[ $thisParam ] ];    // Add parameter to be used
        }
        
        $commandExecute = $commandMap[ $command ]['execute'] . implode(' ', $useParams);
        $this->_log('calls', "Job: {$callId} Execute: {$commandExecute}");
    
    }
    
    function _createRemoteCallAuthorization($command, $data) {
        // $data needs to be string!
        
        $authorization = "{$command}: {$data}|{$this->_server}|{$this->_myHostname}|{$this->_remoteKey}";
        //echo "Auth: \n {$authorization} \n";
        
        return sha1( $authorization );
    
    }
    
    function makeCall($command, $data) {
        $commandMap = array(
            'userTraffic'   =>  array('uri' => 'http://' . $this->_server . '/api/userTraffic.php', 'callbackFunction' => null),
            'userQuota'   => array('uri' => 'http://' . $this->_server . '/api/userQuota.php', 'callbackFunction' => null),
            'serverDiskFree' => array('uri' => 'http://' . $this->_server . '/api/serverDiskFree.php', 'callbackFunction' => null)
        );
        
        if (!isset( $commandMap[$command] )) {
            $this->_log('remote', "API Command {$command} not found");
            return false;
        }
        
        if (is_array($data)) $callData = serialize($data);
            else $callData = $data;
            
        
        $thisUniqueId = md5($command . $callData . date('Y-m-d H:i:s'));
        $thisAuthorization = $this->_createRemoteCallAuthorization($command, $callData);
        
        $this->_log('remote', "API Call ({$thisUniqueId}) command {$command} with call data: {$callData} executing");
        
        $uri = $commandMap[ $command ]['uri'] . '?data=' . $callData . '&a=' . $thisAuthorization;
        $callData = urlencode($callData);
        $response = file_get_contents($uri);
        
        if (!empty($response)) $this->_log('remote', "API Call ({$thisUniqueId}) response: {$response}");

        
        if (!empty( $commandMap[ $command ]['callbackFunction'] )) {
            if ($this->_debugMode == true) $this->_log('remote', "Execute API Callback: {$commandMap[$command]['callbackFunction']}");
            #TODO Add the actual callback - need somekind of module type framework perhaps
        }
        
        
    }
    
    function _log($type, $message) {
        $file = '/var/log/pmss/masterApi.' . $type . '.log';
        $message = date('Y-m-d H:i:s') . '| ' . $message . "\n";
        
        file_put_contents($file, $message, FILE_APPEND);

    }
}