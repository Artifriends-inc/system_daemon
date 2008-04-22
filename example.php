#!/usr/bin/php -q
<?php
/**
 * System_Daemon turns PHP-CLI scripts into daemons.
 * 
 * PHP version 5
 *
 * @category  System
 * @package   System_Daemon
 * @author    Kevin <kevin@vanzonneveld.net>
 * @copyright 2008 Kevin van Zonneveld
 * @license   http://www.opensource.org/licenses/bsd-license.php New BSD Licence
 * @version   SVN: Release: $Id$
 * @link      http://trac.plutonia.nl/projects/system_daemon
 */

/**
 * System_Daemon Example Code
 */

// Arguments 
$runmode                = array();
$runmode["no-daemon"]   = false;
$runmode["help"]        = false;
$runmode["write-initd"] = false;
foreach ($argv as $k=>$arg) {
    if (substr($arg, 0, 2) == "--" && isset($runmode[substr($arg, 2)])) {
        $runmode[substr($arg, 2)] = true;
    }
}

// Help
if ($runmode["help"] == true) {
    echo "Usage: ".$argv[0]." [runmode]\n";
    echo "Available runmodes:\n"; 
    foreach ($runmode as $runmod=>$val) {
        echo " --".$runmod."\n";
    }
    die();
}
    
// Spawn Daemon 
set_time_limit(0);
ini_set("memory_limit", "1024M");
if ($runmode["no-daemon"] == false) {
    // conditional so use include
    $path_to_daemon = dirname(__FILE__)."/ext/System_Daemon/";
    $path_to_daemon = "";
    if(!@include_once $path_to_daemon."Daemon.Class.php"){
        echo "Unable to locate System_Daemon class";
    } else {
        $daemon                 = new System_Daemon("mydaemon");
        $daemon->appDir         = dirname(__FILE__);
        $daemon->appDescription = "My 1st Daemon";
        $daemon->authorName     = "Kevin van Zonneveld";
        $daemon->authorEmail    = "kevin@vanzonneveld.net";
        $daemon->start();
        
        if ($runmode["write-initd"]) {
            if (!$daemon->initdWrite()) {
                echo "Unable to write init.d script\n";
            } else {
                echo "I wrote an init.d script\n";
            }
        }
    }
}

// Run your code
$fatal_error = false;
while (!$fatal_error && !$daemon->isDying) {
    // do deamon stuff
    echo $daemon->appDir." daemon is running...\n";
    
    // relax the system by sleeping for a little bit
    sleep(5);
}

if ($runmode["no-daemon"] == false) {
    $daemon->stop();
}
?>