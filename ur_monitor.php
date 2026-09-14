<?php
/**
 * ur_monitor.php — UR賃貸 空き部屋監視スクリプト
 *
 * UR賃貸のページを開いて空き部屋を取り出し、前回との差分から「新着」を見つけ、
 * config.json の条件に合うものを Slack へ通知する。
 * 本番は GitHub Actions（Linux）で動く。開発は Windows / macOS でも行うため、
 * OS 固有の処理は置かず、Chrome の場所だけ detect_chrome_path() で吸収している。
 *
 * ==========================================================================
 * 全体の中での位置づけ
 * ==========================================================================
 *   Cloudflare Worker（trigger/worker.js）
 *     └ 8〜21時（JST）の5分おきに、GitHub Actions の monitor.yml を起動する
 *   GitHub Actions（.github/workflows/monitor.yml）
 *     └ まっさらな Ubuntu に PHP と依存を入れ、このスクリプトを実行する
 *   このスクリプト
 *     └ UR を読み、差分を取り、Slack へ通知し、状態と一覧を Worker の保管庫（KV）へ預ける
 *
 * ==========================================================================
 * 起動のしかた（モード）
 * ==========================================================================
 *   php ur_monitor.php                 通常の監視（本番）。下の「処理の流れ（通常の監視）」を行う
 *   php ur_monitor.php --dry-run       開発用。取得と差分までは行うが、Slack 送信と
 *                                      state / 一覧の書き込みをしない
 *   php ur_monitor.php --seed-state    保管先を作った直後に1回だけ。前回状態を保管先へ置く
 *   php ur_monitor.php --setup         セレクター調整用。画面ありの Chrome で開き、
 *                                      debug_page.html とスクリーンショットを保存する
 *   php ur_monitor.php --check-robots  robots.txt の確認だけ。拒否が1件でもあれば終了コード1
 *
 * ==========================================================================
 * 処理の流れ（通常の監視 = run_monitor()）
 * ==========================================================================
 *  1. 設定を読む（load_config）
 *     1-1. config.json を読む。無ければ終了コード1で止まる
 *     1-2. 環境変数 SLACK_WEBHOOK_URL があれば、config の slack_webhook_url をそれで上書きする
 *
 *  2. 監視対象を整える（normalize_groups / group_url_map）
 *     2-1. config の groups を「名前・通知するか・間取り・URL 一覧」の形にそろえる
 *          （旧形式の search_urls + watch なら、全 URL を1つのグループにまとめる）
 *     2-2. 「URL → グループ」の対応表を作る。同じ URL が複数のグループにあれば上のグループを採る
 *     2-3. 監視対象が1つも無ければ終了コード1で止まる
 *
 *  3. ランダムに待つ（0〜jitter_max_seconds 秒。--dry-run では待たない）
 *     Cloudflare からの起動は毎回同じ秒に来るので、UR から見て規則的な足跡にならないようにする
 *
 *  4. 前回の状態を読む（load_state）
 *     4-1. STORE_URL / STORE_TOKEN があれば、Worker の保管先（GET /state）から読む。
 *          無ければ手元の state.json を読む（開発用）
 *     4-2. 保管先に state が無い（404）・読めない・JSON が壊れている → 終了コード1で止まる
 *          （空とみなすと全部屋が新着になり、まとめて誤通知が飛ぶため）
 *
 *  5. 監視が止まっていなかったかを見る（warn_if_stale。--dry-run では行わない）
 *     前回の実行時刻から今までの空白を、稼働時間帯（monitoring_hours）の中だけで数え、
 *     stale_warning_hours（既定3時間）以上なら Slack に「監視が止まっていました」を送る
 *
 *  6. Chrome を1回だけ起動する（create_browser）
 *
 *  7. 監視対象の URL ごとに次を繰り返す（2本目以降は3秒あけてから）
 *     7-1. 前回の state から、この URL で取れていた部屋だけを取り出しておく（失敗時の引き継ぎ用）
 *     7-2. robots.txt を確認する（check_robots_txt）。
 *          拒否されていたら取りに行かず、前回の部屋をそのまま引き継いで次の URL へ
 *     7-3. ページを開いて部屋を取り出す（scrape_url）
 *          a. ページを開き、DOM の読み込みを待つ（最長30秒）
 *          b. 部屋の行の件数が1秒間変わらなくなるまで待つ（wait_for_rooms。最長20秒）
 *          c. 各部屋の建物名・部屋名・家賃・間取り・URL を取り出す
 *     7-4. 結果が怪しいかを判定する（untrusted_result_reason）
 *          ・前回この URL に部屋があったのに、今回0件
 *          ・前回5件以上あったのに、前回の shrink_guard_ratio（既定0.7）倍を下回った
 *          怪しければ5秒待って、1回だけ取り直す
 *     7-5. 取得中に例外が起きたら、前回の部屋を引き継いで次の URL へ
 *     7-6. 取り直しても怪しければ、この URL の連続回数（zero_streak）を1増やす
 *          ・zero_streak_limit（既定18回）未満なら、前回の部屋を引き継いで次の URL へ
 *            （ログに「…のため前回状態を維持」が出る。これは正常な動作）
 *          ・達したら「本当にそうなった」と判断して、今回の結果を受け入れる
 *     7-7. 信用できる結果なら連続回数を消し、部屋を今回の一覧に加える
 *          （同じ部屋 URL が既に入っていれば、先に入った＝上のグループのほうを残す）
 *
 *  8. Chrome を閉じる（close_browser_safely。途中で例外が起きても必ず閉じる）
 *
 *  9. 信用できる結果が1つも無く、今回の一覧も空なら、何も書き込まずに終わる
 *     （state も一覧も前回のまま。取得の失敗を「空室ゼロ」と取り違えないため）
 *
 * 10. 前回と比べる
 *     ・新着   = 今回あって、前回に無い部屋 URL
 *     ・消えた = 前回あって、今回に無い部屋 URL（件数をログに出すだけ）
 *
 * 11. 新着のうち、通知すべきものを選んで送る（room_notifies / notify_watch）
 *     ・その部屋のグループが notify: true で、間取りが madori のどれかに部分一致すれば通知する
 *       （madori が空なら間取りを問わない）
 *     ・旧形式では、watch の建物名・間取りとの部分一致、または notify_all_new で決める
 *     ・通知対象があれば、グループごとにまとめて Slack へ1通で送る（--dry-run では送らない）
 *
 * 12. --dry-run なら、ここで終わる
 *
 * 13. 一覧ページ（HTML）を作って書き出す（save_html）
 *     保管先があれば PUT /list、無ければ docs/index.html（開発用）。失敗したら終了コード1
 *
 * 14. 今回の状態を書き出す（save_state）
 *     部屋の一覧・連続回数・実行時刻（last_checked）を、保管先があれば PUT /state、
 *     無ければ state.json へ書く。失敗したら終了コード1
 *     （一覧を state より先に書くのは、state だけ進んで一覧が古いまま残るのを防ぐため）
 *
 * ==========================================================================
 * 処理の流れ（--seed-state = run_seed_state()）
 * ==========================================================================
 *  1. STORE_URL / STORE_TOKEN が無ければ終了コード1
 *  2. 手元に state.json があればその中身を、無ければ空の状態（部屋0件）を用意する
 *  3. 保管先に state が既にある（200）なら、動いている状態を上書きしないよう終了コード1
 *  4. 保管先へ PUT /state する
 *  ※ 空の状態を置いた場合、次の通常実行では、いま出ている部屋がすべて新着として通知される
 *
 * ==========================================================================
 * 処理の流れ（--setup = run_setup()）
 * ==========================================================================
 *  1. 監視対象の先頭の URL を、画面ありの Chrome で開く
 *  2. 描画を待ってから、スクリーンショット（debug_日時.png）と HTML（debug_page.html）を保存する
 *  3. 取れた件数と先頭5件を表示する。0件なら、セレクターを直す手順を表示する
 *
 * ==========================================================================
 * 処理の流れ（--check-robots）
 * ==========================================================================
 *  1. 監視対象のすべての URL について robots.txt を確認する
 *  2. 1件でも拒否されていれば終了コード1
 *
 * ==========================================================================
 * 入力と出力
 * ==========================================================================
 *   入力  config.json                        監視対象・通知条件・しきい値・セレクター
 *         環境変数 SLACK_WEBHOOK_URL          Slack の送り先
 *         環境変数 STORE_URL / STORE_TOKEN    保管先の URL と合言葉（無ければローカルのファイル）
 *   出力  Slack                              新着の通知、停止の警告
 *         保管先の /state と /list           前回状態（JSON）と一覧ページ（HTML）
 *         monitor.log                        実行ログ（画面にも同じものを出す）
 *   終了コード  0 = 正常（前回状態を維持してスキップした場合も含む）
 *               1 = 設定・前回状態・書き込みの異常、または想定外の例外
 *
 * ==========================================================================
 * 判断の基準
 * ==========================================================================
 *   通知を1回逃すより、誤った通知を飛ばすほうが害が大きい。
 *   そのため「怪しい結果は採用しない」「前回状態が分からなければ止まる」を優先している。
 *
 * ==========================================================================
 * コード内の節（// ── で区切ってある）
 * ==========================================================================
 *   ユーティリティ / 実行結果の保管先 / robots.txt チェック / スクレイピング / HTML 出力 /
 *   Slack 通知 / 監視が止まっていないかの見張り / メイン処理 / エントリーポイント
 */

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/vendor/autoload.php';

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;

define('BASE_DIR', __DIR__);
define('CONFIG_FILE',  BASE_DIR . '/config.json');
define('STATE_FILE',   BASE_DIR . '/state.json');
define('LOG_FILE',     BASE_DIR . '/monitor.log');
// 保管先（STORE_URL）が未設定のときだけ使う、開発用の一覧の書き出し先。
// 本番の一覧は Worker の KV に置く。docs/ は Pages の公開ディレクトリなのでコミットしないこと。
define('RESULTS_FILE', BASE_DIR . '/docs/index.html');

// ──────────────────────────────────────────
// ユーティリティ
// ──────────────────────────────────────────

/**
 * ログを1行出す。
 *
 * 画面（標準出力）と monitor.log の両方に、時刻とレベルを付けて書く。
 * GitHub Actions では画面の出力がそのまま実行ログになる。
 *
 * @param string $level ログの重さ（INFO / WARNING / ERROR）
 * @param string $msg   本文
 * @return void
 */
function log_msg(string $level, string $msg): void
{
    $ts   = date('Y-m-d H:i:s');
    $line = "[{$ts}] [{$level}] {$msg}";
    echo $line . PHP_EOL;
    file_put_contents(LOG_FILE, $line . PHP_EOL, FILE_APPEND);
}

/**
 * config.json を読み、秘密情報を環境変数から補う。
 *
 * 1. config.json が無ければ終了コード1で止まる
 * 2. JSON として読む（解釈できなければ空の設定になり、後で「監視対象が無い」として止まる）
 * 3. 環境変数 SLACK_WEBHOOK_URL があれば、slack_webhook_url をそれで上書きする
 *
 * @return array 設定
 */
