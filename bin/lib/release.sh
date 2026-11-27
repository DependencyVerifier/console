# shellcheck shell=bash
# Release rules shared by bin/install-console and .github/workflows/release.yml (sourced by both, tested by
# bin/tests/install-console.sh): the published image, who may sign it, how versions compare.
# shellcheck disable=SC2034 # constants read by the scripts that source this file

CONSOLE_IMAGE_REPO=ghcr.io/dependencyverifier/console
SIGSTORE_ISSUER=https://token.actions.githubusercontent.com
RELEASE_WORKFLOW=https://github.com/DependencyVerifier/console/.github/workflows/release.yml
# A release tag is vX.Y.Z, nothing else: same rule as the tag check of .github/workflows/release.yml.
RELEASE_TAG_REGEXP='^v[0-9]+\.[0-9]+\.[0-9]+$'
# The certificate identity of a release signature: the release workflow of this repository, run for a release tag.
RELEASE_IDENTITY_REGEXP='^https://github\.com/DependencyVerifier/console/\.github/workflows/release\.yml@refs/tags/v[0-9]+\.[0-9]+\.[0-9]+$'

# The exact identity that signs version X.Y.Z.
release_identity() {
    printf '%s@refs/tags/v%s' "$RELEASE_WORKFLOW" "$1"
}

# Succeeds when $1 is a release tag (vX.Y.Z).
is_release_tag() {
    [[ "$1" =~ $RELEASE_TAG_REGEXP ]]
}

# Succeeds when $1 is a release signing identity.
is_release_identity() {
    [[ "$1" =~ $RELEASE_IDENTITY_REGEXP ]]
}

# Succeeds when version $1 is strictly lower than version $2 (X.Y.Z, compared number by number: 1.9.0 < 1.10.0).
version_lt() {
    [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -n 1)" = "$1" ]
}

# The image digest that `cosign verify --output json` (on stdin) vouches for; nothing when absent or malformed.
verified_digest() {
    grep -oE '"docker-manifest-digest": *"sha256:[0-9a-f]{64}"' \
        | head -n 1 \
        | grep -oE 'sha256:[0-9a-f]{64}' || true
}
