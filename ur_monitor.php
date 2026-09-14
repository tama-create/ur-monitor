<?php
/**
 * ur_monitor.php — UR賃貸 空き部屋監視スクリプト
 *
 * UR のページから空き部屋を取り出し、前回との差分で新着を見つけ、条件に合えば Slack へ通知する。
 *
 * 処理の順番は FLOW.md にある。コード中の「// [FLOW 2.3]」のような印が、FLOW.md の見出し番号に対応する。
 * 設定項目・動作の仕様・理由は README.md と docs/architecture.html にある。
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
// 一覧ページの見た目（HTML と CSS）。見た目だけ直すときはコードではなくここを触る。
define('TEMPLATE_DIR', BASE_DIR . '/templates');

// ──────────────────────────────────────────
// ユーティリティ
// ──────────────────────────────────────────

/**
 * ログを1行、画面と monitor.log に出す。
 *
 * @param string $level INFO / WARNING / ERROR
 * @param string $msg   本文
 */
function log_msg(string $level, string $msg): void
{
    $ts   = date('Y-m-d H:i:s');
    $line = "[{$ts}] [{$level}] {$msg}";
    echo $line . PHP_EOL;
    file_put_contents(LOG_FILE, $line . PHP_EOL, FILE_APPEND);
}

/**
 * config.json を読む。環境変数 SLACK_WEBHOOK_URL があれば Slack の送り先をそれで上書きする。
 * [FLOW 1.1]
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
 * 保管先の URL と合言葉を、環境変数 STORE_URL / STORE_TOKEN から取り出す。
 *
 * @return array [URL, 合言葉]。どちらかが無ければ ['', '']
 */
function store_conf(): array
{
    $url   = (string)(getenv('STORE_URL')   ?: '');
    $token = (string)(getenv('STORE_TOKEN') ?: '');
    return ($url !== '' && $token !== '') ? [rtrim($url, '/'), $token] : ['', ''];
}

/**
 * 保管先へ HTTP リクエストを1回送る。
 * 通信自体が失敗したらステータスは 0 を返す（呼び出し側が「読めなかった」と扱えるように）。
 *
 * @param string      $method GET / PUT
 * @param string      $path   /state または /list
 * @param string|null $body   送る本文
 * @return array [HTTP ステータス, 本文]
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
 * 前回の状態を読む（保管先があれば保管先、無ければ state.json）。
 * 保管先に無い・読めない・壊れているときは終了コード1で止まる（空とみなすと全部屋が新着になるため）。
 * [FLOW 2.3]
 *
 * @return array rooms / zero_streak / last_checked
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
 * 今回の状態を書き出す（保管先があれば保管先、無ければ state.json）。書けなければ終了コード1。
 * [FLOW 2.14]
 *
 * @param array $state rooms / zero_streak / last_checked
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
 * robots.txt の1つのルールが、URL のパスとクエリに当てはまるかを判定する。
 *
 * @param string $rule   Disallow / Allow の値
 * @param string $target URL のパスとクエリ
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
 * robots.txt がこの URL へのアクセスを許可しているかを確かめる。取得できなければ許可とみなす。
 * [FLOW 2.7.2 / 5.2]
 *
 * @param string $url 確認する URL
 * @return bool 許可なら true
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
 * ディレクトリを中身ごと削除する（壊れた Chrome プロファイルの掃除用）。
 *
 * @param string $dir 削除するディレクトリ
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
 * Chrome の実行ファイルを探す。config.json の chrome_path を最優先し、無ければ OS ごとの既定の場所を見る。
 * 本番は Linux だが、開発は Windows / macOS でも行うため 3 OS 分を見る。
 *
 * @param array $config 設定
 * @return string|null パス。見つからなければ null（chrome-php の既定の探し方に任せる）
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
 * Chrome を閉じる。閉じるときの例外は無視する
 * （Chrome 側がソケットを先に閉じると例外が飛ぶことがあるが、結果には影響しない）。
 * [FLOW 2.8]
 *
 * @param object $browser chrome-php の Browser
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
 * config.json の chrome_flags から、Chrome に足す起動フラグを取り出す（-- で始まるものだけ）。
 * chrome_path と同じ「環境差の逃げ道」で、本番では空のまま使う。
 *
 * @param array $config 設定
 * @return string[] 起動フラグ
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
 * Chrome を起動する。失敗したらプロファイルを作り直して1回だけやり直す。
 * [FLOW 2.6 / 4.2]
 *
 * @param array $config   設定
 * @param bool  $headless false なら画面あり（--setup 用）
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
 * 部屋の行が描画され、件数が1秒間変わらなくなるまで待つ。
 *
 * UR は物件情報を描画後に JS で差し込む。固定秒数の待機だと遅いときに空の DOM を読んで0件になり、
 * 描画の途中で読むと取りこぼすため、件数が落ち着くのを見る。
 *
 * @param object $page       chrome-php の Page
 * @param array  $selectors  セレクター
 * @param int    $timeoutSec 最長の待ち時間（秒）
 * @return bool 描画を確認できたら true、時間切れなら false
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
 * 1つの URL を開いて、空き部屋の一覧を取り出す。例外は呼び出し側へ投げる。
 * [FLOW 2.7.3 / 4.2]
 *
 * @param object $browser  chrome-php の Browser
 * @param string $url      UR のページ
 * @param array  $config   設定
 * @param bool   $headless false ならスクリーンショットと HTML も保存する（--setup 用）
 * @return array[] 部屋（building / name / price / floor_plan / url）
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
 * --setup 用。Chrome を起動して1つの URL を取得し、閉じる。
 * 複数 URL のループには使わないこと（URL ごとに Chrome を起動してしまう）。
 * [FLOW 4.2]
 *
 * @param array $config   設定（search_url を読む）
 * @param bool  $headless false なら画面あり
 * @return array[] 部屋
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
 * templates/ のファイルを読み、{{名前}} を値に置き換える。
 * 読めなければ終了コード1（壊れた一覧を書いたうえで state だけ進めないため）。
 *
 * @param string $file templates/ の中のファイル名
 * @param array  $vars 名前 → 値（HTML に入れる値はエスケープ済みであること）
 * @return string 置き換えたあとの文字列
 */
