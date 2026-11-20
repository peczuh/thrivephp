#!/bin/sh
set -eu

fixtures=$(dirname -- "$0")
. "$fixtures/run-step.sh"
target=$1
verbose=$2
project=$(mktemp -d)
step_log="$project/step.log"
cp -R "$fixtures/project/." "$project"
cp "$project/test/cases/smoke.php" "$project/test/cases/second.php"

managed() {
    browser=$1
    shift
    for workers in 1 2; do
        reports="$project/reports-$browser-$workers"
        run_step "ThrivePHP test runner: $target / $workers worker(s)" \
            php85 -d short_open_tag=1 /repo/bin/cli.php --root "$project" test \
            --browser "$browser" --headless --workers "$workers" --savepath "$reports" "$@"
        run_step "Report check: $target / $workers worker(s)" php85 -r '
            [$script, $reports, $browser, $workers] = $argv;
            $files = glob("$reports/*/report.json");
            if (count($files) !== 1) throw new RuntimeException("Expected one saved report.");
            $report = json_decode(file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
            if ($report["status"] !== "PASS"
                || $report["environment"]["browser"] !== $browser
                || $report["environment"]["workers"] !== (int) $workers
                || $report["totals"]["casesPassed"] !== 2
                || $report["totals"]["assertionsPassed"] !== 4
                || count(glob(dirname($files[0]) . "/screenshots/*/interact/*.png")) !== 2) {
                throw new RuntimeException("Unexpected managed test results or missing screenshots.");
            }
            echo "PASS: report and screenshots for $browser with $workers worker(s)\n";
        ' "$reports" "$browser" "$workers"
    done
}

case "$target" in
    Chromium)
        run_step 'WebDriver check: Chromium' php85 -d short_open_tag=1 "$fixtures/direct.php" chromium
        managed chromium --set 'test.browser.capabilities.goog:chromeOptions.args=["--no-sandbox"]'
        ;;
    Firefox)
        run_step 'WebDriver check: Firefox' php85 -d short_open_tag=1 "$fixtures/direct.php" firefox
        managed firefox
        ;;
    *) printf 'Unknown browser: %s\n' "$target" >&2; exit 2 ;;
esac
