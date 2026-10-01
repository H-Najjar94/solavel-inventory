#!/usr/bin/env bash
# Metadata-only immutable rollout. Never imports unqualified product changes.
set -euo pipefail
repo=${1:?repository required}
sha=${2:?full committed SHA required}
expected=${3:?expected current release required}
base=/var/www/solavel-stock
export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0='*'
[[ $sha =~ ^[0-9a-f]{40}$ && $expected =~ ^[0-9TZ]+-[0-9a-f]{7,12}$ ]]
test "$(git -C "$repo" rev-parse HEAD)" = "$sha"
test -z "$(git -C "$repo" status --porcelain)"
cur=$(readlink -f "$base/current")
test "$cur" = "$base/releases/$expected"
test "$(df --output=avail -B1 / | tail -1 | tr -d ' ')" -ge 3221225472
# The application, dependency lockfiles and all asset sources must already be
# exactly the qualified current release. Only tooling/identity can change.
count=0
while IFS= read -r -d '' path; do
  case "$path" in app/*|config/*|database/*|resources/*|routes/*|composer.json|composer.lock|package.json|package-lock.json|vite.config.*)
    test -f "$cur/$path"
    test "$(git -C "$repo" hash-object "$cur/$path")" = "$(git -C "$repo" rev-parse "$sha:$path")"
    count=$((count+1));;
  esac
done < <(git -C "$repo" ls-tree -r --name-only -z "$sha")
test "$count" -gt 500
printf 'VERIFIED_UNCHANGED_PRODUCT_FILES=%s\n' "$count"
status() { curl -fsS --max-time 25 -o /dev/null -w '%{http_code}' "https://solavel.com$1" || true; }
before_home=$(status /inventory/); before_settings=$(status /inventory/settings)
test "$before_home" = 301; test "$before_settings" = 302
new="$base/releases/$(date -u +%Y%m%dT%H%M%SZ)-${sha:0:8}"
test ! -e "$new"
cp -a "$cur" "$new"
install -m 755 -o hnajjar -g sharedgroup "$repo/scripts/reconcile-verified-release.sh" "$new/scripts/reconcile-verified-release.sh"
export STOCK_VERIFIED_RELEASE="$new" STOCK_VERIFIED_SHA="$sha"
php -r '$p=getenv("STOCK_VERIFIED_RELEASE");$s=getenv("STOCK_VERIFIED_SHA");foreach(["RELEASE_SHA",".release-sha"] as $f){chmod("$p/$f",0644);file_put_contents("$p/$f",$s."\n");}file_put_contents("$p/.release-id",basename($p)."\n");'
chown hnajjar:sharedgroup "$new/RELEASE_SHA" "$new/.release-sha" "$new/.release-id"
chmod 444 "$new/RELEASE_SHA" "$new/.release-sha" "$new/.release-id"
for cache in "$new"/bootstrap/cache/*.php; do
  sed -i -E "s#$base/releases/[0-9TZ]+-[0-9a-f]+#$new#g" "$cache"
done
(cd "$new" && runuser -u hnajjar -- php artisan config:cache && runuser -u hnajjar -- php artisan route:cache && bash scripts/validate-runtime-cache.sh)
test "$(readlink -f "$base/current")" = "$cur"
# Proof is served by the Stock FPM pool, not by CLI. Random short-lived probe
# reports no secrets and is removed on every exit.
probe="_qa32_opcache_$(openssl rand -hex 12).php"
export STOCK_VERIFIED_PROBE="$new/public/$probe"
php -r 'file_put_contents(getenv("STOCK_VERIFIED_PROBE"), "<?php opcache_reset(); echo realpath(__DIR__.\"/..\");");'
chmod 644 "$new/public/$probe"
trap 'rm -f -- "$new/public/$probe"' EXIT
printf '%s\n' "$cur" > "$base/PREVIOUS_RELEASE"
ln -s "$new" "$base/.current.qa32.new"
mv -T "$base/.current.qa32.new" "$base/current"
ok=0
for attempt in $(seq 1 16); do
  answer=$(curl -fsS --max-time 15 "https://solavel.com/inventory/$probe" || true)
  if test "$answer" = "$new"; then ok=$((ok+1)); else ok=0; fi
  test "$ok" -ge 8 && break
done
after_home=$(status /inventory/); after_settings=$(status /inventory/settings)
if test "$ok" -lt 8 || test "$before_home" != "$after_home" || test "$before_settings" != "$after_settings"; then
  ln -s "$cur" "$base/.current.qa32.rollback"
  mv -T "$base/.current.qa32.rollback" "$base/current"
  cp "$new/public/$probe" "$cur/public/$probe"
  curl -fsS --max-time 15 "https://solavel.com/inventory/$probe" || true
  rm -f -- "$cur/public/$probe"
  printf 'ROLLED_BACK=%s HEALTH=%s/%s\n' "$cur" "$after_home" "$after_settings"
  exit 3
fi
printf 'DEPLOYED=%s ROLLBACK=%s HEALTH=%s/%s FPM_PROOFS=%s\n' "$new" "$cur" "$after_home" "$after_settings" "$ok"
