#!/bin/bash

# Post-edit code-quality hook.
#
# Two callers, one script:
#   1. Claude Code (PostToolUse, see .claude/settings.json) — pipes the tool-call
#      JSON on stdin; the edited path is at .tool_input.file_path.
#   2. A human / another script — pass the path as $1.
#
# Only PHP files under api/ trigger anything; everything else exits 0 silently so
# the hook stays invisible during frontend work.

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

EDITED_FILE="${1}"

# No argument → read the Claude Code hook payload from stdin. `jq` is the happy
# path; the grep fallback keeps the hook working on a machine without it.
if [ -z "$EDITED_FILE" ] && [ ! -t 0 ]; then
    PAYLOAD="$(cat)"
    if command -v jq > /dev/null 2>&1; then
        EDITED_FILE="$(printf '%s' "$PAYLOAD" | jq -r '.tool_input.file_path // empty')"
    else
        EDITED_FILE="$(printf '%s' "$PAYLOAD" | grep -o '"file_path"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | sed 's/.*"\([^"]*\)"$/\1/')"
    fi
fi

if [[ "$EDITED_FILE" != *"api/"* ]] || [[ "$EDITED_FILE" != *".php" ]]; then
    exit 0
fi

echo -e "${YELLOW}🔍 Code quality check triggered for API file: ${EDITED_FILE}${NC}"

if ! docker info > /dev/null 2>&1; then
    echo -e "${RED}❌ Docker is not running — skipping code quality checks.${NC}"
    exit 0
fi

# `--services --status running` prints bare service names (the human-readable
# STATUS column says "Up …", never "running", so don't grep that).
if ! docker compose ps --status running --services | grep -qx api; then
    echo -e "${YELLOW}⚠️  API container is not running. Starting it now...${NC}"
    # --wait blocks until the healthcheck passes (entrypoint done, vendor ready)
    # instead of a blind sleep.
    docker compose up -d --wait api
fi

echo -e "${GREEN}✨ Running PHP CS Fixer...${NC}"
if docker compose exec -T api vendor/bin/php-cs-fixer fix --diff --dry-run; then
    echo -e "${GREEN}✅ PHP CS Fixer: No issues found${NC}"
else
    echo -e "${YELLOW}⚠️  PHP CS Fixer found issues. Auto-fixing...${NC}"
    docker compose exec -T api vendor/bin/php-cs-fixer fix --diff
    echo -e "${GREEN}✅ PHP CS Fixer: Issues fixed${NC}"
fi

echo -e "${GREEN}🔬 Running PHPStan (level 8)...${NC}"
if docker compose exec -T api vendor/bin/phpstan analyse; then
    echo -e "${GREEN}✅ PHPStan: No issues found${NC}"
else
    echo -e "${RED}❌ PHPStan found issues that need manual fixing${NC}"
    # Exit 2 tells Claude Code the hook failed and feeds this output back as
    # context, so the errors get addressed instead of silently ignored.
    exit 2
fi

echo -e "${GREEN}✨ Code quality checks completed successfully!${NC}"
exit 0
