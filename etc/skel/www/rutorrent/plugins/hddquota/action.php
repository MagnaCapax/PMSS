<?php
/**
* Pulsed Media HDD Quota ruTorrent Plugin
*
* Based on the Novik's original HDD space plugin, this plugin measures
* based upon HDD quota in Linux system.
*
* Author: Aleksi Ursin / NuCode
* License: GPL
**/


require_once( '../../php/util.php' );

cachedEcho('{ "total": ' . userQuota::totalSpace() . ', "free": ' . userQuota::freeSpace() . ', "hardLimit": ' . userQuota::$_hardLimitBytes . ' }',"application/json");

class userQuota {

    protected static $_quotaFile = '../../../../.quota';   // Configure this to the file where you have quota data saved, or use shell_exec on parseData
    
    protected static $_usedBytes;
    protected static $_softLimitBytes;
    static $_hardLimitBytes;

    static function freeSpace() {
        if (empty(self::$_usedBytes)) self::parseData();
        
        return self::$_softLimitBytes - self::$_usedBytes;
    }
    
    static function totalSpace() {
        if (empty(self::$_usedBytes)) self::parseData();
        
        return self::$_softLimitBytes;
    }
    
    static protected function _parseSize($field) {
        $field = str_replace('*', '', $field);  // if over quota/bursting * indicates that when using quota command
        $abbreviation = $field[ strlen($field) - 1 ];
        
        $iterations = 0;
        if ($abbreviation == 'K') $iterations = 1;
        if ($abbreviation == 'M') $iterations = 2;
        if ($abbreviation == 'G') $iterations = 3;
        if ($abbreviation == 'T') $iterations = 4;
        if ($iterations != 0) {
            $field = (int) substr($field, 0, strlen($field));
            for($i=0; $i<$iterations; ++$i)
                $field = $field * 1024;
        }
        
        return $field;
    }
    
   static function parseData() {
        $indexOffset = 0;
        // You can also change following file_get_contents to shell_exec to get the quota data :)        
        $data = file_get_contents(self::$_quotaFile);
        /*
        $data = <<<EOF
Disk quotas for user debug (uid 1007):
     Filesystem   space   quota   limit   grace   files   quota   limit   grace
       /dev/md4    594G   1380G   1725G            5642    690k    863k

EOF;
*/
        $data = explode("\n", $data);
        $thisLine = trim( $data[2] );   // Quota line
        
        if (strpos($thisLine, 'disk/by-uuid') !== false or
            strpos($thisLine, '/mapper/') !== false) { $thisLine = $data[3]; $indexOffset = -1; }
            
        $thisLine = preg_replace("/([\s]--)/", "", $thisLine);
        $thisLine = preg_split("/(\s)/", $thisLine, -1, PREG_SPLIT_NO_EMPTY);

        self::$_usedBytes = self::_parseSize( $thisLine[1 + $indexOffset] );
        self::$_softLimitBytes = self::_parseSize( $thisLine[2 + $indexOffset] );
        self::$_hardLimitBytes = self::_parseSize( $thisLine[3 + $indexOffset] );
    }
}
