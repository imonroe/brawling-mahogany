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
# Re-running is safe: the API call is a PUT, so it replaces the rule rather
# than stacking a second one.

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

if [ "${1:-}" = "--show" ]; then
    payload
    exit 0
fi

command -v gh >/dev/null || {
    echo "This needs the gh CLI: https://cli.github.com" >&2
    exit 1
}

for branch in dev main; do
    echo "Protecting ${branch} on ${REPO}..."
    payload | gh api -X PUT "repos/${REPO}/branches/${branch}/protection" --input - >/dev/null
    echo "  ${branch}: pull request required, ${#CHECKS[@]} checks blocking."
done

echo
echo "Verify at https://github.com/${REPO}/settings/branches"
