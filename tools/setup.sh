#!/bin/bash
# ur-monitor セットアップの呼び水（Mac / Linux 用）。
#
# 【使い方】
#   bash tools/setup.sh
#
# 【この呼び水がすること】
# 前提コマンド（git / GitHub CLI / Node.js）を確かめ、無ければ Homebrew で入れる。
# 中身の対話・検証・API 呼び出しはすべて tools/setup.mjs（Node 製、OS 共通）に任せる。
# ここに処理を増やさないこと。OS ごとに書き分けると、テストスイートが無いこの
# リポジトリでは片方の修正漏れに気づけるのが本番実行時になる。

set -u
cd "$(dirname "${BASH_SOURCE[0]}")/.."

has() { command -v "$1" >/dev/null 2>&1; }

if ! has brew; then
    echo "前提の導入に Homebrew を使います。"
    if [ "$(uname)" != "Darwin" ] && ! has curl; then
        echo "curl が無いため Homebrew を自動導入できません。お使いのパッケージ管理で" >&2
        echo "git / gh / node を入れてから、もう一度 bash tools/setup.sh を実行してください。" >&2
        exit 1
    fi
    /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
    # Apple Silicon の Mac では /opt/homebrew、Linuxbrew では /home/linuxbrew に入る。
    # このシェルにまだ PATH が通っていないことがあるため、その場で通す。
    if [ -x /opt/homebrew/bin/brew ]; then eval "$(/opt/homebrew/bin/brew shellenv)"; fi
    if [ -x /home/linuxbrew/.linuxbrew/bin/brew ]; then eval "$(/home/linuxbrew/.linuxbrew/bin/brew shellenv)"; fi
fi

for pkg_cmd in "git:git" "gh:gh" "node:node"; do
    pkg="${pkg_cmd%%:*}"; cmd="${pkg_cmd##*:}"
    if ! has "$cmd"; then
        echo "${pkg} を導入しています..."
        brew install "$pkg"
    fi
done

exec node tools/setup.mjs "$@"
