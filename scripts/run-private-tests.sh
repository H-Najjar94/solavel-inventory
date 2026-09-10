#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ -f /qualification/host-net-namespace && "$(readlink /proc/self/ns/net)" != "$(cat /qualification/host-net-namespace)" ]] || exit 2
[[ ! -e /run/mysqld && ! -e /root/.env && ! -e /var/www ]] || exit 2
[[ -z "$(ip route show)" ]] || exit 2
RUN="$(mktemp -d /tmp/stock-tests.XXXXXXXXXX)"
cp -a --no-preserve=ownership /qualification/source "$RUN/source"
cd "$RUN/source"
mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache "$RUN/data"
SOCKET="$RUN/mariadb.sock"
SERVER_PID=''
cleanup() {
  status=$?
  set +e
  if [[ -S "$SOCKET" ]]; then mariadb-admin --no-defaults --protocol=SOCKET --socket="$SOCKET" -uroot shutdown >/dev/null 2>&1; fi
  if [[ -n "$SERVER_PID" ]]; then kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; fi
  exit "$status"
}
trap cleanup EXIT INT TERM HUP
mariadb-install-db --no-defaults --auth-root-authentication-method=normal --basedir=/usr --datadir="$RUN/data" >/dev/null
mariadbd --no-defaults --user=root --datadir="$RUN/data" --socket="$SOCKET" --pid-file="$RUN/mariadb.pid" \
  --skip-networking --port=0 --log-error="$RUN/mariadb.log" --tmpdir="$RUN" --performance-schema=OFF & SERVER_PID=$!
for attempt in {1..120}; do
  [[ -S "$SOCKET" ]] && mariadb-admin --no-defaults --protocol=SOCKET --socket="$SOCKET" -uroot ping >/dev/null 2>&1 && break
  sleep .25
done
[[ "$(cat "$RUN/mariadb.pid")" == "$SERVER_PID" ]] || exit 2
! ss -ltnp | grep -F "pid=$SERVER_PID," >/dev/null || exit 2
PASSWORD="$(openssl rand -hex 24)"
mariadb --no-defaults --protocol=SOCKET --socket="$SOCKET" -uroot <<SQL
CREATE USER 'stock_qualification'@'localhost' IDENTIFIED BY '$PASSWORD';
GRANT ALL PRIVILEGES ON solastock_test_a.* TO 'stock_qualification'@'localhost';
GRANT ALL PRIVILEGES ON solastock_test_b.* TO 'stock_qualification'@'localhost';
GRANT ALL PRIVILEGES ON solastock_test_central.* TO 'stock_qualification'@'localhost';
SQL
export TEST_DATABASE_ENVIRONMENT=isolated_staging TEST_DATABASE_PREFIX=solastock_test_
export TEST_DB_SOCKET="$SOCKET" TEST_SCHEMA_DB_USER=stock_qualification TEST_SCHEMA_DB_PASSWORD="$PASSWORD"
export APP_ENV=testing CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array
export SOLABOOKS_DELIVERY_ENABLED=false
bash scripts/run-tests.sh "$@"
echo STOCK_PRIVATE_TESTS=PASS