function load_config(): array
{
    if (!file_exists(CONFIG_FILE)) {
        echo "[ERROR] config.json が見つかりません\n";
        exit(1);
    }
    $config = json_decode(file_get_contents(CONFIG_FILE), true) ?? [];

    // GitHub Actions では Secrets を環境変数として渡す（config.json に平文で秘密を置かないため）。
    // ローカル実行時に環境変数が未設定なら config.json の値をそのまま使う。
    $envWebhook = getenv('SLACK_WEBHOOK_URL');
    if ($envWebhook !== false && $envWebhook !== '') {
        $config['slack_webhook_url'] = $envWebhook;
    }

    return $config;
}

// ──────────────────────────────────────────
// 実行結果の保管先
// ──────────────────────────────────────────
//
// state と一覧 HTML は Cloudflare Worker の KV に置く。**リポジトリに戻さない。**
// 公開リポジトリにコミットすると、UR から取った物件名・家賃・間取りが誰にでも
// 読める状態になる。UR は「このサイトについて」で私的使用と引用を除く転載を認めて
// おらず、しかも「ウェブページに貼り付けることは、運営者が個人であっても私的使用に
// はならない」と明示している。ここを GitHub Pages に戻さないこと。
//
// STORE_URL / STORE_TOKEN が無ければ従来どおりローカルのファイルを使う。
// 開発中の --dry-run が秘密情報なしでそのまま動くようにするため。

/**
 * 保管先（Cloudflare Worker）の URL と合言葉を、環境変数から取り出す。
 *
 * STORE_URL と STORE_TOKEN の両方がそろっているときだけ有効とみなす。
 * 片方でも欠けていれば「保管先なし」を返し、呼び出し側はローカルのファイルを使う。
 *
 * @return array{0: string, 1: string} [URL（末尾の / は除く）, 合言葉]。保管先なしなら ['', '']
 */
function store_conf(): array
{
    $url   = (string)(getenv('STORE_URL')   ?: '');
    $token = (string)(getenv('STORE_TOKEN') ?: '');
    return ($url !== '' && $token !== '') ? [rtrim($url, '/'), $token] : ['', ''];
}

/**
 * 保管先へ HTTP リクエストを1回送る。
 *
 * 合言葉を Authorization: Bearer に付けて送り、4xx / 5xx でも本文を受け取る（タイムアウト15秒）。
 * 通信自体が失敗したらステータスは 0 を返す（呼び出し側が「読めなかった」と扱えるように）。
 *
 * @param string      $method HTTP メソッド（GET / PUT）
 * @param string      $path   保管先のパス（/state または /list）
 * @param string|null $body   送る本文。null なら本文なし
 * @return array{0: int, 1: string} [HTTP ステータス（通信失敗は 0）, 応答の本文]
 */
