<?php
// A page without the security headers, for the tests of the scan
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/login') {
    setcookie('session', 'abc123');
    echo '<!doctype html><html><head><title>Login</title></head><body><h1>Login</h1>'
        . '<form method="post" action="/login"><input name="email"><input type="password" name="password"><button>Login</button></form>'
        . '<form method="post" action="/contact"><input type="hidden" name="data[_Token][key]" value="0123456789abcdef"><input name="message"><button>Send</button></form>'
        . '<a href="/">Home</a></body></html>';
    return;
}
if ($path === '/error') {
    http_response_code(500);
    echo '<pre>PDOException: SQLSTATE[42S02] in /var/www/app/src/Model/Post.php on line 12</pre>';
    return;
}
header('X-Powered-By: PHP/8.4');
echo '<!doctype html><html><head><title>Home</title><script src="http://cdn.example.com/lib.js"></script></head>'
    . '<body><h1>Home</h1><a href="/login">Login</a> <a href="/error">Error</a> <a href="/posts?page=1">Posts</a></body></html>';
