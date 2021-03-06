<?php
/**
 * Simple functions of Application
 * be careful with this way
 * @author  Anton Shevchuk
 * @created 25.07.13 13:34
 */

// Write message to log file
if (!function_exists('errorLog')) {
    function errorLog($message)
    {
        if (is_dir(BOLMER_CORE_PATH.'/log')
            && is_writable(BOLMER_CORE_PATH .'/log')) {
            file_put_contents(
                BOLMER_CORE_PATH .'/log/'.(date('Y-m-d')).'.log',
                "[".date("H:i:s")."]\t".$message."\n",
                FILE_APPEND | LOCK_EX
            );
        }
    }
}

// Error Handler
if (!function_exists('errorHandler')) {
    function errorHandler()
    {
        $e = error_get_last();
        // check error type
        if (!is_array($e)
            || !in_array($e['type'], array(E_ERROR, E_USER_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
            return;
        }
        // clean all buffers
        if(!defined("NOCLEAR_ERROR_BUFFER") or !NOCLEAR_ERROR_BUFFER){
            while (ob_get_level()) {
                ob_end_clean();
            }
        }
        // try to write log
        errorLog($e['message'] ."\n". $e['file'] ."#". $e['line'] ."\n");
    }
}

// Error Handler
if (!function_exists('errorDisplay')) {
    function errorDisplay() {
       if (!$e = error_get_last()) {
            return;
        }

        if (!is_array($e)
            || !in_array($e['type'], array(E_ERROR, E_USER_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
            return;
        }
        require_once BOLMER_CORE_PATH . '/error.php';
    }
}

if (!function_exists('getService')){
    function getService($key, $nop = true){
        return \Bolmer\Service::getInstance()->get($key, $nop);
    }
}

/**
 * Simple functions of framework
 * be careful with this way
 * @author   Anton Shevchuk
 * @created  07.09.12 11:29
 */
if (!function_exists('core_dump')) {
    /**
     * Debug variables
     *
     * @return void
     */
    function core_dump()
    {
        // check definition
        if (!defined('BOLMER_DEBUG') or !BOLMER_DEBUG) {
            return;
        }

        ini_set('xdebug.var_display_max_children', 512);

        if ('cli' == PHP_SAPI) {
            if (extension_loaded('xdebug')) {
                // try to enable CLI colors
                ini_set('xdebug.cli_color', 1);
                xdebug_print_function_stack();
            } else {
                debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            }
            var_dump(func_get_args());
        } else {
            echo '<div class="textleft clear"><pre>';
            debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            var_dump(func_get_args());
            echo '</pre></div>';
        }
    }
}
if ( ! function_exists('getkey'))
{
    /**
     *  Код MODX гавно, поэтому забываем про TypeHinting
     * @param  mixed  $data
     * @param string $key
     * @param mixed $default null
     * @return mixed
     */
    function getkey($data, $key, $default = null)
    {
        $out = $default;
        if(is_array($data) && array_key_exists($key, $data)){
            $out = $data[$key];
        }
        return $out;
    }
}

if ( ! function_exists('with'))
{
    /**
     * Return the given object. Useful for chaining.
     *
     * @param  mixed  $object
     * @return mixed
     */
    function with($object)
    {
        return $object;
    }
}

// start cms session
if(!function_exists('startCMSSession')) {
    function startCMSSession(){
        $site_sessionname = getkey(getService('global_config'), 'site_sessionname');
        session_name($site_sessionname);
        session_start();
        $cookieExpiration = $cookieLifetime = 0;
        if (isset ($_SESSION['mgrValidated']) || isset ($_SESSION['webValidated'])) {
            $contextKey= isset ($_SESSION['mgrValidated']) ? 'mgr' : 'web';
            if (isset ($_SESSION['modx.' . $contextKey . '.session.cookie.lifetime']) && is_numeric($_SESSION['modx.' . $contextKey . '.session.cookie.lifetime'])) {
                $cookieLifetime= intval($_SESSION['modx.' . $contextKey . '.session.cookie.lifetime']);
            }
            if (isset ($_SESSION['bolmer.' . $contextKey . '.session.cookie.lifetime']) && is_numeric($_SESSION['bolmer.' . $contextKey . '.session.cookie.lifetime'])) {
                $cookieLifetime= intval($_SESSION['bolmer.' . $contextKey . '.session.cookie.lifetime']);
            }
            if ($cookieLifetime) {
                $cookieExpiration= time() + $cookieLifetime;
            }
            if (!isset($_SESSION['modx.session.created.time'])) {
                $_SESSION['modx.session.created.time'] = time();
            }
            if (!isset($_SESSION['bolmer.session.created.time'])) {
                $_SESSION['bolmer.session.created.time'] = time();
            }
        }
        setcookie(session_name(), session_id(), $cookieExpiration, BOLMER_BASE_URL);
    }
}