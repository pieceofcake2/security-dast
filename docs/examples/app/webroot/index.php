<?php
// The fictional application of the sample report of the full scan (docs/examples/report-sample.php): a contact
// form of CakePHP 2 that prints the message without escaping it
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('X-Powered-By: PHP/8.4');
header("Content-Security-Policy: default-src 'self'; form-action 'self'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('Cross-Origin-Embedder-Policy: require-corp');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('Permissions-Policy: geolocation=()');
header('Cache-Control: no-store');
if ($path === '/contacts/add') {
    setcookie('CAKEPHP', 'k8c2l1v0q9', ['httponly' => true]);
    $message = $_POST['data']['Contact']['message'] ?? null;
    echo '<!doctype html><html><head><title>Contact</title></head><body><h1>Contact</h1>';
    if (is_string($message)) {
        // The bug of the sample: the message is not escaped
        echo '<p>Thank you for your message:</p><p>' . $message . '</p>';
    }
    echo '<form method="post" action="/contacts/add">'
        . '<input type="hidden" name="data[_Token][key]" value="' . bin2hex(random_bytes(8)) . '">'
        . '<textarea name="data[Contact][message]"></textarea><button>Send</button></form>'
        . '<a href="/">Home</a></body></html>';
    return;
}
echo '<!doctype html><html><head><title>Home</title></head><body><h1>Home</h1><a href="/contacts/add">Contact</a></body></html>';
