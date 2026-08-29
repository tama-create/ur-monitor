<?php
/**
 * ur_monitor.php - UR賃貸 空き部屋監視スクリプト
 *
 * 本番は GitHub Actions（Linux）で定期実行する。開発は Windows / macOS でも行うため、
 * OS 固有の処理は置かず、Chrome の場所だけ detect_chrome_path() で吸収している。
 *
 * 使い方:
 *   php ur_monitor.php              # 通常監視実行（結果を docs/index.html に出力）
 *   php ur_monitor.php --dry-run    # 開発用。Slack 送信と state.json / docs/index.html 更新をしない
 *   php ur_monitor.php --setup      # セレクター確認用（ブラウザ表示 + スクリーンショット保存）
 *   php ur_monitor.php --check-robots  # robots.txt 確認のみ
 */

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/vendor/autoload.php';

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;

define('BASE_DIR', __DIR__);
define('CONFIG_FILE',  BASE_DIR . '/config.json');
define('STATE_FILE',   BASE_DIR . '/state.json');
define('LOG_FILE',     BASE_DIR . '/monitor.log');
// GitHub Pages が main の /docs を配信するため、一覧はそこへ直接書き出す。
// index.html にしているのは、サイトのトップがそのまま一覧になるように。
define('RESULTS_FILE', BASE_DIR . '/docs/index.html');

// ──────────────────────────────────────────
// ユーティリティ
// ──────────────────────────────────────────

function log_msg(string $level, string $msg): void
{
    $ts   = date('Y-m-d H:i:s');
    $line = "[{$ts}] [{$level}] {$msg}";
    echo $line . PHP_EOL;
    file_put_contents(LOG_FILE, $line . PHP_EOL, FILE_APPEND);
}

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

