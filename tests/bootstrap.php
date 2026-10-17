<?php
/**
 * Bootstrap for PHPUnit
 */

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (!defined('TMP')) {
    define('TMP', sys_get_temp_dir() . DS . 'security_dast_test_' . getmypid() . DS);
}
if (!is_dir(TMP)) {
    mkdir(TMP, 0777, true);
}

require_once dirname(__DIR__) . DS . 'vendor' . DS . 'autoload.php';