function render_template(string $file, array $vars): string
{
    $path = TEMPLATE_DIR . '/' . $file;
    $text = @file_get_contents($path);
    if ($text === false) {
        log_msg('ERROR', "テンプレートを読めませんでした: {$path}");
        exit(1);
    }
    $pairs = [];
    foreach ($vars as $name => $value) {
        $pairs['{{' . $name . '}}'] = $value;
    }
    return strtr($text, $pairs);
}

/**
 * 空き部屋の一覧ページを作って書き出す（保管先があれば保管先、無ければ docs/index.html）。
 * 見た目は templates/list.html と templates/list.css。書けなければ終了コード1。
 * [FLOW 2.13]
 *
 * @param array[]  $rooms   今回の部屋
 * @param string[] $newUrls 新着の部屋 URL
 * @param array[]  $groups  グループ一覧
 * @param array    $config  設定
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

    $html = render_template('list.html', [
        'style'    => render_template('list.css', []),
        'doc_nav'  => $docNav,
        'count'    => (string)$count,
        'tiles'    => $newTile . $hotTile,
        'updated'  => $ts,
        'sections' => $sections,
    ]);

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
 * Slack へメッセージを1通送る。送り先が未設定なら何もしない。失敗しても止めない。
 *
 * @param string $webhookUrl Webhook URL
 * @param string $text       本文
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
 * 旧形式の watch 条件（建物名・間取りの部分一致。空文字は問わない）のどれかに部屋が合うかを判定する。
 *
 * @param array   $r     部屋
 * @param array[] $watch 条件の一覧
 * @return bool 合えば true
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
 * [FLOW 2.11]
 *
 * @param string  $webhookUrl Webhook URL
 * @param array[] $matched    通知する部屋
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

// ──────────────────────────────────────────
// 監視が止まっていないかの見張り
// ──────────────────────────────────────────

/**
 * 2つの時刻のあいだに、稼働時間帯が何分含まれるかを数える。
 * 夜間を差し引かないと、毎朝「11時間空いた」と誤報するため（worker.js も同じ数え方）。
 *
 * @param int $from      始まり（UNIX 時刻）
 * @param int $to        終わり（UNIX 時刻）
 * @param int $startHour 稼働開始（時・JST）
 * @param int $endHour   稼働終了（時・JST）
 * @return int 分数
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
 * 前回の実行から、稼働時間帯で stale_warning_hours 以上空いていたら Slack に「監視が止まっていました」を送る。
 * [FLOW 2.4]
 *
 * 止まるときは「黙って止まる」になり、実際に4日間気づかなかったため置いている。
 * 実行できたときにしか出せないので、実行そのものが詰まった場合は trigger/worker.js が知らせる。
 *
 * @param array  $state      前回の状態
 * @param string $webhookUrl Webhook URL
 * @param array  $config     設定
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
 * 取得結果が怪しいかを判定する（前回あったのに0件、または前回5件以上から $ratio 倍未満に急減）。
 * [FLOW 2.7.3 / 2.7.5]
 *
 * 描画待ちが足りないと中途半端な件数で返ることがあり、素通しすると誤った新着通知が飛ぶ。
 * 実際に 18→12 件、19→7 件が起き、後者で誤通知が出た。0.7 はこの2件を捕まえ、本物の 20→19 件を通す値。
 *
 * @param array[] $rooms      今回の部屋
 * @param array   $prevForUrl 前回この URL にあった部屋
 * @param float   $ratio      shrink_guard_ratio
 * @return string|null 怪しければ理由、信用できれば null
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
 * 設定を「希望順位ごとのグループ」（name / notify / madori / urls / legacy）の一覧にそろえる。
 * 旧形式（search_urls + watch）なら1つのグループにまとめる。フォークした人の設定を壊さないため両方読む。
 * [FLOW 2.1 / 4.1 / 5.1]
 *
 * @param array $config 設定
 * @return array[] グループ（上ほど希望順位が高い）。監視対象が無ければ空配列
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
 * 監視 URL → グループの対応表を作る。同じ URL が複数あれば上のグループを採る。キーの順が巡回の順になる。
 * [FLOW 2.1 / 4.1 / 5.1]
 *
 * @param array[] $groups グループ一覧
 * @return array URL → グループ
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
 * 部屋を Slack に通知すべきかを判定する（グループの notify と間取りで決める。旧形式は watch で決める）。
 * [FLOW 2.11]
 *
 * @param array $room   部屋
 * @param array $group  部屋のグループ
 * @param array $config 設定
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
 * [FLOW 2]
 *
 * @param array $config 設定
 * @param bool  $dryRun true なら取得と差分だけ行い、通知・書き込み・待機をしない（開発用）
 */
