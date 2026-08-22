#!/bin/bash
# Claude Code on the web でこのリポジトリを扱うためのセットアップスクリプト。
#
# 【使い方】このファイルはリポジトリに置いてあるだけでは効きません。
# claude.ai/code の環境設定ダイアログを開き、「Setup script」欄に中身を貼り付けてください。
# 併せて「Network access」を Custom にし、許可ドメインに次の2行を追加したうえで
# 「Also include default list of common package managers」を有効にしてください。
#
#   ur-net.go.jp
#   *.ur-net.go.jp
#
# ドメイン指定は完全一致のため、実際にアクセスする www.ur-net.go.jp は
# 1行目だけでは許可されません。2行目の *. が www を拾います。
# 既定の Trusted のままだと UR のサイトに到達できず、スクレイピングが動きません。
#
# 前提: サンドボックスは Ubuntu 24.04、root 実行。PHP 8.4 と Composer は導入済み。
# 逆に Chrome は入っていないため、このスクリプトで用意します。

set -u

# 各ステップの結果を集計して最後にまとめて出す。個々のコマンドは失敗しても
# 止めない作り（後述）なので、そのままだと Chrome の取得に失敗しても気づけるのは
# ずっと後の dry-run が謎の0件になったときになる。それを防ぐための記録。
chrome_result="未実行"
libs_result="未実行"
composer_result="未実行"
failed_libs=""
had_failure=0

# Chrome 本体。Google 公式の apt リポジトリ(dl.google.com)は Trusted の許可ドメインに
# 含まれないため使えない。許可されている storage.googleapis.com から配信される
# Chrome for Testing を @puppeteer/browsers 経由で取得する。
npx --yes @puppeteer/browsers install chrome@stable --path /opt/chrome || true

# 展開先のパスはバージョン番号を含み変動するので、固定の場所に symlink を張る。
# ur_monitor.php の detect_chrome_path() はこの位置を見る。
CHROME_BIN="$(find /opt/chrome -type f -name chrome -perm -u+x 2>/dev/null | head -n1)"
if [ -n "$CHROME_BIN" ]; then
    ln -sf "$CHROME_BIN" /usr/local/bin/google-chrome-stable
    chrome_result="OK ($CHROME_BIN)"
    echo "Chrome を配置: $CHROME_BIN"
else
    chrome_result="NG (取得できず)"
    had_failure=1
    echo "警告: Chrome の取得に失敗しました" >&2
fi

# Chrome の実行に必要な共有ライブラリ。ヘッドレスでも必要。
# Ubuntu 24.04 で名前が変わったもの(t64 接尾辞)があるため、1つずつ入れて
# 失敗しても止めない。スクリプトが非ゼロ終了するとセッションが起動しないため。
apt-get update -qq || true
for pkg in \
    libnss3 libnspr4 \
    libatk1.0-0t64 libatk-bridge2.0-0t64 libatspi2.0-0t64 \
    libcups2t64 libdrm2 libgbm1 \
    libxkbcommon0 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 \
    libpango-1.0-0 libcairo2 libasound2t64
do
    if ! apt-get install -y -qq --no-install-recommends "$pkg"; then
        failed_libs="${failed_libs} ${pkg}"
    fi
done

if [ -z "$failed_libs" ]; then
    libs_result="OK"
else
    # 全滅でなければ Chrome が動くこともあるので、失敗した名前を控えて先へ進む
    libs_result="NG (失敗:${failed_libs} )"
    had_failure=1
fi

# 依存パッケージ。composer.lock は追跡していないので毎回解決する。
# composer はカレントディレクトリの composer.json を見るため、リポジトリ直下から
# 実行される前提。想定と違う場所で動いていることが分かるよう先に確認しておく。
if [ ! -f composer.json ]; then
    composer_result="NG (composer.json が見つからない: $(pwd))"
    had_failure=1
elif ! composer install --no-interaction --no-progress --no-dev; then
    composer_result="NG (composer install が失敗)"
    had_failure=1
elif [ ! -f vendor/autoload.php ]; then
    # composer が成功扱いでも実体が無ければ ur_monitor.php は起動直後に fatal になる
    composer_result="NG (vendor/autoload.php が無い)"
    had_failure=1
else
    composer_result="OK"
fi

# 結果のまとめ。個々のコマンドの出力は長く流れてしまうため、
# 何が使える状態になったのかを最後に一箇所で読めるようにする。
echo
echo "================ セットアップ結果 ================"
echo "  Chrome        : ${chrome_result}"
echo "  共有ライブラリ: ${libs_result}"
echo "  composer      : ${composer_result}"
echo "=================================================="

if [ "$had_failure" -ne 0 ]; then
    echo "警告: NG の項目があります。この状態で php ur_monitor.php --dry-run を" >&2
    echo "      実行すると、原因の分かりにくい 0 件や fatal error になります。" >&2
else
    echo "php ur_monitor.php --dry-run を実行できます。"
fi

# セットアップスクリプトが非ゼロで終わるとセッションが起動しないため必ず 0 を返す。
exit 0