function load_state(): array
{
    if (!file_exists(STATE_FILE)) {
        return ['rooms' => []];  // 初回実行。全部屋が新着として扱われる
    }

    $raw = file_get_contents(STATE_FILE);
    if ($raw === false) {
        log_msg('ERROR', 'state.json を読めませんでした。前回状態が分からないため中止します');
        exit(1);
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

function save_state(array $state): void
{
    $ok = file_put_contents(
        STATE_FILE,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
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
function robots_rule_matches(string $rule, string $target): bool
{
    // メタ文字を殺してから、robots.txt での意味を持つ * と末尾 $ だけを戻す
    $anchored = str_ends_with($rule, '$');
    $body     = $anchored ? substr($rule, 0, -1) : $rule;
    $regex    = str_replace('\\*', '.*', preg_quote($body, '#'));

    return (bool)preg_match('#^' . $regex . ($anchored ? '$' : '') . '#', $target);
}

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

// ディレクトリを再帰削除（壊れた Chrome プロファイルの掃除に使用）
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

// Chrome の実行ファイルを探す。本番は GitHub Actions（Linux）だが、開発は
// Windows / macOS でも行うため 3 OS 分の既定パスを見る。
// config.json に chrome_path があればそれを最優先（環境差の逃げ道として残してある）。
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

// Browser の終了時例外は握りつぶす。Chrome 側がソケットを先に閉じると
// 「Socket is not connected」が飛ぶことがあるが、スクレイプ結果には影響しない。
function close_browser_safely(object $browser): void
{
    try {
        $browser->close();
    } catch (\Throwable $e) {
        log_msg('INFO', "ブラウザ終了時の例外を無視: " . $e->getMessage());
    }
}

// config.json の chrome_flags を Chrome の起動フラグとして受け取る。
// chrome_path と同じ「環境差の逃げ道」で、本番では空のまま使わない想定。
// 実行環境側の都合（プロキシ、TLS、サンドボックス）でコード変更なしに逃げられるようにしておく。
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

// 部屋行が DOM に現れ、件数が増えなくなるまで待つ。
//
// UR のページは物件情報を HTML と一緒に返さず、描画後に JS が取得して差し込む。
// そのため DOMContentLoaded の時点では中身が空で、固定秒数の待機だと回線やサーバーが
// 遅いときに空の DOM を読んで 0 件になる（実際に発生を確認済み）。
// 件数が 1 秒間変化しなくなるまで見るのは、行が順次描画される途中で読み取って
// 取りこぼすのを防ぐため。現れれば即座に返るので通常は数秒で終わる。
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
                    const h = document.querySelector(S.page_name);
                    // 見出しは団地名のあとにふりがなが続く。1行目だけが名前。
                    // この JS は PHP のヒアドキュメントの中にあり、書いたエスケープ列が
                    // 実際の制御文字に変換されてしまう。だから改行を表す記法は使わず、
                    // 文字コードから組み立てて切っている。ここに正規表現を書かないこと。
                    const raw = h ? (h.innerText || '').trim() : '';
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

// --setup モード専用の単発ラッパー。
// 内部で create_browser を呼ぶため、複数 URL をループで処理する用途には使わないこと
// （その場合は create_browser を1回だけ呼び、scrape_url を直接ループさせる）。
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

function save_html(array $rooms, array $newUrls, array $groups, array $config = []): void
{
    $ts       = date('Y-m-d H:i');
    $count    = count($rooms);
    $newCount = count($newUrls);
    $highlightKeywords = $config['highlight_keywords'] ?? [];

    // 「候補」の件数。志望順位のグループが通知対象なら、そこにある部屋が候補。
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

    // 志望順位のグループごとにまとめる（config の並び順のまま）
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

    $html = <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- 公開先（GitHub Pages）で検索エンジンに拾われないようにする。
     狙っている物件が highlight_keywords から読み取れるため。 -->
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
    <p class="hero-kicker">UR賃貸 空き部屋 監視ツール</p>
    <h1>空き部屋一覧</h1>
  </div>
</header>

<nav class="topbar" aria-label="ページ切り替え">
  <p class="nav">
    <span class="grp">
      <span class="current" aria-current="page">空き部屋一覧</span>
      <span class="sep">│</span>
      <a href="guide.html">使い方ガイド</a>
      <span class="sep">│</span>
      <a href="setup.html">セットアップ手順書</a>
      <span class="sep">│</span>
      <a href="architecture.html">仕組みの技術資料</a>
    </span>
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

    // 出力先は GitHub Pages の公開ディレクトリ。フォーク直後などで無い場合に備えて作る
    $dir = dirname(RESULTS_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    // ここで state だけ進むと、HTML が古いまま次回の差分がゼロになって更新されなくなる。
    // 呼び出し元は直後に save_state するため、書けなかったら state ごと進めない。
    if (file_put_contents(RESULTS_FILE, $html) === false) {
        log_msg('ERROR', 'HTML の書き込みに失敗しました: ' . RESULTS_FILE);
        exit(1);
    }
    log_msg('INFO', "HTML 出力: " . RESULTS_FILE);
}

// ──────────────────────────────────────────
// Slack 通知（オプション）
// ──────────────────────────────────────────

// Slack へテキストを1通 POST する（Webhook 未設定・プレースホルダ時は何もしない）
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

// 部屋が watch 条件（物件名 building ＆ 間取り madori の部分一致）のいずれかに合致するか
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

// 狙っている条件に合致した新着を、目立つ形で Slack 通知
function notify_watch(string $webhookUrl, array $matched): void
{
    if (empty($matched)) {
        return;
    }
    $lines = [":rotating_light: *【空き速報】新着 " . count($matched) . "件*", ""];
    // 志望順位ごとにまとめる。第一志望に出たのか滑り止めに出たのかで動き方が
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

// 全新着をまとめて Slack 通知（notify_all_new=true のときのみ呼ばれる）
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

// 2つの時刻の間で、稼働時間帯に何分あったかを数える。
// 夜間に動かないのは正常なので、その分を差し引かないと毎朝「11時間空いた」と誤報になる。
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

// 前回の実行から不自然に空いていたら Slack に知らせる。
//
// 外部トリガー（Cloudflare Workers）が死んでも、トークンが切れても、GitHub の遅延が
// 悪化しても、症状はすべて「黙って止まる」になる。実際に4日間気づかなかったことがある。
// GitHub の schedule を低頻度で残してあるので、そこで動いた回がこの見張りを実行し、
// 空白に気づける。この関数は実行のたびに呼ばれるが、警告後は last_checked が
// 更新されるため連投にはならない。
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

// 取得結果を信用してよいかを判定する。信用できないなら理由を返し、できるなら null。
//
// 0 件だけでなく「前回より大幅に減った」も疑う。描画待ちが足りないと件数が 0 ではなく
// 中途半端な数で返ることがあり、素通しすると消えた分が「成約」、戻ってきた分が「新着」
// として通知される。実際に2回起きた:
//   2026-08-22 16:36  18 → 12 件（うち5件が1時間後に復活）
//   2026-08-24 14:34  19 →  7 件（12件が33分後に復活し、誤った新着通知が飛んだ）
// この2件を両方捕まえるため、既定のしきい値は「前回の 70% 未満」にしてある。
// 同じ103回の履歴で本物の減少は 20 → 19 件（95%）だけで、これは誤って捕まえない。
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

// 設定を「志望順位ごとのグループ」に正規化する。
//
// 大学受験の第一志望・滑り止めと同じ考え方で、入居したい団地ほど上に置き、
// 相場を知りたいだけの地域は下に置いて通知を切る。グループの名前がそのまま
// 一覧ページの見出しになるので、「エリア 1」のような無意味な見出しが消える。
//
// 旧形式（search_urls + watch）の設定もそのまま動く。フォークした人の設定が
// ある日いきなり壊れないようにするため、当面は両方を読む。
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

// URL からグループを引く表。同じ URL が複数のグループにあるときは、
// 上のグループ（志望順位が高いほう）を採る。取得ループ・robots 確認・
// セットアップの3か所が同じ URL 一覧を見るようにするための共通化。
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

// この部屋を通知すべきか。グループの志望順位と間取りで決める。
// 旧形式のときだけ、従来どおり watch と notify_all_new を見る。
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

// $dryRun: 開発（Windows / macOS）用。スクレイプはするが Slack 送信と
// state.json / docs/index.html の書き込みを行わない。本番の状態を壊さずに動作確認できる。
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

    // 実行が毎時 :00/:30 ちょうどに揃う規則性を消すためのランダム待機。
    // jitter_max_seconds で調整可（0 で無効）。開発時は待たされると煩わしいので dry-run では省く。
    $jitterMax = $dryRun ? 0 : (int)($config['jitter_max_seconds'] ?? 120);
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
    $zeroLimit = max(1, (int)($config['zero_streak_limit'] ?? 6));

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
                // 先に入ったものを残す。URL は志望順位の高いグループから並べてあるので、
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

        // 通知するかはグループごとに決まる。志望順位の高いグループは通知し、
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

// どこで例外が起きても必ず monitor.log に痕跡を残す（無人実行のサイレント死を防ぐ）
try {
    $config = load_config();
    $args   = array_slice($argv, 1);

    if (in_array('--setup', $args)) {
        run_setup($config);
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
