<?php
// A small application with a login, for the scans with a user: the forms have a token as those of
// SecurityComponent, and the page of the account has a form that prints its value without escaping
session_start();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$page = static function (string $title, string $body): void {
    echo '<!doctype html><html><head><title>' . $title . '</title></head><body><h1>' . $title . '</h1>' . $body . '</body></html>';
};
$token = static function (): string {
    // Many tokens are valid at a time, as with the csrfTokens of SecurityComponent
    $key = bin2hex(random_bytes(16));
    $_SESSION['tokens'][$key] = time() + 1800;

    return '<input type="hidden" name="data[_Token][key]" value="' . $key . '">';
};
$valid = static fn(): bool => isset($_POST['data']['_Token']['key']) && ($_SESSION['tokens'][$_POST['data']['_Token']['key']] ?? 0) > time();

if ($path === '/users/login') {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($valid() && ($_POST['data']['User']['email'] ?? '') === 'test@example.com' && ($_POST['data']['User']['password'] ?? '') === 'secret') {
            session_regenerate_id(true);
            $_SESSION['user'] = 'test@example.com';
            header('Location: /account');
            return;
        }
        $error = '<p>Invalid email or password</p>';
    }
    $page('Login', $error . '<form method="post" action="/users/login">' . $token()
        . '<input type="email" name="data[User][email]" id="UserEmail"><input type="password" name="data[User][password]" id="UserPassword">'
        . '<button type="submit">Login</button></form>');
    return;
}
if ($path === '/users/logout') {
    session_destroy();
    header('Location: /');
    return;
}
if (str_starts_with($path, '/account')) {
    if (!isset($_SESSION['user'])) {
        header('Location: /users/login');
        return;
    }
    if ($path === '/account/profile' && $_SERVER['REQUEST_METHOD'] === 'POST' && $valid()) {
        $_SESSION['name'] = $_POST['data']['Profile']['name'] ?? '';
    }
    $page('Account', '<p>Logged in as ' . htmlspecialchars($_SESSION['user']) . '</p>'
        . '<p>Name: ' . ($_SESSION['name'] ?? '') . '</p>'
        . '<form method="post" action="/account/profile">' . $token() . '<input name="data[Profile][name]"><button>Save</button></form>'
        . '<p><a href="/account/search?q=books">Search</a> <a href="/users/logout">Logout</a></p>');
    if ($path === '/account/search') {
        echo '<p>Results for ' . ($_GET['q'] ?? '') . '</p>';
    }
    return;
}
$page('Home', '<a href="/users/login">Login</a>');
