#!/usr/bin/env bash
set -euo pipefail
umask 077
# Reuse run-tests.sh and its disposable-schema guards inside an empty root and
# network namespace. No application environment, host socket or home is mounted.
SOURCE="$(cd "$(dirname "$0")/.." && pwd -P)"
[[ "$(git -C "$SOURCE" rev-parse --show-toplevel)" == "$SOURCE" ]] || exit 2
EVIDENCE="${STOCK_CONTAINED_EVIDENCE:?An existing /var/tmp evidence directory is required}"
DEPENDENCIES="${STOCK_CONTAINED_VENDOR:?An explicit installed Stock vendor directory is required}"
[[ "$EVIDENCE" == /var/tmp/* && -d "$EVIDENCE" && "$(realpath "$EVIDENCE")" == "$EVIDENCE" ]] || exit 2
[[ -f "$DEPENDENCIES/autoload.php" && "$(realpath "$DEPENDENCIES")" == "$DEPENDENCIES" ]] || exit 2
RUN="$(mktemp -d /var/tmp/stock-contained.XXXXXXXXXX)"
mkdir "$RUN/source" "$RUN/etc" "$RUN/php"
rsync -a --exclude='/.git' --exclude='/.env*' --exclude='/vendor' --exclude='/node_modules' \
  --exclude='/storage' --exclude='/bootstrap/cache/*.php' --exclude='/public/build' \
  --exclude='/public/storage' --exclude='*.log' "$SOURCE/" "$RUN/source/"
rsync -a "$DEPENDENCIES/" "$RUN/source/vendor/"
! find "$RUN/source" -type l -print -quit | grep -q . || exit 2
git -C "$SOURCE" rev-parse HEAD > "$EVIDENCE/source-head.txt"
git -C "$SOURCE" diff --binary > "$EVIDENCE/source.diff"
(cd "$RUN/source" && find . -type f -print0 | sort -z | xargs -0 sha256sum) > "$EVIDENCE/source.sha256"
printf 'root:x:0:0:Qualification:/tmp:/bin/bash\n' > "$RUN/etc/passwd"
printf 'root:x:0:\n' > "$RUN/etc/group"
printf 'passwd: files\ngroup: files\nhosts: files\n' > "$RUN/etc/nsswitch.conf"
printf '127.0.0.1 localhost\n' > "$RUN/etc/hosts"
printf 'memory_limit=1024M\ndate.timezone=UTC\n' > "$RUN/php/php.ini"
for extension in mysqlnd pdo phar bcmath ctype curl dom fileinfo gd iconv intl mbstring mysqli pdo_mysql pdo_sqlite posix simplexml sockets sqlite3 tokenizer xml xmlreader xmlwriter zip; do
  printf 'extension=%s.so\n' "$extension" >> "$RUN/php/php.ini"
done
readlink /proc/self/ns/net > "$RUN/host-net-namespace"
bwrap --unshare-all --die-with-parent --new-session --cap-drop ALL \
  --ro-bind /usr/bin /usr/bin --ro-bind /usr/sbin /usr/sbin \
  --ro-bind /usr/lib /usr/lib --ro-bind /usr/lib64 /usr/lib64 --ro-bind /usr/share /usr/share \
  --symlink usr/bin /bin --symlink usr/sbin /sbin --symlink usr/lib /lib --symlink usr/lib64 /lib64 \
  --proc /proc --dev /dev --tmpfs /tmp --dir /run --dir /var --dir /root \
  --ro-bind "$RUN/etc" /etc --ro-bind "$RUN/php" /qualification/php \
  --ro-bind "$RUN/host-net-namespace" /qualification/host-net-namespace \
  --symlink /usr/bin/php8.4 /qualification/bin/php \
  --ro-bind "$RUN/source" /qualification/source --bind "$EVIDENCE" /evidence \
  --clearenv --setenv PATH /qualification/bin:/usr/sbin:/usr/bin:/sbin:/bin \
  --setenv HOME /tmp --setenv PHPRC /qualification/php/php.ini --setenv PHP_INI_SCAN_DIR /qualification/php/empty \
  --chdir /qualification/source /bin/bash scripts/run-private-tests.sh "$@"
(cd "$RUN/source" && sha256sum --check --quiet "$EVIDENCE/source.sha256")
echo STOCK_CONTAINMENT=PASS
