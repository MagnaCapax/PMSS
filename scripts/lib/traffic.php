<?php

class trafficStatistics {

	public function getData($user, $timePeriod = 5050) {
		return trim( `tail -n{$timePeriod} /var/log/pmss/traffic/{$user} 2>/dev/null` );
	}
    
    public function parseLine($thisLine) {
        $thisLine = trim( $thisLine );        
        if (empty($thisLine)) return false;
        if (strpos($thisLine, ': ') === false) return false;
        $thisLine = explode(': ', $thisLine);
        
        if (count($thisLine) != 2) return false;    // Erroneous data, too many parts :
        $thisTime = strtotime( trim($thisLine[0]) );
        $thisData = trim($thisLine[1]) / 1024 / 1024;   // Transform from bytes to megabytes
        
        if ($thisData > 150000 ) { return false; }    // Pruning erroneous data, 7500Mb in max 6 minutes or so? Yeap.
        
        return array(
            'data' => $thisData,
            'timestamp' => $thisTime
        );
    }
    
    public function saveUserTraffic( $user, $data ) {
        $trafficDataFile = '.trafficData';
        $trafficDataFileVar = $user;
        
        if (strpos($user, '-localnet')) {
                $trafficDataFile = '.trafficDataLocal';
                $trafficDataFileVar = $user;    // keep the -localnet
                $user = str_replace('-localnet', '', $user);
        }
        
        $saveData = serialize( $data );
        if (file_exists("/home/{$user}")) file_put_contents("/home/{$user}/{$trafficDataFile}", $saveData);
        if (!file_exists('/var/run/pmss/trafficStats') ) mkdir('/var/run/pmss/trafficStats', 0600);
        
        file_put_contents('/var/run/pmss/trafficStats/' . $trafficDataFileVar, $saveData);
    
    }

}
