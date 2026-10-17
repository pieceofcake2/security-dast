<?php
/**
 * The router script of PHP's built-in web server for a CakePHP application, as webroot/.htaccess: a file of the
 * document root is served as it is, and the other requests go to index.php. The application runs in the
 * directory of the project (`SECURITY_DAST_WORKING_DIR`), so that relative paths, e.g. of a SQLite database, are
 * the same as in the tests.
 */
$workingDir = getenv('SECURITY_DAST_WORKING_DIR');
if (is_string($workingDir) && $workingDir !== '' && is_dir($workingDir)) {
    chdir($workingDir);
}

// The path as a file name: decoded (/css/my%20site.css), and only in the document root
$path = rawurldecode((string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = $_SERVER['DOCUMENT_ROOT'] . $path;
if ($path !== '/' && !str_contains($path, "\0") && !preg_match('#(?:\A|[/\\\\])\.\.(?:[/\\\\]|\z)#', $path) && is_file($file) && !str_ends_with(strtolower($file), '.php')) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $_SERVER['SCRIPT_FILENAME'];