function run_monitor(array $config, bool $dryRun = false): void
{
    // [FLOW 2.1] 監視対象を整える
    $groups     = normalize_groups($config);
    $webhookUrl = $config['slack_webhook_url'] ?? '';

    if ($groups === []) {
        log_msg('ERROR', "config.json の groups（または search_urls）を設定してください");
        exit(1);
    }

    // URL とグループの対応。取得ループとロボット確認の両方で使う
    $urlGroup   = group_url_map($groups);
    $searchUrls = array_keys($urlGroup);

    // [FLOW 2.2] ランダムに待つ
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

    // [FLOW 2.3] 前回の状態を読む
    $state      = load_state();

    // [FLOW 2.4] 止まっていなかったか確認する
    // スクレイプの成否に関わらず、まず前回からの空白を見る
    if (!$dryRun) {
        warn_if_stale($state, $webhookUrl, $config);
    }

    // [FLOW 2.5] 取得の準備をする
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

    // [FLOW 2.6] Chrome を起動する
    // Chrome を1回だけ起動してすべての URL を処理（高速化）
    $browser = create_browser($config, headless: true);
    try {
        // [FLOW 2.7] URL ごとに取得する
        foreach ($searchUrls as $i => $searchUrl) {
            if ($i > 0) {
                sleep(3); // 連続アクセスを避けるため URL 間に 3 秒待機
            }
            $group = $urlGroup[$searchUrl];
            log_msg('INFO', sprintf('URL %d/%d を処理中（%s）', $i + 1, count($searchUrls), $group['name']));

            // [FLOW 2.7.1] 前回この URL にあった部屋を取り出す
            // 前回 state のうち、この検索URL 由来の分だけ取り出しておく（失敗時の引き継ぎ用）
            $prevForUrl = [];
            foreach ($state['rooms'] ?? [] as $prevUrl => $prevRoom) {
                if (($prevRoom['source_url'] ?? '') === $searchUrl) {
                    $prevForUrl[$prevUrl] = $prevRoom;
                }
            }

            // [FLOW 2.7.2] robots.txt を確かめる
            // robots.txt が許可していない URL は取りに行かない。前回状態は引き継ぎ、
            // 一時的な取得失敗と同じ扱いにする（誤った「成約」通知を出さないため）。
            if (!check_robots_txt($searchUrl)) {
                $currentMap += $prevForUrl;
                continue;
            }

            // [FLOW 2.7.3] ページを取得する（怪しければ1回だけ取り直す）
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
                // [FLOW 2.7.4] 取得に失敗したら引き継ぐ
                // 1 URL の失敗で全体を巻き添えにしない。前回 state のこの URL 分を引き継ぎ、
                // 一時的な取得失敗が誤った「成約」「新着」通知を生むのを防ぐ。
                log_msg('ERROR', "URL の取得に失敗（前回状態を維持してスキップ）: {$searchUrl} — " . $e->getMessage());
                $currentMap += $prevForUrl;
                continue;
            }

            // [FLOW 2.7.5] 怪しい結果が続くか数える
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
            // [FLOW 2.7.6] 部屋を今回の一覧に加える
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
        // [FLOW 2.8] Chrome を閉じる
        close_browser_safely($browser);
    }

    // [FLOW 2.9] 信用できる結果が無ければ終わる
    // 全 URL が引き継ぎ扱いで終わった＝結果を信用できないので、state も HTML も触らない。
    // 逆に「ちゃんと取得できたうえで 0 件」なら、それは事実なので下へ進んで反映する。
    // （ここで一律 return すると、3 回連続 0 件の判定が state に永久に書かれない）
    if (empty($currentMap) && !$scrapedOk) {
        log_msg('WARNING', "部屋が1件も取得できませんでした。php ur_monitor.php --setup でセレクターを確認してください");
        return;
    }

    // [FLOW 2.10] 前回と比べる
    if (empty($currentMap)) {
        log_msg('WARNING', "空き部屋は 0 件でした（取得自体は成功しています）");
    } else {
        log_msg('INFO', "現在の空き部屋: " . count($currentMap) . " 件（全URL合計）");
    }

    $newUrls  = array_diff(array_keys($currentMap), $prevUrls);
    $goneUrls = array_diff($prevUrls, array_keys($currentMap));

    // [FLOW 2.11] 新着を通知する
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

    // [FLOW 2.12] --dry-run ならここで終わる
    if ($dryRun) {
        log_msg('INFO', "dry-run: ここで state.json / docs/index.html を更新するところを省略しました");
        return;
    }

    // [FLOW 2.13] 一覧ページを書く
    save_html(array_values($currentMap), array_values($newUrls), $groups, $config);

    // [FLOW 2.14] 今回の状態を書く
    save_state([
        'rooms'        => $currentMap,
        'zero_streak'  => $zeroStreak,
        'last_checked' => date('c'),
    ]);
}

