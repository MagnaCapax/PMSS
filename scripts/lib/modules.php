<?php

/**
 * Class modules
 *
 * Simple loader for optional seedbox modules. Each module is expected to
 * live under `/etc/seedbox/modules/<baseName>` and contain a PHP class with
 * the same name as the file. This mechanism would allow extending the core
 * scripts without editing them directly.
 *
 * At the time of writing there are no references to this class within the
 * repository and the directory does not exist by default. It appears to be
 * an experimental or legacy feature kept for future expansion. The loader
 * contains logic errors and is not instantiated anywhere in the codebase.
 */
class modules {
    /** @var string Subdirectory name for module lookup */
    var $baseName;
    /** @var array Capability hooks (legacy, currently unused). */
    var $capabilities;
    /** @var array Loaded module instances keyed by class name */
    var $modules;
    
    /**
     * Construct a modules loader bound to a specific base directory.
     *
     * The loader will later scan `/etc/seedbox/modules/<baseName>` for PHP
     * files and instantiate classes whose names match the filenames. This
     * keeps optional extension points isolated from the core repository.
     *
     * @param string $baseName     Subdirectory of `/etc/seedbox/modules` to scan.
     * @param array  $capabilities Capability hooks to seek (currently unused).
     *
     * @return void
     */
    public function __construct($baseName, $capabilities = array())
    {
        $this->baseName = $baseName;
        $this->capabilities = $capabilities;
    }
    
    /**
     * Load all PHP files from the module directory and instantiate them.
     * Each file must declare a class whose name matches the filename.
     * Created objects are stored in $this->modules keyed by class name.
     *
     * @return void
     */
    public function seekModules() {
        $directory = '/etc/seedbox/modules/' . $this->baseName;
        if (!file_exists($directory) || !is_dir($directory)) {
            return;
        }
        
        $modulesFound = glob($directory . '/*.php');

        if (!is_array($this->modules)) {
            $this->modules = array();
        }

        foreach ($modulesFound as $moduleFile) {
            include $moduleFile;
            $className = pathinfo($moduleFile, PATHINFO_FILENAME);

            $this->modules[$className] = new $className();
        }
    }


}
