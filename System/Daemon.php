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

// Autoloader borrowed from PHP_CodeSniffer, see function for credits
spl_autoload_register(array("System_Daemon", "autoload"));

// Make these corresponding with PEAR
// Ensures compatibility while maintaining independency
define("SYSTEM_DAEMON_LOG_EMERG",    0);     /* System is unusable */
define("SYSTEM_DAEMON_LOG_ALERT",    1);     /* Immediate action required */
define("SYSTEM_DAEMON_LOG_CRIT",     2);     /* Critical conditions */
define("SYSTEM_DAEMON_LOG_ERR",      3);     /* Error conditions */
define("SYSTEM_DAEMON_LOG_WARNING",  4);     /* Warning conditions */
define("SYSTEM_DAEMON_LOG_NOTICE",   5);     /* Normal but significant */
define("SYSTEM_DAEMON_LOG_INFO",     6);     /* Informational */
define("SYSTEM_DAEMON_LOG_DEBUG",    7);     /* Debug-level messages */

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
abstract class System_Daemon
{
    /**
     * Wether or not to run this class standalone, or as a part of PEAR
     *
     * @var boolean
     */
    static public $usePEAR = true;
    
    /**
     * Accepts a PEAR_Log instance to handle all logging.
     * This will replace System_Daemon's own logging facility 
     *
     * @var mixed
     */
    static public $usePEARLogInstance = false;
    
    /**
     * Author name, e.g.: Kevin van zonneveld 
     * Required for forging init.d script
     *
     * @var string
     */
    static public $authorName;

    /**
     * Author name, e.g.: kevin@vanzonneveld.net 
     * Required for forging init.d script
     *
     * @var string
     */
    static public $authorEmail;
  
    /**
     * The application name, e.g.: logparser
     *
     * @var string
     */
    static public $appName;

    /**
     * Daemon description, e.g.: Parses logfiles of vsftpd and stores them in MySQL
     * Required for forging init.d script
     *
     * @var string
     */
    static public $appDescription;

    /**
     * The home directory of the applications, e.g.: /usr/local/logparser
     * Defaults to: SCRIPT_NAME dir
     * Highly recommended to set this yourself though
     *
     * @var string
     */
    static public $appDir;

    /**
     * The executeble daemon file, e.g.: logparser.php
     * Defaults to: SCRIPT_NAME basename
     * Recommended to set this yourself though
     *
     * @var string
     */
    static public $appExecutable;

    /**
     * The pid filepath , e.g.: /var/run/logparser.pid
     * Defaults to: /var/run/${appName}.pid
     *
     * @var string
     */
    static public $appPidLocation;

    /**
     * The log filepath , e.g.: /var/log/logparser_daemon.log
     * Defaults to: /var/log/${appName}_daemon.log
     *
     * @var string
     */
    static public $logLocation;

    /**
     * The user id under which to run the process, e.g.: 1000
     * Defaults to: 0, root (warning, very insecure!)
     * 
     * @var string
     */
    static public $appRunAsUID = 0;

    /**
     * The group id under which to run the process, e.g.: 1000
     * Defaults to: 0, root (warning, very insecure!)
     *
     * @var string
     */
    static public $appRunAsGID = 0;
    
    /**
     * Kill daemon if it cannot assume the identity (uid + gid)
     * that you specified.
     * Defaults to: true
     *
     * @var string
     */
    static public $appDieOnIdentityCrisis = true;

    /**
     * Messages below this log level are ignored (not written 
     * to logfile, not displayed on screen) 
     * Defaults to: 6, info. Meaning info & higher are logged.
     *
     * @var integer
     */
    static public $logVerbosity = SYSTEM_DAEMON_LOG_INFO;
    
    /**
     * Some ini settings to help the daemon. These are public
     * and may be overwritten.
     *
     * @var array
     */    
    static public $iniSettings = array(
        "max_execution_time" => "0",
        "max_input_time" => "0",
        "memory_limit" => "128M"
    );    
    
    
    
