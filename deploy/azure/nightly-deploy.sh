#!/usr/bin/env bash
# =============================================================================
# KMP — Nightly deploy helper
# -----------------------------------------------------------------------------
# Thin wrapper over the `gh` CLI so you don't have to remember the workflow
# file names / input parameters. All real work happens in GitHub Actions.
#
# Usage:
#   deploy/azure/nightly-deploy.sh                 # build + deploy (push-free)
#   deploy/azure/nightly-deploy.sh deploy          # deploy current :nightly image
#   deploy/azure/nightly-deploy.sh build           # rebuild image (auto-chains deploy)
#   deploy/azure/nightly-deploy.sh reset           # deploy + full DB reset + reseed
#   deploy/azure/nightly-deploy.sh status          # show last 5 deploy runs
#   deploy/azure/nightly-deploy.sh watch           # tail the latest deploy run
#   deploy/azure/nightly-deploy.sh health          # curl the nightly /health endpoint
#   deploy/azure/nightly-deploy.sh url             # print the nightly URL
#
# Environment:
#   NIGHTLY_BRANCH  — branch to deploy from (default: current branch)
#   GH_REPO         — owner/repo override (default: jhandel/KMP)
# =============================================================================
set -euo pipefail

REPO="${GH_REPO:-jhandel/KMP}"
BRANCH="${NIGHTLY_BRANCH:-$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo feature/workflow-engine)}"

BUILD_WF="nightly.yml"
DEPLOY_WF="nightly-deploy-azure.yml"
BUILD_NAME="Nightly / Dev Docker Image"
DEPLOY_NAME="Nightly / Deploy to Azure"
NIGHTLY_URL="https://kmpnightly-web.lemonstone-62ccb06f.centralus.azurecontainerapps.io"

need() {
    command -v "$1" >/dev/null 2>&1 || { echo "❌ required command missing: $1" >&2; exit 1; }
}

cmd_deploy() {
    local extra=()
    if [[ "${1:-}" == "--reset" ]]; then
        extra=(-f full_reset=true)
        echo "⚠️  FULL RESET requested — database will be wiped and reseeded."
    fi
    echo "🚀 Triggering $DEPLOY_NAME on $BRANCH (repo: $REPO)"
    if ! gh workflow run "$DEPLOY_WF" --repo "$REPO" --ref "$BRANCH" "${extra[@]}" 2>&1; then
        cat >&2 <<EOF

❌ gh refused to trigger the deploy workflow.

   This is almost always because '$DEPLOY_WF' does not yet exist on the
   repo's default branch. GitHub Actions only registers workflow_dispatch
   entry points for workflows present on the default branch.

   Fixes (pick one):
     • Merge / cherry-pick .github/workflows/$DEPLOY_WF to 'main'.
     • Or run \`$0 build\` instead — the build workflow (nightly.yml) IS
       registered, and once the deploy workflow is on main it will
       auto-chain via workflow_run.
EOF
        exit 1
    fi
    sleep 4
    cmd_status
    echo "→ run \`$0 watch\` to tail the run."
}

cmd_build() {
    echo "🏗  Triggering $BUILD_NAME on $BRANCH (will auto-chain deploy on success)"
    gh workflow run "$BUILD_WF" --repo "$REPO" --ref "$BRANCH"
    sleep 4
    _list_runs "$BUILD_WF" 3
    echo "→ run \`$0 watch\` once the build finishes to watch the deploy."
}

_list_runs() {
    # Works even when the workflow file isn't on the default branch.
    local wf="$1" limit="${2:-5}"
    local json
    json=$(gh api "repos/$REPO/actions/workflows/$wf/runs?per_page=$limit" 2>/dev/null || true)
    if [[ -z "$json" ]] || echo "$json" | grep -q '"message":"Not Found"'; then
        echo "  (workflow '$wf' not yet registered — it must exist on the default branch, or be triggered once manually)"
        return
    fi
    echo "$json" | jq -r '.workflow_runs[] | [(.status // "?"), (.conclusion // "-"), .head_branch, .event, ("#" + (.run_number|tostring)), .display_title, .html_url] | @tsv' \
        | awk -F'\t' '{printf "  %-10s %-10s %-28s %-15s %-6s %s\n    %s\n", $1, $2, $3, $4, $5, $6, $7}'
}

cmd_status() {
    echo "── last 5 deploy runs ──"
    _list_runs "$DEPLOY_WF" 5
    echo ""
    echo "── last 5 build runs ──"
    _list_runs "$BUILD_WF" 5
}

cmd_watch() {
    local id
    id=$(gh api "repos/$REPO/actions/workflows/$DEPLOY_WF/runs?per_page=1" --jq '.workflow_runs[0].id' 2>/dev/null)
    [[ -z "$id" || "$id" == "null" ]] && { echo "no deploy runs found"; exit 1; }
    echo "⌚ watching deploy run $id"
    gh run watch "$id" --repo "$REPO" --exit-status
}

cmd_health() {
    echo "🩺 GET $NIGHTLY_URL/health"
    curl -sS "$NIGHTLY_URL/health"
    echo
}

cmd_url() {
    echo "$NIGHTLY_URL"
}

need gh
need git

case "${1:-deploy}" in
    deploy)   cmd_deploy "${2:-}" ;;
    reset)    cmd_deploy --reset ;;
    build)    cmd_build ;;
    status)   cmd_status ;;
    watch)    cmd_watch ;;
    health)   cmd_health ;;
    url)      cmd_url ;;
    -h|--help|help)
        sed -n '2,19p' "$0"
        ;;
    *)
        echo "unknown subcommand: $1" >&2
        sed -n '2,19p' "$0"
        exit 2
        ;;
esac
