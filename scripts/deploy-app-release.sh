#!/usr/bin/env bash
# Immutable SolaStock application release. Schema provisioning is a separate signed release.
set -Eeuo pipefail
umask 0027
PATH=/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export PATH
repo= sha= branch= baseline= expected= expected_sha= finance_candidate= descriptor=/etc/solavel/spsb/product-artifacts.json readiness=
root=/var/www/solavel-stock; test_mode=0; activate=0; app_user=hnajjar; runtime_group=sharedgroup
while (($#)); do
 case "$1" in
 --repo) repo=$2;shift 2;; --sha) sha=$2;shift 2;; --branch) branch=$2;shift 2;; --baseline-sha) baseline=$2;shift 2;;
 --expected-current-release) expected=$2;shift 2;; --expected-current-sha) expected_sha=$2;shift 2;;
 --finance-candidate) finance_candidate=$2;shift 2;; --descriptor) descriptor=$2;shift 2;; --readiness-proof) readiness=$2;shift 2;;
 --activate) activate=1;shift;; --test-mode) test_mode=1;shift;; --root) root=$2;shift 2;; *) echo "Unknown argument: $1" >&2;exit 64;; esac
done
fail(){ echo "STOCK_RELEASE_REFUSED: $*" >&2;exit 1; }
[[ "$sha" =~ ^[a-f0-9]{40}$ && "$baseline" =~ ^[a-f0-9]{40}$ && "$expected_sha" =~ ^[a-f0-9]{40}$ && -n "$repo" && -n "$branch" && -n "$expected" ]] || fail 'Exact source, branch, baseline and live identities are required.'
if ((test_mode));then
 [[ $EUID != 0 && "$root" == /tmp/solastock-app-deploy-test-*/root && "$repo" == /tmp/solastock-app-deploy-test-*/repo ]] || fail 'Test mode requires a non-root isolated fixture.'
 app_user=$(id -un);runtime_group=$(id -gn)
