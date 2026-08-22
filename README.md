# ur-monitor

UR賃貸の空き部屋を定期監視し、条件に合う新着があれば Slack へ通知する。

## なぜ作ったのか

**UR賃貸の空室情報は、基本的に Web にしか出ません。** メールで知らせてくれるわけでもないので、
気になる物件のページを自分で何度も開いて確かめるしかありません。

そして**空きが出たら、気づいた人から順に電話で押さえる早い者勝ち**です。
数時間気づくのが遅れただけで、もう他の人に決まっていた、ということが普通に起こります。

1件を見張るだけでも大変なのに、候補が複数あればなおさら現実的ではありません。
仕事中も寝ている間も、人間が張り付き続けることはできません。

**だから、入居したい物件を横断的に監視して、空きが出た瞬間に知らせる仕組みが要ります。**
このツールはそのために作りました。30分ごとに自動で見に行き、条件に合う新着だけを通知します。
見張る作業を機械に任せて、**人間は「電話をかける」という本当に大事な一手に集中できます。**

- **本番実行は GitHub Actions（Linux）のみ**。PCの起動状態に関係なく動く。
- **開発は Windows / macOS / Linux のどこでもできる**。OS固有の処理は持たず、
  Chrome の場所の違いだけ `detect_chrome_path()` で吸収している。
