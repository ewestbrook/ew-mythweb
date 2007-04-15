<?php
/**
 * Alternate constants for user-invoked errors.
 * Changes error_reporting().
 * User-defined error handler.
 *
 * This file was originally written by Chris Petersen for several different open
 * source projects.  It is distrubuted under the GNU General Public License.
 * I (Chris Petersen) have also granted a special LGPL license for this code to
 * several companies I do work for on the condition that these companies will
 * release any changes to this back to me and the open source community as GPL,
 * thus continuing to improve the open source version of the library.  If you
 * would like to inquire about the status of this arrangement, please contact
 * me personally.
 *
 * @url         $URL$
 * @date        $Date$
 * @version     $Revision$
 * @author      $Author$
 * @license     GPL
 *
 * @package     MythWeb
 *
/**/

// Probably already loaded, but it *is* used by this library
    require_once 'includes/errordisplay.php';

// Define some easier-to-read error values
    define('FATAL',          E_USER_ERROR);
    define('ERROR',          E_USER_WARNING);
    define('WARNING',        E_USER_NOTICE);
    define('E_ASSERT_ERROR', 4096);

// set the error reporting level for this script
    error_reporting(FATAL | ERROR | WARNING | E_ERROR | E_WARNING | E_PARSE | E_COMPILE_ERROR | E_ASSERT_ERROR);

// Reconfigure the error handler to use our own routine
    set_error_handler('error_handler');

// Active assert and make it quiet
    assert_options(ASSERT_ACTIVE,     1);
    assert_options(ASSERT_WARNING,    0);
    assert_options(ASSERT_QUIET_EVAL, 1);
// Set up the callback
    assert_options(ASSERT_CALLBACK, 'assert_handler');

/**
 * This user-defined assert handler just tosses a silent error out if it fails
/**/
    function assert_handler($file, $line, $code) {
        error_handler(E_ASSERT_ERROR, $code, $file, $line, null);
    }

/**
 * Function to turn error numbers into strings.  Used in several locations to
 * take the place of a global variable.
 *
 * @param  int $errno The error number to look up.
 * @return string     Human-readable name for error number $errno.
/**/
    function error_type($errno) {
        static $types = array(1    => 'Error',            2    => 'Warning',
                              4    => 'Parsing Error',    8    => 'Notice',
                              16   => 'Core Error',       64   => 'Compile Error',
                              128  => 'Compile Warning',  256  => 'User Error',
                              512  => 'User Warning',     1024 => 'User Notice',
                              4096 => 'Assertion Error');
        return $types[$errno];
    }

/**
 *  This user-defined error handler supports the built-in error_reporting()
 *  function, and is basically just an expansion of the built-in error-
 *  handling routine.  If it receives a fatal error (E_USER_ERROR or E_ERROR),
 *  it prints a more reassuring message to the viewer of the page and sends an
 *  email message to the address stored in Error_Email, which is defined in
 *  conf.php.
/**/
    function error_handler($errno, $errstr, $errfile, $errline, $vars) {
        global $db;
    // Try to auto-repair damaged SQL tables
        if ($db && preg_match("/Incorrect key file for table: '(\\w+)'/", $errstr, $match))
            $db->query('REPAIR TABLE '.$match[1]);
    // Don't die on so-called fatal regex errors
        if (preg_match("/Got error '(.+?)' from regexp/", $errstr, $match)) {
            add_error('Regular Expression Error:  '.$match[1]);
            return;
        }
    // Leave early if we haven't requested reports from this kind of error
        if (!($errno & error_reporting()))
            return;
    // Fatal errors should report considerably more detail
        if (in_array($errno, array(E_USER_ERROR, E_ERROR, E_ASSERT_ERROR))) {
        // What type of error?
            $subject = ($errno == E_ASSERT_ERROR) ? 'ASSERT' : 'FATAL Error';
        // Email a backtrace
            $err = build_backtrace($errno, $errstr, $errfile, $errline, $vars);
            email_backtrace($err, $errfile, $errline, $subject);
        // Print something to the user, too.
            if ($errno != E_ASSERT_ERROR) {
                echo "<hr><p><b>Fatal Error</b> at $errfile, line $errline:<br />$errstr</p>\n",
                     '<p>If you choose to ',
                     '<b><u><a href="http://svn.mythtv.org/trac/newticket" target="_blank">submit a bug report</a></u></b>, ',
                     'please make sure to include a<br />',
                     'brief description of what you were doing, along with the following<br />',
                     'backtrace as an attachment (please don\'t paste the whole thing into<br />',
                     "the ticket).\n",
                     "<hr>\n",
                     "<b>Backtrace</b>:<br />\n<pre>", htmlentities($err), '</pre>';
            // Fatal error means that we exit.
                exit;
            }
        }
    // Otherwise, just report the error
        else {
            echo "<hr><p><b>Error</b> at $errfile, line $errline:<br />$errstr</p>\n",
                 "<hr>\n";
        }
    }

