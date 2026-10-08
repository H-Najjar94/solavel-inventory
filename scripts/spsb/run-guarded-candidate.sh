#!/usr/bin/env bash
set -euo pipefail
umask 077

SOURCE_ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
[[ -f "$SOURCE_ROOT/artisan" && "$(git -C "$SOURCE_ROOT" rev-parse --is-inside-work-tree 2>/dev/null)" == true ]] || {
  echo 'REFUSING: SolaStock SPSB must run from an isolated Git worktree.' >&2; exit 2;
}
[[ "$(git -C "$SOURCE_ROOT" branch --show-current)" == spsb/solastock-app-* ]] || {
  echo 'REFUSING: candidate generation requires the dedicated SolaStock application branch.' >&2; exit 2;
}
[[ -z "$(git -C "$SOURCE_ROOT" status --porcelain --untracked-files=all)" ]] || {
  echo 'REFUSING: candidate generation requires a clean application candidate.' >&2; exit 2;
}
for binary in mariadb-install-db mariadbd mariadb mariadb-admin openssl php jq git ss sha256sum; do
  command -v "$binary" >/dev/null || { echo "REFUSING: required binary is unavailable: $binary" >&2; exit 2; }
done

FINANCE_CANDIDATE=/var/www/html/solavel-finance/resources/spsb/candidates/solacount-sv2-b0004-362a2189
[[ -d "$FINANCE_CANDIDATE" && ! -L "$FINANCE_CANDIDATE" ]] || {
  echo 'REFUSING: canonical SolaCount candidate is unavailable.' >&2; exit 2;
}
[[ "$(sha256sum "$FINANCE_CANDIDATE/manifest.json" | cut -d' ' -f1)" == 0109f334eb97eb738053c39b5974c58fe8b6dd17351ce52f8b7ec7a81b3bb55e ]] || {
  echo 'REFUSING: canonical SolaCount manifest pin differs.' >&2; exit 2;
}
(cd "$FINANCE_CANDIDATE" && sha256sum --quiet -c CHECKSUMS.sha256) || {
  echo 'REFUSING: canonical SolaCount candidate checksum validation failed.' >&2; exit 2;
}

RUN_ROOT="$(mktemp -d /tmp/solastock-spsb.XXXXXXXXXXXX)"
[[ -d "$RUN_ROOT" && ! -L "$RUN_ROOT" ]] || { echo 'REFUSING: unsafe SPSB temporary root.' >&2; exit 2; }
chmod 700 "$RUN_ROOT"
DATADIR="$RUN_ROOT/data"; SOCKET="$RUN_ROOT/mariadb.sock"; PIDFILE="$RUN_ROOT/mariadb.pid"; LOGFILE="$RUN_ROOT/mariadb.log"
KEY="$RUN_ROOT/hmac.key"; DESCRIPTOR="$RUN_ROOT/descriptor.json"; SEAL_FILE="$RUN_ROOT/descriptor.seal"
REFERENCE_SNAPSHOT="$RUN_ROOT/reference.json"; BEFORE_SNAPSHOT="$RUN_ROOT/before.json"; AFTER_SNAPSHOT="$RUN_ROOT/after.json"; RERUN_SNAPSHOT="$RUN_ROOT/rerun.json"
VERIFICATION="$RUN_ROOT/verification.json"; STAGING="$RUN_ROOT/candidate-staging"
REFERENCE_DB=spsb_probe_solastock_reference
LIFECYCLE_DB=spsb_probe_solastock_lifecycle
REFERENCE_CHALLENGE="$(openssl rand -hex 32)"; LIFECYCLE_CHALLENGE="$(openssl rand -hex 32)"
SERVER_PID=''

cleanup() {
  status=$?
  set +e
  if [[ -S "$SOCKET" ]]; then mariadb-admin --no-defaults --protocol=SOCKET --socket="$SOCKET" -uroot shutdown >/dev/null 2>&1; fi
  if [[ -n "$SERVER_PID" && -e "/proc/$SERVER_PID" ]]; then kill "$SERVER_PID" 2>/dev/null; wait "$SERVER_PID" 2>/dev/null; fi
  if [[ -d "$RUN_ROOT" && ! -L "$RUN_ROOT" && "$RUN_ROOT" == /tmp/solastock-spsb.* ]]; then find "$RUN_ROOT" -xdev -depth -delete; fi
  exit "$status"
}
trap cleanup EXIT INT TERM HUP

