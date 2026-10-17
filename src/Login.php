<?php
declare(strict_types=1);

namespace PieceOfCake2\SecurityDast;

/**
 * A user to log in as, for a scan of the pages behind a login. ZAP logs in with a browser on the login page, so
 * that the forms with a CSRF token, such as those of `SecurityComponent`, work as for a person.
 */
final class Login
{
    /**
     * Constructor
     *
     * @param string $url Login page: a path of the application, e.g. `/users/login`, or a URL
     * @param string $username User name, e.g. the email of a user of the test database
     * @param string $password Password
     */
    public function __construct(
        public string $url,
        public string $username,
        public string $password,
    ) {
    }

    /**
     * The URL of the login page on a site
     *
     * @param string $site URL of the site, e.g. `http://127.0.0.1:8123`
     * @return string
     */
    public function urlOn(string $site): string
    {
        if (preg_match('#\Ahttps?://#i', $this->url)) {
            return $this->url;
        }

        return rtrim($site, '/') . '/' . ltrim($this->url, '/');
    }
}