    /**
     * Available log levels
     *
     * @var array
     */
    static private $_logLevels = array(
        SYSTEM_DAEMON_LOG_EMERG => "emerg",
        SYSTEM_DAEMON_LOG_ALERT => "alert",
        SYSTEM_DAEMON_LOG_CRIT => "crit",
        SYSTEM_DAEMON_LOG_ERR => "err",
        SYSTEM_DAEMON_LOG_WARNING => "warning",
        SYSTEM_DAEMON_LOG_NOTICE => "notice",
        SYSTEM_DAEMON_LOG_INFO => "info",
        SYSTEM_DAEMON_LOG_DEBUG => "debug"        
    );
    
    /**
     * Available signal handlers
     * setSigHandler can overwrite these values individually.
     *
     * @var array
     * @see setSigHandler()
     */
    static private $_sigHandlers = array(
        SIGCONT => array("System_Daemon", "daemonHandleSig"),
        SIGALRM => array("System_Daemon", "daemonHandleSig"),
        SIGINT => array("System_Daemon", "daemonHandleSig"),
        SIGABRT => array("System_Daemon", "daemonHandleSig"),
        SIGTERM => array("System_Daemon", "daemonHandleSig"),
        SIGHUP => array("System_Daemon", "daemonHandleSig"),
        SIGUSR1 => array("System_Daemon", "daemonHandleSig"),
        SIGCHLD => array("System_Daemon", "daemonHandleSig")
    );
    
    /**
     * The current process identifier
     *
     * @var integer
     */
    static protected $processId = 0;

    /**
     * Wether the our daemon is being killed
     *
     * @var boolean
     */
    static protected $daemonIsDying = false;    
    
    /**
     * Wether all the variables have been initialized
     *
     * @var boolean
     */
    static protected $daemonIsInitialized = false;

    /**
     * Wether the current process is a forked child
     *
     * @var boolean
     */
    static protected $processIsChild = false;
    
    /**
     * Wether SAFE_MODE is on or off. This is important for ini_set
     * behavior
     *
     * @var boolean
     */
    static protected $safe_mode = false;
    
    /**
     * Cache that holds values of some functions 
     * for performance gain. Easier then doing 
     * if (!isset(self::$XXX)) { self::$XXX = self::XXX(); }
     * every time, in my opinion. 
     *
     * @var array
     */
    static private $_intFunctionCache = array();
    
    /**
     * Autoload static method for loading classes and interfaces.
     * Code from the PHP_CodeSniffer package by Greg Sherwood and 
     * Marc McIntyre
     *
     * @param string $className The name of the class or interface.
     *
     * @return void
     */
    static public function autoload($className)
    {
        $parent     = 'System_';
        $parent_len = strlen($parent);
        if (substr($className, 0, $parent_len) == $parent) {
            $newClassName = substr($className, $parent_len);
        } else {
            $newClassName = $className;
        }

        $path = str_replace('_', '/', $newClassName).'.php';

        if (is_file(dirname(__FILE__).'/'.$path) === true) {
            // Check standard file locations based on class name.
            include dirname(__FILE__).'/'.$path;
        } else {
            // Everything else.
            include $path;
        }

    }//end autoload()
    
    
    /**
     * Spawn daemon process.
     * 
     * @param boolean $usePEAR Wether or not to run as a part of pear.
     * 
     * @return void
     * @see stop()
     * @see _daemonInit()
     * @see _daemonBecome()
     */
    static public function start($usePEAR = true)
    {
        self::$usePEAR    = $usePEAR;
        
        // to run as a part of PEAR
        if ( self::$usePEAR ) {
            include_once "PEAR.php";
            include_once "PEAR/Exception.php";
            
            if (class_exists('System_Daemon_Exception', true) === false) {
                throw new Exception('Class System_Daemon_Exception not found');
            }            
        }
        
        // check the PHP configuration
        if (!defined("SIGHUP")) {
            $msg = "PHP is compiled without --enable-pcntl directive";
            if ( self::$usePEAR ) {
                throw new System_Daemon_Exception($msg);
            } else {
                trigger_error($msg, E_USER_ERROR);
            }
        }        
        
        // check for CLI
        if ((php_sapi_name() != 'cli')) {
            $msg = "You can only create daemon from the command line";
            if ( self::$usePEAR ) {
                throw new System_Daemon_Exception($msg);
            } else {
                trigger_error($msg, E_USER_ERROR);
            }
        }
        
        // initialize & check variables
        self::_daemonInit();
        
        //self::log(SYSTEM_DAEMON_LOG_EMERG, "test");
        
        // become daemon
        self::_daemonBecome();

    }//end start()

