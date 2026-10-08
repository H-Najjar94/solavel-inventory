#!/usr/bin/env bash
# Complete Stock activation only after the registered transport worker aligns.
# Use the registered systemd SIGTERM stop policy; never force-kill deliveries.
set -euo pipefail
release=${1:?immutable release directory}
sha=${2:?exact release SHA}
service=solastock-finance-v2-worker.service
[[ "$sha" =~ ^[a-f0-9]{40}$ ]] || { echo 'Invalid release SHA' >&2; exit 2; }
release=$(readlink -f "$release")
root=$(dirname "$(dirname "$release")")
assert_current() {
  [[ "$(readlink -f "$root/current")" == "$release" && "$(cat "$release/RELEASE_SHA")" == "$sha" ]]     || { echo 'Serving release changed; dedicated worker completion refused' >&2; return 1; }
}
assert_current
[[ "$(systemctl show "$service" -p LoadState --value)" == loaded ]]   || { echo "Required registered worker is unavailable: $service" >&2; exit 1; }
# Use the existing unit's graceful stop policy; never force-kill a job.
systemctl restart "$service"
for attempt in {1..30}; do
  assert_current
  pid=$(systemctl show "$service" -p MainPID --value)
  if systemctl is-active --quiet "$service" && [[ "$pid" =~ ^[1-9][0-9]*$ ]]       && [[ "$(readlink -f "/proc/$pid/cwd" 2>/dev/null || true)" == "$release" ]] \
      && php "$release/scripts/verify-transport-worker-release.php" "$release" "$sha"; then
    printf 'DEDICATED_WORKER=PASS SERVICE=%s PID=%s SHA=%s\n' "$service" "$pid" "$sha"
    exit 0
  fi
  sleep 1
done
echo "Dedicated worker failed to align with $sha; activation is not verified" >&2
exit 1