mkdir -m 700 "$DATADIR" "$STAGING"
openssl rand 48 > "$KEY"; chmod 600 "$KEY"
mariadb-install-db --no-defaults --auth-root-authentication-method=normal --basedir=/usr --datadir="$DATADIR" >/dev/null
mariadbd --no-defaults --user="$(id -un)" --datadir="$DATADIR" --socket="$SOCKET" --pid-file="$PIDFILE" \
  --skip-networking --port=0 --log-error="$LOGFILE" --tmpdir="$RUN_ROOT" --performance-schema=OFF \
  --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci & SERVER_PID=$!
for ignored in $(seq 1 120); do
  [[ -S "$SOCKET" ]] && mariadb-admin --no-defaults --protocol=SOCKET --socket="$SOCKET" -uroot ping >/dev/null 2>&1 && break
  sleep .25
done
mariadb-admin --no-defaults --protocol=SOCKET --socket="$SOCKET" -uroot ping >/dev/null
[[ "$(cat "$PIDFILE")" == "$SERVER_PID" ]] || { echo 'REFUSING: disposable MariaDB PID mismatch.' >&2; exit 2; }
! ss -ltnp | grep -F "pid=$SERVER_PID," >/dev/null || { echo 'REFUSING: disposable MariaDB opened TCP.' >&2; exit 2; }