function store_request(string $method, string $path, ?string $body = null): array
{
    [$base, $token] = store_conf();
    $headers = "Authorization: Bearer {$token}\r\n";
    if ($body !== null) {
        $headers .= "Content-Type: application/json; charset=utf-8\r\n";
    }
    $ctx = stream_context_create(['http' => [
        'method'        => $method,
        'header'        => $headers,
        'content'       => $body ?? '',
        'timeout'       => 15,
        // 4xx/5xx でも本文を受け取りたい。false のままだと警告だけ出て中身が取れない
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents($base . $path, false, $ctx);
    if ($res === false) {
        return [0, ''];
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int)$m[1];   // リダイレクトがあれば最後のものが残る
        }
    }
    return [$status, $res];
}

/**
 * 前回の状態（state）を読む。
 *
 * 1. 保管先があれば GET /state で読む
 *    ・404（まだ置かれていない）なら、全部屋が新着になるのを避けて終了コード1
 *    ・200 以外なら、前回状態が分からないので終了コード1
 * 2. 保管先が無ければ state.json を読む
 *    ・ファイルが無ければ初回とみなし、部屋0件の状態を返す（開発用の経路）
 *    ・読めなければ終了コード1
 * 3. JSON として解釈できなければ終了コード1
 *
 * state の中身:
 *   rooms        部屋 URL → 部屋の情報（building / name / price / floor_plan / url / source_url / group）
 *   zero_streak  監視 URL → 怪しい結果が続いた回数
 *   last_checked 前回の実行時刻（ISO 8601）
 *
 * @return array 前回の状態
 */
function load_state(): array
{
    [$base] = store_conf();
    if ($base !== '') {
        [$status, $raw] = store_request('GET', '/state');
        // 「まだ無い」を初回とみなして続けると、全部屋が新着になってまとめて Slack に飛ぶ。
        // 保管先を用意した直後は必ずここを通るので、黙って続けず手順を示して止める。
        if ($status === 404) {
            log_msg('ERROR', '保管先に state がありません。全部屋を新着として通知して'
                . 'しまうため中止します。初回は監視ワークフローを seed_state を有効にして'
                . '手動実行し、いまの state.json を保管先へ移してください');
            exit(1);
        }
        if ($status !== 200) {
            log_msg('ERROR', "保管先から state を読めませんでした（HTTP {$status}）。"
                . '前回状態が分からないため中止します');
            exit(1);
        }
    } else {
        if (!file_exists(STATE_FILE)) {
            return ['rooms' => []];  // 初回実行。全部屋が新着として扱われる
        }
        $raw = file_get_contents(STATE_FILE);
        if ($raw === false) {
            log_msg('ERROR', 'state.json を読めませんでした。前回状態が分からないため中止します');
            exit(1);
        }
    }

    $decoded = json_decode($raw, true);
    // 壊れた state を空とみなすと「全部屋が新着」になり、条件に合うものが
    // まとめて Slack に飛ぶ。誤通知のほうが害が大きいので、黙って続けず止める。
    if (!is_array($decoded)) {
        log_msg('ERROR', 'state.json を解釈できませんでした（壊れている可能性があります）。'
            . '全部屋を新着として通知してしまうため中止します。'
            . '意図的にやり直す場合は state.json を {"rooms":{}} にしてください');
        exit(1);
    }

    return $decoded;
}

/**
 * 今回の状態（state）を書き出す。
 *
 * 保管先があれば PUT /state、無ければ state.json に JSON で書く。
 * 書けなかったら終了コード1で止まる（次回が古い state で動き、消えた部屋がまた新着になるため）。
 *
 * @param array $state rooms・zero_streak・last_checked を持つ状態
 * @return void
 */
function save_state(array $state): void
{
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    [$base] = store_conf();
    if ($base !== '') {
        [$status] = store_request('PUT', '/state', $json);
        // 書けていないと次回が古い state で動き、消えたはずの部屋がまた新着になる
        if ($status !== 200) {
            log_msg('ERROR', "保管先へ state を書けませんでした（HTTP {$status}）");
            exit(1);
        }
        return;
    }

    $ok = file_put_contents(STATE_FILE, $json);
    // 書けていないと次回の差分計算が狂うため、成功したことにして進まない
    if ($ok === false) {
        log_msg('ERROR', 'state.json の書き込みに失敗しました: ' . STATE_FILE);
        exit(1);
    }
}

// ──────────────────────────────────────────
// robots.txt チェック
// ──────────────────────────────────────────

// robots.txt の Disallow / Allow を照合する。
//
// UR の robots.txt は `Disallow: /chintai/*/result/?skcs=` のようにワイルドカードと
// クエリ文字列を使うため、パスの前方一致だけでは判定できない。`*`（任意文字列）と
// 末尾 `$`（終端固定）を正規表現に変換し、クエリを含めた文字列に対して照合する。
// Allow は「より長く一致した方が勝つ」という一般的な解釈に従う。

/**
 * robots.txt の1つのルール（Disallow / Allow の値）が、URL に当てはまるかを判定する。
 *
 * ルール中の「*」を任意の文字列、末尾の「$」を終端として正規表現に直し、
 * URL のパスとクエリを先頭から照合する。
 *
 * @param string $rule   ルールの値（例: /chintai/ で始まる文字列。* と末尾 $ を含んでよい）
 * @param string $target 判定する URL のパスとクエリ（例: /chintai/kanto/saitama/area/208.html）
 * @return bool 当てはまれば true
 */
function robots_rule_matches(string $rule, string $target): bool
{
    // メタ文字を殺してから、robots.txt での意味を持つ * と末尾 $ だけを戻す
    $anchored = str_ends_with($rule, '$');
    $body     = $anchored ? substr($rule, 0, -1) : $rule;
    $regex    = str_replace('\\*', '.*', preg_quote($body, '#'));

    return (bool)preg_match('#^' . $regex . ($anchored ? '$' : '') . '#', $target);
}

/**
 * URL へのアクセスを robots.txt が許可しているかを確認する。
 *
 * 1. そのホストの robots.txt を PHP で取得する（Chrome は使わない。同じホストは1回だけ取得）
 * 2. 取得できなければ警告を出して「許可」とみなす（robots.txt が無いサイトは全許可が既定）
 * 3. User-agent: * のブロックだけを読み、当てはまる Disallow / Allow のうち最長のものを探す
 * 4. Disallow のほうが長く当てはまれば拒否、それ以外は許可
 *
 * robots.txt が取得できても Chrome が UR に届くとは限らない（通信の経路が違う）点に注意。
 *
 * @param string $url 確認する URL
 * @return bool 許可なら true、拒否なら false
 */
function check_robots_txt(string $url): bool
{
    static $contentCache = [];

    $parsed     = parse_url($url);
    $host       = $parsed['host'] ?? '';
    $robots_url = "{$parsed['scheme']}://{$host}/robots.txt";

    // robots.txt はホスト単位なので、同一ホストへの複数 URL では取得結果を使い回す
    if (!array_key_exists($host, $contentCache)) {
        $ctx = stream_context_create(['http' => [
            'timeout' => 10,
            'header'  => "User-Agent: Mozilla/5.0\r\n",
        ]]);
        $contentCache[$host] = @file_get_contents($robots_url, false, $ctx);
    }
    $content = $contentCache[$host];

    if ($content === false) {
        // 取得できないだけで拒否と決めつけない（robots.txt が無いサイトは全許可が既定）
        log_msg('WARNING', "robots.txt 取得失敗（続行します）");
        return true;
    }

    // 判定対象はクエリまで含める。Disallow がクエリ文字列を指すことがあるため。
    $target = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');

    $currentAgent = null;
    $disallowLen  = -1;  // 一致した Disallow のうち最長のもの
    $allowLen     = -1;  // 一致した Allow のうち最長のもの

    foreach (explode("\n", $content) as $line) {
        // 行内コメントを落としてから判定する
        $line = trim((string)preg_replace('/#.*$/', '', $line));

        if (stripos($line, 'user-agent:') === 0) {
            $currentAgent = trim(substr($line, 11));
            continue;
        }
        if ($currentAgent !== '*') {
            continue; // 自分（一般クローラー）宛でないブロックは読み飛ばす
        }

        if (stripos($line, 'disallow:') === 0) {
            $rule = trim(substr($line, 9));
            // 空の Disallow は「制限なし」の意味なので無視する
            if ($rule !== '' && robots_rule_matches($rule, $target)) {
                $disallowLen = max($disallowLen, strlen($rule));
            }
        } elseif (stripos($line, 'allow:') === 0) {
            $rule = trim(substr($line, 6));
            if ($rule !== '' && robots_rule_matches($rule, $target)) {
                $allowLen = max($allowLen, strlen($rule));
            }
        }
    }

    if ($disallowLen >= 0 && $disallowLen > $allowLen) {
        log_msg('ERROR', "robots.txt が許可していない URL です（スキップします）: {$url}");
        return false;
    }

    log_msg('INFO', "robots.txt チェック OK ({$robots_url})");
    return true;
}

// ──────────────────────────────────────────
// スクレイピング
// ──────────────────────────────────────────

/**
 * ディレクトリを中身ごと削除する。
 *
 * 壊れた Chrome のプロファイルを掃除するために使う。ディレクトリが無ければ何もしない。
 *
 * @param string $dir 削除するディレクトリ
 * @return void
 */
function remove_dir_recursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

/**
 * Chrome の実行ファイルの場所を探す。
 *
 * 1. config.json に chrome_path があり、そのファイルが存在すればそれを使う
 *    （存在しなければ警告を出して 2 へ）
 * 2. OS ごとの既定の場所を順に探す（Windows / macOS / Linux）
 * 3. どこにも無ければ null を返し、chrome-php の既定の探し方（PATH 上の chrome）に任せる
 *
 * 本番は GitHub Actions（Linux）だが、開発は Windows / macOS でも行うため 3 OS 分の既定パスを見る。
 * chrome_path は環境差の逃げ道として残してあり、本番では設定しない。
 *
 * @param array $config 設定
 * @return string|null 実行ファイルのパス。見つからなければ null
 */
function detect_chrome_path(array $config): ?string
{
    $configured = trim((string)($config['chrome_path'] ?? ''));
    if ($configured !== '') {
        if (is_file($configured)) {
            return $configured;
        }
        log_msg('WARNING', "chrome_path が見つかりません: {$configured}（自動検出に切り替えます）");
    }

    $candidates = match (PHP_OS_FAMILY) {
        'Windows' => [
            getenv('ProgramFiles') . '\\Google\\Chrome\\Application\\chrome.exe',
            getenv('ProgramFiles(x86)') . '\\Google\\Chrome\\Application\\chrome.exe',
            getenv('LOCALAPPDATA') . '\\Google\\Chrome\\Application\\chrome.exe',
        ],
        'Darwin' => [
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            getenv('HOME') . '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
        ],
        default => [
            '/usr/bin/google-chrome-stable',
            '/usr/bin/google-chrome',
            // Claude Code on the web のサンドボックスには Chrome が無く、セットアップ
            // スクリプトで入れてここに symlink を張る（tools/cloud-setup.sh 参照）
            '/usr/local/bin/google-chrome-stable',
            '/usr/bin/chromium-browser',
            '/usr/bin/chromium',
        ],
    };

    foreach ($candidates as $path) {
        if ($path !== '' && is_file($path)) {
            return $path;
        }
    }

    // どれも無ければ null を返し、chrome-php 既定の探索（PATH 上の chrome）に委ねる。
    return null;
}

/**
 * Chrome を閉じる。閉じるときに起きた例外は、ログに残して無視する。
 *
 * Chrome 側がソケットを先に閉じると「Socket is not connected」が飛ぶことがあるが、
 * スクレイプ結果には影響しないため、処理を止めない。
 *
 * @param object $browser chrome-php の Browser
 * @return void
 */
function close_browser_safely(object $browser): void
{
    try {
        $browser->close();
    } catch (\Throwable $e) {
        log_msg('INFO', "ブラウザ終了時の例外を無視: " . $e->getMessage());
    }
}

/**
 * config.json の chrome_flags から、Chrome に追加する起動フラグを取り出す。
 *
 * 配列でない・文字列でない・「--」で始まらない要素は、警告を出して捨てる。
 * 空文字は黙って捨てる。
 *
 * chrome_path と同じ「環境差の逃げ道」で、本番では空のまま使わない想定。
 * 実行環境側の都合（プロキシ、TLS、サンドボックス）でコード変更なしに逃げられるようにしておく。
 *
 * @param array $config 設定
 * @return string[] 使ってよい起動フラグの一覧
 */
function configured_chrome_flags(array $config): array
{
    $raw = $config['chrome_flags'] ?? [];
    if (!is_array($raw)) {
        log_msg('WARNING', 'chrome_flags は配列で指定してください（無視します）');
        return [];
    }

    $flags = [];
    foreach ($raw as $flag) {
        if (!is_string($flag)) {
            log_msg('WARNING', 'chrome_flags に文字列でない要素があります（無視します）');
            continue;
        }
        $flag = trim($flag);
        if ($flag === '') {
            continue;
        }
        // 引数の取り違えでコマンドラインが壊れるのを防ぐため、フラグ形式だけを通す
        if (!str_starts_with($flag, '--')) {
            log_msg('WARNING', "chrome_flags は -- で始まる必要があります: {$flag}（無視します）");
            continue;
        }
        $flags[] = $flag;
    }

    return $flags;
}

/**
 * Chrome を起動する。
 *
 * 1. 作業用のプロファイルを一時ディレクトリに用意する（通常用と --setup 用で分ける）
 * 2. 既定の起動フラグ（画像を読まない、UA を通常のブラウザに見せる など）の後ろに
 *    chrome_flags を足す（同じフラグは後ろの config 側が勝つ）
 * 3. 起動する。失敗したらプロファイルを消して、1回だけやり直す
 *
 * @param array $config   設定
 * @param bool  $headless true なら画面なし（通常の監視）、false なら画面あり（--setup）
 * @return object chrome-php の Browser
 */
function create_browser(array $config, bool $headless = true): object
{
    // 作業用プロファイルは一時ディレクトリに置く（リポジトリを汚さないため）。
    // headless（通常実行）と setup（手動デバッグ）で分け、同時起動時のプロファイルロック衝突を防ぐ。
    $profileDir = sys_get_temp_dir() . '/chrome-ur-monitor' . ($headless ? '' : '-setup');

    $flags = [
        '--disable-gpu',
        '--no-sandbox',
        '--disable-dev-shm-usage',
        '--disable-extensions',
        '--blink-settings=imagesEnabled=false', // 画像を読まず転送量と実行時間を削る
        // ヘッドレス特有の UA(HeadlessChrome) を隠し、通常ブラウザとして振る舞う
        '--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36',
    ];

    // 後ろに置くことで、同じフラグを指定されたときに config 側を優先させる
    $extra = configured_chrome_flags($config);
    if ($extra !== []) {
        log_msg('INFO', 'chrome_flags を追加: ' . implode(' ', $extra));
        $flags = array_merge($flags, $extra);
    }

    $options = [
        'headless'    => $headless,
        'userDataDir' => $profileDir,
        'customFlags' => $flags,
    ];

    $factory = new BrowserFactory(detect_chrome_path($config));
    try {
        return $factory->createBrowser($options);
    } catch (\Throwable $e) {
        // 強制終了やスリープでプロファイルが壊れると以降の起動が毎回失敗するため、
        // 壊れたプロファイルを消して 1 回だけ再試行する（無人実行の自己回復）。
        log_msg('WARNING', "Chrome 起動失敗。プロファイルを再作成して再試行: " . $e->getMessage());
        remove_dir_recursive($profileDir);
        return $factory->createBrowser($options);
    }
}

/**
 * 部屋の行が描画され、件数が増えなくなるまで待つ。
 *
 * 0.3秒ごとに「リンクを持つ部屋の行」の数を数え、1件以上あって1秒間変わらなければ完了とする。
 * $timeoutSec 秒待っても落ち着かなければ、警告を出して false を返す。
 *
 * UR のページは物件情報を HTML と一緒に返さず、描画後に JS が取得して差し込む。
 * そのため DOMContentLoaded の時点では中身が空で、固定秒数の待機だと回線やサーバーが
 * 遅いときに空の DOM を読んで 0 件になる（実際に発生を確認済み）。
 * 件数が 1 秒間変化しなくなるまで見るのは、行が順次描画される途中で読み取って
 * 取りこぼすのを防ぐため。現れれば即座に返るので通常は数秒で終わる。
 *
 * @param object $page       chrome-php の Page
 * @param array  $selectors  セレクター（room_rows と link を使う）
 * @param int    $timeoutSec 最長の待ち時間（秒）
 * @return bool 描画を確認できたら true。時間切れなら false（本当に空室ゼロのページでも false）
 */
function wait_for_rooms(object $page, array $selectors, int $timeoutSec = 20): bool
{
    // room_rows には部屋以外の行（「リノベーションしたお部屋とは？」等の説明の吹き出し。
    // class に js-room-pict を持つ）も混ざる。それらはリンクを持たず後段で捨てられるので、
    // 待機の判定は「リンクを持つ行＝実際に欲しい部屋」の数だけを見る。
    $js = sprintf(
        'Array.from(document.querySelectorAll(%s)).filter(r => r.querySelector(%s)).length',
        json_encode($selectors['room_rows'], JSON_UNESCAPED_UNICODE),
        json_encode($selectors['link'], JSON_UNESCAPED_UNICODE)
    );

    $deadline    = microtime(true) + $timeoutSec;
    $lastCount   = -1;
    $stableSince = null;

    while (microtime(true) < $deadline) {
        $count = (int)$page->evaluate($js)->getReturnValue(10000);

        if ($count > 0) {
            if ($count === $lastCount) {
                $stableSince ??= microtime(true);
                if (microtime(true) - $stableSince >= 1.0) {
                    return true; // 件数が落ち着いた＝描画完了
                }
            } else {
                $stableSince = null; // まだ増えている
            }
        }
        $lastCount = $count;
        usleep(300000);
    }

    // 本当に空室ゼロのページでもここに来る。呼び出し側が前回状態との比較で判断する。
    log_msg('WARNING', "部屋行が現れないまま {$timeoutSec} 秒でタイムアウトしました");
    return false;
}

/**
 * 1つの URL を開いて、空き部屋の一覧を取り出す。
 *
 * 1. セレクターを決める（コード内の既定値を、config.json の selectors で上書き）
 * 2. 新しいタブでページを開き、DOM の読み込みを待つ（最長30秒）
 * 3. 部屋の行が描画されるまで待つ（wait_for_rooms）
 * 4. 画面ありのとき（--setup）は、スクリーンショットと HTML を保存する
 * 5. ページ内で JavaScript を実行して部屋を取り出す
 *    ・検索結果ページは団地ごとの箱から、団地ページは見出し（h1）から建物名を取る
 *    ・リンクを持たない行（「リノベーションしたお部屋とは？」などの吹き出し）は捨てる
 *    ・家賃欄が空の割引対象の部屋は、行の文字から金額を拾う
 * 6. 部屋の URL を絶対 URL にそろえ、欠けた項目には「（名称不明）」「（家賃不明）」を入れる
 * 7. タブを閉じる（途中で例外が起きても閉じる）
 *
 * 取得中の例外は呼び出し側へそのまま投げる（run_monitor が前回状態の引き継ぎで扱う）。
 *
 * @param object $browser  chrome-php の Browser
 * @param string $url      開く UR のページ
 * @param array  $config   設定
 * @param bool   $headless false なら --setup 用のスクリーンショットと HTML も保存する
 * @return array[] 部屋の一覧。各要素は building / name / price / floor_plan / url を持つ
 */
function scrape_url(object $browser, string $url, array $config, bool $headless = true): array
{
    // 建物レベル・部屋レベルすべてのセレクターを config から取得（未指定は現行サイトのデフォルト）
    // タグ名を書かず class だけで指定している。UR は同じ情報を、検索結果ページでは
    // <strong>/<span>、団地ページでは <span>/<td> と別のタグで出す。class は共通なので、
    // タグ名を外すと1組のセレクターで両方から取れる。タグ名を書き戻さないこと。
    $selectors = array_replace([
        'room_list'      => '.module_searchs_property',   // 検索結果ページの団地ごとの箱
        'page_name'      => 'h1',                         // 団地ページの団地名（箱が無いとき）
        'name'           => '.rep_bukken-name',
        'room_rows'      => 'tr.js-log-item',
        'room_name_main' => '.rep_room-name-main',        // 検索結果ページ：号棟
        'room_name_sub'  => '.rep_room-name-sub',         // 検索結果ページ：号室
        'room_name'      => '.rep_room-name',             // 団地ページ：号棟と号室がひとかたまり
        'price'          => '.rep_room-price',
        'type'           => '.rep_room-type',
        'space'          => '.rep_room-floor',
        'floor'          => '.rep_room-kai',
        'link'           => '.rep_room-link',
    ], $config['selectors'] ?? []);

    $parsed = parse_url($url);
    $base   = "{$parsed['scheme']}://{$parsed['host']}";
    $rooms  = [];

    log_msg('INFO', "ページ取得中: {$url}");

    $page = null;
    try {
        $page = $browser->createPage();
        $page->navigate($url)->waitForNavigation(Page::DOM_CONTENT_LOADED, 30000);

        // DOMContentLoaded では物件情報がまだ入っていないため、実際に部屋行が
        // 描画されるまで待つ。debug 出力より先に行い、保存される HTML も
        // 描画後のもの（＝セレクター調整に使える状態）にする。
        wait_for_rooms($page, $selectors);

        if (!$headless) {
            $ts       = date('Ymd_His');
            $ssPath   = BASE_DIR . "/debug_{$ts}.png";
            $htmlPath = BASE_DIR . '/debug_page.html';
            $page->screenshot()->saveToFile($ssPath, 30000);
            $html = $page->evaluate('document.documentElement.outerHTML')->getReturnValue(30000);
            file_put_contents($htmlPath, $html);
            log_msg('INFO', "スクリーンショット保存: {$ssPath}");
            log_msg('INFO', "HTML 保存: {$htmlPath}");
        }

        // セレクターマップを1回の json_encode で JS オブジェクトとして安全に渡す
        // （addslashes は CSS の \ や改行を誤処理するため不可）
        $selJson = json_encode($selectors, JSON_UNESCAPED_UNICODE);

        $js = <<<JS
            (() => {
                const S = {$selJson};
                const rooms = [];
                // 検索結果ページは団地ごとの箱があるが、団地ページには無い（ページ全体で1団地）。
                // 箱が見つからなければページ全体を1つの箱として扱い、名前は見出しから取る。
                let boxes = Array.from(document.querySelectorAll(S.room_list));
                let fallbackName = '';
                if (boxes.length === 0) {
                    boxes = [document];
                    // 見出しにはふりがな（rt）と所在地（item_sub）が同居している。
                    // そのまま読むと「けやき通り ぷらざしてぃ… (埼玉県所沢市の賃貸物件)」に
                    // なるので、名前でないものを落としてから読む。元の DOM は壊さないよう複製する。
                    const h0 = document.querySelector(S.page_name);
                    let h = null;
                    if (h0) {
                        h = h0.cloneNode(true);
                        if (S.page_name_ignore) {
                            h.querySelectorAll(S.page_name_ignore).forEach(el => el.remove());
                        }
                    }
                    // 切り離した要素は innerText が空になることがあるため textContent も見る。
                    // この JS は PHP のヒアドキュメントの中にあり、書いたエスケープ列が
                    // 実際の制御文字に変換されてしまう。だから改行を表す記法は使わず、
                    // 文字コードから組み立てて切っている。ここに正規表現を書かないこと。
                    const raw = h ? ((h.innerText || h.textContent || '').trim()) : '';
                    const nl  = raw.indexOf(String.fromCharCode(10));
                    fallbackName = (nl >= 0 ? raw.slice(0, nl) : raw).trim();
                }
                boxes.forEach(building => {
                    const nameEl = building.querySelector(S.name);
                    const buildingName = (nameEl ? (nameEl.innerText || '').trim() : '') || fallbackName;
                    // 部屋以外の行（「リノベーションしたお部屋とは？」等の吹き出し）が
                    // 同数混ざる。リンクを持たないので、ここで落とす。落とさないと
                    // 件数が倍に見え、急減ガードの判定が甘くなる。
                    const rows = Array.from(building.querySelectorAll(S.room_rows))
                        .filter(row => row.querySelector(S.link));
                    rows.forEach(row => {
                        const roomMain  = (row.querySelector(S.room_name_main) || {}).innerText?.trim() || '';
                        const roomSub   = (row.querySelector(S.room_name_sub)  || {}).innerText?.trim() || '';
                        const roomOne   = (row.querySelector(S.room_name)      || {}).innerText?.trim() || '';
                        const roomLabel = [roomMain, roomSub].filter(Boolean).join(' ') || roomOne;
                        let price       = (row.querySelector(S.price)          || {}).innerText?.trim() || '';
                        if (!price) {
                            // 割引対象の部屋はサイト側が通常の家賃欄を使わず
                            // 「割引適用前家賃： 98,300円 / 家賃についてはお問い合わせください」と出す。
                            // セレクターに載らないだけで金額自体は表示されているので行テキストから拾う。
                            const rowText = row.innerText || '';
                            const m = rowText.match(/割引適用前家賃[：:]\s*([\d,]+円)/);
                            if (m) {
                                price = m[1] + '（割引前・要問合せ）';
                            } else {
                                const g = rowText.match(/[\d,]+円/);
                                if (g) { price = g[0] + '（要確認）'; }
                            }
                        }
                        const type      = (row.querySelector(S.type)           || {}).innerText?.trim() || '';
                        const space     = (row.querySelector(S.space)          || {}).innerText?.trim() || '';
                        const floor     = (row.querySelector(S.floor)          || {}).innerText?.trim() || '';
                        const link      = (row.querySelector(S.link)           || {}).href || '';
                        rooms.push({
                            building:   buildingName,
                            name:       buildingName + (roomLabel ? ' ' + roomLabel : ''),
                            price,
                            floor_plan: [type, space, floor].filter(Boolean).join(' / '),
                            url:        link,
                        });
                    });
                });
                return rooms;
            })()
JS;

        $result = $page->evaluate($js)->getReturnValue(30000);
        log_msg('INFO', count($result) . " 件の部屋を検出");

        foreach ($result as $r) {
            $href = $r['url'] ?? '';
            if ($href && !str_starts_with($href, 'http')) {
                $href = $base . $href;
            }
            $rooms[] = [
                'building'   => $r['building'] ?? '',
                'name'       => $r['name']  ?: '（名称不明）',
                'price'      => $r['price'] ?: '（家賃不明）',
                'floor_plan' => $r['floor_plan'] ?? '',
                'url'        => $href,
            ];
        }
    } finally {
        if ($page !== null) {
            $page->close();
        }
    }

    return $rooms;
}

/**
 * --setup 用に、Chrome の起動・1つの URL の取得・Chrome の終了をまとめて行う。
 *
 * 内部で create_browser を呼ぶため、複数 URL をループで処理する用途には使わないこと
 * （その場合は create_browser を1回だけ呼び、scrape_url を直接ループさせる）。
 *
 * @param array $config   設定（search_url に取得する URL を入れておく）
 * @param bool  $headless false なら画面ありで開き、スクリーンショットと HTML を保存する
 * @return array[] 部屋の一覧
 */
function scrape_rooms(array $config, bool $headless = true): array
{
    $browser = create_browser($config, $headless);
    try {
        return scrape_url($browser, $config['search_url'], $config, $headless);
    } finally {
        close_browser_safely($browser);
    }
}

// ──────────────────────────────────────────
// HTML 出力
// ──────────────────────────────────────────

/**
 * 空き部屋の一覧ページ（HTML）を作って書き出す。
 *
 * 1. 「候補」（通知の対象になる部屋）を判定する関数を用意し、件数を数える
 * 2. 部屋をグループごとに分ける（config の並び順のまま。グループ名の無い古い部屋は先頭へ）
 * 3. グループごとに見出しとカードを作る。新着には「NEW」、候補には「候補」の印を付ける
 * 4. 上段に件数（空き部屋・新着・候補）を置き、docs_base_url があれば上部メニューに資料へのリンクを置く
 * 5. 保管先があれば PUT /list、無ければ docs/index.html に書く。失敗したら終了コード1
 *
 * 生成時刻を埋め込むので、部屋に変化が無くても毎回内容が変わる。
 *
 * @param array[]  $rooms   今回の部屋の一覧
 * @param string[] $newUrls 新着の部屋 URL
 * @param array[]  $groups  normalize_groups() の結果
 * @param array    $config  設定
 * @return void
 */
function save_html(array $rooms, array $newUrls, array $groups, array $config = []): void
{
    $ts       = date('Y-m-d H:i');
    $count    = count($rooms);
    $newCount = count($newUrls);
    $highlightKeywords = $config['highlight_keywords'] ?? [];

    // 「候補」の件数。希望順位のグループが通知対象なら、そこにある部屋が候補。
    // 旧形式のときだけ、従来どおり highlight_keywords との一致を見る。
    $legacy   = !empty($groups[0]['legacy']);
    $notifyOf = [];
    foreach ($groups as $g) {
        $notifyOf[$g['name']] = !empty($g['notify']);
    }
    $isHotRoom = function (array $r) use ($legacy, $notifyOf, $highlightKeywords, $groups, $config): bool {
        if ($legacy) {
            foreach ($highlightKeywords as $kw) {
                if ($kw !== '' && str_contains($r['name'], $kw)) { return true; }
            }
            return false;
        }
        if (empty($notifyOf[$r['group'] ?? ''])) {
            return false;
        }
        // 通知するグループでも、間取りで絞っていれば外れる部屋がある
        foreach ($groups as $g) {
            if ($g['name'] === ($r['group'] ?? '')) {
                return room_notifies($r, $g, $config);
            }
        }
        return false;
    };

    $hotCount = 0;
    foreach ($rooms as $r) {
        if ($isHotRoom($r)) { $hotCount++; }
    }

    // 希望順位のグループごとにまとめる（config の並び順のまま）
    $grouped = [];
    foreach ($groups as $g) {
        $grouped[$g['name']] = [];
    }
    foreach ($rooms as $r) {
        $key = $r['group'] ?? '';
        if (!isset($grouped[$key])) {
            $key = array_key_first($grouped);  // 旧 state から読んだ、グループ名の無い部屋
        }
        $grouped[$key][] = $r;
    }

    $sections = '';
    foreach ($groups as $g) {
        $areaRooms = $grouped[$g['name']] ?? [];
        $aCount    = count($areaRooms);
        $nameEsc   = htmlspecialchars($g['name']);
        $urlEsc    = htmlspecialchars($g['urls'][0] ?? '');
        $quiet     = empty($g['notify']) ? ' <span class="area-quiet">通知しない</span>' : '';

        $sections .= "<section class=\"area\">\n";
        $sections .= "<div class=\"area-head\">\n";
        $sections .= "  <h2>{$nameEsc}<span class=\"area-count\">{$aCount} 件</span>{$quiet}</h2>\n";
        if ($urlEsc !== '') {
            $sections .= "  <a class=\"area-src\" href=\"{$urlEsc}\" target=\"_blank\" rel=\"noopener\">UR のページで見る →</a>\n";
        }
        $sections .= "</div>\n";

        if ($aCount === 0) {
            $sections .= "<p class=\"empty\">いま空き部屋はありません</p>\n</section>\n";
            continue;
        }

        $cards = '';
        foreach ($areaRooms as $r) {
            $isNew = in_array($r['url'], $newUrls, true);

            $isHot = $isHotRoom($r);

            $cls = 'card';
            if ($isNew) { $cls .= ' is-new'; }
            if ($isHot) { $cls .= ' is-hot'; }

            $name  = htmlspecialchars($r['name']);
            $price = htmlspecialchars($r['price']);
            $fp    = htmlspecialchars($r['floor_plan']);
            $href  = $r['url'] ? htmlspecialchars($r['url']) : '';

            $badges = '';
            if ($isNew) { $badges .= '<span class="tag tag-new">NEW</span>'; }
            if ($isHot) { $badges .= '<span class="tag tag-hot">候補</span>'; }
            if ($badges !== '') { $badges = "<p class=\"tags\">{$badges}</p>\n"; }

            // カード全体をリンクにする。通知から来てそのまま指で押せるようにするため
            $open  = $href ? "<a class=\"{$cls}\" href=\"{$href}\" target=\"_blank\" rel=\"noopener\">" : "<div class=\"{$cls}\">";
            $close = $href ? '</a>' : '</div>';
            $arrow = $href ? '<span class="go">詳細 →</span>' : '';

            $cards .= "{$open}\n{$badges}<p class=\"c-name\">{$name}</p>\n"
                    . "<p class=\"c-plan\">{$fp}</p>\n"
                    . "<p class=\"c-foot\"><span class=\"c-price\">{$price}</span>{$arrow}</p>\n{$close}\n";
        }
        $sections .= "<div class=\"cards\">\n{$cards}</div>\n</section>\n";
    }

    $hotTile = $hotCount > 0
        ? "  <div class=\"tile tile-hot\"><p class=\"t-num\">{$hotCount}</p><p class=\"t-lbl\">候補の部屋</p></div>\n"
        : '';
    $newTile = "  <div class=\"tile" . ($newCount > 0 ? ' tile-new' : '') . "\"><p class=\"t-num\">{$newCount}</p><p class=\"t-lbl\">新着</p></div>\n";

    // 資料ページへのリンク。この一覧は GitHub Pages ではなく Worker から配るので、
    // 相対パスだと Worker 側の存在しない URL を指してしまう。行き先が分かるとき
    // （config.json の docs_base_url）だけ絶対 URL で出し、無ければリンク自体を出さない。
    $docsBase = rtrim((string)($config['docs_base_url'] ?? ''), '/');
    $docNav   = '';
    if ($docsBase !== '') {
        $b = htmlspecialchars($docsBase, ENT_QUOTES, 'UTF-8');
        foreach ([['guide.html', '使い方ガイド'],
                  ['setup.html', 'セットアップ手順書'],
                  ['architecture.html', '仕組みの技術資料']] as [$file, $label]) {
            $docNav .= "      <span class=\"sep\">│</span>\n"
                     . "      <a href=\"{$b}/{$file}\">{$label}</a>\n";
        }
    }

    $html = <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- Basic 認証の内側に置くので本来は不要だが、取り違えて公開したときの保険。
     狙っている物件がグループ名と見出しから読み取れるため。 -->
<meta name="robots" content="noindex, nofollow">
<title>UR賃貸 空き部屋一覧</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2064%2064%22%3E%3Crect%20width%3D%2264%22%20height%3D%2264%22%20rx%3D%2214%22%20fill%3D%22%23005bac%22%2F%3E%3Cpath%20d%3D%22M25%2010L48%2029H41V52H9V29H2Z%22%20fill%3D%22%23fff%22%2F%3E%3Cg%20stroke%3D%22%23005bac%22%20stroke-width%3D%229%22%20stroke-linecap%3D%22round%22%20fill%3D%22none%22%3E%3Cpath%20d%3D%22M50%2048L59%2057%22%2F%3E%3Ccircle%20cx%3D%2241%22%20cy%3D%2239%22%20r%3D%2213%22%2F%3E%3C%2Fg%3E%3Ccircle%20cx%3D%2241%22%20cy%3D%2239%22%20r%3D%2213%22%20fill%3D%22%23fff%22%20stroke%3D%22%23ffc233%22%20stroke-width%3D%226%22%2F%3E%3Cpath%20d%3D%22M50%2048L59%2057%22%20stroke%3D%22%23ffc233%22%20stroke-width%3D%227%22%20stroke-linecap%3D%22round%22%2F%3E%3C%2Fsvg%3E">
<style>
  :root {
    --ink: #24292f; --sub: #57606a; --line: #d0d7de; --bg: #fff;
    --accent: #005bac; --accent-bg: #e8f0fb;
    --hot: #cf222e; --hot-bg: #ffebe9;
    --new: #bf8700; --new-bg: #fff8e5;
    --code-bg: #f6f8fa;
  }
  * { box-sizing: border-box; }
  body {
    font-family: -apple-system, "Segoe UI", "Hiragino Sans", "Noto Sans JP", sans-serif;
    margin: 0; color: var(--ink); background: var(--bg); line-height: 1.8;
  }

  .hero {
    position: relative; overflow: hidden; color: #fff;
    background:
      radial-gradient(120% 200% at 84% 30%, #1a6fd0 0%, rgba(26,111,208,0) 62%),
      linear-gradient(120deg, #07203a 0%, #00417f 58%, #002b56 100%);
  }
  .hero::before {
    content: ""; position: absolute; inset: 0;
    background-image: radial-gradient(rgba(255,255,255,.15) 1px, transparent 1px);
    background-size: 20px 20px; opacity: .45;
  }
  .hero-inner { position: relative; z-index: 2; max-width: 1100px; margin: 0 auto; padding: 20px 24px 18px; }
  .hero-head { display: flex; align-items: center; gap: 15px; }
  .hero-icon { flex: none; }
  .hero-icon svg { width: 52px; height: 52px; display: block; border-radius: 13px; }
  .hero-kicker { margin: 0 0 3px; font-size: .8rem; font-weight: 700; letter-spacing: .16em; color: #8fc2f5; }
  .hero h1 { margin: 0; font-size: 1.72rem; line-height: 1.3; color: #fff; }

  /* 資料ページと同じ、追従するメニュー。.hero は overflow:hidden なので外に置く */
  .topbar {
    position: sticky; top: 0; z-index: 50;
    background: linear-gradient(120deg, #07203a 0%, #00417f 58%, #002b56 100%);
    border-bottom: 1px solid rgba(255,255,255,.16);
    box-shadow: 0 2px 10px rgba(0,0,0,.22);
  }
  .nav {
    max-width: 1100px; margin: 0 auto; padding: 11px 24px;
    font-size: .82rem; color: #cfe2f7;
    display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px;
  }
  .nav > .grp { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; }
  /* 右に置くのはサイトの外へ出る先だけ。本体（空き部屋一覧）は左端に置く */
  .nav > .grp-right { margin-left: auto; }
  .nav .sep { color: rgba(255,255,255,.32); }
  .nav a { color: #fff; font-weight: 700; text-decoration: none; border-bottom: 1px solid rgba(255,255,255,.5); }
  .nav a:hover { border-bottom-color: #fff; }
  .nav .current { color: #fff; font-weight: 700; background: rgba(255,255,255,.16); padding: 2px 10px; border-radius: 999px; }

  .wrap { max-width: 1100px; margin: 0 auto; padding: 26px 24px 90px; }

  /* 上段の数字。開いてまず見るのはここ */
  .tiles { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 12px; margin: 0 0 10px; }
  .tile { padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; background: var(--code-bg); }
  .tile-new { border-color: var(--new); background: var(--new-bg); }
  .tile-hot { border-color: var(--hot); background: var(--hot-bg); }
  .t-num { margin: 0; font-size: 1.6rem; font-weight: 700; line-height: 1.2; font-variant-numeric: tabular-nums; }
  .tile-new .t-num { color: var(--new); }
  .tile-hot .t-num { color: var(--hot); }
  .t-lbl { margin: 0; font-size: .78rem; color: var(--sub); letter-spacing: .04em; }
  .updated { margin: 0 0 30px; font-size: .8rem; color: var(--sub); }

  .area { margin: 0 0 34px; }
  .area-head {
    display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 14px;
    border-bottom: 2px solid var(--accent); padding-bottom: 6px; margin-bottom: 14px;
  }
  .area h2 { margin: 0; font-size: 1.08rem; letter-spacing: .02em; }
  .area-count { margin-left: 10px; font-size: .82rem; font-weight: 400; color: var(--sub); }
  .area-quiet {
    margin-left: 10px; font-size: .7rem; font-weight: 400; color: var(--sub);
    border: 1px solid var(--line); border-radius: 999px; padding: 1px 8px; vertical-align: middle;
  }
  .area-src { margin-left: auto; font-size: .78rem; color: var(--accent); text-decoration: none; }
  .area-src:hover { text-decoration: underline; }
  .empty { color: var(--sub); font-size: .9rem; margin: 0; }

  /* カード。通知から来てそのまま指で押せる大きさにしてある */
  .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(268px, 1fr)); gap: 12px; }
  .card {
    display: block; text-decoration: none; color: inherit;
    border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px 12px;
    background: #fff; transition: box-shadow .15s, transform .15s, border-color .15s;
  }
  .card:hover { box-shadow: 0 4px 14px rgba(0,0,0,.10); transform: translateY(-1px); border-color: var(--accent); }
  .card.is-new { background: var(--new-bg); border-color: var(--new); }
  .card.is-hot { border-color: var(--hot); border-width: 2px; }
  .card.is-hot.is-new { background: var(--hot-bg); }
  .tags { margin: 0 0 6px; display: flex; gap: 6px; }
  .tag { font-size: .68rem; font-weight: 700; letter-spacing: .06em; padding: 2px 8px; border-radius: 999px; color: #fff; }
  .tag-new { background: var(--new); }
  .tag-hot { background: var(--hot); }
  .c-name { margin: 0 0 4px; font-size: .98rem; font-weight: 700; line-height: 1.55; }
  .card.is-hot .c-name { color: var(--hot); }
  .c-plan { margin: 0 0 10px; font-size: .82rem; color: var(--sub); }
  .c-foot { margin: 0; display: flex; align-items: baseline; gap: 10px; }
  .c-price { font-size: 1.06rem; font-weight: 700; font-variant-numeric: tabular-nums; }
  .go { margin-left: auto; font-size: .78rem; color: var(--accent); font-weight: 700; }

  footer { max-width: 1100px; margin: 0 auto; padding: 0 24px 60px; color: var(--sub); font-size: .82rem; }
  footer a { color: var(--accent); }

  @media (max-width: 720px) {
    .hero-inner { padding: 16px 18px 14px; }
    .hero-icon svg { width: 42px; height: 42px; border-radius: 11px; }
    .hero h1 { font-size: 1.3rem; }
    .nav { padding: 9px 18px; font-size: .76rem; }
    .nav > .grp-right { margin-left: 0; flex-basis: 100%; }
    .wrap { padding: 20px 18px 70px; }
    .tiles { grid-template-columns: repeat(3, minmax(0,1fr)); gap: 8px; }
    .tile { padding: 10px 12px; }
    .t-num { font-size: 1.3rem; }
    .cards { grid-template-columns: 1fr; }
    .area-src { margin-left: 0; flex-basis: 100%; }
  }
</style>
</head>
<body>

<header class="hero">
  <div class="hero-inner">
    <div class="hero-head">
      <span class="hero-icon"><svg viewBox="0 0 64 64" aria-hidden="true"><rect width="64" height="64" rx="14" fill="#005bac"/><path d="M25 10L48 29H41V52H9V29H2Z" fill="#fff"/><g stroke="#005bac" stroke-width="9" stroke-linecap="round" fill="none"><path d="M50 48L59 57"/><circle cx="41" cy="39" r="13"/></g><circle cx="41" cy="39" r="13" fill="#fff" stroke="#ffc233" stroke-width="6"/><path d="M50 48L59 57" stroke="#ffc233" stroke-width="7" stroke-linecap="round"/></svg></span>
      <div>
        <p class="hero-kicker">UR賃貸 空き部屋 監視ツール</p>
        <h1>空き部屋一覧</h1>
      </div>
    </div>
  </div>
</header>

<nav class="topbar" aria-label="ページ切り替え">
  <p class="nav">
    <span class="grp">
      <span class="current" aria-current="page">空き部屋一覧</span>
{$docNav}    </span>
    <span class="grp grp-right">
      <a href="https://github.com/tama-create/ur-monitor" target="_blank" rel="noopener">リポジトリ</a>
    </span>
  </p>
</nav>

<div class="wrap">

<div class="tiles">
  <div class="tile"><p class="t-num">{$count}</p><p class="t-lbl">空き部屋</p></div>
{$newTile}{$hotTile}</div>
<p class="updated">最終更新 {$ts}　／　8:00〜21:00 の間、5分おきに自動更新</p>

{$sections}
</div>

<footer>
<p>このページは自動生成されています。内容は取得時点のもので、実際の募集状況は
<a href="https://www.ur-net.go.jp/chintai/" target="_blank" rel="noopener">UR賃貸住宅の公式サイト</a>でご確認ください。</p>
</footer>

</body>
</html>
HTML;

    // 本番の出力先は Cloudflare Worker の KV。Basic 認証の内側に置くため、
    // リポジトリにも GitHub Pages にも UR のデータが残らない。
    // ここで state だけ進むと、一覧が古いまま次回の差分がゼロになって更新されなくなる。
    // 呼び出し元は直後に save_state するため、書けなかったら state ごと進めない。
    [$base] = store_conf();
    if ($base !== '') {
        [$status] = store_request('PUT', '/list', $html);
        if ($status !== 200) {
            log_msg('ERROR', "保管先へ一覧を書けませんでした（HTTP {$status}）");
            exit(1);
        }
        log_msg('INFO', '一覧を保管先へ出力（' . strlen($html) . ' バイト）');
        return;
    }

    // 保管先が未設定のときはローカルに書く。開発中に見た目を確かめるための逃げ道で、
    // docs/ は Pages の公開ディレクトリなので **この出力をコミットしないこと。**
    $dir = dirname(RESULTS_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (file_put_contents(RESULTS_FILE, $html) === false) {
        log_msg('ERROR', 'HTML の書き込みに失敗しました: ' . RESULTS_FILE);
        exit(1);
    }
    log_msg('INFO', "HTML 出力: " . RESULTS_FILE);
}

// ──────────────────────────────────────────
// Slack 通知（オプション）
// ──────────────────────────────────────────

/**
 * Slack へメッセージを1通送る。
 *
 * Webhook URL が空、または「YOUR_」を含むプレースホルダのときは何もしない。
 * 送信の成否はログに出すだけで、失敗しても処理は止めない（タイムアウト10秒）。
 *
 * @param string $webhookUrl Slack の Incoming Webhook URL
 * @param string $text       送る本文（Slack の mrkdwn 記法）
 * @return void
 */
function slack_send(string $webhookUrl, string $text): void
{
    if (!$webhookUrl || str_contains($webhookUrl, 'YOUR_')) {
        return;
    }
    $payload = json_encode(['text' => $text], JSON_UNESCAPED_UNICODE);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 10,
    ]]);
    $res = @file_get_contents($webhookUrl, false, $ctx);
    log_msg($res !== false ? 'INFO' : 'ERROR', $res !== false ? "Slack 通知完了" : "Slack 通知失敗");
}

/**
 * 旧形式の watch 条件のどれかに、部屋が合うかを判定する。
 *
 * 条件ごとに、building（建物名）が部屋名に、madori（間取り）が間取り欄に部分一致するかを見る。
 * 空文字の項目は「その条件は問わない」。両方を満たす条件が1つでもあれば合う。
 *
 * @param array   $r     部屋
 * @param array[] $watch 条件の一覧（各要素は building / madori を持つ）
 * @return bool どれか1つに合えば true
 */
function room_matches_watch(array $r, array $watch): bool
{
    $name = $r['name'] ?? '';
    $fp   = $r['floor_plan'] ?? '';
    foreach ($watch as $w) {
        $bld = $w['building'] ?? '';
        $mad = $w['madori']   ?? '';
        $okBuilding = ($bld === '' || str_contains($name, $bld));
        $okMadori   = ($mad === '' || str_contains($fp,   $mad));
        if ($okBuilding && $okMadori) {
            return true;
        }
    }
    return false;
}

/**
 * 通知対象の新着を、グループごとにまとめて Slack へ1通で送る。
 *
 * 本文の形:
 *   【空き速報】新着 N件
 *   グループ名（複数のグループにまたがるときは見出しとして、1つだけなら「… に新着」）
 *   物件名　間取り/㎡/階　家賃　詳細を見る（部屋のページへのリンク）   ← 1部屋1行
 *
 * 対象が0件なら何もしない。
 *
 * @param string  $webhookUrl Slack の Incoming Webhook URL
 * @param array[] $matched    通知する部屋（group を持つ）
 * @return void
 */
function notify_watch(string $webhookUrl, array $matched): void
{
    if (empty($matched)) {
        return;
    }
    $lines = [":rotating_light: *【空き速報】新着 " . count($matched) . "件*", ""];
    // 希望順位ごとにまとめる。第一希望に出たのか参考に出たのかで動き方が
    // 変わるので、通知を見た時点で分かるようにする。
    $byGroup = [];
    foreach ($matched as $r) {
        $byGroup[$r['group'] ?? ''][] = $r;
    }
    foreach ($byGroup as $groupName => $rooms) {
        if ($groupName !== '' && count($byGroup) > 1) {
            $lines[] = "*{$groupName}*";
        } elseif ($groupName !== '') {
            $lines[] = "*{$groupName}* に新着";
            $lines[] = "";
        }
        foreach ($rooms as $r) {
            $name  = $r['name']       ?? '';
            $fp    = $r['floor_plan'] ?? '';
            $price = $r['price']      ?? '';
            $url   = $r['url']        ?? '';
            // "物件名　間取り/㎡/階　家賃　詳細を見る（クリッカブル）" の1行フォーマット
            $link  = $url ? "<{$url}|詳細を見る>" : '';
            $parts = array_filter([$name, $fp, $price, $link], fn($v) => $v !== '');
            $lines[] = implode('　', $parts);
        }
        $lines[] = "";
    }
    slack_send($webhookUrl, implode("\n", $lines));
}

/**
 * 新着をすべて、1部屋2行の箇条書きで Slack へ送る。
 *
 * いまの run_monitor からは呼ばれていない（notify_all_new も含め、通知は notify_watch に一本化した）。
 * 旧形式の頃の送り方として残してある。
 *
 * @param string  $webhookUrl Slack の Incoming Webhook URL
 * @param array[] $newRooms   新着の部屋
 * @param string  $searchUrl  本文に載せる検索ページの URL
 * @return void
 */
function notify_slack(string $webhookUrl, array $newRooms, string $searchUrl): void
{
    if (empty($newRooms)) {
        return;
    }
    $lines = [":house: *UR賃貸 新着物件 " . count($newRooms) . "件*", "検索条件: {$searchUrl}", ""];
    foreach ($newRooms as $r) {
        $lines[] = "• " . trim("{$r['name']}  {$r['floor_plan']}  {$r['price']}");
        if (!empty($r['url'])) $lines[] = "  {$r['url']}";
    }
    slack_send($webhookUrl, implode("\n", $lines));
}

// ──────────────────────────────────────────
// 監視が止まっていないかの見張り
// ──────────────────────────────────────────

/**
 * 2つの時刻のあいだに、稼働時間帯が何分含まれるかを数える。
 *
 * 始まりの前日の0時から1日ずつ、その日の [開始時, 終了時] の枠と期間の重なりを足していく。
 * 夜間に動かないのは正常なので、その分を差し引かないと毎朝「11時間空いた」と誤報になる。
 * trigger/worker.js の monitoringGapMinutes も同じ数え方をしている。
 *
 * @param int $from      始まりの時刻（UNIX 時刻）
 * @param int $to        終わりの時刻（UNIX 時刻）
 * @param int $startHour 稼働時間帯の開始（時・JST）
 * @param int $endHour   稼働時間帯の終了（時・JST）
 * @return int 稼働時間帯に含まれる分数（$to が $from 以前なら 0）
 */
function monitoring_gap_minutes(int $from, int $to, int $startHour, int $endHour): int
{
    if ($to <= $from) {
        return 0;
    }
    $minutes = 0;
    // 日をまたぐことがあるため、前日の 0 時から1日ずつ窓と重なりを足していく
    for ($day = strtotime('today', $from) - 86400; $day <= $to; $day += 86400) {
        $windowStart = $day + $startHour * 3600;
        $windowEnd   = $day + $endHour   * 3600;
        $overlap = min($to, $windowEnd) - max($from, $windowStart);
        if ($overlap > 0) {
            $minutes += (int)($overlap / 60);
        }
    }
    return $minutes;
}

/**
 * 前回の実行から不自然に空いていたら、Slack に「監視が止まっていました」を送る。
 *
 * 1. state の last_checked が無い・時刻として読めなければ何もしない（初回）
 * 2. stale_warning_hours（既定3時間）が 0 以下なら何もしない（無効）
 * 3. 前回の実行から今までの空白を、稼働時間帯（monitoring_hours、既定 8〜21時）の中だけで数える
 * 4. しきい値以上なら、ログと Slack に警告を出す
 *
 * 外部トリガー（Cloudflare Workers）が死んでも、トークンが切れても、GitHub の遅延が
 * 悪化しても、症状はすべて「黙って止まる」になる。実際に4日間気づかなかったことがある。
 * GitHub の schedule を低頻度で残してあるので、そこで動いた回がこの見張りを実行し、
 * 空白に気づける。この関数は実行のたびに呼ばれるが、警告後は last_checked が
 * 更新されるため連投にはならない。
 *
 * ただしこの警告は、実行が動けたときにしか出せない（再開したあとの事後報告になる）。
 * 実行そのものが詰まった場合は、trigger/worker.js が GitHub の外から「監視が止まっています」を送る。
 *
 * @param array  $state      前回の状態（last_checked を見る）
 * @param string $webhookUrl Slack の Incoming Webhook URL
 * @param array  $config     設定（stale_warning_hours と monitoring_hours を見る）
 * @return void
 */
function warn_if_stale(array $state, string $webhookUrl, array $config): void
{
    $last = (string)($state['last_checked'] ?? '');
    if ($last === '') {
        return;  // 初回。比べる相手がいない
    }
    $lastTs = strtotime($last);
    if ($lastTs === false) {
        return;
    }

    $limitMinutes = (int)(((float)($config['stale_warning_hours'] ?? 3)) * 60);
    if ($limitMinutes <= 0) {
        return;  // 0 以下で無効
    }

    [$startHour, $endHour] = $config['monitoring_hours'] ?? [8, 21];
    $gap = monitoring_gap_minutes($lastTs, time(), (int)$startHour, (int)$endHour);
    if ($gap < $limitMinutes) {
        return;
    }

    log_msg('WARNING', "前回実行から稼働時間帯で {$gap} 分空いていました");
    slack_send($webhookUrl, sprintf(
        ":warning: *監視が止まっていました*\n"
        . "稼働時間帯で %d時間%d分 空きました（前回の確認 %s）。\n"
        . "起動トリガーの停止、トークンの期限切れ、GitHub の遅延などが考えられます。",
        intdiv($gap, 60), $gap % 60, date('n/j H:i', $lastTs)
    ));
}

// ──────────────────────────────────────────
// メイン処理
// ──────────────────────────────────────────

/**
 * 取得結果を信用してよいかを判定する。
 *
 * 次のどちらかに当てはまれば「怪しい」として、その理由を返す。
 *   ・前回この URL に部屋があったのに、今回0件
 *   ・前回5件以上あり、今回の件数が前回の $ratio 倍を下回った
 * 前回この URL に部屋が無ければ（初回や、新しく足した URL）比べようがないので信用する。
 * 前回5件未満を割合の判定から外すのは、1〜2件の出入りで割合が大きく振れるため。
 *
 * 0 件だけでなく「前回より大幅に減った」も疑う。描画待ちが足りないと件数が 0 ではなく
 * 中途半端な数で返ることがあり、素通しすると消えた分が「成約」、戻ってきた分が「新着」
 * として通知される。実際に2回起きた:
 *   2026-08-22 16:36  18 → 12 件（うち5件が1時間後に復活）
 *   2026-08-24 14:34  19 →  7 件（12件が33分後に復活し、誤った新着通知が飛んだ）
 * この2件を両方捕まえるため、既定のしきい値は「前回の 70% 未満」にしてある。
 * 同じ103回の履歴で本物の減少は 20 → 19 件（95%）だけで、これは誤って捕まえない。
 *
 * @param array[] $rooms      今回取れた部屋
 * @param array   $prevForUrl 前回この URL で取れていた部屋
 * @param float   $ratio      急減とみなす割合（shrink_guard_ratio）
 * @return string|null 怪しければ理由（例: 「0 件」「件数が急減（19 → 7 件）」）、信用できれば null
 */
function untrusted_result_reason(array $rooms, array $prevForUrl, float $ratio): ?string
{
    $prevCount = count($prevForUrl);
    if ($prevCount === 0) {
        return null;  // 前回が無ければ比べようがない（初回や、新しく足した URL）
    }
    if (empty($rooms)) {
        return '0 件';
    }
    // 少ない一覧は 1〜2 件の出入りで割合が大きく振れるため、割合判定の対象から外す
    if ($prevCount >= 5 && count($rooms) < $prevCount * $ratio) {
        return sprintf('件数が急減（%d → %d 件）', $prevCount, count($rooms));
    }
    return null;
}

/**
 * 設定を「希望順位ごとのグループ」の一覧にそろえる。
 *
 * ・groups があるとき: 各グループを次の形にそろえる。URL の無いグループは捨てる
 *     name    グループ名（無ければ「グループ N」）
 *     notify  通知するか（省略時は true）
 *     madori  通知する間取りの一覧（空文字は捨てる。空配列なら間取りを問わない）
 *     urls    監視するページの URL
 *     legacy  false
 * ・groups が無いとき: 旧形式とみなし、search_urls 全体を「監視対象」という1グループにする
 *   （notify は true、legacy は true。通知の判定は watch に任せる）
 *
 * 入居したい団地ほど上に置き、相場を知りたいだけの地域は下に置いて通知を切る。
 * グループの名前がそのまま一覧ページの見出しになるので、「エリア 1」のような
 * 無意味な見出しが消える。
 *
 * 旧形式（search_urls + watch）の設定もそのまま動く。フォークした人の設定が
 * ある日いきなり壊れないようにするため、当面は両方を読む。
 *
 * @param array $config 設定
 * @return array[] グループの一覧（config の並び順＝希望順位の高い順）。監視対象が無ければ空配列
 */
function normalize_groups(array $config): array
{
    if (!empty($config['groups']) && is_array($config['groups'])) {
        $groups = [];
        foreach ($config['groups'] as $i => $g) {
            if (!is_array($g)) {
                continue;
            }
            $urls = array_values(array_filter((array)($g['urls'] ?? []), 'is_string'));
            if ($urls === []) {
                continue;  // URL の無いグループは存在しないのと同じ
            }
            $groups[] = [
                'name'   => (string)($g['name'] ?? ('グループ ' . ($i + 1))),
                // 既定は通知する。「一覧に出すだけ」は明示的に false を書いてもらう
                'notify' => !array_key_exists('notify', $g) || (bool)$g['notify'],
                'madori' => array_values(array_filter(
                    (array)($g['madori'] ?? []),
                    fn($v) => is_string($v) && $v !== ''
                )),
                'urls'   => $urls,
                'legacy' => false,
            ];
        }
        return $groups;
    }

    // 旧形式。search_urls をひとまとめにし、通知の判定は従来どおり watch に任せる
    $urls = array_values(array_filter((array)($config['search_urls'] ?? []), 'is_string'));
    if ($urls === []) {
        return [];
    }
    return [[
        'name'   => '監視対象',
        'notify' => true,
        'madori' => [],
        'urls'   => $urls,
        'legacy' => true,
    ]];
}

/**
 * 監視ページの URL からグループを引く対応表を作る。
 *
 * 同じ URL が複数のグループにあるときは、上のグループ（希望順位が高いほう）を採る。
 * 表のキーの並びが、そのまま URL を巡回する順番になる。
 * 取得ループ・robots 確認・セットアップの3か所が同じ URL 一覧を見るようにするための共通化。
 *
 * @param array[] $groups normalize_groups() の結果
 * @return array<string, array> 監視ページの URL → グループ
 */
function group_url_map(array $groups): array
{
    $map = [];
    foreach ($groups as $g) {
        foreach ($g['urls'] as $u) {
            $map[$u] ??= $g;
        }
    }
    return $map;
}

/**
 * 部屋を Slack に通知すべきかを判定する。
 *
 * 次の順に判定する。
 *   1. 旧形式のグループ: notify_all_new が true なら通知。そうでなければ watch 条件に合えば通知
 *   2. notify: false のグループ: 通知しない（一覧に出すだけ）
 *   3. madori が空: 間取りを問わず通知
 *   4. それ以外: 間取り欄に madori のどれかが部分一致すれば通知
 *
 * @param array $room   部屋（floor_plan を見る）
 * @param array $group  部屋が属するグループ
 * @param array $config 設定（旧形式のときだけ watch と notify_all_new を見る）
 * @return bool 通知するなら true
 */
function room_notifies(array $room, array $group, array $config): bool
{
    if (!empty($group['legacy'])) {
        if (!empty($config['notify_all_new'])) {
            return true;
        }
        return room_matches_watch($room, $config['watch'] ?? []);
    }
    if (empty($group['notify'])) {
        return false;   // 一覧に出すだけのグループ（相場を見るためのもの）
    }
    if ($group['madori'] === []) {
        return true;    // 間取りを問わない
    }
    foreach ($group['madori'] as $m) {
        if (str_contains($room['floor_plan'] ?? '', $m)) {
            return true;
        }
    }
    return false;
}

/**
 * 通常の監視を1回行う。
 *
 * 処理の順番は、ファイル冒頭の「処理の流れ（通常の監視）」の 2〜14 のとおり。
 *
 * $dryRun: 開発（Windows / macOS）用。スクレイプはするが Slack 送信と
 * state.json / docs/index.html の書き込みを行わない。本番の状態を壊さずに動作確認できる。
 * ランダム待機と「止まっていました」の確認も省く。
 *
 * @param array $config 設定
 * @param bool  $dryRun true なら取得と差分だけ行い、送信と書き込みをしない
 * @return void
 */
function run_monitor(array $config, bool $dryRun = false): void
{
    $groups     = normalize_groups($config);
    $webhookUrl = $config['slack_webhook_url'] ?? '';

    if ($groups === []) {
        log_msg('ERROR', "config.json の groups（または search_urls）を設定してください");
        exit(1);
    }

    // URL とグループの対応。取得ループとロボット確認の両方で使う
    $urlGroup   = group_url_map($groups);
    $searchUrls = array_keys($urlGroup);

    if ($dryRun) {
        log_msg('INFO', "dry-run: Slack 通知と state.json / docs/index.html の更新は行いません");
    }

    // Cloudflare からの起動は毎回きっかり同じ秒に来るので、その規則性を消すためのランダム待機。
    // jitter_max_seconds で調整可（0 で無効）。開発時は待たされると煩わしいので dry-run では省く。
    // 省略時の値は README / config.json の既定（40）と揃えてある。
    $jitterMax = $dryRun ? 0 : (int)($config['jitter_max_seconds'] ?? 40);
    if ($jitterMax > 0) {
        $jitter = random_int(0, $jitterMax);
        if ($jitter > 0) {
            log_msg('INFO', "ランダム待機 {$jitter} 秒（アクセス間隔のゆらぎ）");
            sleep($jitter);
        }
    }

    $state      = load_state();

    // スクレイプの成否に関わらず、まず前回からの空白を見る
    if (!$dryRun) {
        warn_if_stale($state, $webhookUrl, $config);
    }

    $prevUrls   = array_keys($state['rooms'] ?? []);
    $currentMap = [];
    // 「取得できた結果を信用してよい URL」が1つでもあったか。
    // 取得失敗・robots 拒否・0 件保留で前回状態を引き継いだ URL は含めない。
    $scrapedOk  = false;
    // 検索URLごとの「信用できない結果が続いた回数」。一時的な取得失敗と本当の減少を
    // 区別するために持つ。キー名が zero_streak なのは 0 件だけを見ていた頃の名残で、
    // 既存の state.json と互換を保つためそのままにしてある。
    $zeroStreak = $state['zero_streak'] ?? [];

    // 何回連続で信用できない結果が続いたら「本当にそうなった」と認めるか。
    // これは回数であって時間ではないため、実行間隔を変えたらここも合わせること。
    // 実行間隔 × この回数 が、UR 側の一時的な不調に耐えられる時間になる。
    // 省略時の 18 は 5分間隔 × 18回 = 約90分（README / config.json の既定と同じ）。
    $zeroLimit = max(1, (int)($config['zero_streak_limit'] ?? 18));

    // 前回のこの割合を下回ったら部分取得を疑う。1.0 で無効（0 件だけを見る）。
    $shrinkRatio = min(1.0, max(0.0, (float)($config['shrink_guard_ratio'] ?? 0.7)));

    // Chrome を1回だけ起動してすべての URL を処理（高速化）
    $browser = create_browser($config, headless: true);
    try {
        foreach ($searchUrls as $i => $searchUrl) {
            if ($i > 0) {
                sleep(3); // 連続アクセスを避けるため URL 間に 3 秒待機
            }
            $group = $urlGroup[$searchUrl];
            log_msg('INFO', sprintf('URL %d/%d を処理中（%s）', $i + 1, count($searchUrls), $group['name']));

            // 前回 state のうち、この検索URL 由来の分だけ取り出しておく（失敗時の引き継ぎ用）
            $prevForUrl = [];
            foreach ($state['rooms'] ?? [] as $prevUrl => $prevRoom) {
                if (($prevRoom['source_url'] ?? '') === $searchUrl) {
                    $prevForUrl[$prevUrl] = $prevRoom;
                }
            }

            // robots.txt が許可していない URL は取りに行かない。前回状態は引き継ぎ、
            // 一時的な取得失敗と同じ扱いにする（誤った「成約」通知を出さないため）。
            if (!check_robots_txt($searchUrl)) {
                $currentMap += $prevForUrl;
                continue;
            }

            try {
                $rooms = scrape_url($browser, $searchUrl, $config);

                // ページは開けたのに 0 件や中途半端な件数になることがある
                // （描画完了前に読み取ってしまう等）。疑わしければ 1 度だけ取り直す。
                $reason = untrusted_result_reason($rooms, $prevForUrl, $shrinkRatio);
                if ($reason !== null) {
                    log_msg('WARNING', "{$reason}のため取得し直します: {$searchUrl}");
                    sleep(5);
                    $rooms = scrape_url($browser, $searchUrl, $config);
                }
            } catch (\Throwable $e) {
                // 1 URL の失敗で全体を巻き添えにしない。前回 state のこの URL 分を引き継ぎ、
                // 一時的な取得失敗が誤った「成約」「新着」通知を生むのを防ぐ。
                log_msg('ERROR', "URL の取得に失敗（前回状態を維持してスキップ）: {$searchUrl} — " . $e->getMessage());
                $currentMap += $prevForUrl;
                continue;
            }

            // 取り直しても怪しい。一時障害なら前回状態を維持したいが、本当に減った場合に
            // 古い部屋を永久に表示し続けてしまうため、一定回数続いたら実態として受け入れる。
            $reason = untrusted_result_reason($rooms, $prevForUrl, $shrinkRatio);
            if ($reason !== null) {
                $streak = (int)($zeroStreak[$searchUrl] ?? 0) + 1;
                if ($streak < $zeroLimit) {
                    $zeroStreak[$searchUrl] = $streak;
                    log_msg('ERROR', "{$reason}のため前回状態を維持（{$streak}/{$zeroLimit} 回目）: {$searchUrl}");
                    $currentMap += $prevForUrl;
                    continue;
                }
                log_msg('WARNING', "{$zeroLimit} 回連続で{$reason}のため、実態として受け入れます: {$searchUrl}");
            }
            unset($zeroStreak[$searchUrl]);
            $scrapedOk = true;

            foreach ($rooms as $r) {
                if (empty($r['url'])) {
                    continue;
                }
                // 同じ部屋が複数の URL に出ることがある（団地ページと地域ページなど）。
                // 先に入ったものを残す。URL は希望順位の高いグループから並べてあるので、
                // 結果として高いほうのグループに属する扱いになる。
                if (isset($currentMap[$r['url']])) {
                    continue;
                }
                $r['source_url'] = $searchUrl;
                $r['group']      = $group['name'];
                $currentMap[$r['url']] = $r;
            }
        }
    } finally {
        close_browser_safely($browser);
    }

    // 全 URL が引き継ぎ扱いで終わった＝結果を信用できないので、state も HTML も触らない。
    // 逆に「ちゃんと取得できたうえで 0 件」なら、それは事実なので下へ進んで反映する。
    // （ここで一律 return すると、3 回連続 0 件の判定が state に永久に書かれない）
    if (empty($currentMap) && !$scrapedOk) {
        log_msg('WARNING', "部屋が1件も取得できませんでした。php ur_monitor.php --setup でセレクターを確認してください");
        return;
    }

    if (empty($currentMap)) {
        log_msg('WARNING', "空き部屋は 0 件でした（取得自体は成功しています）");
    } else {
        log_msg('INFO', "現在の空き部屋: " . count($currentMap) . " 件（全URL合計）");
    }

    $newUrls  = array_diff(array_keys($currentMap), $prevUrls);
    $goneUrls = array_diff($prevUrls, array_keys($currentMap));

    if (!empty($newUrls)) {
        log_msg('INFO', "新着 " . count($newUrls) . " 件");
        $newRooms = array_values(array_intersect_key($currentMap, array_flip($newUrls)));

        // 通知するかはグループごとに決まる。希望順位の高いグループは通知し、
        // 相場を見るためだけのグループは一覧に出すだけで黙っている。
        $matched = array_values(array_filter(
            $newRooms,
            fn($r) => room_notifies($r, $urlGroup[$r['source_url'] ?? ''] ?? $groups[0], $config)
        ));
        if (!empty($matched)) {
            if ($dryRun) {
                log_msg('INFO', "dry-run: 通知対象の新着 " . count($matched) . " 件（通知は送りません）");
            } else {
                log_msg('INFO', "通知対象の新着 " . count($matched) . " 件 → Slack 通知");
                notify_watch($webhookUrl, $matched);
            }
        } else {
            log_msg('INFO', "新着はあるが、通知対象のグループには無し");
        }
    } else {
        log_msg('INFO', "新着なし");
    }

    if (!empty($goneUrls)) {
        log_msg('INFO', "成約/非表示: " . count($goneUrls) . " 件");
    }

    if ($dryRun) {
        log_msg('INFO', "dry-run: ここで state.json / docs/index.html を更新するところを省略しました");
        return;
    }

    save_html(array_values($currentMap), array_values($newUrls), $groups, $config);

    save_state([
        'rooms'        => $currentMap,
        'zero_streak'  => $zeroStreak,
        'last_checked' => date('c'),
    ]);
}

/**
 * 保管先へ前回状態を1回だけ置く（--seed-state）。保管先を用意した直後に使う。
 *
 * 処理の順番は、ファイル冒頭の「処理の流れ（--seed-state）」のとおり。
 *
 * これをやらずに本番を回すと、保管先が空のまま「前回の部屋がゼロ」になり、
 * **いま出ている部屋がすべて新着として Slack に飛ぶ。** load_state は 404 で
 * 止まるようにしてあるが、その止まった状態を解くのがこの処理。
 *
 * @return void
 */
function run_seed_state(): void
{
    [$base] = store_conf();
    if ($base === '') {
        log_msg('ERROR', 'STORE_URL / STORE_TOKEN が設定されていません');
        exit(1);
    }
    // 引き継ぐ state がある場合（保管先へ移す途中）と、まっさらから始める場合の両方を扱う。
    // リポジトリに state.json は置いていないので、フォークした直後はこちらの経路になる。
    if (file_exists(STATE_FILE)) {
        $raw     = (string)file_get_contents(STATE_FILE);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['rooms'])) {
            log_msg('ERROR', 'state.json を解釈できませんでした。移送を中止します');
            exit(1);
        }
    } else {
        // 空から始めると、次の実行で「前回は0件」になり、いま出ている部屋が
        // すべて新着として扱われる。初回だけは通知がまとめて来るということ。
        $decoded = ['rooms' => []];
        $raw     = json_encode($decoded, JSON_UNESCAPED_SLASHES);
        log_msg('WARNING', 'state.json が無いので空の状態から始めます。'
            . '次の実行では、いま出ている部屋がすべて新着として通知されます');
    }

    // 二度流し込んで、動いている状態を古い内容で上書きしないようにする
    [$status] = store_request('GET', '/state');
    if ($status === 200) {
        log_msg('ERROR', '保管先にはすでに state があります。上書きしないため中止します');
        exit(1);
    }
    if ($status !== 404) {
        log_msg('ERROR', "保管先の状態を確認できませんでした（HTTP {$status}）");
        exit(1);
    }

    [$put] = store_request('PUT', '/state', $raw);
    if ($put !== 200) {
        log_msg('ERROR', "保管先へ書けませんでした（HTTP {$put}）");
        exit(1);
    }
    log_msg('INFO', '保管先へ state を置きました（' . count($decoded['rooms']) . ' 件）');
}

/**
 * セレクター調整用に、監視対象の先頭の URL を画面ありで開いて結果を表示する（--setup）。
 *
 * 処理の順番は、ファイル冒頭の「処理の流れ（--setup）」のとおり。
 * 保存した debug_page.html をブラウザで開き、config.json の selectors を直すのに使う。
 *
 * @param array $config 設定
 * @return void
 */
function run_setup(array $config): void
{
    $searchUrls = array_keys(group_url_map(normalize_groups($config)));
    if (empty($searchUrls)) {
        log_msg('ERROR', "config.json の groups（または search_urls）を設定してください");
        exit(1);
    }

    // 先頭のURLで確認
    $config['search_url'] = $searchUrls[0];
    log_msg('INFO', "セットアップモード: 1番目のURLでブラウザを表示します");
    $rooms = scrape_rooms($config, headless: false);

    if (!empty($rooms)) {
        log_msg('INFO', "取得成功: " . count($rooms) . " 件（先頭5件を表示）");
        foreach (array_slice($rooms, 0, 5) as $i => $r) {
            printf("  [%d] %s / %s / %s\n", $i + 1, $r['name'], $r['price'], $r['url']);
        }
        if (count($rooms) > 5) echo "      ... 他 " . (count($rooms) - 5) . " 件\n";
        echo "\n問題なければ通常実行: php ur_monitor.php\n";
    } else {
        log_msg('WARNING', "部屋が取得できませんでした");
        echo "\n1. debug_page.html をブラウザで開いて HTML 構造を確認\n";
        echo "2. config.json の selectors を更新して再実行\n";
    }
}

// ── エントリーポイント ─────────────────────────

// 引数を見てモードを選び、対応する処理を呼ぶ。
//   --setup        → run_setup（セレクター調整）
//   --seed-state   → run_seed_state（保管先へ前回状態を置く）
//   --check-robots → すべての監視 URL の robots.txt を確認する。拒否が1件でもあれば終了コード1
//   それ以外       → run_monitor（--dry-run があれば dry-run として）
// どこで例外が起きても必ず monitor.log に痕跡を残し、終了コード1で止まる（無人実行のサイレント死を防ぐ）
try {
    $config = load_config();
    $args   = array_slice($argv, 1);

    if (in_array('--setup', $args)) {
        run_setup($config);
    } elseif (in_array('--seed-state', $args)) {
        run_seed_state();
    } elseif (in_array('--check-robots', $args)) {
        // 1件でも拒否があれば終了コードで分かるようにする（CI や手動確認で拾えるように）
        $urls = array_keys(group_url_map(normalize_groups($config)));
        if (empty($urls)) {
            log_msg('ERROR', "config.json の groups（または search_urls）を設定してください");
            exit(1);
        }
        $allowed = true;
        foreach ($urls as $url) {
            $allowed = check_robots_txt($url) && $allowed;
        }
        if (!$allowed) {
            exit(1);
        }
    } else {
        run_monitor($config, dryRun: in_array('--dry-run', $args));
    }
} catch (\Throwable $e) {
    log_msg('ERROR', "未捕捉の例外で停止: " . $e->getMessage()
        . " ({$e->getFile()}:{$e->getLine()})");
    exit(1);
}
