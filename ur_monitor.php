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
    $selectors = array_replace([
        'room_list'      => '.module_searchs_property',
        'name'           => 'span.rep_bukken-name',
        'room_rows'      => 'tbody.rep_bukken-room tr.js-log-item',
        'room_name_main' => 'span.rep_room-name-main',
        'room_name_sub'  => 'span.rep_room-name-sub',
        'price'          => 'strong.rep_room-price',
        'type'           => 'span.rep_room-type',
        'space'          => 'span.rep_room-floor',
        'floor'          => 'td.rep_room-kai',
        'link'           => 'a.rep_room-link',
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
                const buildings = Array.from(document.querySelectorAll(S.room_list));
                buildings.forEach(building => {
                    const buildingName = (building.querySelector(S.name) || {}).innerText?.trim() || '';
                    const rows = Array.from(building.querySelectorAll(S.room_rows));
                    rows.forEach(row => {
                        const roomMain  = (row.querySelector(S.room_name_main) || {}).innerText?.trim() || '';
                        const roomSub   = (row.querySelector(S.room_name_sub)  || {}).innerText?.trim() || '';
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
                            name:       buildingName + (roomMain ? ' ' + roomMain : '') + (roomSub ? ' ' + roomSub : ''),
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

function save_html(array $rooms, array $newUrls, array $searchUrls, array $highlightKeywords = []): void
{
    $ts       = date('Y-m-d H:i:s');
    $count    = count($rooms);
    $newCount = count($newUrls);

    // search_url ごとにグループ化（順序を search_urls の定義順に揃える）
    $grouped = array_fill_keys($searchUrls, []);
    foreach ($rooms as $r) {
        $src = $r['source_url'] ?? '';
        if (isset($grouped[$src])) {
            $grouped[$src][] = $r;
        }
    }

    $sections = '';
    foreach ($grouped as $srcUrl => $areaRooms) {
        $aCount  = count($areaRooms);
        $urlEsc  = htmlspecialchars($srcUrl);
        $sections .= "<section>\n<h2><a href=\"{$urlEsc}\" target=\"_blank\">エリア " . (array_search($srcUrl, $searchUrls) + 1) . "</a> <span class=\"room-count\">{$aCount}件</span></h2>\n";
        $sections .= "<p class=\"area-url\"><a href=\"{$urlEsc}\" target=\"_blank\">{$urlEsc}</a></p>\n";

        if ($aCount === 0) {
            $sections .= "<p class=\"no-rooms\">空き部屋なし</p>\n</section>\n";
            continue;
        }

        $rows = '';
        foreach ($areaRooms as $r) {
            $isNew  = in_array($r['url'], $newUrls);
            $badge  = $isNew ? '<span class="badge">NEW</span>' : '';
            $link   = $r['url'] ? "<a href=\"" . htmlspecialchars($r['url']) . "\" target=\"_blank\">詳細を見る</a>" : '―';
            $name   = htmlspecialchars($r['name']);
            $price  = htmlspecialchars($r['price']);
            $fp     = htmlspecialchars($r['floor_plan']);

            // highlight_keywords に一致する物件名は色＋ボールドで目立たせる
            $isHot = false;
            foreach ($highlightKeywords as $kw) {
                if ($kw !== '' && str_contains($r['name'], $kw)) {
                    $isHot = true;
                    break;
                }
            }
            $classes = [];
            if ($isNew) { $classes[] = 'new-row'; }
            if ($isHot) { $classes[] = 'highlight-row'; }
            $rowCls = $classes ? ' class="' . implode(' ', $classes) . '"' : '';
            $rows  .= "<tr{$rowCls}><td>{$badge}{$name}</td><td>{$fp}</td><td>{$price}</td><td>{$link}</td></tr>\n";
        }
        $sections .= "<table>\n<thead><tr><th>物件名</th><th>間取り</th><th>家賃</th><th>詳細</th></tr></thead>\n<tbody>\n{$rows}</tbody>\n</table>\n</section>\n";
    }

    $newBadge = $newCount > 0 ? "／ <span class=\"new-count\">新着: {$newCount} 件</span>" : '';

    $html = <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<!-- 公開先（GitHub Pages）で検索エンジンに拾われないようにする。
     狙っている物件が highlight_keywords から読み取れるため。 -->
<meta name="robots" content="noindex, nofollow">
<title>UR賃貸 空き部屋一覧</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🏠</text></svg>">
<style>
  body { font-family: sans-serif; max-width: 960px; margin: 40px auto; padding: 0 16px; color: #333; }
  h1 { font-size: 1.4rem; margin-bottom: 4px; }
  h2 { font-size: 1.1rem; margin: 28px 0 4px; padding: 6px 12px; background: #e8f0fb; border-left: 4px solid #005bac; }
  h2 a { color: #005bac; text-decoration: none; }
  h2 a:hover { text-decoration: underline; }
  .room-count { font-size: .85rem; font-weight: normal; color: #555; margin-left: 8px; }
  .area-url { font-size: .78rem; color: #888; margin: 0 0 8px; word-break: break-all; }
  .area-url a { color: #888; }
  .meta { color: #666; font-size: .9rem; margin-bottom: 16px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
  th { background: #005bac; color: #fff; padding: 10px 12px; text-align: left; }
  td { padding: 9px 12px; border-bottom: 1px solid #ddd; }
  tr:hover td { background: #f5f9ff; }
  tr.new-row td { background: #fff8e1; }
  tr.highlight-row td { background: #ffe3e3; color: #c62828; font-weight: bold; }
  tr.highlight-row.new-row td { background: #ffd9b3; }
  .badge { background: #e53935; color: #fff; font-size: .75rem; padding: 2px 6px; border-radius: 4px; margin-right: 6px; }
  a { color: #0066cc; }
  .summary { margin-bottom: 12px; }
  .new-count { color: #e53935; font-weight: bold; }
  .no-rooms { color: #999; font-size: .9rem; margin: 4px 0 16px; }
  section { margin-bottom: 32px; }
</style>
</head>
<body>
<h1>UR賃貸 空き部屋一覧</h1>
<div class="meta">取得日時: {$ts}</div>
<div class="summary">
  空き部屋: <strong>{$count} 件</strong>
  {$newBadge}
</div>
{$sections}
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
    foreach ($matched as $r) {
        $name  = $r['name']       ?? '';
        $fp    = $r['floor_plan'] ?? '';
        $price = $r['price']      ?? '';
        $url   = $r['url']        ?? '';
        // "物件名　間取り/㎡/階　家賃　詳細を見る（クリッカブル）" の1行フォーマット
        $link  = $url ? "<{$url}|詳細を見る>" : '';
        $parts = array_filter([$name, $fp, $price, $link], fn($v) => $v !== '');
        $lines[] = implode('　', $parts);
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
// メイン処理
// ──────────────────────────────────────────

// $dryRun: 開発（Windows / macOS）用。スクレイプはするが Slack 送信と
// state.json / docs/index.html の書き込みを行わない。本番の状態を壊さずに動作確認できる。
function run_monitor(array $config, bool $dryRun = false): void
{
    $searchUrls = $config['search_urls'] ?? [];
    $webhookUrl = $config['slack_webhook_url'] ?? '';

    if (empty($searchUrls)) {
        log_msg('ERROR', "config.json の search_urls を設定してください");
        exit(1);
    }

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
    $prevUrls   = array_keys($state['rooms'] ?? []);
    $currentMap = [];
    // 「取得できた結果を信用してよい URL」が1つでもあったか。
    // 取得失敗・robots 拒否・0 件保留で前回状態を引き継いだ URL は含めない。
    $scrapedOk  = false;
    // 検索URLごとの「0 件が続いた回数」。一時的な取得失敗と本当の空き無しを区別するために持つ。
    $zeroStreak = $state['zero_streak'] ?? [];

    // 何回連続で 0 件なら「本当に空きが尽きた」と認めるか。
    // これは回数であって時間ではないため、実行間隔を変えたらここも合わせること。
    // 実行間隔 × この回数 が、UR 側の一時的な不調に耐えられる時間になる。
    $zeroLimit = max(1, (int)($config['zero_streak_limit'] ?? 6));

    // Chrome を1回だけ起動してすべての URL を処理（高速化）
    $browser = create_browser($config, headless: true);
    try {
        foreach ($searchUrls as $i => $searchUrl) {
            if ($i > 0) {
                sleep(3); // 連続アクセスを避けるため URL 間に 3 秒待機
            }
            log_msg('INFO', "検索URL " . ($i + 1) . "/" . count($searchUrls) . " を処理中");

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

                // ページは開けたのに 0 件になることがある（描画完了前に読み取ってしまう等）。
                // 前回この URL に部屋があったなら異常を疑い、1 度だけ取り直す。
                if (empty($rooms) && !empty($prevForUrl)) {
                    log_msg('WARNING', "0 件だったため取得し直します: {$searchUrl}");
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

            // 取り直しても 0 件。一時障害なら前回状態を維持したいが、本当に空きが尽きた場合に
            // 古い部屋を永久に表示し続けてしまうため、一定回数続いたら実態として受け入れる。
            if (empty($rooms) && !empty($prevForUrl)) {
                $streak = (int)($zeroStreak[$searchUrl] ?? 0) + 1;
                if ($streak < $zeroLimit) {
                    $zeroStreak[$searchUrl] = $streak;
                    log_msg('ERROR', "0 件のため前回状態を維持（{$streak}/{$zeroLimit} 回目）: {$searchUrl}");
                    $currentMap += $prevForUrl;
                    continue;
                }
                log_msg('WARNING', "{$zeroLimit} 回連続で 0 件のため、実際に空きが無くなったと判断: {$searchUrl}");
            }
            unset($zeroStreak[$searchUrl]);
            $scrapedOk = true;

            foreach ($rooms as $r) {
                if (!empty($r['url'])) {
                    $r['source_url'] = $searchUrl;
                    $currentMap[$r['url']] = $r;
                }
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

        // 狙っている条件（watch）に合致する新着を最優先で通知
        $watch   = $config['watch'] ?? [];
        $matched = array_values(array_filter($newRooms, fn($r) => room_matches_watch($r, $watch)));
        if (!empty($matched)) {
            if ($dryRun) {
                log_msg('INFO', "dry-run: 条件に合致する新着 " . count($matched) . " 件（通知は送りません）");
            } else {
                log_msg('INFO', "狙っている条件に合致する新着 " . count($matched) . " 件 → Slack 通知");
                notify_watch($webhookUrl, $matched);
            }
        }

        // 全新着の通知は既定オフ（notify_all_new=true で有効化）。スパム防止のため。
        if (!empty($config['notify_all_new']) && !$dryRun) {
            notify_slack($webhookUrl, $newRooms, implode(', ', $searchUrls));
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

    save_html(array_values($currentMap), array_values($newUrls), $searchUrls, $config['highlight_keywords'] ?? []);

    save_state([
        'rooms'        => $currentMap,
        'zero_streak'  => $zeroStreak,
        'last_checked' => date('c'),
    ]);
}

function run_setup(array $config): void
{
    $searchUrls = $config['search_urls'] ?? [];
    if (empty($searchUrls)) {
        log_msg('ERROR', "config.json の search_urls を設定してください");
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
        $allowed = true;
        foreach ($config['search_urls'] ?? [] as $url) {
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
