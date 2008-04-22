<?php
/* vim: set noai expandtab tabstop=4 softtabstop=4 shiftwidth=4: */

/**
 * System_Daemon turns PHP-CLI scripts into daemons.
 * 
 * PHP version 5
 *
 * @category  System
 * @package   System_Daemon
 * @author    Kevin <kevin@vanzonneveld.net>
 * @copyright 2008 Kevin van Zonneveld (http://kevin.vanzonneveld.net)
 * @license   http://www.opensource.org/licenses/bsd-license.php New BSD Licence
 * @version   SVN: Release: $Id$
 * @link      http://trac.plutonia.nl/projects/system_daemon
 */

/**
 * System_Daemon. Create daemons with practicle functions 
 * like $daemon->start()
 *
 * Requires PHP build with --enable-cli --with-pcntl.
 * Only runs on *NIX systems, because Windows lacks of the pcntl ext.
 *
 * PHP version 5
 *
 * @category  System
 * @package   System_Daemon
 * @author    Kevin <kevin@vanzonneveld.net>
 * @copyright 2008 Kevin van Zonneveld (http://kevin.vanzonneveld.net)
 * @license   http://www.opensource.org/licenses/bsd-license.php New BSD Licence
 * @version   SVN: Release: $Id$
 * @link      http://trac.plutonia.nl/projects/system_daemon
 * 
 */
class System_Daemon
{
    /**
     * Wether or not to run this class standalone, or as a part of PEAR
     *
     * @var boolean
     */
    public $pear = true;
    
    /**
     * Author name, e.g.: Kevin van zonneveld 
     * Required for forging init.d script
     *
     * @var string
     */
    public $authorName;

    /**
     * Author name, e.g.: kevin@vanzonneveld.net 
     * Required for forging init.d script
     *
     * @var string
     */
    public $authorEmail;
  
    /**
     * The application name, e.g.: logparser
     *
     * @var string
     */
    public $appName;

    /**
     * Daemon description, e.g.: Parses logfiles of vsftpd and stores them in MySQL
     * Required for forging init.d script
     *
     * @var string
     */
    public $appDescription;

    /**
     * The home directory of the applications, e.g.: /usr/local/logparser. 
     * Defaults to: SCRIPT_NAME dir
     * Highly recommended to set this yourself though
     *
     * @var string
     */
    public $appDir;

    /**
     * The executeble daemon file, e.g.: logparser.php. 
     * Defaults to: SCRIPT_NAME basename
     * Recommended to set this yourself though
     *
     * @var string
     */
    public $appExecutable;

    /**
     * The pid filepath , e.g.: /var/run/logparser.pid. 
     * Defaults to: /var/run/${appName}.pid
     *
     * @var string
     */
    public $appPidLocation;

    /**
     * The log filepath , e.g.: /var/log/logparser_daemon.log. 
     * Defaults to: /var/log/${appName}_daemon.log
     *
     * @var string
     */
    public $appLogLocation;

    /**
     * The user id under which to run the process, e.g.: 1000
     * Defaults to: 0, root (warning, very insecure!)
     * 
     * @var string
     */
    public $appRunAsUID = 0;

    /**
     * The group id under which to run the process, e.g.: 1000
     * Defaults to: 0, root (warning, very insecure!)
     *
     * @var string
     */
    public $appRunAsGID = 0;
    
    /**
     * Kill daemon if it cannot assume the identity (uid + gid)
     * that you specified.
     * Defaults to: true
     *
     * @var string
     */
    public $appDieOnIdentityCrisis = true;


    
    /**
     * Available log levels
     *
     * @var array
     */
    private $_appLogLevels = array(
        0=> "debug",
        1=> "info",
        2=> "waring",
        3=> "critical",
        4=> "fatal"
    );

    /**
     * The current process identifier
     *
     * @var integer
     */
    private $_processId = 0;

    /**
     * Wether the our daemon is being killed
     *
     * @var boolean
     */
    private $_daemonIsDying = false;    
    