/**
 * 保管先に前回状態を置く（--seed-state。保管先を作った直後に1回だけ）。
 * これをしないと load_state が止まったままになる。既に状態があれば上書きせず止まる。
 * [FLOW 3]
 */
function run_seed_state(): void
{
    // [FLOW 3.1] 保管先の設定を確かめる
    [$base] = store_conf();
    if ($base === '') {
        log_msg('ERROR', 'STORE_URL / STORE_TOKEN が設定されていません');
        exit(1);
    }
    // [FLOW 3.2] 置く状態を用意する
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

    // [FLOW 3.3] 上書きしないか確かめる
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

    // [FLOW 3.4] 保管先へ置く
    [$put] = store_request('PUT', '/state', $raw);
    if ($put !== 200) {
        log_msg('ERROR', "保管先へ書けませんでした（HTTP {$put}）");
        exit(1);
    }
    log_msg('INFO', '保管先へ state を置きました（' . count($decoded['rooms']) . ' 件）');
}

/**
 * 先頭の監視 URL を画面ありで開き、セレクター調整用のファイルを保存して結果を表示する（--setup）。
 * [FLOW 4]
 *
 * @param array $config 設定
 */
function run_setup(array $config): void
{
    // [FLOW 4.1] 先頭の監視 URL を選ぶ
    $searchUrls = array_keys(group_url_map(normalize_groups($config)));
    if (empty($searchUrls)) {
        log_msg('ERROR', "config.json の groups（または search_urls）を設定してください");
        exit(1);
    }
    $config['search_url'] = $searchUrls[0];

    // [FLOW 4.2] 画面ありで開いてファイルを保存する
    log_msg('INFO', "セットアップモード: 1番目のURLでブラウザを表示します");
    $rooms = scrape_rooms($config, headless: false);

    // [FLOW 4.3] 結果を表示する
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

// 引数でモードを選ぶ。例外はすべて monitor.log に残して終了コード1（無人実行で黙って死なないため）
try {
    // [FLOW 1.1] 設定を読む
    $config = load_config();
    $args   = array_slice($argv, 1);

    // [FLOW 1.2] モードを選ぶ
    if (in_array('--setup', $args)) {
        run_setup($config);
    } elseif (in_array('--seed-state', $args)) {
        run_seed_state();
    } elseif (in_array('--check-robots', $args)) {
        // [FLOW 5.1] 監視 URL を読む
        $urls = array_keys(group_url_map(normalize_groups($config)));
        if (empty($urls)) {
            log_msg('ERROR', "config.json の groups（または search_urls）を設定してください");
            exit(1);
        }
        // [FLOW 5.2] すべての URL を確かめる
        // 1件でも拒否があれば終了コードで分かるようにする（CI や手動確認で拾えるように）
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
    // [FLOW 1.3] 想定外の例外で止める
    log_msg('ERROR', "未捕捉の例外で停止: " . $e->getMessage()
        . " ({$e->getFile()}:{$e->getLine()})");
    exit(1);
}
