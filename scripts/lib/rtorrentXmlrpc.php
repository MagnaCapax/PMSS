<?php
/**
 * rTorrent xmlrpc scgi class
 *
 * Ment for communicating with rTorrent instance, sending commands, receiving data.
 *
 * @author Aleksi Ursin
 * @copyright NuCode 2014
 * @since 13/09/2014
 * @version 0.7
 */
 
 class rtorrentXmlrpc {
 
    protected $_host;
    protected $_port;
    
    public function __construct($host, $port) {
        $this->_host = $host;
        $this->_port = $port;
    }
    
    function setUploadRate($rate) {
            return $this->makeCall(
                $this->formatCall('set_upload_rate',
                    array($rate)
                )
            );
    }
    
    function setDownloadRate($rate) {
            return $this->makeCall(
                $this->formatCall('set_download_rate',
                    array($rate)
                )
            );
    }
 
    function formatCall($call, $params=array()) {
        $result = '<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>';
        $result .= $call;
        $result .= "</methodName><params>\r\n";
        
        if (count($params) != 0 &&
            !empty($params)) {
         
            foreach($params AS $thisParam) {
                $type = '';
                if (is_int($thisParam)) $type='i4';
                if (is_double($thisParam)) $type='i8';
                if (empty($type)) $type='string';
                
                $result .= '<param><value>';
                $result .= "<{$type}>{$thisParam}</{$type}>";
                $result .= '</value></param>';
                $result .= "\r\n";
            }
        }
        
        $result .= "</params></methodCall>";
        return $result;
    }

    function makeCall($data) {
        $socket = fsockopen($this->_host, $this->_port, $errorNumber, $errorString, 5);
        $header = "CONTENT_LENGTH\x0" . strlen($data) . "\x0"."SCGI\x0"."1\x0";
        $sendData = strlen($header) . ":{$header},{$data}";
        //var_dump($sendData);
        fwrite($socket, $sendData, strlen($sendData));
        
        $result = '';
        $readBytes = '';
        while($readBytes = fread($socket, 4096))
            $result .= $readBytes;
            
        fclose($socket);
        return $result;

    }
 
 }