    /**
     * Stop daemon process.
     *
     * @return void
     * @see start()
     */
    static public function stop()
    {
        self::log(SYSTEM_DAEMON_LOG_INFO, "stopping ".self::$appName." daemon", 
            __FILE__, __CLASS__, __FUNCTION__, __LINE__);
        self::_daemonDie();
    }//end stop()
    
    /**
     * Overrule or add signal handlers.
     *
     * @param string $signal  Signal constant (e.g. SIGHUP)
     * @param mixed  $handler Which handler to call on signal
     * 
     * @return boolean
     * @see $_sigHandlers
     */
    static public function setSigHandler($signal, $handler)
    {
        if (!isset(self::$_sigHandlers[$signal])) {
            // the signal should be defined already
            self::log(SYSTEM_DAEMON_LOG_NOTICE, "You can only overrule a ".
                "handler that has been defined already.", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        
        // overwrite on existance
        self::$_sigHandlers[$signal] = $handler;
        return true;
    }//end setSigHandler()
    
    /**
     * Almost every deamon requires a log file, this function can
     * facilitate that. Also handles class-generated errors, chooses 
     * either PEAR handling or PEAR-independant handling, depending on:
     * self::$usePEAR
     * 
     * It logs a string according to error levels specified in array: 
     * self::$_logLevels (4 is fatal and handles daemon's death)
     *
     * @param integer $level    What function the log record is from
     * @param string  $str      The log record
     * @param string  $file     What code file the log record is from
     * @param string  $class    What class the log record is from
     * @param string  $function What function the log record is from
     * @param integer $line     What code line the log record is from
     *
     * @throws System_Daemon_Exception  
     * @return boolean
     * @see _logLevels
     * @see logLocation
     */
    static public function log($level, $str, $file = false, $class = false, 
        $function = false, $line = false)
    {
        if ($level < self::$logVerbosity) {
            return true;
        }
        
        
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
        $str_date  = "[".date("M d H:i:s")."]"; 
        $str_ident = "@".substr(self::_daemonWhatIAm(), 0, 1)."-".posix_getpid();
        $str_level = str_pad(self::$_logLevels[$level]."", 8, " ", STR_PAD_LEFT);
        $log_line  = $str_date." ".$str_level.": ".$str; // $str_ident
        
        if ($level < SYSTEM_DAEMON_LOG_DEBUG) {
            if (!self::daemonIsInBackground() || !is_writable(self::$logLocation)) {
                // it's okay to echo if you're running as a fore-ground process
                // maybe the command to write an init.d file was issued.
                // in such a case it's important to echo failures to the 
                // STDOUT
                echo $log_line."\n";
            }
            
            // write to logfile
            file_put_contents(self::$logLocation, $log_line."\n", FILE_APPEND);
        }
        
        if ($level < SYSTEM_DAEMON_LOG_WARNING) {
            if (self::$usePEAR) {
                throw new System_Daemon_Exception($log_line);
            }
            if ($level == SYSTEM_DAEMON_LOG_EMERG) {
                self::_daemonDie();
            }
        }
        
        return true;
        
    }//end log()    
    
    /**
     * Signal handler function
     * This is the default signal handler. You can overrule
     * individual signals with the setSigHandler() method
     *
     * @param integer $signo The posix signal received.
     * 
     * @return void
     * @see setSigHandler()
     * @see $_sigHandlers
     */
    static public function daemonHandleSig( $signo )
    {
        // Must be public or else will throw a 
        // fatal error: Call to private method 
         
        self::log(SYSTEM_DAEMON_LOG_DEBUG, self::$appName.
            " daemon received signal: ".$signo, 
            __FILE__, __CLASS__, __FUNCTION__, __LINE__);
        switch ($signo) {
        case SIGTERM:
            // handle shutdown tasks
            if (self::daemonIsInBackground()) {
                self::_daemonDie();
            } else {
                exit;
            }
            break;
        case SIGHUP:
            // handle restart tasks
            self::log(SYSTEM_DAEMON_LOG_INFO, self::$appName.
                " daemon received signal: restart", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            break;
        case SIGCHLD:
            self::log(SYSTEM_DAEMON_LOG_INFO, self::$appName.
                " daemon received signal: hold", 
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
    static public function daemonIsInBackground()
    {
        return self::$processIsChild;
    }//end daemonIsInBackground()
    
    /**
     * Wether the our daemon is being killed, you might 
     * want to include this in your loop
     * 
     * @return boolean
     */
    static public function daemonIsDying()
    {
        return self::$daemonIsDying;
    }//end daemonIsDying()
    
    /**
     * Uses OS class to writes an: 'init.d' script on the filesystem
     *  
     * @param bolean $overwrite May the existing init.d file be overwritten?
     * 
     * @return boolean
     */
    static public function osInitDWrite( $overwrite=false )
    {
        // init vars (needed for init.d script)
        self::_daemonInit();
        
        $properties                   = array();
        $properties["appName"]        = self::$appName;
        $properties["appDescription"] = self::$appDescription;
        $properties["authorName"]     = self::$authorName;
        $properties["authorEmail"]    = self::$authorEmail;
            
        try {
            // copy properties to OS object
            System_Daemon_OS::setProperties($properties);
            
            // try to write init.d 
            $ret = System_Daemon_OS::initDWrite($overwrite);
        } catch (System_Daemon_OS_Exception $e) {
            // Catch-all for System_Daemon_OS errors...
            self::log(SYSTEM_DAEMON_LOG_WARNING, "Unable to create startup file: ".
                $e->getMessage());
        }
    }//end osInitDWrite()
    
    
    
    /**
     * Initializes, sanitizes & defaults unset variables
     *
     * @return boolean
     */
    static private function _daemonInit() 
    {
        // if already initialized, skip
        if (self::$daemonIsInitialized) {
            return true;
        }
        
        self::$safe_mode = ((boolean)@ini_get("safe_mode") === FALSE) ? FALSE : TRUE;
        
        // system settings
        self::$processId      = 0;
        self::$processIsChild = false;
        if (!self::$safe_mode) {
            foreach (self::$iniSettings as $setting=>$value) {
                ini_set($setting, $value);
            }
        }
        set_time_limit(0);        
        ob_implicit_flush();
        
        // verify appName
        if (!isset(self::$appName) || !self::$appName) {
            self::log(SYSTEM_DAEMON_LOG_EMERG, "No appName set", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        if (!self::strIsUnix(self::$appName)) {
            // suggest a better appName
            $safe_name = self::strToUnix(self::$appName);
            self::log(SYSTEM_DAEMON_LOG_EMERG, "'".self::$appName.
                "' is not a valid daemon name, ".
                "try using something like '".$safe_name."' instead", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        
        // default appPidLocation
        if (!self::$appPidLocation) {
            self::$appPidLocation = "/var/run/".self::$appName.".pid";
        }
        // verify appPidLocation
        if (!is_writable($dir = dirname(self::$appPidLocation))) {
            self::log(SYSTEM_DAEMON_LOG_EMERG, "".self::$appName.
                " daemon cannot write to ".
                "pidfile directory: ".$dir, 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }

        // default logLocation
        if (!self::$logLocation) {
            self::$logLocation = "/var/log/".self::$appName.".log";
        }
        // verify logLocation
        if (!is_writable($dir = dirname(self::$logLocation))) {
            self::log(SYSTEM_DAEMON_LOG_EMERG, "".self::$appName." daemon cannot write ".
                "to log directory: ".$dir,
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        
        // verify logVerbosity
        if (self::$logVerbosity < 0 || self::$logVerbosity > 4) {
            self::log(SYSTEM_DAEMON_LOG_EMERG, "logVerbosity needs to be ". 
                "between 0 and 4 ".
                "logVerbosity: ".self::$logVerbosity."", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        
        // verify appRunAsUID
        if (!is_numeric(self::$appRunAsUID)) {
            self::log(SYSTEM_DAEMON_LOG_EMERG, "".self::$appName.
                " daemon has invalid ".
                "appRunAsUID: ".self::$appRunAsUID.". ",
                "It should be an integer", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        $passwd = posix_getpwuid(self::$appRunAsUID);
        if (!is_array($passwd) || !count($passwd) || 
            !isset($passwd["name"]) || !$passwd["name"]) {
            self::log(SYSTEM_DAEMON_LOG_EMERG, "".self::$appName." daemon has invalid ".
                "appRunAsUID: ".self::$appRunAsUID.". ".
                "No matching user on the system. ", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;            
        }

        // verify appRunAsGID
        if (!is_numeric(self::$appRunAsGID)) {
            self::log(SYSTEM_DAEMON_LOG_EMERG, "".self::$appName.
                " daemon has invalid ".
                "appRunAsGID: ".self::$appRunAsGID.". ",
                "It should be an integer",  
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        $group = posix_getgrgid(self::$appRunAsGID);
        if (!is_array($group) || !count($group) || 
            !isset($group["name"]) || !$group["name"]) {
            self::log(SYSTEM_DAEMON_LOG_EMERG, "".self::$appName.
                " daemon has invalid ".
                "appRunAsGID: ".self::$appRunAsGID.". ".
                "No matching group on the system. ", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;            
        }
        
        // default appDir
        if (!self::$appDir) {
            self::$appDir = dirname($_SERVER["SCRIPT_FILENAME"]);
        }
        // verify appDir
        if (!is_dir(self::$appDir)) {
            self::log(SYSTEM_DAEMON_LOG_EMERG, "".self::$appName.
                " daemon has invalid appDir: ".
                self::$appDir."", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        }
        
        // verify appExecutable
        if (!self::$appExecutable) {
            self::$appExecutable = basename($_SERVER["SCRIPT_FILENAME"]);
        }

        
        // combine appdir + exe here to make SURE we got our data right 
        
        self::$daemonIsInitialized = true;
        return true;
    }//end _daemonInit()

    /**
     * Put the running script in background
     *
     * @return void
     */
    static private function _daemonBecome() 
    {

        self::log(SYSTEM_DAEMON_LOG_INFO, "starting ".self::$appName.
            " daemon, output in: ". 
            self::$logLocation, 
            __FILE__, __CLASS__, __FUNCTION__, __LINE__);
        
        // important for daemons
        // see http://nl2.php.net/manual/en/function.pcntl-signal.php
        declare(ticks = 1);
        
        // setup signal handlers
        // handlers for individual signals can be overrulled with
        // setSigHandler()
        foreach (self::$_sigHandlers as $signal=>$handler) {
            pcntl_signal($signal, $handler);
        }
        
        // allowed?
        if (self::daemonIsRunning()) {
            self::log(SYSTEM_DAEMON_LOG_EMERG, "".self::$appName.
                " daemon is still running. ".
                "exiting", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
        }

        // fork process!
        if (!self::_daemonFork()) {
            self::log(SYSTEM_DAEMON_LOG_EMERG, "".self::$appName.
                " daemon was unable to fork", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
        }

        // assume specified identity (uid & gid)
        if (!posix_setuid(self::$appRunAsUID) || 
            !posix_setgid(self::$appRunAsGID)) {
            if (self::$appDieOnIdentityCrisis) {
                $lvl = SYSTEM_DAEMON_LOG_EMERG;
                $swt = "on";
            } else {
                $lvl = SYSTEM_DAEMON_LOG_CRIT;
                $swt = "off";
            }
            self::log($lvl, "".self::$appName." daemon was unable assume ".
                "identity (uid=".self::$appRunAsUID.", gid=".
                self::$appRunAsGID.") ".
                "and appDieOnIdentityCrisis was ". $swt, 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
        }

        // additional PID succeeded check
        if (!is_numeric(self::$processId) || self::$processId < 1) {
            self::log(SYSTEM_DAEMON_LOG_EMERG, "".self::$appName.
                " daemon didn't have a valid ".
                "pid: '".self::$processId."'", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
        } else {
            if (!file_put_contents(self::$appPidLocation, self::$processId)) {
                self::log(SYSTEM_DAEMON_LOG_EMERG, "".self::$appName.
                    " daemon was unable ".
                    "to write to pidfile: ".self::$appPidLocation."", 
                    __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            }
        }

        // change dir & umask
        @chdir(self::$appDir);
        @umask(0);
    }//end _daemonBecome()

    /**
     * Check if a previous process with same pidfile was already running
     *
     * @return boolean
     */
    static protected function daemonIsRunning() 
    {
        if(!file_exists(self::$appPidLocation)) return false;
        $pid = @file_get_contents(self::$appPidLocation);

        if ($pid !== false) {
            if (!posix_kill(intval($pid), 0)) {
                // not responding so unlink pidfile
                @unlink(self::$appPidLocation);
                self::log(SYSTEM_DAEMON_LOG_WARNING, "".self::$appName.
                    " daemon orphaned pidfile ".
                    "found and removed: ".self::$appPidLocation, 
                    __FILE__, __CLASS__, __FUNCTION__, __LINE__);
                return false;
            } else {
                return true;
            }
        } else {
            return false;
        }
    }//end daemonIsRunning()

    /**
     * Fork process and kill parent process, the heart of the 'daemonization'
     *
     * @return boolean
     */
    static private function _daemonFork()
    {
        self::log(SYSTEM_DAEMON_LOG_DEBUG, "forking ".self::$appName.
            " daemon", 
            __FILE__, __CLASS__, __FUNCTION__, __LINE__);
        $pid = pcntl_fork();
        if ( $pid == -1 ) {
            // error
            self::log(SYSTEM_DAEMON_LOG_WARNING, "".self::$appName.
                " daemon could not be forked", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            return false;
        } else if ($pid) {
            // parent
            self::log(SYSTEM_DAEMON_LOG_DEBUG, "ending ".self::$appName.
                " parent process", 
                __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            // die without attracting attention
            exit();
        } else {
            // child
            self::$processIsChild = true;
            self::$daemonIsDying  = false;
            self::$processId      = posix_getpid();
            return true;
        }
    }//end _daemonFork()

    /**
     * Return what the current process is: child or parent
     *
     * @return string
     */
    static private function _daemonWhatIAm()
    {
        return (self::daemonIsInBackground()?"child":"parent");
    }//end _daemonWhatIAm()

    /**
     * Sytem_Daemon::_daemonDie()
     * Kill the daemon
     *
     * @return void
     */
    static private function _daemonDie()
    {
        if (!self::daemonIsDying()) {
            self::$daemonIsDying       = true;
            self::$daemonIsInitialized = false;
            if (!self::daemonIsInBackground() || 
                !file_exists(self::$appPidLocation)) {
                self::log(SYSTEM_DAEMON_LOG_INFO, "Not stopping ".self::$appName.
                    ", daemon was not running",
                    __FILE__, __CLASS__, __FUNCTION__, __LINE__);
            } else {
                @unlink(self::$appPidLocation);
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
    static protected function strIsUnix( $str )
    {
        return preg_match('/^[a-z0-9_]+$/', $str);
    }//end strIsUnix()

    /**
     * Convert a string to a unix proof format (strip spaces, 
     * special chars, etc)
     * 
     * @param string $str What string to make unix compliant
     * 
     * @return string
     */
    static protected function strToUnix( $str )
    {
        return preg_replace('/[^0-9a-z_]/', '', strtolower($str));
    }//end strToUnix()

}//end class
?>