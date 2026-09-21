#!/usr/bin/env bash
#
# データの初期化スクリプト。
#
#   bin/reset.sh --all        イベント・企業・設問・回答など、運用データをすべて消す
#   bin/reset.sh --responses  回答と回答者に関するデータだけを消す（イベント・企業・設問は残す）
#
# よく使う形：
#   bin/reset.sh --responses --force              本番前に、テストで入れた回答だけを消す
#   bin/reset.sh --all --force                    次のイベントに向けて、まっさらにする
#   bin/reset.sh --all --event=3 --force          イベント3だけを消す
#   bin/reset.sh --responses --dry-run            消える件数だけを確認する（消さない）
#
# 消す前に自動でバックアップを取ります（--no-backup で省略できます）。
# 管理ユーザー（主催者・受付）と .env は消しません。
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "${SCRIPT_DIR}")"

MODE=""
EVENT_ID=""
FORCE=0
ASSUME_YES=0
DRY_RUN=0
BACKUP=1
BACKUP_DIR="${PROJECT_DIR}/backups"

# Windows（Git Bash）などで実行する場合は、環境変数でコマンドの場所を指定できます
#   MYSQL_BIN=/c/xampp/mysql/bin/mysql.exe MYSQLDUMP_BIN=/c/xampp/mysql/bin/mysqldump.exe bin/reset.sh ...
MYSQL_BIN="${MYSQL_BIN:-mysql}"
MYSQLDUMP_BIN="${MYSQLDUMP_BIN:-mysqldump}"

usage() {
    sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

for arg in "$@"; do
    case "$arg" in
        --all)          MODE="all" ;;
        --responses)    MODE="responses" ;;
        --event=*)      EVENT_ID="${arg#*=}" ;;
        --force)        FORCE=1 ;;
        --yes|-y)       ASSUME_YES=1 ;;
        --dry-run)      DRY_RUN=1 ;;
        --no-backup)    BACKUP=0 ;;
        --backup-dir=*) BACKUP_DIR="${arg#*=}" ;;
        --help|-h)      usage 0 ;;
        *) echo "不明なオプション: ${arg}" >&2; usage 1 ;;
    esac
done

if [ -z "$MODE" ]; then
    echo "--all または --responses のどちらかを指定してください。" >&2
    usage 1
fi

if [ -n "$EVENT_ID" ] && ! [[ "$EVENT_ID" =~ ^[0-9]+$ ]]; then
    echo "--event には数字のイベントIDを指定してください: ${EVENT_ID}" >&2
    exit 1
fi

# ---------------------------------------------------------------- .env の読み込み

ENV_FILE="${PROJECT_DIR}/.env"
if [ ! -r "$ENV_FILE" ]; then
    echo ".env が読めません: ${ENV_FILE}" >&2
    exit 1
fi