    /**
     * Wether all the variables have been initialized
     *
     * @var boolean
     */
    private $_daemonIsInitialized = false;

    /**
     * Wether the current process is a forked child
     *
     * @var boolean
     */
    private $_processIsChild = false;
    
    /**
     * Cache that holds values of some functions 
     * for performance gain. Easier then doing 
     * if (!isset($this->XXX)) { $this->XXX = $this->XXX(); }
     * every time, in my opinion. 
     *
     * @var array
     */
    private $_intFunctionCache = array();


    
    /**
     * Constructs a System_Daemon object.
     *
     * @param string  $appName The unix name of your daemon application.
     * @param boolean $pear    Wether or not to run as a part of pear.
     *
     * @see start()
     */
    public function __construct($appName, $pear = true)
    {
        $this->appName = $appName;
        $this->pear    = $pear;
        
        // to run as a part of PEAR
        if ( $this->pear ) {
            // conditional so use include
            include_once "PEAR.php";
            include_once "PEAR/Exception.php";
            include_once "System/Daemon/Exception.php";
        }
        
        // check the PHP configuration
        if (!defined("SIGHUP")) {
            trigger_error("PHP is compiled without --enable-pcntl directive\n", 
                E_USER_ERROR);
        }        
        
        if ((php_sapi_name() != 'cli')) {
            trigger_error("You can only create daemon from the command line\n", 
                E_USER_ERROR);
        }
        
        ini_set("max_execution_time", "0");
        ini_set("max_input_time", "0");
        ini_set("memory_limit", "1024M");
        set_time_limit(0);        
        ob_implicit_flush();
    }//end __construct()

    /**
     * Spawn daemon process.
     *
     * @return void
     */
    public function start()
    {
        // initialize & check variables
        $this->_daemonInit();

        // become daemon
        $this->_daemonBecome();

    }//end start()

    /**
     * Stop daemon process.
     *
     * @return void
     */
    public function stop()
    {
        $this->_appLogger(1, "stopping ".$this->appName." daemon", 
            __FILE__, __CLASS__, __FUNCTION__, __LINE__);
        $this->_daemonDie();
    }//end stop()

