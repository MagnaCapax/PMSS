<?php

class users {
    var $users;
    
    public function __construct() {
        $this->getUsers();  // sets directly to $this->users as well, so no need to set by return value here
    }
    
    public function __deconstruct() {
        $this->saveUsers();
    }
    
    public function getUsers() {
        if (is_array($this->users)) return $this->users;
        
        $this->users = unserialize( @file_get_contents( '/etc/seedbox/runtime/users' ) );
        if (!is_array($this->users)) $this->users = array();
        
        return $this->users;
    }
    
    public function addUser($username, $data) {
        $this->modifyUser($username, $data);
        $this->saveUsers();
        
    }
    
    // Modify or add an user, works for both
    public function modifyUser($username, $data) {
        if (!isset($data['rtorrentRam']) or
            !isset($data['rtorrentPort']) or
            !isset($data['quota']) or
            !isset($data['quotaBurst'])) return false;

        #TODO Check data validity etc.
        $this->users[ $username ] = $data;
    }
    
    public function saveUsers() {
        if (!is_array($this->users)) return false;
        
        file_put_contents( '/etc/seedbox/runtime/users', serialize( $this->users ) );
        return true;
    }


    public static function systemUsers() {  // Get the users from the system rather than "db"
        $filterList = array(
            'aquota.user',
            'aquota.group',
            'lost+found',
            'ftp',
            'srvadmin',
            'srvapi',
            'pmcseed',
            'pmcdn',
            'srvmgmt'
        );
        $directory = opendir('/home');
        if (!$directory) die('Fatal error with /home');

        $users = array();
        while(false !== ($file = readdir($directory))) {
            if ($file[0] == '.') continue;
            if (strpos($file, 'backup-') === 0) continue;   // skip backup directories
            
            if (!in_array($file, $filterList) &&
                is_dir( '/home/' . $file) ) $users[] = $file;
        }
        return $users;
    }
    
    
    
}
