#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ -f /qualification/host-net-namespace && "$(readlink /proc/self/ns/net)" != "$(cat /qualification/host-net-namespace)" ]] || exit 2
[[ ! -e /run/mysqld && ! -e /root/.env && ! -e /var/www ]] || exit 2
[[ -z "$(ip route show)" ]] || exit 2
# Each cohort owns a fresh SQL datadir. Append-only ledger guards remain enabled.
if [[ "${1:-}" != --private-lifecycle ]]; then
  for task_arg in "$@"; do
    [[ "$task_arg" != --group && "$task_arg" != --group=* && "$task_arg" != --exclude-group && "$task_arg" != --exclude-group=* ]] || {
      echo 'REFUSING: lifecycle group selection belongs to the contained launcher.' >&2; exit 2;
    }
  done
  export APP_ENV=testing TEST_DATABASE_PREFIX=solastock_test_
  # Native PHPUnit selection, including caller filter and individual data-set IDs.
  for cohort in rollback committed; do
    select_group=(--exclude-group committed-native-transport)
    [[ "$cohort" != committed ]] || select_group=(--group committed-native-transport)
    ./vendor/bin/phpunit "$@" "${select_group[@]}" --list-tests-xml "/evidence/selection-$cohort.xml"
  done
  python3 scripts/private-test-selection.py enumerate /evidence
  rollback_count=$(wc -l </evidence/selection-rollback.ids)
  committed_count=$(wc -l </evidence/selection-committed.ids)
  (( rollback_count + committed_count > 0 )) || { echo 'LAUNCHER_FAILURE total-zero-selection' >&2; exit 2; }
  TASK_STATUS=0
  if (( rollback_count > 0 )); then
    set +e
    bash "$0" --private-lifecycle rollback "$@" --exclude-group committed-native-transport
    status=$?; set -e
    (( status <= TASK_STATUS )) || TASK_STATUS=$status
  else echo STOCK_PRIVATE_COHORT=rollback NOT_SELECTED; fi
  # Original selection has already been applied. Replace only its filter with the
  # exact selected native ID, retaining suite/file/config arguments and all guards.
  args=(); skip=0
  for arg in "$@"; do
    if (( skip )); then skip=0; continue; fi
    case "$arg" in --filter) skip=1;; --filter=*) ;; *) args+=("$arg");; esac
  done
  index=0
  while IFS= read -r filter; do
    index=$((index+1)); printf -v case_index '%04d' "$index"
    set +e
    STOCK_PRIVATE_CASE_INDEX="$case_index" bash "$0" --private-lifecycle committed "${args[@]}" --filter "$filter" --group committed-native-transport
    status=$?; set -e
    (( status <= TASK_STATUS )) || TASK_STATUS=$status
  done </evidence/selection-committed.filters
  if (( committed_count > 0 )); then python3 scripts/private-test-selection.py aggregate /evidence "$committed_count";
  else echo STOCK_PRIVATE_COHORT=committed NOT_SELECTED; fi
  exit "$TASK_STATUS"
fi
shift
TASK_COHORT=${1:?private lifecycle cohort required}; shift
[[ "$TASK_COHORT" == rollback || "$TASK_COHORT" == committed ]] || exit 2
printf 'STOCK_PRIVATE_COHORT=%s START\n' "$TASK_COHORT"
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
  # Keep proof, release this disposable lifecycle before the next singleton. No
  # host paths exist in this namespace; current source inventory stays read-only.
  proof_suffix="${STOCK_PRIVATE_CASE_INDEX:+-${STOCK_PRIVATE_CASE_INDEX}}"
  [[ ! -f "$RUN/committed-lifecycle.json" ]] || cp "$RUN/committed-lifecycle.json" "/evidence/lifecycle-$TASK_COHORT$proof_suffix.json"
  [[ ! -f "$RUN/mariadb.log" ]] || cp "$RUN/mariadb.log" "/evidence/sql-$TASK_COHORT$proof_suffix.log"
  if [[ "$RUN" == /tmp/stock-tests.* && "$(realpath "$RUN")" == "$RUN" && -d "$RUN/data" ]]; then
    cd /qualification/source
    rm -rf -- "$RUN"
  else echo 'REFUSING: disposable lifecycle cleanup identity mismatch' >&2; status=2; fi
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
export STOCK_PRIVATE_CONFIG_CACHE="$RUN/phpunit-config.php"
export STOCK_PRIVATE_REBUILD_CONFIG_CACHE="$RUN/rebuild-config.php"
if [[ "$TASK_COHORT" == committed ]]; then
  export STOCK_COMMITTED_FIXTURE_MANIFEST="$RUN/committed-lifecycle.json"
  python3 - "$STOCK_COMMITTED_FIXTURE_MANIFEST" "$SOCKET" "$SERVER_PID" <<'PRIVATE_MANIFEST'
import json,sys,pathlib
p,socket,pid=sys.argv[1:]
pathlib.Path(p).write_text(json.dumps({'cohort':'committed-native-transport','socket':socket,'server_pid':int(pid),'disposable_datadir':str(pathlib.Path(p).parent/'data'),'cleanup':'owned-private-server-shutdown-and-schema-lifecycle'}))
PRIVATE_MANIFEST
fi
CASE_SUFFIX="${STOCK_PRIVATE_CASE_INDEX:+-${STOCK_PRIVATE_CASE_INDEX}}"
[[ -z "$CASE_SUFFIX" || "$CASE_SUFFIX" =~ ^-[0-9]{4}$ ]] || exit 2
COHORT_JUNIT="/evidence/cohort-$TASK_COHORT$CASE_SUFFIX.xml"
[[ ! -e "$COHORT_JUNIT" ]] || { echo 'REFUSING: existing cohort evidence would be overwritten.' >&2; exit 2; }
set +e
bash scripts/run-tests.sh "$@" --log-junit "$COHORT_JUNIT"
TASK_TEST_STATUS=$?
set -e
if [[ ! -f "$COHORT_JUNIT" ]]; then
  printf 'STOCK_PRIVATE_COHORT=%s LAUNCHER_FAILURE missing_junit test_exit=%s\n' "$TASK_COHORT" "$TASK_TEST_STATUS" >&2
  exit 2
fi
python3 - "$COHORT_JUNIT" "$TASK_COHORT" "$RUN/executed-cases" <<'COHORT_COUNTS'
import json,sys,pathlib,xml.etree.ElementTree as ET
root=ET.parse(sys.argv[1]).getroot(); cases=root.findall('.//testcase')
pathlib.Path(sys.argv[3]).write_text(str(len(cases)))
print(json.dumps({'cohort':sys.argv[2],'executed_cases':len(cases),'failures':len(root.findall('.//failure')),'errors':len(root.findall('.//error')),'skipped':len(root.findall('.//skipped')),'junit_evidence':sys.argv[1],'empty_selection_is_not_product_pass':True}))
COHORT_COUNTS
if (( TASK_TEST_STATUS != 0 )); then
  printf 'STOCK_PRIVATE_COHORT=%s FAILED exit=%s\n' "$TASK_COHORT" "$TASK_TEST_STATUS"
elif [[ $(cat "$RUN/executed-cases") == 0 ]]; then
  printf 'STOCK_PRIVATE_COHORT=%s LAUNCHER_FAILURE expected-nonempty-selection\n' "$TASK_COHORT"
  exit 2
else
  printf 'STOCK_PRIVATE_COHORT=%s PASS\n' "$TASK_COHORT"
fi
exit "$TASK_TEST_STATUS"
