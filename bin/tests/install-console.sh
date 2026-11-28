#!/usr/bin/env bash
# Tests of the release rules of bin/install-console and of the release workflow (bin/lib/release.sh), plus the
# invariant the verified path relies on (Makefile: `exec`, never `run`). Run by `make shell-test` (part of `make qa`).
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
# shellcheck source=bin/lib/release.sh
. "$ROOT/bin/lib/release.sh"

failures=0
check() {
    local label="$1"
    shift
    if "$@"; then
        printf 'ok    %s\n' "$label"
    else
        printf 'FAIL  %s\n' "$label"
        failures=$((failures + 1))
    fi
}
not() { ! "$@"; }

# version_lt
check '1.9.0 < 1.10.0' version_lt 1.9.0 1.10.0
check 'not 1.10.0 < 1.9.0' not version_lt 1.10.0 1.9.0
check 'not 1.1.1 < 1.1.1' not version_lt 1.1.1 1.1.1
check '1.1.1 < 2.0.0' version_lt 1.1.1 2.0.0
check '0.9.9 < 1.0.0' version_lt 0.9.9 1.0.0

# release tags
check 'v1.1.1 is a release tag' is_release_tag v1.1.1
check 'v1.10.0 is a release tag' is_release_tag v1.10.0
check 'v1.1 is not' not is_release_tag v1.1
check 'v1.1.1-rc is not' not is_release_tag v1.1.1-rc
check '1.1.1 is not' not is_release_tag 1.1.1
check 'arbitrary text is not' not is_release_tag 'v1.1.1; rm -rf /'

# signing identities
check 'the exact identity of v1.1.1 is a release identity' is_release_identity "$(release_identity 1.1.1)"
check 'a neighbour repository is not' not is_release_identity \
    'https://github.com/DependencyVerifier/console-x/.github/workflows/release.yml@refs/tags/v1.1.1'
check 'another workflow is not' not is_release_identity \
    'https://github.com/DependencyVerifier/console/.github/workflows/ci.yml@refs/tags/v1.1.1'
check 'a branch is not' not is_release_identity \
    'https://github.com/DependencyVerifier/console/.github/workflows/release.yml@refs/heads/main'
check 'a pre-release tag is not' not is_release_identity \
    'https://github.com/DependencyVerifier/console/.github/workflows/release.yml@refs/tags/v1.1.1-rc'
check 'an unescaped dot does not match' not is_release_identity \
    'https://githubXcom/DependencyVerifier/console/.github/workflows/release.yml@refs/tags/v1.1.1'

# digest of a real `cosign verify --output json`
digest=$(verified_digest < "$ROOT/bin/tests/fixtures/cosign-verify.json")
check 'digest read from a real cosign output' \
    test "$digest" = 'sha256:91566162dbf73538d64f53a73bdefbb78e4abc456f56d3b030955127b066c4f9'
check 'no digest from a malformed output' test -z "$(printf '{"docker-manifest-digest":"sha256:abc"}' | verified_digest)"
check 'no digest from nothing' test -z "$(printf '' | verified_digest)"

# release notes: the CHANGELOG.md section of a version
CHANGELOG_FILE=$(mktemp)
export CHANGELOG_FILE
trap 'rm -f "$CHANGELOG_FILE"' EXIT
cat > "$CHANGELOG_FILE" <<'EOF'
# Changelog

## Unreleased

- Next thing.

## 1.1.01 (2026-11-28)

- Not 1.1.0.

## 11.1.0

- Not 1.1.0 either.

## 1.1.0-rc

- A pre-release.

## 1.1.2 (2026-11-27)

**Installation**
- Fixed the smoke test.

- Second paragraph.

## 1.1.1

## 1.0.0
- The first one.
EOF
notes_are() { [ "$(changelog_section "$1")" = "$2" ]; }
check 'notes of a dated section, blank lines around trimmed' notes_are 1.1.2 $'**Installation**\n- Fixed the smoke test.\n\n- Second paragraph.'
check 'notes of an undated section, the last of the file' notes_are 1.0.0 '- The first one.'
check 'notes of 11.1.0 are its own' notes_are 11.1.0 '- Not 1.1.0 either.'
check 'no notes for a missing section' not changelog_section 2.0.0
check 'no notes for an empty section' not changelog_section 1.1.1
check '## 1.1.01, ## 11.1.0 and ## 1.1.0-rc are not 1.1.0' not changelog_section 1.1.0
check 'a version still under ## Unreleased has no notes: its tag stops the release' not changelog_section 1.2.0
check 'the dot of a version is no wildcard' not changelog_section 1x1x2
check 'no notes without a CHANGELOG' not env CHANGELOG_FILE=/nonexistent bash -c ". '$ROOT/bin/lib/release.sh'; changelog_section 1.1.2 2> /dev/null"

# the verified path never lets Compose pull: the Makefile runs commands in the started containers
check 'the Makefile never uses docker compose run' not grep -qE '(\$\(DC\)|docker compose) +run\b' "$ROOT/Makefile"

if [ "$failures" -gt 0 ]; then
    printf '\n%s check(s) failed\n' "$failures"
    exit 1
fi
printf '\nall checks passed\n'
