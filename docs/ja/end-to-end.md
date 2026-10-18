# End-to-End テストをシナリオにする

クロールは、ページのリンクをたどります。注文の確認画面のように、操作を順に重ねないとたどり着かないページは見落とす
ことがあり、フォームに正しい値を入れないので、フォームの先のページにはなかなか届きません。プロジェクトの End-to-End
テストは、こうしたシナリオを知っています。`--end-to-end` を付けると、ZAP はクロールの代わりに、テストのしたことを
記録して、そのリクエストを確かめ、フルスキャンでは攻撃します。

```bash
vendor/bin/security-dast --end-to-end="vendor/bin/phpunit --testsuite EndToEnd"
vendor/bin/security-dast --end-to-end="vendor/bin/phpunit --testsuite EndToEnd" --scan=full
```

テストは [pieceofcake2/panther](https://pieceofcake2.github.io/panther/ja/) (または Symfony Panther) のものを、その
まま使います。スキャンのためにテストを変える必要はありません。

## しくみ

1. ほかのスキャンと同じく、アプリケーションをスキャンの組み込みウェブサーバー (`APP_ENV=test`) で起動します。
2. ZAP をプロキシとして起動します (Docker の `zap.sh -daemon`)。`127.0.0.1` だけで待ち受け、API にはランダムな鍵を
   付けます。
3. テストのコマンドを、プロジェクトで、次の環境変数を付けて実行します。
   - `PANTHER_CHROME_ARGUMENTS`: テストの Chrome が ZAP のプロキシを使う設定 (`--proxy-server=...`)。すでにある引数に
     足すので、ブラウザのすべてのリクエストが ZAP を通ります
   - `PANTHER_EXTERNAL_BASE_URI`: スキャンのアプリケーション。テストが自分のウェブサーバーを起動しないようにします
   - `APP_ENV`: スキャンのもの (`test`)
4. テストが終わったら、ZAP は記録したリクエストとレスポンスを確かめ、フルスキャンでは、テストのセッション (または
   指定した[ログイン](login.md)のセッション) で攻撃します。

テストの出力はコマンドのエラー出力に、まとめは出力に出ます。テストが失敗しても、それまでのリクエストでスキャンを
続け、報告書の表紙に、テストの終了コードとともにそのことが載ります。失敗のあとのページは検査されていないことが
あるので、コマンドは `--fail-on` に関係なく終了コード `2` で終わります。

シナリオがクロールの代わりになるので、`--minutes` と `--ajax-spider` は使いません。

## シナリオに向いたテストの書き方

フォームを通る End-to-End テストなら、そのままよいシナリオになります。次の点に気をつけると、さらにスキャンに向きます。

- **相対パスの URL を使います** (`$client->request('GET', '/contacts/add')`)。`PANTHER_EXTERNAL_BASE_URI` に従う
  ようになります。`http://127.0.0.1:9080/...` を開くテストは、スキャンを通りません。
- **フォームは正しい値で送ります。** テストがそうしているとおりです。フルスキャンは、テストが送ったフォームの各欄を、
  正しいリクエストから攻撃します。
- **データを変えるページを通ります。** アカウント、注文、コメント、アップロードのフォームなどです。攻撃が意味を持つの
  はそこです。
- **ログインしたまま終わるか、ログインを指定します。** 攻撃は、テストの最後のセッションで行います。テストが最後に
  ログアウトするなら、`--login-url` を指定して、ZAP が攻撃のために自分でログインするようにしてください。

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

このテストと `--scan=full` で、ZAP は `/contacts/add` のフォームを、`data[Contact][message]` に攻撃を入れて、毎回
`SecurityComponent` の新しいトークンを付けて送り直します。ビューがメッセージを `h()` なしで出力していれば、XSS として
報告します。

## 一部のテストだけ

コマンドは何でもかまいません。スキャンを短くするために、フォームのシナリオだけなど、テストのグループやフィルターを
実行できます。

```bash
vendor/bin/security-dast --scan=full \
    --end-to-end="vendor/bin/phpunit --testsuite EndToEnd --group security"
```

## 必要なもの

- End-to-End テストそのものと同じく、テストの Chrome と chromedriver (`vendor/bin/bdi detect drivers`)
- テストに必要なものが入ったテストのデータベース。テストのフィクスチャーは、いつもどおりテストが読み込みます
- ほかのスキャンと同じく Docker。ZAP のプロキシは、macOS と Windows (Docker Desktop) でも、ホストの `127.0.0.1` で
  待ち受けます

テストがスキャンのときだけ失敗するときは、[トラブルシューティング](troubleshooting.md#end-to-end-テスト)をご覧ください。