- Claude Code on the web でも開発できる（[設定手順](#claude-code-on-the-web-で開発する場合)）。

## はじめての方へ

**プログラミングの知識がなくても使えます。** 画面の操作と設定値のコピー＆ペーストだけで、
30〜60分ほどでセットアップできます。

### 📖 [セットアップ手順書（図解つき）](https://tama-create.github.io/ur-monitor/setup.html)

GitHub アカウントの作り方から順に、図を交えて説明しています。はじめての方はこちらをどうぞ。

手順書には次が含まれます。

- 全体の仕組み（図解）
- GitHub アカウントの用意 → フォーク → 監視条件の設定
- Slack の通知先の作り方（アプリ作成・チャンネル・Webhook 発行）
- 動作確認のしかた
- 空き部屋一覧の Web 公開（任意）
- うまくいかないときの対処と、使っている技術の説明

以降のこの README は、**技術的な詳細を知りたい方向け**の内容です。

## このリポジトリを使う方へ

個人的に作って個人的に使っているツールを、そのまま公開しています。
同じことをしたい方はご自由にどうぞ。ただし次の前提でお願いします。

- **セットアップはご自身で。** 手順は下記に書いてありますが、個別のサポートは行いません
- **自己責任でお願いします。** 動作を保証するものではなく、利用によって生じた不利益について作者は責任を負いません（[MIT License](LICENSE)）
- **`config.json` は作者の設定例です。** `search_urls` / `watch` / `highlight_keywords` は
  ご自身の条件に置き換えてください。そのまま動かしても作者が見ている物件を追うだけになります
- **Slack Webhook は含まれていません。** ご自身で用意してください

UR のサイトへアクセスするツールです。`robots.txt` を確認し、URL間に待機を入れ、30分間隔で
動かしています。**取得間隔を極端に詰めるような改変はしないでください。** 相手のサーバーに
負荷をかける使い方をされると、この方法自体が使えなくなります。

## 仕組み

- 08:00〜21:00 JST・30分間隔で `.github/workflows/monitor.yml` が実行される
- `config.json` の `search_urls` を巡回してスクレイピング
- `watch` 条件に合致する新着があれば Slack へ通知
- 実行結果（`state.json` / `docs/index.html`）は毎回リポジトリへコミットして永続化する
  （Actions のランナーは毎回まっさらな環境で、ファイルが残らないため）

## 開発（Windows / Mac 共通）

```bash
composer install
php ur_monitor.php --dry-run
```

`--dry-run` はスクレイピングだけ行い、**Slack 通知も `state.json` / `docs/index.html` の更新もしない**。
本番の状態を壊さずに手元で動作確認できるので、開発中は基本これを使う。

`--dry-run` を付けずに実行すると本番と同じ動作になり、`state.json` が書き換わる。
このファイルは Actions 側も毎回コミットしているため、うっかり実行するとコミットが衝突する点に注意。

| コマンド | 用途 |
|---|---|
| `php ur_monitor.php --dry-run` | 開発用。副作用なしで動作確認 |
| `php ur_monitor.php` | 本番と同じ動作（通常はローカルで実行しない） |
| `php ur_monitor.php --setup` | セレクター確認。ブラウザを表示し `debug_page.html` と `debug_*.png` を保存 |
| `php ur_monitor.php --check-robots` | robots.txt の確認のみ |

### Claude Code on the web で開発する場合

サンドボックスは Ubuntu 24.04 で、**PHP 8.4 と Composer は導入済みだが Chrome は入っていない**。
また既定のネットワーク設定では UR のサイトに到達できない。次の2つを claude.ai/code の
環境設定ダイアログで設定する（**リポジトリにファイルを置くだけでは効かない**）。

**1. Setup script 欄**に [`docs/cloud-setup.sh`](docs/cloud-setup.sh) の中身を貼り付ける。
Chrome for Testing の導入と `composer install` を行う。最後に「セットアップ結果」の
まとめを出すので、`NG` があればセッション開始時点で気づける（このスクリプトは
途中で失敗しても止まらないため、まとめを見ないと後の dry-run が原因不明の0件になる）。

**2. Network access を Custom** にし、許可ドメインへ次の2行を追加したうえで、
`Also include default list of common package managers` を有効にする。

```
ur-net.go.jp
*.ur-net.go.jp
```

ドメイン指定は完全一致のため、実際にアクセスする `www.ur-net.go.jp` は
`ur-net.go.jp` の1行だけでは許可されない。`*.` の行が www を拾う。

既定の **Trusted** は npm や Packagist などの配布元しか許可しないため、そのままだと
コードの編集はできてもスクレイピングの動作確認ができない。なお Chrome を
`dl.google.com` から取らず Chrome for Testing を使っているのも、前者が Trusted の
許可ドメインに含まれないため。

許可ドメインを設定しても Chrome だけが `ERR_CONNECTION_RESET` になり全 URL が 0 件になる場合、
サンドボックスのプロキシと Chrome の間で TLS 1.3 のハンドシェイクが落ちている可能性がある
（`curl` や `check_robots_txt()` の `file_get_contents()` は通るのに Chrome だけ届かないのが目印）。
その場合は `config.json` に次を足すと通る。プロキシを挟まない本番では不要。

```json
"chrome_flags": ["--ssl-version-max=tls1.2"]
```

この手順は `.github/workflows/verify-cloud-setup.yml` で検証できる。同じ Ubuntu 24.04 上で、
ランナーのプリインストール Chrome を退避してからスクリプトを実行し、入れた Chrome だけで
スクレイピングが通るかを確認する。ネットワーク設定はサンドボックス固有のため対象外。

### Chrome の場所

通常は自動検出されるので設定不要。Windows の `Program Files` 配下、
macOS の `/Applications/Google Chrome.app`、Linux の `/usr/bin/google-chrome-stable` などを順に探す。
見つからない環境では `config.json` に `chrome_path` を書けば優先される。

### Chrome の起動フラグ

通常は設定不要。実行環境の都合でどうしても Chrome の起動オプションが必要な場合のみ、
`config.json` に `chrome_flags` を書くと既定のフラグの後ろに追加される。
同じフラグを指定した場合は後勝ちで `chrome_flags` 側が使われる。

```json
"chrome_flags": ["--ssl-version-max=tls1.2"]
```

`--` で始まらない要素は無視される。本番（GitHub Actions）では空のまま使う。

### セレクターの調整

URサイトのHTML構造が変わって0件になった場合：

1. `php ur_monitor.php --setup` を実行
2. 生成された `debug_page.html` をブラウザで開いて構造を確認
3. `config.json` の `selectors` を更新

## セットアップ手順書の公開（GitHub Pages）

`docs/setup.html` は GitHub Pages で公開している。**ワークフローもトークンも不要**で、
`main` に push すれば自動で反映される（反映まで最大10分程度）。

フォークして自分の手順書を公開したい場合は、リポジトリの
Settings → Pages → Build and deployment を次のように設定する。

| 項目 | 値 |
|---|---|
| Source | Deploy from a branch |
| Branch | `main` |
| Folder | `/docs` |

公開 URL は `https://<ユーザー名>.github.io/ur-monitor/setup.html` になる。

`docs/.nojekyll` は Jekyll による変換処理を止めるための空ファイル。HTML をそのまま配信させる。
これが無いと、HTML 内の `{{ }}` や `{% %}` が Jekyll のテンプレート記法として解釈されて壊れたり、
アンダースコアで始まるファイルが無視されたりする。**消さないこと。**

**GitHub Free では公開リポジトリのみ**この機能を使える。private のままにしたい場合は
Web 公開を諦め、Slack 通知だけを使うこと。

## 設定（config.json）

振る舞いはすべて `config.json` で決まる。監視対象も通知条件もセレクターも設定側にあるので、
**コードを触らずに変えられる**。

| キー | 必須 | 意味 |
|---|---|---|
| `search_urls` | ✓ | 巡回する UR の検索結果ページ。**ここに出てくる物件だけが監視対象**になる |
| `watch` |  | 通知する条件。合致した新着だけが Slack に飛ぶ |
| `notify_all_new` |  | `true` で `watch` に関係なく全新着を通知。既定 `false`（スパム防止） |
| `highlight_keywords` |  | 公開ページで強調表示する文字列。**通知には影響しない**（見た目だけ） |
| `jitter_max_seconds` |  | 実行開始前のランダム待機の上限秒。`0` で無効 |
| `selectors` |  | UR ページの CSS セレクター。省略時はコード内の既定値が使われる |
| `slack_webhook_url` |  | Slack の Webhook URL。**環境変数 `SLACK_WEBHOOK_URL` があればそちらが優先**。実値は書かない |
| `chrome_path` |  | Chrome 実行ファイルのパス。省略時は自動検出 |
| `chrome_flags` |  | Chrome の追加起動フラグ |

### search_urls

UR のサイトで条件を絞り込んだ**検索結果ページの URL** をそのまま貼る。エリア単位・駅単位など
絞り方は問わない。複数書けば全部巡回する。ただし URL を増やすほど1回の実行時間が延びる。

### watch

`building`（建物名）と `madori`（間取り）の組を並べる。**どれか1つに合致すれば通知**される。

```json
"watch": [
  { "building": "プラザシティ新所沢　けやき通り", "madori": "1LDK" },
  { "building": "プラザシティ新所沢　けやき通り", "madori": "2DK" }
]
```

これは「けやき通りの 1LDK **または** 2DK」という意味。間取り違いは行を分けて書く。

照合は**部分一致**なので、次の2点に注意する。

- **空文字は「その条件を問わない」**。`"madori": ""` なら建物名だけで判定する
- **短く書くと巻き込む**。上の例で全角スペースを外して `"プラザシティ新所沢けやき通り"` と
  書くと「けやき通り第二」「けやき通り第三」まで一致してしまう

### highlight_keywords

公開ページ（`docs/index.html`）の中で色を付けて目立たせるだけの設定。通知の条件とは無関係なので、
`watch` と同じ文字列を書いておくと画面上でも見つけやすい、という使い方になる。

### selectors

UR のサイト構造が変わって0件になったときに直す場所。手順は
[セレクターの調整](#セレクターの調整)を参照。省略すればコード内の既定値が使われるので、
**問題が起きていないうちは書かなくてよい**。

## Slack 通知の設定

Webhook URL は**リポジトリに置かない**。GitHub Secrets から環境変数で渡している。

- リポジトリの Settings → Secrets and variables → Actions で `SLACK_WEBHOOK_URL` を登録
- ローカルで通知まで試したい場合は環境変数として渡す：
  - Mac/Linux: `SLACK_WEBHOOK_URL=https://... php ur_monitor.php`
  - Windows (PowerShell): `$env:SLACK_WEBHOOK_URL="https://..."; php ur_monitor.php`

環境変数が未設定なら `config.json` の `slack_webhook_url` を見るが、
**そこに実際の値を書いてコミットしないこと**（Git履歴に残ると消すのが面倒）。

## 空き部屋一覧の Web 公開（GitHub Pages）

監視が生成する一覧は `docs/index.html` に書き出され、実行のたびにコミットされる。
GitHub Pages が `main` の `/docs` を配信しているので、**push されるたび自動で公開ページが最新になる**。

**外部サービスもトークンも要らない。** 手順書と同じ仕組みに相乗りしている。

| URL | 中身 |
|---|---|
| `https://<ユーザー名>.github.io/ur-monitor/` | 空き部屋一覧 |
| `https://<ユーザー名>.github.io/ur-monitor/setup.html` | セットアップ手順書 |

有効化の手順は下記「セットアップ手順書の公開（GitHub Pages）」と共通。**設定は1回だけ**でよい。

生成 HTML には `noindex, nofollow` を入れている。狙っている物件が `highlight_keywords` から
読み取れるため、検索エンジンに載せない意図。**ただし URL を知っていれば誰でも見られる。**

## 取得失敗への対策

ページは開けたのに0件になることが実際に起きる（描画完了前に読み取ってしまうなど）。
そのまま受け取ると全部屋が「成約」扱いになり、次回復活したときに誤った新着通知が飛ぶ。
そのため次の順で守っている：

1. 0件だったら5秒待って1回取り直す
2. それでも0件なら前回の状態を維持してスキップ
3. ただし3回連続（約1.5時間）で0件なら、実際に空きが尽きたと判断して受け入れる

連続回数は `state.json` の `zero_streak` に記録している。

## 既知の制約

- Actions の `schedule` は負荷状況により数分〜十数分遅れることがある
  （Windows のタスクスケジューラほど時刻に厳密ではない）
- プライベートリポジトリの Actions 無料枠は月2,000分。30分間隔なら月約800分で収まるが、
  間隔を詰めたり監視URLを増やしたりすると超過して課金される
- リポジトリに60日間動きがないとスケジュール実行が自動停止する
  （正常動作中はコミットが発生し続けるため、通常は問題にならない）
- `jitter_max_seconds` は 0。Actions の cron はもともと実行時刻がゆらぐうえ、
  待機時間がそのまま課金対象になるため
