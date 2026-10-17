# ログインしてスキャンする

訪問者としてのクロールは、ログインページで止まります。アプリケーションのフォームの多くはその先にあります。テストの
データベースのユーザーを指定すると、ZAP はログインページでブラウザを使ってログインし、そのユーザーとしてクロールと、
フルスキャンでは攻撃をします。

```bash
export SECURITY_DAST_PASSWORD=secret
vendor/bin/security-dast --login-url=/users/login --username=test@example.com
vendor/bin/security-dast --scan=full --login-url=/users/login --username=test@example.com
```

| オプション | 環境変数 | |
| --- | --- | --- |
| `--login-url=PATH` | `SECURITY_DAST_LOGIN_URL` | ログインページ。アプリケーションのパス (`/users/login`) か URL |
| `--username=NAME` | `SECURITY_DAST_USERNAME` | ログインするユーザー。ログインフォームに入力するもの (メールアドレスなど) |
| `--password=PASSWORD` | `SECURITY_DAST_PASSWORD` | そのパスワード |
| `--exclude=LIST` | `SECURITY_DAST_EXCLUDE` | 除く URL の正規表現 (Java のもの)。デフォルトは `.*logout.*` |

## ZAP のログインのしかた

- ZAP は、ログインページをブラウザ (イメージの Firefox) で開き、ユーザー名とパスワードの欄に入力してフォームを送ります。
  人と同じなので、`SecurityComponent` のトークン付きの `FormHelper` のフォームでも、そのままログインできます。
- ログインの保ち方 (セッションの Cookie) と、ログインしているかの確かめ方 (ログインで変わるページ) は、ZAP が自分で
  見つけます。ログアウトしたとわかると、もう一度ログインします。
- ログインできなかったときは、気づかないまま訪問者としてスキャンしないよう、エラー (終了コード `2`) になります。
  報告書の表紙には、ユーザーとログインページが載ります。

## テストのユーザー

スキャンのときに、そのユーザーがテストのデータベースにいる必要があります。スキャンの組み込みウェブサーバーは、
テストと同じく `APP_ENV=test` のデータベースを使いますが、テストのフィクスチャーはテストの実行中にしかありません。
スキャンの前にユーザーを作ってください。たとえば、CI がスキーマのあとに読み込む、プロジェクトの SQL ファイルです。

```sql
-- tests/seed/security.sql: スキャンのユーザー。パスワードは、アプリケーションのハッシュで
INSERT INTO users (email, password, role, created, modified)
VALUES ('test@example.com', '$2y$10$...', 'user', NOW(), NOW());
```

ハッシュは、アプリケーションが確かめるものにします。たとえば、`AuthComponent` の `Blowfish` のパスワードハッシャーなら
`php -r 'echo password_hash("secret", PASSWORD_BCRYPT);'` です。テストのユーザーでも、パスワードは CI のシークレットに
してください。

テストのユーザーは、ユーザー名とパスワードだけでログインできるようにしてください。ZAP は、CAPTCHA、2 段階認証、
ほかのサイトでのログイン (SSO) を通れません。テスト環境では、`.env.test` の設定などで無効にしてください。

## 複数の役割

ZAP がログインするのは、1 回のスキャンで 1 人です。管理者のページもスキャンするときは、別のユーザーと別の報告書で
もう一度スキャンします。

```bash
SECURITY_DAST_PASSWORD=$USER_PASSWORD vendor/bin/security-dast --login-url=/users/login \
    --username=user@example.com --pdf=tmp/security-dast-user.pdf
SECURITY_DAST_PASSWORD=$ADMIN_PASSWORD vendor/bin/security-dast --login-url=/users/login \
    --username=admin@example.com --pdf=tmp/security-dast-admin.pdf
```

一般のユーザーが管理者のページを開けるかどうかは、アプリケーションのルールで、スキャンでは確かめません。
アプリケーションのテストで確かめてください。

## 除くページ

スキャン中にログアウトしないよう、`.*logout.*` に合う URL は除きます。自分のパターンは `--exclude` で指定します
(デフォルトを置き換えるので、ログアウトも入れておいてください)。

```bash
vendor/bin/security-dast --login-url=/users/login --username=test@example.com \
    --exclude='.*/users/logout.*,.*/users/delete.*,.*/admin/.*'
```

スキャンを止めたりテスト環境を壊したりするページは除いてください。ユーザーのアカウントの削除や、実在のアドレスに
メールを送るページなどです。

## パスワード

パスワードは `--password` ではなく `SECURITY_DAST_PASSWORD` (CI のシークレット) で渡してください。オプションは、
プロセスの一覧やシェルの履歴で見えてしまいます。[設定ファイル](configuration.md#設定ファイル)では、環境変数から
読みます。

```php
'dast' => [
    'loginUrl' => '/users/login',
    'username' => 'test@example.com',
    'password' => getenv('SECURITY_DAST_PASSWORD') ?: null,
],
```

パスワードは、報告書にも ZAP の plan にも書きません。ZAP にはコンテナの環境変数で、名前だけを指定して渡すので、
コマンドラインにも出ません。`--end-to-end` のテストとアプリケーションには、`SECURITY_DAST_PASSWORD` を渡しません。