mariadb --no-defaults --protocol=SOCKET --socket="$SOCKET" -uroot <<SQL
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db LIKE 'test\\_%';
DELETE FROM mysql.user WHERE User='';
CREATE DATABASE \`$REFERENCE_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE \`$LIFECYCLE_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE \`$REFERENCE_DB\`.spsb_guard_marker (marker_id TINYINT UNSIGNED PRIMARY KEY, challenge_nonce CHAR(64) NOT NULL);
CREATE TABLE \`$LIFECYCLE_DB\`.spsb_guard_marker (marker_id TINYINT UNSIGNED PRIMARY KEY, challenge_nonce CHAR(64) NOT NULL);
INSERT INTO \`$REFERENCE_DB\`.spsb_guard_marker VALUES (1, '$REFERENCE_CHALLENGE');
INSERT INTO \`$LIFECYCLE_DB\`.spsb_guard_marker VALUES (1, '$LIFECYCLE_CHALLENGE');
SQL

export APP_ENV=testing TEST_DATABASE_ENVIRONMENT=spsb_guarded_isolated TEST_DATABASE_PREFIX=spsb_probe_solastock_
export SPSB_RUN_ROOT="$RUN_ROOT" SPSB_DESCRIPTOR_PATH="$DESCRIPTOR" SPSB_HMAC_KEY_PATH="$KEY" SPSB_SEAL_PATH="$SEAL_FILE"
export DB_SOCKET="$SOCKET" DB_USERNAME=root DB_PASSWORD='' DB_CONNECTION=tenant TENANT_CONNECTION=tenant
export TENANT_DB_USERNAME=root TENANT_DB_PASSWORD='' TENANT_DB_ADMIN_USER=root TENANT_DB_ADMIN_PASS=''
export APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array
export SOLABOOKS_DELIVERY_ENABLED=false SOLABOOKS_LEGACY_INVENTORY_WRITES_BLOCKED=true
export SOLABOOKS_LEGACY_JOURNAL_CONTRACT_ENABLED=false SOLABOOKS_HISTORICAL_REPAIR_ENABLED=false SOLABOOKS_PENDING_EVENT_REPLAY_ENABLED=false
export SOLASTOCK_PHASE6A_UAT_DELIVERY_ENABLED=false TENANT_EXTERNAL_ORCHESTRATOR_ONLY=true
unset DB_HOST DB_PORT TENANT_DB_HOST TENANT_DB_PORT DATABASE_URL

bind_database() {
  export DB_DATABASE="$1" TENANT_DB_DATABASE="$1" SPSB_CHALLENGE_NONCE="$2"
  export SPSB_DESCRIPTOR_SEAL
  SPSB_DESCRIPTOR_SEAL="$(php "$SOURCE_ROOT/scripts/spsb/create-descriptor.php" | tr -d '\n')"
  php "$SOURCE_ROOT/scripts/spsb/schema-tool.php" guard >/dev/null
}

bind_database "$REFERENCE_DB" "$REFERENCE_CHALLENGE"
(cd "$SOURCE_ROOT" && php artisan migrate --database=tenant --path=database/migrations/tenant --force --no-interaction >/dev/null)
php "$SOURCE_ROOT/scripts/spsb/schema-tool.php" snapshot "$REFERENCE_SNAPSHOT" >/dev/null

bind_database "$LIFECYCLE_DB" "$LIFECYCLE_CHALLENGE"
while IFS= read -r fragment; do
  php "$SOURCE_ROOT/scripts/spsb/apply-canonical-fragment.php" "$fragment" || {
    echo "REFUSING: canonical Shared Core fragment failed: $fragment" >&2; exit 2;
  }
done < <(jq -r '.fragments[] | select(.owner | startswith("capability.")) | .path' "$FINANCE_CANDIDATE/manifest.json")
while IFS= read -r fragment; do
  php "$SOURCE_ROOT/scripts/spsb/apply-canonical-fragment.php" "$fragment" || {
    echo "REFUSING: canonical SolaCount fragment failed: $fragment" >&2; exit 2;
  }
done < <(jq -r '.fragments[] | select(.owner | startswith("solacount.")) | .path' "$FINANCE_CANDIDATE/manifest.json")
php "$SOURCE_ROOT/scripts/spsb/schema-tool.php" preflight "$REFERENCE_SNAPSHOT" > "$RUN_ROOT/preflight-result.json"
php "$SOURCE_ROOT/scripts/spsb/schema-tool.php" snapshot "$BEFORE_SNAPSHOT" >/dev/null
(cd "$SOURCE_ROOT" && php artisan migrate --database=tenant --path=database/migrations/tenant --force --no-interaction >/dev/null)
php "$SOURCE_ROOT/scripts/spsb/schema-tool.php" snapshot "$AFTER_SNAPSHOT" >/dev/null
(cd "$SOURCE_ROOT" && php artisan migrate --database=tenant --path=database/migrations/tenant --force --no-interaction) > "$RUN_ROOT/rerun.log"
grep -F 'Nothing to migrate' "$RUN_ROOT/rerun.log" >/dev/null || { echo 'REFUSING: SolaStock migration rerun was not a no-op.' >&2; exit 2; }
php "$SOURCE_ROOT/scripts/spsb/schema-tool.php" snapshot "$RERUN_SNAPSHOT" >/dev/null
php "$SOURCE_ROOT/scripts/spsb/schema-tool.php" postflight "$REFERENCE_SNAPSHOT" "$BEFORE_SNAPSHOT" "$AFTER_SNAPSHOT" "$RERUN_SNAPSHOT" "$VERIFICATION" > "$RUN_ROOT/postflight-result.json"

export SPSB_APPLICATION_CANDIDATE_SHA="$(git -C "$SOURCE_ROOT" rev-parse HEAD)"
export SOURCE_DATE_EPOCH="$(git -C "$SOURCE_ROOT" show -s --format=%ct HEAD)"
php "$SOURCE_ROOT/scripts/spsb/schema-tool.php" generate "$AFTER_SNAPSHOT" "$VERIFICATION" "$STAGING" > "$RUN_ROOT/generation-result.json"
CANDIDATE_ROOT="$(jq -r '.candidate_root' "$RUN_ROOT/generation-result.json")"
php "$SOURCE_ROOT/scripts/spsb/validate-candidate.php" "$CANDIDATE_ROOT" > "$RUN_ROOT/validation-result.json"
BUNDLE_ID="$(jq -r '.bundle_id' "$RUN_ROOT/generation-result.json")"
DESTINATION="$SOURCE_ROOT/resources/spsb/candidates/$BUNDLE_ID"
[[ ! -e "$DESTINATION" && ! -L "$DESTINATION" ]] || { echo 'REFUSING: immutable SolaStock candidate already exists.' >&2; exit 2; }
mkdir -p "$SOURCE_ROOT/resources/spsb/candidates"
cp -a "$CANDIDATE_ROOT" "$DESTINATION"

jq -n \
  --arg database "$LIFECYCLE_DB" \
  --arg bundle_id "$BUNDLE_ID" \
  --arg manifest_sha256 "$(jq -r '.manifest_sha256' "$RUN_ROOT/generation-result.json")" \
  --arg application_sha "$SPSB_APPLICATION_CANDIDATE_SHA" \
  --arg candidate_root "$DESTINATION" \
  --slurpfile lifecycle "$RUN_ROOT/postflight-result.json" \
  '{result:"PASS",database:$database,bundle_id:$bundle_id,manifest_sha256:$manifest_sha256,application_candidate_sha:$application_sha,candidate_root:$candidate_root,lifecycle:$lifecycle[0]}'