    /**
     * Signal handler function
     *
     * @param integer $signo The posix signal received.
     * 
     * @return void
     */
    public function daemonHandleSig( $signo )
    {
        // Must be public or else will throw error: 
        // Fatal error: Call to private method 
        // Daemon::daemonHandleSig() from context '' 
        $this->_appLogger(0, $this->appName." daemon received signal: ".$signo, 
            __FILE__, __CLASS__, __FUNCTION__, __LINE__);
        switch ($signo) {
        case SIGTERM:
            // handle shutdown tasks
            if ($this->daemonInBackGround()) {
                $this->_daemonDie();
            } else {
                exit;
            }
            break;
        case SIGHUP:
            // handle restart tasks
            $this->_appLogger(1, $this->appName." daemon received signal: restart", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            break;
        case SIGCHLD:
            $this->_appLogger(1, $this->appName." daemon received signal: hold", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            while (pcntl_wait($status, WNOHANG OR WUNTRACED) > 0) {
                usleep(1000);
            }
            break;
        default:
            // handle all other signals
            break;
        }
    }//end daemonHandleSig()

    /**
     * Wether the class is already running in the background
     * 
     * @return boolean
     */
    public function daemonInBackground()
    {
        return $this->_processIsChild;
    }//end daemonInBackground()
    
    /**
     * Wether the our daemon is being killed, you might 
     * want to include this in your loop
     * 
     * @return boolean
     */
    public function daemonIsDying()
    {
        return $this->_daemonIsDying;
    }//end daemonIsDying()
    
    /**
     * Returns an array(main, distro, version) of the OS it's executed on
     *
     * @return array
     */
    public function osDetermine()
    {
        // this will not change during 1 run, so just cache the result
        if (!isset($this->_intFunctionCache[__FUNCTION__])) {
            $osv_files = array(
                "RedHat"=>"/etc/redhat-release",
                "SuSE"=>"/etc/SuSE-release",
                "Mandrake"=>"/etc/mandrake-release",
                "Debian"=>"/etc/debian_version",
                "Ubuntu"=>"/etc/lsb-release"
            );

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $main   = "Windows";
                $distro = PHP_OS;
            } else {
                $main = php_uname('s');
                foreach ($osv_files as $distro=>$osv_file) {
                    if (file_exists($osv_file)) {
                        $version = trim(file_get_contents($osv_file));
                        break;
                    }
                }
            }

            $this->_intFunctionCache[__FUNCTION__] = compact("main", "distro", "version");
        }

        return $this->_intFunctionCache[__FUNCTION__];
    }//end osDetermine()  
    
    /**
     * Writes an: 'init.d' script on the filesystem
     *
     * @param bolean $overwrite May the existing init.d file be overwritten?
     * 
     * @return boolean
     */
    public function osInitDWrite($overwrite = false)
    {
        // up to date filesystem information
        clearstatcache();
        
        // collect init.d path
        $osInitDLocation = $this->osInitDLocation();
        if (!$osInitDLocation) {
            // explaining errors should have been generated by osInitDLocation() 
            // already
            return false;
        }
        
        // collect init.d body
        $initd = $this->osInitDForge();
        if (!$initd) {
            // explaining errors should have been generated by osInitDForge() 
            // already
            return false;
        }
        
        // as many safety checks as possible
        if (!$overwrite && file_exists(($osInitDLocation))) {
            $this->_appLogger(1, "init.d script already exists", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        } 
        if (!is_dir($dir = dirname($osInitDLocation))) {
            $this->_appLogger(3, "init.d directory: '".$dir."' does not ".
                "exist. Can this be a correct path?", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        if (!is_writable($dir = dirname($osInitDLocation))) {
            $this->_appLogger(3, "init.d directory: '".$dir."' cannot be ".
                "written to. Check the permissions", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        
        if (!file_put_contents($osInitDLocation, $initd)) {
            $this->_appLogger(3, "init.d file: '".$osInitDLocation."' cannot be ".
                "written to. Check the permissions", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        
        if (!chmod($osInitDLocation, 0777)) {
            $this->_appLogger(3, "init.d file: '".$osInitDLocation."' cannot be ".
                "chmodded. Check the permissions", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        } 
        
        return true;
    }//end osInitDWrite() 
    
    /**
     * Returns an: 'init.d' script path as a string. For now only Debian & Ubuntu
     *
     * @return mixed boolean on failure, string on success
     */
    public function osInitDLocation()
    {
        // this will not change during 1 run, so just cache the result
        if (!isset($this->_intFunctionCache[__FUNCTION__])) {
            $osInitDLocation = false;
            
            // collect OS information
            list($main, $distro, $version) = array_values($this->osDetermine());
            
            // where to collect the skeleton (template) for our init.d script
            switch (strtolower($distro)){
            case "debian":
            case "ubuntu":
                // here it is for debian systems
                $osInitDLocation = "/etc/init.d/".$this->appName;
                break;
            default:
                // not supported yet
                $this->_appLogger(2, "skeleton retrieval for OS: ".$distro.
                    " currently not supported ", 
                    __FILE__, __CLASS__, __FUNCTION__, __LINE__);
                return false;
            }
            
            $this->_intFunctionCache[__FUNCTION__] = $osInitDLocation;
        }
        
        return $this->_intFunctionCache[__FUNCTION__];
    }//end osInitDLocation()
    
    /**
     * Returns an: 'init.d' script as a string. for now only Debian & Ubuntu
     *
     * @return mixed boolean on failure, string on success
     */
    public function osInitDForge()
    {
        // initialize & check variables
        $this->_daemonInit();
        $skeleton_filepath = false;

        // sanity
        $daemon_filepath = $this->appDir."/".$this->appExecutable;
        if (!file_exists($daemon_filepath)) {
            $this->_appLogger(3, "unable to forge skeleton for non existing ".
                "daemon_filepath: ".$daemon_filepath.", try setting a valid ".
                "appDir or appExecutable", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }

        if (!is_executable($daemon_filepath)) {
            $this->_appLogger(3, "unable to forge skeleton for non executable ".
                "daemon_filepath: ".$daemon_filepath.", try chmodding ".
                "your daemon", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        
        if (!$this->authorName) {
            $this->_appLogger(3, "unable to forge skeleton for non existing ".
                "authorName: ".$this->authorName."", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        if (!$this->authorEmail) {
            $this->_appLogger(3, "unable to forge skeleton for non existing ".
                "authorEmail: ".$this->authorEmail."", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        if (!$this->appDescription) {
            $this->_appLogger(3, "unable to forge skeleton for non existing ".
                "appDescription: ".$this->appDescription."", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }

        // collect OS information
        list($main, $distro, $version) = array_values($this->osDetermine());

        // where to collect the skeleton (template) for our init.d script
        switch (strtolower($distro)){
        case "debian":
        case "ubuntu":
            // here it is for debian based systems
            $skeleton_filepath = "/etc/init.d/skeleton";
            break;
        default:
            // not supported yet
            $this->_appLogger(2, "skeleton retrieval for OS: ".$distro.
                " currently not supported ", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
            break;
        }

        // open skeleton
        if (!$skeleton_filepath || !file_exists($skeleton_filepath)) {
            $this->_appLogger(2, "skeleton file for OS: ".$distro." not found at: ".
                $skeleton_filepath, 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        } elseif ($skeleton = file_get_contents($skeleton_filepath)) {
            // skeleton opened, set replace vars
            switch (strtolower($distro)){
            case "debian":
            case "ubuntu":                
                $replace = array(
                    "Foo Bar" => $this->authorName,
                    "foobar@baz.org" => $this->authorEmail,
                    "daemonexecutablename" => $this->appName,
                    "Example" => $this->appName,
                    "skeleton" => $this->appName,
                    "/usr/sbin/\$NAME" => $daemon_filepath,
                    "Description of the service"=> $this->appDescription,
                    " --name \$NAME" => "",
                    "--options args" => "",
                    "# Please remove the \"Author\" ".
                        "lines above and replace them" => "",
                    "# with your own name if you copy and modify this script." => ""
                );
                break;
            default:
                // not supported yet
                $this->_appLogger(2, "skeleton modification for OS: ".$distro.
                    " currently not supported ", 
                    __FILE__, __CLASS__, __FUNCTION__, __LINE__);
                return false;
                break;
            }

            // replace skeleton placeholders with actual daemon information
            $skeleton = str_replace(array_keys($replace), 
                array_values($replace), 
                $skeleton);

            // return the forged init.d script as a string
            return $skeleton;
        }
    }//end osInitDForge()

    /**
     * Initializes, sanitizes & defaults unset variables
     *
     * @return boolean
     */
    private function _daemonInit() 
    {
        if ($this->_daemonIsInitialized) {
            return true;
        }

        $this->_daemonIsInitialized = true;

        if (!$this->_strisunix($this->appName)) {
            $safe_name = $this->_strtounix($this->appName);
            $this->_appLogger(4, "'".$this->appName."' is not a valid daemon name, ".
                "try using something like '".$safe_name."' instead", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        if (!$this->appPidLocation) {
            $this->appPidLocation = "/var/run/".$this->appName.".pid";
        }
        if (!$this->appLogLocation) {
            $this->appLogLocation = "/var/log/".$this->appName."_daemon.log";
        }
        
        $this->_processId     = 0;
        $this->_processIsChild = false;
        if (!is_numeric($this->appRunAsUID)) {
            $this->_appLogger(4, "".$this->appName." daemon has invalid appRunAsUID: ".
                $this->appRunAsUID."", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        if (!is_numeric($this->appRunAsGID)) {
            $this->_appLogger(4, "".$this->appName." daemon has invalid appRunAsGID: ".
                $this->appRunAsGID."", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        if (!$this->appDir) {
            $this->appDir = dirname($_SERVER["SCRIPT_FILENAME"]);
        }
        if (!$this->appExecutable) {
            $this->appExecutable = basename($_SERVER["SCRIPT_FILENAME"]);
        }

        if (!is_dir($this->appDir)) {
            $this->_appLogger(4, "".$this->appName." daemon has invalid appDir: ".
                $this->appDir."", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        
        return true;
    }//end _daemonInit()

    /**
     * Put the running script in background
     *
     * @return void
     */
    private function _daemonBecome() 
    {

        $this->_appLogger(1, "starting ".$this->appName." daemon, using logfile: ". 
            $this->appLogLocation, 
            __FILE__, __CLASS__, __FUNCTION__, __LINE__);
        
        // important for daemons
        // see http://nl2.php.net/manual/en/function.pcntl-signal.php
        declare(ticks = 1);

        // setup signal handlers
        pcntl_signal(SIGCONT, array($this, "daemonHandleSig"));
        pcntl_signal(SIGALRM, array($this, "daemonHandleSig"));
        pcntl_signal(SIGINT, array($this, "daemonHandleSig"));
        pcntl_signal(SIGABRT, array($this, "daemonHandleSig"));
        
        pcntl_signal(SIGTERM, array($this, "daemonHandleSig"));
        pcntl_signal(SIGHUP, array($this, "daemonHandleSig"));
        pcntl_signal(SIGUSR1, array($this, "daemonHandleSig"));
        pcntl_signal(SIGCHLD, array($this, "daemonHandleSig"));

        // allowed?
        if ($this->_daemonIsRunning()) {
            $this->_appLogger(4, "".$this->appName." daemon is still running. ".
                "exiting", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
        }

        // fork process!
        if (!$this->_daemonFork()) {
            $this->_appLogger(4, "".$this->appName." daemon was unable to fork", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
        }

        // assume specified identity (uid & gid)
        if (!posix_setuid($this->appRunAsUID) || !posix_setgid($this->appRunAsGID)) {
            if ($this->appDieOnIdentityCrisis) {
                $lvl = 4;
                $swt = "on";
            } else {
                $lvl = 3;
                $swt = "off";
            }
            $this->_appLogger($lvl, "".$this->appName." daemon was unable assume ".
                "identity (uid=".$this->appRunAsUID.", gid=".$this->appRunAsGID.") ".
                "and appDieOnIdentityCrisis was ". $swt, 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
        }

        // additional PID succeeded check
        if (!is_numeric($this->_processId) || $this->_processId < 1) {
            $this->_appLogger(4, "".$this->appName." daemon didn't have a valid ".
                "pid: '".$this->_processId."'", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
        } else {
            if (!file_put_contents($this->appPidLocation, $this->_processId)) {
                $this->_appLogger(4, "".$this->appName." daemon was unable to write ".
                    "to pidfile: ".$this->appPidLocation."", 
                    __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            }
        }

        // change dir & umask
        @chdir($this->appDir);
        @umask(0);
    }//end _daemonBecome()

    /**
     * Check if a previous process with same pidfile was already running
     *
     * @return boolean
     */
    private function _daemonIsRunning() 
    {
        if(!file_exists($this->appPidLocation)) return false;
        $_processId = @file_get_contents($this->appPidLocation);

        if ($_processId !== false) {
            if (!posix_kill(intval($_processId), 0)) {
                // not responding so unlink pidfile
                @unlink($this->appPidLocation);
                $this->_appLogger(2, "".$this->appName." daemon orphaned pidfile ".
                    "found and removed: ".$this->appPidLocation, 
                    __FILE__, __CLASS__, __FUNCTION__, __LINE__);
                return false;
            } else {
                return true;
            }
        } else {
            return false;
        }
    }//end _daemonIsRunning()

    /**
     * Fork process and kill parent process, the heart of the 'daemonization'
     *
     * @return boolean
     */
    private function _daemonFork()
    {
        $this->_appLogger(0, "forking ".$this->appName." daemon", 
            __FILE__, __CLASS__, __FUNCTION__, __LINE__);

        $pid = pcntl_fork();
        if ( $pid == -1 ) {
            // error
            $this->_appLogger(3, "".$this->appName." daemon could not be forked", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        } else if ($pid) {
            // parent
            $this->_appLogger(0, "ending ".$this->appName." parent process", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            // die without attracting attention
            exit();
        } else {
            // child
            $this->_processIsChild = true;
            $this->_daemonIsDying  = false;
            $this->_processId      = posix_getpid();
            return true;
        }
    }//end _daemonFork()

    /**
     * Return what the current process is: child or parent
     *
     * @return string
     */
    private function _daemonWhatIAm()
    {
        return ($this->daemonInBackground()?"child":"parent");
    }//end _daemonWhatIAm()

    /**
     * Sytem_Daemon::_daemonDie()
     * Kill the daemon
     *
     * @return void
     */
    private function _daemonDie()
    {
        if (!$this->daemonIsDying()) {
            $this->_daemonIsDying = true;
            if (!$this->daemonInBackground() || !file_exists($this->appPidLocation)) {
                $this->_appLogger(1, "Not stopping ".$this->appName.", daemon was not running",
                    __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            } else {
                @unlink($this->appPidLocation);
            }
            exit();
        }
    }//end _daemonDie()


    
    /**
     * Check if a string has a unix proof format (stripped spaces, 
     * special chars, etc)
     *
     * @param string $str What string to test for unix compliance
     * 
     * @return boolean
     */   
    private function _strisunix( $str )
    {
        return preg_match('/^[a-z0-9_]+$/', $str);
    }//end _strisunix()

    /**
     * Convert a string to a unix proof format (strip spaces, 
     * special chars, etc)
     * 
     * @param string $str What string to make unix compliant
     * 
     * @return string
     */
    private function _strtounix( $str )
    {
        return preg_replace('/[^0-9a-z_]/', '', strtolower($str));
    }//end _strtounix()

    /**
     * Almost every deamon requires a log file, this function can
     * facilitate that. Also handles class errors, chooses either
     * PEAR handling or PEAR-independant handling. 
     * It logs a string according to error levels specified in array: 
     * log_levels (4 is fatal and handles daemon's death)
     *
     * @param integer $level    What function the log record is from
     * @param string  $str      The log record
     * @param string  $file     What code file the log record is from
     * @param string  $class    What class the log record is from
     * @param string  $function What function the log record is from
     * @param integer $line     What code line the log record is from
     *  
     * @return void
     * @see _appLogLevels
     * @see appLogLocation
     */
    private function _appLogger($level, $str, $file = false, $class = false, 
        $function = false, $line = false)
    {
        if ( function_exists("debug_backtrace") && ($file == false || 
            $class == false || $function == false || $line == false) ) {
            // saves resources if arguments are passed.
            // but by using debug_backtrace() it still works 
            // if someone forgets to pass them
            $dbg_bt   = @debug_backtrace();
            $class    = (isset($dbg_bt[1]["class"])?$dbg_bt[1]["class"]:"");
            $function = (isset($dbg_bt[1]["function"])?$dbg_bt[1]["function"]:"");
            $file     = $dbg_bt[0]["file"];
            $line     = $dbg_bt[0]["line"];
        }

        // determine what process the log is originating from and forge a logline
        $str_level = $this->_appLogLevels[$level];
        $str_pid   = str_pad("from[".$this->_daemonWhatIAm()."".posix_getpid()."] ", 
            19, " ", STR_PAD_RIGHT) ;
        $log_line  = str_pad($str_level."", 8, " ", STR_PAD_LEFT)." " .
            $str_pid." : ".$str; 
        //echo $log_line."\n";
        file_put_contents($this->appLogLocation, $log_line."\n", FILE_APPEND);
        
        if ($level > 1) {
            if ($this->pear) {
                PEAR::raiseError($log_line);
            }
        }
        
        if ($level == 4) {
            // to run as a part of pear
            if ($this->pear) {            
                throw new System_Daemon_Exception($log_line);
            }
            $this->_daemonDie();
        }
    }//end _appLogger()
}//end class


?>