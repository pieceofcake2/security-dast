# End-to-End Tests as the Scenario

A crawl follows the links of the pages: it can miss the pages that only a series of actions reaches, such as
the confirmation of an order, and it does not fill the forms with valid values, so it rarely reaches the page
after a form. The End-to-End tests of the project know these scenarios. With `--end-to-end`, ZAP records what
the tests do, and checks (and, for the full scan, attacks) these requests instead of crawling:

```bash
vendor/bin/security-dast --end-to-end="vendor/bin/phpunit --testsuite EndToEnd"
vendor/bin/security-dast --end-to-end="vendor/bin/phpunit --testsuite EndToEnd" --scan=full
```

The tests are those of [pieceofcake2/panther](https://pieceofcake2.github.io/panther/) (or of Symfony
Panther), as they are: nothing of them changes for the scan.

## How It Works

1. The application starts with the built-in web server of the scan (`APP_ENV=test`), as for the other scans.
2. ZAP starts as a proxy (`zap.sh -daemon` in Docker), on `127.0.0.1` only, with a random key for its API.
3. The command of the tests runs in the project, with:
   - `PANTHER_CHROME_ARGUMENTS`: the proxy of ZAP for the Chrome of the tests (`--proxy-server=...`), added to
     the arguments that are already set, so that every request of the browser goes through ZAP
   - `PANTHER_EXTERNAL_BASE_URI`: the application of the scan, so that the tests do not start a web server of
     their own
   - `APP_ENV`: the one of the scan (`test`)
4. When the tests have finished, ZAP checks the requests and responses that it recorded, and, for the full scan,
   attacks them, with the session of the tests (or of the [login](login.md), when it is given).

The output of the tests goes to the error output of the command, and the summary to its output. When the tests
fail, the scan goes on with the requests made until then, and the cover of the report says so with the exit
code of the tests.

## Writing Tests That Make a Good Scenario

The End-to-End tests already make a good scenario when they go through the forms of the application. A few
points make them better for the scan:

- **Use relative URLs** (`$client->request('GET', '/contacts/add')`), so that they follow
  `PANTHER_EXTERNAL_BASE_URI`. A test that opens `http://127.0.0.1:9080/...` bypasses the scan.
- **Send the forms with valid values**, as the tests do: the full scan attacks each field of the forms that the
  tests sent, starting from the valid request.
- **Cover the pages that change data**: the forms of the account, of the orders, of the comments, of the
  uploads. They are where the attacks matter.
- **End logged in, or give a login.** The attacks use the last session of the tests: if the tests log out at the
  end, give `--login-url` so that ZAP logs in by itself for the attacks.

```php
// tests/TestCase/EndToEnd/ContactsTest.php
use PieceOfCake2\Panther\TestSuite\PantherTestCase;

class ContactsTest extends PantherTestCase
{
    public function testSendsAMessage(): void
    {
        $client = static::createPantherClient();
        $client->request('GET', '/contacts/add');
        $client->submitForm('Send', ['data[Contact][message]' => 'Hello']);

        $this->assertSelectorTextContains('.sent', 'Hello');
    }
}
```

With this test and `--scan=full`, ZAP sends the form of `/contacts/add` again with attacks in
`data[Contact][message]`, each with a new token of `SecurityComponent`, and reports an XSS when the view
prints the message without `h()`.

## Only Some Tests

The command is any command: run a group or a filter of the tests, e.g. the scenarios of the forms only, to keep
the scan short.

```bash
vendor/bin/security-dast --scan=full \
    --end-to-end="vendor/bin/phpunit --testsuite EndToEnd --group security"
```

## Requirements

- Chrome and the chromedriver of the tests, as for the End-to-End tests themselves
  (`vendor/bin/bdi detect drivers`)
- The test database with what the tests need: the fixtures of the tests are loaded by the tests, as usual
- Docker, as for the other scans. The proxy of ZAP listens on `127.0.0.1` of the host, also on macOS and
  Windows (Docker Desktop)

See [Troubleshooting](troubleshooting.md#end-to-end-tests) when the tests fail only in the scan.
