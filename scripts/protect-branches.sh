#!/usr/bin/env bash
#
# Apply the branch protection that docs/Deployment.md §7 specifies, and that
# the Slice 0 epic (#1) makes an exit criterion.
#
# This is a script rather than a paragraph because it is the one part of the
# pipeline that cannot be committed. CI already runs five jobs on every pull
# request; without this, none of them *block* a merge, so "a PR to dev runs
# Pest, PHPStan and Pint and blocks on failure" is only half true. A red
# pipeline stays advisory until somebody runs this.
#
#   ./scripts/protect-branches.sh          # apply
#   ./scripts/protect-branches.sh --show   # print the payload, change nothing
#
# Requires the `gh` CLI authenticated as a repository admin (`gh auth login`).
#
# Re-running is safe in that the API call is a PUT, so it replaces the rule
# rather than stacking a second one. The flip side of a PUT is worth knowing
# before you run it on a repository somebody has configured by hand: anything
# not in the payload below — required signed commits, required conversation
# resolution, linear history — is *cleared*, not preserved.

set -euo pipefail

REPO="${REPO:-imonroe/brawling-mahogany}"

# The five jobs in .github/workflows/ci.yml. These are the job *names* as
# GitHub reports them — the `name:` fields, not the job keys. If a job is
# renamed there, it must be renamed here, or the rule will require a check
# that never reports and every pull request will wait forever.
CHECKS=('Tests' 'Static analysis' 'Code style' 'Front end' 'Container build')

payload() {
    # `enforce_admins` is deliberately false. The point is to stop a red merge
    # by accident, not to lock the owner out of their own repository during an
    # incident — and a rule you cannot bypass is a rule people disable.
    #
    # No required approvals: this is a small team, and a required reviewer who
    # is out on a showing is a stalled merge. The five checks are the gate.
    CHECKS_TSV="$(printf '%s\t' "${CHECKS[@]}")" python3 -c '
import json, os
print(json.dumps({
    "required_status_checks": {
        # Not strict: a strict rule requires every PR to be up to date with the
        # base before merging, so on a five-job pipeline each merge invalidates
        # every other open PR and the team spends the day re-running CI. The
        # cost is that a PR green against an older base can still break dev;
        # with this few concurrent PRs that trade is the right way round.
        "strict": False,
        "contexts": [c for c in os.environ["CHECKS_TSV"].split("\t") if c],
    },
    "enforce_admins": False,
    "required_pull_request_reviews": {
        "required_approving_review_count": 0,
        "dismiss_stale_reviews": False,
    },
    "restrictions": None,
    "allow_force_pushes": False,
    "allow_deletions": False,
}, indent=2))
'
}

# Anything that is not exactly `--show` used to fall through to the PUT, so a
# mistyped `--dry-run` silently rewrote the settings on two branches. For a
# script whose other mode is "replace repository configuration", an unknown
# argument has to be a refusal.
need() {
    command -v "$1" >/dev/null || {
        echo "This needs $1 on PATH." >&2
        exit 1
    }
}

# python3 builds the payload, so both modes need it. gh is only needed to send
# it — `--show` should work on a machine with no GitHub CLI at all.
need python3

case "${1:-}" in
    '')
        ;;
    --show)
        payload
        exit 0
        ;;
    *)
        echo "Unknown argument: $1" >&2
        echo "Usage: $0 [--show]" >&2
        exit 1
        ;;
esac

need gh

for branch in dev main; do
    echo "Protecting ${branch} on ${REPO}..."
    payload | gh api -X PUT "repos/${REPO}/branches/${branch}/protection" --input - >/dev/null
    echo "  ${branch}: pull request required, ${#CHECKS[@]} checks blocking."
done

echo
echo "Verify at https://github.com/${REPO}/settings/branches"