else
 [[ $EUID == 0 && "$root" == /var/www/solavel-stock && "$(readlink -f "${BASH_SOURCE[0]}")" == /usr/local/sbin/solavel-stock-app-deploy ]] || fail 'Production requires the durable root-owned deployer.'
 for path in /usr /usr/local /usr/local/sbin /usr/local/sbin/solavel-stock-app-deploy;do
  [[ $(stat -c %u "$path") == 0 ]] || fail "Unsafe owner: $path"
  (( (8#$(stat -c %a "$path") & 8#022) == 0 )) || fail "Writable deployer path: $path"
 done
fi
[[ "$(realpath "$root")" == "$root" && "$(realpath "$repo")" == "$repo" && "$(realpath "$root/releases")" == "$root/releases" && "$(realpath "$root/shared")" == "$root/shared" && -d "$root/releases" && -d "$root/shared" && ! -L "$root/.app-deployment.lock" ]] || fail 'Unsafe release layout.'
exec 9>"$root/.app-deployment.lock";flock -n 9 || fail 'Another Stock application deployment is active.'
git_repo(){ if ((test_mode));then git -C "$repo" "$@";else runuser -u "$app_user" -- git -c "safe.directory=$repo" -C "$repo" "$@";fi; }
assert_live(){
 [[ -L "$root/current" && $(readlink -f "$root/current") == "$root/releases/$expected" ]] || fail 'Current release pointer changed.'
 [[ $(tr -d '\r\n' < "$root/current/RELEASE_SHA") == "$expected_sha" ]] || fail 'Current source marker changed.'
}
assert_live; old=$(readlink -f "$root/current")
[[ $(git_repo rev-parse HEAD) == "$sha" && -z $(git_repo status --porcelain --untracked-files=all) ]] || fail 'Source must be clean at the requested commit.'
git_repo merge-base --is-ancestor "$baseline" "$sha" || fail 'Reviewed live baseline is not an ancestor.'
remote_sha=$(git_repo ls-remote --exit-code origin "refs/heads/$branch" | awk 'NR==1{print $1}')
[[ "$remote_sha" == "$sha" ]] || fail 'Candidate is not the exact pushed branch commit.'
[[ ! $(git_repo ls-tree --name-only "$sha" .env) ]] || fail 'Secrets must not be exported from Git.'
free=$(df -B1 --output=avail "$root"|tail -1|tr -d ' ');((test_mode))&&free=${STOCK_DEPLOY_TEST_FREE_BYTES:-$free}
(( free >= 3221225472 )) || fail 'At least 3 GiB free is required.'
# Actual deployed source, including reconciled live overlays, must match the explicit baseline.
verify_live_baseline(){
python3 - "$repo" "$baseline" "$old" <<'PY'
import subprocess,sys,pathlib,hashlib
repo,sha,live=sys.argv[1:]; live=pathlib.Path(live)
entries=subprocess.check_output(['git','-c','safe.directory='+repo,'-C',repo,'ls-tree','-r','--name-only',sha],text=True).splitlines()
required=[p for p in entries if p.startswith(('app/','config/','database/','resources/','routes/')) or p in ['artisan','bootstrap/app.php','composer.json','composer.lock','package.json','package-lock.json'] or p.startswith('vite.config.')]
for name in required:
 p=live/name
 if not p.is_file() or p.is_symlink():raise SystemExit('BASELINE_SOURCE_MISMATCH '+name)
 source=subprocess.check_output(['git','-c','safe.directory='+repo,'-C',repo,'show',sha+':'+name])
 if hashlib.sha256(source).digest()!=hashlib.sha256(p.read_bytes()).digest():raise SystemExit('BASELINE_SOURCE_MISMATCH '+name)
# Reject unversioned executable source that a Git export would lose.
for directory in ['app','config','database','resources','routes']:
 if not (live/directory).exists():continue
 for p in (live/directory).rglob('*'):
  if p.is_file() and str(p.relative_to(live)) not in entries:raise SystemExit('UNRECONCILED_LIVE_SOURCE '+str(p.relative_to(live)))
print('ACTUAL_LIVE_BASELINE=PASS FILES='+str(len(required)))
PY
}
verify_live_baseline
# Shared runtime state must already be shared; do not snapshot away active sessions/uploads.
[[ $(readlink -f "$old/storage") == "$(readlink -f "$root/shared/storage")" && -L "$old/storage" && -f "$root/shared/.env" ]] || fail 'Live storage/env must be reconciled to shared before deployment.'
release_id="$(date -u +%Y%m%dT%H%M%SZ)-${sha:0:8}"; release="$root/releases/$release_id"
[[ ! -e "$release" ]] || fail 'Release identity already exists.'
mkdir "$release";git_repo archive "$sha" | tar -x -C "$release"
[[ ! -e "$release/.git" ]] || fail 'Git metadata in export.'
printf '%s\n' "$sha" > "$release/RELEASE_SHA";printf '%s\n' "$sha" > "$release/.release-sha";printf '%s\n' "$release_id" > "$release/.release-id"
# Git exports do not carry runtime directories; do not delete any shared data.
[[ ! -L "$release/storage" ]] || fail 'Unexpected source storage symlink.'
if [[ -d "$release/storage" ]];then mv "$release/storage" "$release/.storage-template";fi
ln -s "$root/shared/storage" "$release/storage";ln -s "$root/shared/.env" "$release/.env"
mkdir -p "$release/public" "$release/bootstrap/cache"
[[ ! -e "$release/public/storage" ]] || fail 'Unexpected source public storage.'
ln -s "$root/shared/storage/app/public" "$release/public/storage"
if ((!test_mode));then
 chown -hR "$app_user:$runtime_group" "$release"
 runuser -u "$app_user" -- bash -c 'set -e;cd "$1";composer install --no-dev --prefer-dist --no-interaction --no-progress;npm ci --no-audit --no-fund;npm run build' _ "$release"
 for command in config:cache route:cache event:cache view:cache;do runuser -u "$app_user" -- php "$release/artisan" "$command" --no-ansi;done
 runuser -u "$app_user" -- php "$release/artisan" about --only=environment >/dev/null
 chown -hR "$app_user:$runtime_group" "$release/bootstrap/cache"
 chmod -R u+rwX,g+rwX "$release/bootstrap/cache"
 bash "$release/scripts/validate-runtime-cache.sh" || fail 'Runtime cache permissions/view binding verification failed.'
 [[ -f "$release/public/build/manifest.json" ]] || fail 'Compiled asset manifest missing.'
 python3 - "$release" <<'PY'
import pathlib,json,sys
r=pathlib.Path(sys.argv[1]);m=json.loads((r/'public/build/manifest.json').read_text())
for entry in m.values():
 for name in [entry.get('file'),*entry.get('css',[]),*entry.get('assets',[])]:
  if name and (not (r/'public/build'/name).is_file() or not (r/'public/build'/name).resolve().is_relative_to((r/'public/build').resolve())):raise SystemExit('Missing/unsafe asset '+name)
print('ASSET_MANIFEST=PASS')
PY
 chmod -R go-w "$release";chmod -R u+rwX,g+rwX "$release/bootstrap/cache"
 for actor in "$app_user" www-data;do runuser -u "$actor" -- test -r "$release/public/index.php";runuser -u "$actor" -- test -w "$release/storage";done
fi
# Existing tabs can still request their original lazy chunks after activation.
python3 - "$old/public/build/assets" "$release/public/build/assets" <<'PYASSETS'
from pathlib import Path
import hashlib,shutil,sys
old,new=map(Path,sys.argv[1:]);retained=0
if old.exists():
 assert old.is_dir() and not old.is_symlink()
 for source in sorted(old.rglob('*')):
  if source.is_dir(): continue
  assert source.is_file() and not source.is_symlink(), 'Unsafe previous hashed asset'
  relative=source.relative_to(old);target=new/relative
  if target.exists():
   assert target.is_file() and not target.is_symlink() and hashlib.sha256(target.read_bytes()).digest()==hashlib.sha256(source.read_bytes()).digest(), 'Hashed asset collision'
  else:
   target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(source,target);retained+=1
print('RETAINED_LAZY_ASSETS='+str(retained))
PYASSETS
if ((!test_mode));then
 # Build tools and retained chunks create files after the initial ownership pass.
 chown -hR "$app_user:$runtime_group" "$release"
 chmod -R go-w "$release";chmod -R u+rwX,g+rwX "$release/bootstrap/cache"
 runuser -u www-data -- php -r '
  $r=$argv[1];require $r."/vendor/autoload.php";
  $m=json_decode(file_get_contents($r."/public/build/manifest.json"),true,512,JSON_THROW_ON_ERROR);
  foreach($m as $entry)foreach(array_filter(array_merge([$entry["file"]??null],$entry["css"]??[],$entry["assets"]??[]))as $file)
   if(!is_readable($r."/public/build/".$file))throw new RuntimeException("Compiled asset unreadable by web user");
  echo "WEB_ASSET_READABILITY=PASS\n";
 ' "$release"
fi
schema_preflight(){
 if ((test_mode));then [[ ${STOCK_DEPLOY_TEST_FAIL:-} != preflight ]] || fail 'Fixture schema preflight refusal';return;fi
 [[ -d "$finance_candidate" && -f "$descriptor" ]] || fail 'Signed schema descriptor and immutable Finance candidate required.'
 php "$finance_candidate/scripts/deployment/preflight-migration-inventory.php" --candidate="$finance_candidate" --root-inventory="$release" --descriptor="$descriptor" --log="$root/migration-preflight.jsonl"
 # Central also needs every Stock migration in its canonical inventory follow-up manifest.
 php "$release/scripts/verify-stock-release-schema.php" "$release" "$descriptor" "$finance_candidate" "$old"
}
schema_preflight
assert_live
if ((!activate));then echo "STOCK_RELEASE_BUILT=$release SHA=$sha ACTIVATED=false";exit 0;fi
if ((!test_mode));then
 [[ -f /etc/solavel/maintenance/provisioning-paused && -f /etc/solavel/maintenance/unit-conversions-paused ]] || fail 'Coordinated provisioning and conversion pause required for activation.'
 python3 - "$readiness" "$sha" "$descriptor" "$old" <<'PY'
import json,sys,pathlib,stat
p,sha,descriptor,old=sys.argv[1:];proof=pathlib.Path(p)
assert proof.is_absolute() and not proof.is_symlink(), 'Readiness proof must be an absolute regular file'
st=proof.stat()
assert stat.S_ISREG(st.st_mode) and st.st_uid==0 and not st.st_mode & 0o022, 'Readiness proof must be root-sealed'
for parent in proof.parents:
 st=parent.stat()
 assert st.st_uid==0 and not st.st_mode & 0o022, 'Unsafe readiness proof parent'
r=json.loads(proof.read_text());d=json.loads(pathlib.Path(descriptor).read_text())
assert isinstance(r.get('applied_unit_events'),int) and r['applied_unit_events']>=0, 'Verified conversion event count required'
if r['applied_unit_events']:
 capability=json.loads((pathlib.Path(old)/'scripts/unit-conversion-capability.json').read_text())
 assert capability.get('unit_epoch_engine')==1 and capability.get('history_normalization')==1, 'Rollback target cannot read existing unit epochs'
assert r['status']=='PASS' and r['stock_sha']==sha and r['foundation_content_id']==d['products']['foundation']['content_id'] and not r['failed_clients'],'Exact schema readiness proof required'
PY
fi
free=$(df -B1 --output=avail "$root"|tail -1|tr -d ' ');((test_mode))&&free=${STOCK_DEPLOY_TEST_FREE_BYTES:-$free}
(( free >= 3221225472 )) || fail 'Build reduced available space below 3 GiB.'
assert_live; schema_preflight
if ((test_mode)) && [[ ${STOCK_DEPLOY_TEST_FAIL:-} == live_source_drift ]];then printf '\nfixture drift\n' >> "$old/app/Item.php";fi
verify_live_baseline
probe=; switched=0; previous_recorded=0; old_previous=$(cat "$root/PREVIOUS_RELEASE" 2>/dev/null || true)
rollback(){
 local reason=$1
 if ((switched));then
  ln -s "$old" "$root/.current.app-rollback.$$";mv -T "$root/.current.app-rollback.$$" "$root/current"
  if ((!test_mode));then
   if [[ -n "$probe" && -f "$release/public/$probe" ]];then cp "$release/public/$probe" "$old/public/$probe";python3 "$release/scripts/refresh-verified-stock-pool.py" --release "$(basename "$old")" --sha "$expected_sha" --probe "$probe" || true;fi
   bash "$old/scripts/refresh-dedicated-worker.sh" "$old" "$expected_sha" || true
  fi
 fi
 if ((previous_recorded));then printf '%s\n' "$old_previous" > "$root/PREVIOUS_RELEASE";fi
 echo "STOCK_ROLLED_BACK=$old REASON=$reason" >&2
}
trap 'code=$?; if ((code));then rollback "command failure (exit $code)";fi; if [[ -n "$probe" ]];then [[ ! -f "$release/public/$probe" ]] || unlink "$release/public/$probe"; [[ ! -f "$old/public/$probe" ]] || unlink "$old/public/$probe";fi' EXIT
if ((!test_mode));then
 probe="_qa32_opcache_$(openssl rand -hex 12).php"
 php -r 'file_put_contents($argv[1], "<?php clearstatcache(true); opcache_reset(); echo realpath(__DIR__.\"/..\");");' "$release/public/$probe";chmod 644 "$release/public/$probe"
fi
printf '%s\n' "$old" > "$root/PREVIOUS_RELEASE";previous_recorded=1
ln -s "$release" "$root/.current.app.$$";mv -T "$root/.current.app.$$" "$root/current";switched=1
if ((test_mode));then [[ ${STOCK_DEPLOY_TEST_FAIL:-} != post_activate ]] || fail 'Fixture post-activation failure';else
 ok=0
 for attempt in {1..16};do
  answer=$(python3 "$release/scripts/refresh-verified-stock-pool.py" --release "$release_id" --sha "$sha" --probe "$probe" || true)
  if [[ "$answer" == "$release" ]];then ok=$((ok+1));else ok=0;fi
  ((ok<8)) || break
 done
 ((ok>=8)) || fail 'Eight consecutive exact Stock FPM identities required.'
 bash "$release/scripts/refresh-dedicated-worker.sh" "$release" "$sha"
 for spec in '/inventory/:301' '/inventory/settings:302';do path=${spec%:*};status=${spec##*:};code=$(curl -sS --max-time 20 -o /dev/null -w '%{http_code}' "https://solavel.com$path");[[ "$code" == "$status" ]] || fail "Public health mismatch $path ($code)";done
fi
[[ $(readlink -f "$root/current") == "$release" && $(cat "$release/RELEASE_SHA") == "$sha" ]] || fail 'Activated identity changed.'
switched=0
echo "STOCK_APP_DEPLOYED=PASS RELEASE=$release_id SHA=$sha ROLLBACK=$old PREFLIGHT=enabled"
# Retain active, recorded rollback and the newest additional complete release.
python3 - "$root" "$release" "$old" <<'PYCODE'
from pathlib import Path
import sys,re,shutil
root,current,old=map(Path,sys.argv[1:]);keep={current.resolve(),old.resolve()}
releases=sorted([p for p in (root/'releases').iterdir() if p.is_dir() and not p.is_symlink()],reverse=True)
for p in releases:
 marker=p/'RELEASE_SHA'
 if p.resolve() in keep or not marker.is_file() or not re.fullmatch(r'[a-f0-9]{40}',marker.read_text().strip()):continue
 if len(keep)<3:keep.add(p.resolve());continue
 if p>current:continue
 assert p.parent==root/'releases' and p.resolve().parent==(root/'releases').resolve()
 shutil.rmtree(p);print('PRUNED_OLD_STOCK_RELEASE='+p.name)
PYCODE
