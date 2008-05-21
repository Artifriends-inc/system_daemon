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
 * and stopped directly. You should find a log enty in 
 * /var/log/optest.log
 * 
 */
    
// Include Class
error_reporting(E_ALL);
require_once "System/Daemon.php";


$os_funcs = &new System_Daemon_OS();
$os = $os_funcs->getSpecific();

$details = $os->getDetails();

print_r($details);


die();


// Bare minimum setup
System_Daemon::setOption("appName", "optest");
System_Daemon::setOption("authorEmail", "kevin@vanzonneveld.net");

die();

//System_Daemon::setOption("appDir", dirname(__FILE__));
System_Daemon::log(System_Daemon::LOG_INFO, "Daemon not yet started so ".
    "this will be written on-screen");

// Spawn Deamon!
System_Daemon::start();
System_Daemon::log(System_Daemon::LOG_INFO, "Daemon: '".
    System_Daemon::getOption("appName").
    "' spawned! This will be written to ".
    System_Daemon::getOption("logLocation"));

// Your normal PHP code goes here. Only the code will run in the background
// so you can close your terminal session, and the application will
// still run.

System_Daemon::stop();
?>