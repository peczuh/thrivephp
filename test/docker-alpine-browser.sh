#!/bin/sh
# On-demand native browser regression. Uses the active Docker context and platform.
set -eu

verbose=no
case "${1:-}" in
    --verbose) verbose=yes; shift ;;
esac
if [ "$#" -ne 0 ]; then
    printf 'Usage: sh test/docker-alpine-browser.sh [--verbose]\n' >&2
    exit 2
fi

repo=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
fixtures="$repo/test/fixtures/docker-alpine"
. "$fixtures/run-step.sh"

finish() {
    result=$?
    if [ -n "${scratch:-}" ]; then
        rm -f "$scratch/image" "$scratch/step.log" || :
        rmdir "$scratch" || :
    fi
    if [ "$result" -eq 0 ]; then
        printf '\nPASS  Docker Alpine browser regression\n'
    else
        printf '\nFAIL  Docker Alpine browser regression (exit %s)\n' "$result" >&2
    fi
}
trap finish EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

scratch=$(mktemp -d)
step_log="$scratch/step.log"
printf 'Running Docker Alpine browser regression\n'

build() {
    stage=$1
    run_step "Docker build: $stage" docker build --progress=plain --load --target "$stage" \
        --iidfile "$scratch/image" "$fixtures"
    image=$(cat "$scratch/image")
}

container() {
    docker run --rm --init --network none \
        --mount "type=bind,src=$repo,dst=/repo,readonly" \
        "$image" "$@"
}

build native
platform=$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$image") || {
    status=$?
    printf 'FAIL  Docker image inspect: native (exit %s)\n' "$status" >&2
    exit "$status"
}
printf 'Platform: %s\n' "$platform"
for browser in Chromium Firefox; do
    run_step "Docker run: $browser" container \
        sh /repo/test/fixtures/docker-alpine/run-native.sh "$browser" "$verbose"
    printf 'PASS  %s: headless session, 1-worker and 2-worker suites\n' "$browser"
done

build chrome-stock
run_step 'Docker run: Google Chrome on stock Alpine' container \
    php85 /repo/test/fixtures/docker-alpine/check-chrome.php stock
printf 'PASS  Google Chrome on stock Alpine: pair unavailable as expected\n'
build chrome-compat
run_step 'Docker run: Google Chrome with gcompat' container \
    php85 /repo/test/fixtures/docker-alpine/check-chrome.php compat
printf 'PASS  Google Chrome with gcompat: pair unavailable as expected\n'
