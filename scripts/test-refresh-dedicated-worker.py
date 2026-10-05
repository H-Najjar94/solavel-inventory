#!/usr/bin/env python3
"""Exercise activation completion without systemd or production mutations."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).with_name("refresh-dedicated-worker.sh").resolve()

class WorkerCompletionTest(unittest.TestCase):
    def run_hook(self, mode):
        with tempfile.TemporaryDirectory(prefix="stock-worker-hook-test-") as directory:
            root = Path(directory)
            release = root / "releases" / "candidate"
            release.mkdir(parents=True)
            sha = "a" * 40
            (release / "RELEASE_SHA").write_text(sha + "\n")
            (root / "current").symlink_to(release)
            commands = root / "bin"
            commands.mkdir()
            (commands / "systemctl").write_text("""#!/usr/bin/env bash
case "$1" in
 show) if [[ "$*" == *LoadState* ]]; then [[ "$MODE" == missing ]] && echo not-found || echo loaded; else echo 123; fi ;;
 restart) printf 'restart\n' >> "$TEST_ROOT/actions"; if [[ "$MODE" == race ]]; then /usr/bin/ln -sfn "$TEST_ROOT/releases/other" "$TEST_ROOT/current"; fi ;;
 is-active) exit 0 ;;
esac
""")
            (commands / "readlink").write_text("""#!/usr/bin/env bash
if [[ "${@: -1}" == /proc/123/cwd ]]; then
  [[ "$MODE" == stale ]] && echo "$TEST_ROOT/releases/old" || echo "$TEST_ROOT/releases/candidate"
else /usr/bin/readlink "$@"; fi
""")
            (commands / "sleep").write_text("#!/usr/bin/env bash\nexit 0\n")
            (commands / "php").write_text("#!/usr/bin/env bash\n[[ \"$MODE\" != heartbeatbad ]]\n")
            for command in commands.iterdir():
                command.chmod(0o755)
            result = subprocess.run(["bash", str(SCRIPT), str(release), sha], capture_output=True, text=True,
                                    env={**os.environ, "PATH": str(commands) + ":" + os.environ["PATH"],
                                         "MODE": mode, "TEST_ROOT": str(root)})
            actions = (root / "actions").read_text() if (root / "actions").exists() else ""
            return result, actions

    def test_aligned_worker_completes_once(self):
        result, actions = self.run_hook("aligned")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("DEDICATED_WORKER=PASS", result.stdout)
        self.assertEqual(actions, "restart\n")

    def test_missing_registered_worker_refuses_before_restart(self):
        result, actions = self.run_hook("missing")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(actions, "")

    def test_old_worker_never_reports_success(self):
        result, actions = self.run_hook("stale")
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("DEDICATED_WORKER=PASS", result.stdout)
        self.assertEqual(actions, "restart\n")

    def test_stale_native_heartbeat_never_reports_success(self):
        result, _ = self.run_hook("heartbeatbad")
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("DEDICATED_WORKER=PASS", result.stdout)

    def test_concurrent_release_change_refuses_completion(self):
        result, _ = self.run_hook("race")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Serving release changed", result.stderr)

if __name__ == "__main__":
    unittest.main()
