#!/bin/sh

# The host and container each use their own log so a failed check can name its source.
run_step() {
    label=$1
    shift
    if [ "$verbose" = yes ]; then
        printf '\n== %s ==\n' "$label"
        if "$@" 2>&1; then return 0; else status=$?; fi
    else
        if "$@" > "$step_log" 2>&1; then return 0; else status=$?; fi
        printf '\n== %s ==\n' "$label" >&2
        cat "$step_log" >&2
    fi
    printf 'FAIL  %s (exit %s)\n' "$label" "$status" >&2
    return "$status"
}
