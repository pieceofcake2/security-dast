<?php
// The document root of this project is public/, not webroot/
if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}
if (!defined('WWW_ROOT')) {
    define('WWW_ROOT', ROOT . DS . 'public' . DS);
}
