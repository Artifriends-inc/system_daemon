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
 * 
 * If you run this code successfully, a daemon will be spawned
 * but unless have already generated the init.d script, you have
 * no real way of killing it yet.
 * 
 * In this case wait 3 runs, which is the maximum for this example. 
 * 
 * 
 * In panic situations, you can always kill you daemon by typing
 * 
 * killall -9 logparser.php
 * OR:
 * killall -9 php
 * 
 */

// Allowed arguments & their defaults 
$runmode = array(
    "no-daemon" => false, 
    "help" => false,
    "write-initd" => false
);

// Scan command line attributes for allowed arguments
foreach ($argv as $k=>$arg) {
    if (substr($arg, 0, 2) == "--" && isset($runmode[substr($arg, 2)])) {
        $runmode[substr($arg, 2)] = true;
    }
}

// Help mode. Shows allowed argumentents and quite directly
if ($runmode["help"] == true) {
    echo "Usage: ".$argv[0]." [runmode]\n";
    echo "Available runmodes:\n"; 
    foreach ($runmode as $runmod=>$val) {
        echo " --".$runmod."\n";
    }
    die();
}
    
// Include Class
require_once "System/Daemon.php";

// Setup
System_Daemon::$appDir         = dirname(__FILE__);
System_Daemon::$appDescription = "Parses logfiles of vsftpd and stores them in MySQL";
System_Daemon::$authorName     = "Kevin van Zonneveld";
System_Daemon::$authorEmail    = "kevin@vanzonneveld.net";

// This program can also be run in the forground with runmode --no-daemon
if (!$runmode["no-daemon"]) {
    // Spawn Daemon 
    System_Daemon::start("logparser", true);
}

// With the runmode --write-initd, this program can automatically write a 
// system startup file called: 'init.d'
// This will make sure your daemon will be started on reboot 
if (!$runmode["write-initd"]) {
    System_Daemon::log(1, "not writing an init.d script this time");
} else {
    if (($initd_location = System_Daemon::osInitDWrite()) === false) {
        System_Daemon::log(2, "unable to write init.d script");
    } else {
        System_Daemon::log(1, "sucessfully written startup script: ".$initd_location);
    }
}

// Run your code
// Here comes your own actual code

// This variable gives your own code the ability to breakdown the daemon:
$runningOkay = true;

// This variable keeps track of how many 'runs' or 'loops' your daemon has
// done so far. For example purposes, we're quitting on 3.
$cnt = 1;

// While checks on 3 things in this case:
// - That the Daemon Class hasn't reported it's dying
// - That your own code has been running Okay
// - That we're not executing more than 3 runs 
while (!System_Daemon::daemonIsDying() && $runningOkay && $cnt <=3) {
    // What mode are we in?
    $mode = "'".(System_Daemon::daemonInBackground() ? "" : "non-" )."daemon' mode";
    
    // Log something using the Daemon class's logging facility
    // Depending on runmode it will either end up:
    //  - In the /var/log/logparser.log
    //  - On screen (in case we're not a daemon yet)  
    System_Daemon::log(1, System_Daemon::$appName." running in ".$mode." ".$cnt."/3");
    
    // In the actuall logparser program, You could replace 'true'
    // With e.g. a  parseLog('vsftpd') function, and have it return
    // either true on success, or false on failure.
    $runningOkay = true;
    
    // Should your parseLog('vsftpd') return false, then
    // the daemon is automatically shut down.
    // An extra log entry would be nice, we're using level 3, which is critical.
    // Level 4 would be fatal and shuts down the daemon immediately, which in 
    // this case is handled by the while condition.
    if (!$runningOkay) {
        System_Daemon::log(3, "parseLog() produces an error, so this will be my last run");
    }
    
    // Relax the system by sleeping for a little bit
    sleep(2);
    $cnt++;
}

// Shut down the daemon nicely
// This is ignored if the class is actually running in the foreground
System_Daemon::stop();
?>