<?php

class modules {
    var $baseName;
    var $modules;
    
    /**
    * modules constructor
    *
    *
    * @parameter $baseName the name of the modules to be sought
    * @parameter $capabilities hooks to seek
    **/
    public function construct($baseName, $capabilities = array()) {
        $this->baseName = $baseName;
    }
    
    public function seekModules() {
        $directory = '/etc/seedbox/modules/' . $this->baseName;
        if (!file_exists($directory) or
            !is_dir($directory) ) return;
        
        $modulesFound = glob($directory . '/*.php');
        
        if (!is_array($this->modules)) $this->modules = array();
        
        foreach($modulesFound AS $thisModule) {
            include $directory . '/' . $thisModule;
            $className = str_replace('.php', $thisModule);
            
            $this->modules[ $className ] = new $$className;
        
        }
    }


}