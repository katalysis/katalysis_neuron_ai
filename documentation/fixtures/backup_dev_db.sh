#!/bin/sh
# Dump the dev site database before a task (WP-1).
#
#   documentation/fixtures/backup_dev_db.sh <task-id>      e.g. backup_dev_db.sh P2.1
#
# Writes $NEURON_BACKUP_DIR/<database>_before_<task-id>_<YYYYMMDD_HHMMSS>.sql (mode 600) and
# prints the path and size. NEURON_BACKUP_DIR has no default: set it in your shell or in
# .claude/settings.local.json ("env"). It must be an existing directory outside this repo, the
# web root and any other git work tree, because a dump holds password hashes and chat history.
#
# The connection comes from the site's application/config/database.php (found relative to this
# script). The password is passed in MYSQL_PWD and never printed. Only a local database host
# is accepted. Needs php and mysqldump on PATH. Restoring is manual: see documentation/SETUP.md.

set -eu

fail() {
    echo "backup_dev_db: $*" >&2
    exit 1
}

label=${1:-}
printf '%s' "$label" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9.-]*$' \
    || fail "give the task ID as the only argument, e.g. backup_dev_db.sh P2.1"

[ -n "${NEURON_BACKUP_DIR:-}" ] \
    || fail "NEURON_BACKUP_DIR is not set. Set it to a directory outside the repo and the web root (in your shell, or \"env\" in .claude/settings.local.json). There is no default."

dir=$NEURON_BACKUP_DIR
case "$dir" in
    "~") dir=$HOME ;;
    "~/"*) dir="$HOME/${dir#"~/"}" ;;
esac
[ -d "$dir" ] || fail "NEURON_BACKUP_DIR ($dir) does not exist. Create it first."
dir=$(cd "$dir" && pwd -P)

script_dir=$(cd "$(dirname "$0")" && pwd -P)
package_dir=$(cd "$script_dir/../.." && pwd -P)
web_root=$(cd "$package_dir/../.." && pwd -P)
config="$web_root/application/config/database.php"
[ -f "$config" ] || fail "no Concrete database config at $config"

case "$dir/" in
    "$package_dir/"*) fail "NEURON_BACKUP_DIR ($dir) is inside this repo. Use a directory outside it." ;;
    "$web_root/"*) fail "NEURON_BACKUP_DIR ($dir) is inside the web root. Use a directory outside it." ;;
esac
if git -C "$dir" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    fail "NEURON_BACKUP_DIR ($dir) is inside a git work tree. Use a directory outside any repo."
fi

command -v mysqldump >/dev/null 2>&1 || fail "mysqldump is not on PATH"

setting() {
    php -r '$c = include $argv[1]; $x = $c["connections"][$c["default-connection"]] ?? []; echo (string) ($x[$argv[2]] ?? "");' "$config" "$1"
}

host=$(setting server)
port=$(setting port)
database=$(setting database)
user=$(setting username)
case "$host" in
    *:*) [ -n "$port" ] || port=${host#*:}; host=${host%%:*} ;;
esac
port=${port:-3306}

case "$host" in
    127.0.0.1|localhost) ;;
    *) fail "the database host is $host. Only a local dev database (127.0.0.1 or localhost) is backed up." ;;
esac
[ -n "$database" ] || fail "no database name in $config"

MYSQL_PWD=$(setting password)
export MYSQL_PWD

file="$dir/${database}_before_${label}_$(date +%Y%m%d_%H%M%S).sql"
umask 077
if ! mysqldump -h"$host" -P"$port" -u"$user" --single-transaction --routines --default-character-set=utf8mb4 "$database" > "$file"; then
    rm -f "$file"
    fail "mysqldump failed; nothing was kept"
fi
chmod 600 "$file"

echo "Backup: $file ($(du -h "$file" | cut -f1 | tr -d ' '))"