# KEY=VALUE の行だけを拾う（前後のクォートは外す）
env_value() {
    local key="$1" line value
    line="$(grep -E "^[[:space:]]*${key}[[:space:]]*=" "$ENV_FILE" | tail -n 1 || true)"
    [ -z "$line" ] && return 0
    value="${line#*=}"
    value="$(printf '%s' "$value" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    value="${value%$'\r'}"
    case "$value" in
        \"*\") value="${value:1:${#value}-2}" ;;
        \'*\') value="${value:1:${#value}-2}" ;;
    esac
    printf '%s' "$value"
}

DB_HOST="$(env_value DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_value DB_PORT)"; DB_PORT="${DB_PORT:-3306}"
DB_NAME="$(env_value DB_NAME)"; DB_NAME="${DB_NAME:-enque}"
DB_USER="$(env_value DB_USER)"; DB_USER="${DB_USER:-root}"
DB_PASS="$(env_value DB_PASS)"

# パスワードはコマンドラインに出さない（ps で見えてしまうため）
export MYSQL_PWD="${DB_PASS}"

run_sql() {
    "$MYSQL_BIN" --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
        --default-character-set=utf8mb4 --batch --skip-column-names "$DB_NAME"
}

count_of() {
    printf '%s\n' "$1" | run_sql
}

# ---------------------------------------------------------------- 対象の確認

WHERE_EVENT_SURVEYS=""
WHERE_EVENT_VISITORS=""
WHERE_EVENT_EVENTS=""
TARGET_LABEL="すべてのイベント"

if [ -n "$EVENT_ID" ]; then
    WHERE_EVENT_SURVEYS=" AND s.event_id = ${EVENT_ID}"
    WHERE_EVENT_VISITORS=" WHERE v.event_id = ${EVENT_ID}"
    WHERE_EVENT_EVENTS=" WHERE id = ${EVENT_ID}"
    EVENT_NAME="$(count_of "SELECT name FROM events WHERE id = ${EVENT_ID};")"
    if [ -z "$EVENT_NAME" ]; then
        echo "イベントID ${EVENT_ID} が見つかりません。" >&2
        exit 1
    fi
    TARGET_LABEL="イベント ${EVENT_ID}（${EVENT_NAME}）"
fi

RESPONSES="$(count_of "SELECT COUNT(*) FROM responses r JOIN surveys s ON s.id = r.survey_id WHERE 1=1${WHERE_EVENT_SURVEYS};")"
ANSWERS="$(count_of "SELECT COUNT(*) FROM answers a JOIN responses r ON r.id = a.response_id JOIN surveys s ON s.id = r.survey_id WHERE 1=1${WHERE_EVENT_SURVEYS};")"
VISITORS="$(count_of "SELECT COUNT(*) FROM visitors v${WHERE_EVENT_VISITORS};")"
CLAIMS="$(count_of "SELECT COUNT(*) FROM prize_claims pc JOIN visitors v ON v.id = pc.visitor_id${WHERE_EVENT_VISITORS};")"
INVITES="$(count_of "SELECT COUNT(*) FROM overall_invites oi JOIN visitors v ON v.id = oi.visitor_id${WHERE_EVENT_VISITORS};")"
EVENTS="$(count_of "SELECT COUNT(*) FROM events${WHERE_EVENT_EVENTS};")"
COMPANIES="$(count_of "SELECT COUNT(*) FROM companies c${WHERE_EVENT_EVENTS:+ WHERE c.event_id = ${EVENT_ID}};")"
QUESTIONS="$(count_of "SELECT COUNT(*) FROM questions q JOIN surveys s ON s.id = q.survey_id WHERE 1=1${WHERE_EVENT_SURVEYS};")"
PRIZES="$(count_of "SELECT COUNT(*) FROM prizes p${WHERE_EVENT_EVENTS:+ WHERE p.event_id = ${EVENT_ID}};")"
WALLPAPERS="$(count_of "SELECT COUNT(*) FROM wallpapers w${WHERE_EVENT_EVENTS:+ WHERE w.event_id = ${EVENT_ID}};")"

echo "対象データベース : ${DB_NAME}（${DB_HOST}:${DB_PORT}）"
echo "対象             : ${TARGET_LABEL}"
echo

if [ "$MODE" = "all" ]; then
    echo "■ --all：運用データをすべて消します"
    echo "  消すもの : イベント ${EVENTS} / 企業 ${COMPANIES} / 設問 ${QUESTIONS} / 景品 ${PRIZES} / 壁紙 ${WALLPAPERS}"
    echo "             回答 ${RESPONSES}（回答内容 ${ANSWERS}） / 来場者 ${VISITORS} / 交換コード ${CLAIMS} / 案内メール ${INVITES}"
    echo "  残すもの : 管理ユーザー（主催者・受付）、.env の設定"
    echo "  注意     : 企業担当者のアカウントは企業と一緒に消えます"
else
    echo "■ --responses：回答と回答者のデータだけ消します"
    echo "  消すもの : 回答 ${RESPONSES}（回答内容 ${ANSWERS}） / 来場者 ${VISITORS} / 交換コード ${CLAIMS} / 案内メール ${INVITES}"
    echo "  残すもの : イベント ${EVENTS} / 企業 ${COMPANIES} / 設問 ${QUESTIONS} / 景品 ${PRIZES} / 壁紙 ${WALLPAPERS} / 管理ユーザー"
fi
echo

if [ "$DRY_RUN" = "1" ]; then
    echo "（--dry-run のため、ここまでで終了します。データは消していません）"
    exit 0
fi

if [ "$FORCE" != "1" ]; then
    echo "実行するには --force を付けてください（--dry-run で件数だけ確認できます）。" >&2
    exit 1
fi

if [ "$ASSUME_YES" != "1" ]; then
    echo "元に戻せません。続ける場合はデータベース名「${DB_NAME}」を入力してください。"
    printf '> '
    read -r CONFIRM
    if [ "$CONFIRM" != "$DB_NAME" ]; then
        echo "入力が一致しないため中止しました。" >&2
        exit 1
    fi
fi

# ---------------------------------------------------------------- バックアップ

if [ "$BACKUP" = "1" ]; then
    mkdir -p "$BACKUP_DIR"
    BACKUP_FILE="${BACKUP_DIR}/${DB_NAME}_$(date +%Y%m%d_%H%M%S).sql"
    echo "バックアップを取得中: ${BACKUP_FILE}"
    if "$MYSQLDUMP_BIN" --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
        --default-character-set=utf8mb4 --single-transaction --routines "$DB_NAME" > "$BACKUP_FILE"; then
        echo "バックアップ完了（戻すときは: ${MYSQL_BIN} ${DB_NAME} < ${BACKUP_FILE}）"
    else
        echo "バックアップに失敗したため中止します。--no-backup を付けると省略できます。" >&2
        rm -f "$BACKUP_FILE"
        exit 1
    fi
    echo
fi

# ---------------------------------------------------------------- 削除

# 外部キーの連鎖削除にまかせられる部分は、親を消すだけにしている
if [ "$MODE" = "all" ]; then
    if [ -n "$EVENT_ID" ]; then
        run_sql <<SQL
DELETE FROM events WHERE id = ${EVENT_ID};
SQL
    else
        run_sql <<SQL
DELETE FROM events;
DELETE FROM admin_login_attempts;
SQL
    fi
else
    if [ -n "$EVENT_ID" ]; then
        run_sql <<SQL
DELETE oi FROM overall_invites oi JOIN visitors v ON v.id = oi.visitor_id WHERE v.event_id = ${EVENT_ID};
DELETE FROM visitors WHERE event_id = ${EVENT_ID};
SQL
    else
        run_sql <<SQL
DELETE FROM overall_invites;
DELETE FROM visitors;
SQL
    fi
fi

# ---------------------------------------------------------------- 壁紙ファイルの後片付け

STORAGE_DIR="${PROJECT_DIR}/storage/wallpapers"
if [ "$MODE" = "all" ] && [ -d "$STORAGE_DIR" ]; then
    REMOVED=0
    while IFS= read -r file; do
        [ -z "$file" ] && continue
        name="$(basename "$file")"
        [ "$name" = ".gitkeep" ] && continue
        in_use="$(count_of "SELECT COUNT(*) FROM wallpapers WHERE file_name = '$(printf '%s' "$name" | sed "s/'/''/g")';")"
        if [ "$in_use" = "0" ]; then
            rm -f "$file"
            REMOVED=$((REMOVED + 1))
        fi
    done < <(find "$STORAGE_DIR" -maxdepth 1 -type f)
    [ "$REMOVED" -gt 0 ] && echo "使われなくなった壁紙ファイルを ${REMOVED} 件削除しました。"
fi

# ---------------------------------------------------------------- 結果

REST_RESPONSES="$(count_of 'SELECT COUNT(*) FROM responses;')"
REST_VISITORS="$(count_of 'SELECT COUNT(*) FROM visitors;')"
REST_EVENTS="$(count_of 'SELECT COUNT(*) FROM events;')"
REST_COMPANIES="$(count_of 'SELECT COUNT(*) FROM companies;')"
REST_ADMINS="$(count_of 'SELECT COUNT(*) FROM admin_users;')"

echo "初期化が完了しました。"
echo "  残りの件数 : イベント ${REST_EVENTS} / 企業 ${REST_COMPANIES} / 回答 ${REST_RESPONSES} / 来場者 ${REST_VISITORS} / 管理ユーザー ${REST_ADMINS}"

# イベントを指定した場合は管理ユーザーの増減に関わらないので、全体を消したときだけ知らせる
if [ "$MODE" = "all" ] && [ -z "$EVENT_ID" ] && [ "$REST_ADMINS" = "0" ]; then
    echo "  管理ユーザーが1人もいません。/admin/setup.php から作り直してください。"
fi