/**
 * Build and return a human-readable backtrace message
 *
 * @return array containing the backtrace and an error serial
/**/
    function build_backtrace($errno=null, $errstr=null, $errfile=null, $errline=null, $vars=null) {
    // Generate an error message that can be emailed to the administrator
        $bt = '    datetime:  '.date('Y-m-d H:i:s (T)')."\n";
        if ($errno) {
            $bt .= '    errornum:  '.$errno                 ."\n"
                  .'  error type:  '.error_type($errno)     ."\n"
                  .'error string:  '.$errstr                ."\n"
                  .'    filename:  '.$errfile               ."\n"
                  .'  error line:  '.$errline               ."\n";
        }
    // Print a backtrace
        $bt .= "\n==========================================================================\n\n"
              ."Backtrace: \n\n";
        $backtrace = debug_backtrace();
        array_shift($backtrace);
        if ($backtrace[0]['function'] == 'error_handler')
            array_shift($backtrace);
        foreach ($backtrace as $layer) {
            foreach (array('file', 'line', 'class', 'function', 'type', 'args') as $key) {
                $val = $layer[$key];
                $bt .= str_repeat(' ', max(8-strlen($key), 0)). "$key:  ";
                if (is_array($val) || is_object($val))
                    $bt .= print_r($val, true);
                else
                    $bt .= "$val\n";
            }
            $bt .= "\n";
         }
    // Print out some global stuff since we can't print out all of the variables
        if (!empty($_GET))
            $bt .= "\n==========================================================================\n\n"
                  .'$_GET: '
                  .print_r($_GET, true);
        if (!empty($_POST))
            $bt .= "\n==========================================================================\n\n"
                  .'$_POST: '
                  .print_r($_POST, true);
        if (!empty($_SESSION))
            $bt .= "\n==========================================================================\n\n"
                  .'$_SESSION: '
                  .print_r($_SESSION, true);
        if (!empty($_SERVER))
            $bt .= "\n==========================================================================\n\n"
                  .'$_SERVER: '
                  .print_r($_SERVER, true);
    ### stupid recursive objects break non-cutting-edge versions of php
        #$bt .= "\n==========================================================================\n\n"
        #      ."vars:\n"
        #      .print_r($vars, true);
    // Cleanup
        $bt  = preg_replace('/Array\s+\(\s+\)\n+/', "Array ( )\n", $bt);
        $bt .= "\n\n";
    // Return
        return $bt;
    }

/**
 * Email a backtrace.
 *
 * @param string $backtrace The text of the backtrace (or generate a new one).
 * @param string $errfile   File the backtrace should reference.
 * @param int    $errline   Line in $errfile that the backtrace should reference.
 * @param string $subject   Email subject tagline.
/**/
    function email_backtrace($backtrace=null, $errfile=null, $errline=null, $subject="Backtrace") {
    // No email, just return
        if (!strstr(error_email, '@'))
            return;
    // Generate and email a backtrace
        if (empty($backtrace))
            $backtrace = build_backtrace();
    // Need to figure out where this was called from?
        if (empty($errfile) || empty($errline)) {
            $bt = debug_backtrace();
            $errfile = $bt[1]['file'];
            $errline = $bt[1]['line'];
        }
    // Email the error to the website's error mailbox
        mail(error_email,
             "MythWeb $subject:  $errfile, line $errline",
             $backtrace,
             'From:  MythWeb PHP Error <'.error_email.">\r\n");
    }

