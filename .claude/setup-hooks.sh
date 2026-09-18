#!/bin/bash

# Setup script for Claude Code hooks
# This script installs Git hooks that integrate with Claude Code

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
GIT_HOOKS_DIR="$PROJECT_ROOT/.git/hooks"

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}🔧 Setting up Claude Code hooks...${NC}"

# Refuse to run outside a git repository — mkdir would otherwise create a junk
# `.git/hooks` directory that git never reads.
if ! git -C "$PROJECT_ROOT" rev-parse --git-dir > /dev/null 2>&1; then
    echo -e "${RED}❌ Not a git repository. Run 'git init' first.${NC}"
    exit 1
fi

# Create git hooks directory if it doesn't exist
if [ ! -d "$GIT_HOOKS_DIR" ]; then
    echo -e "${YELLOW}Creating .git/hooks directory...${NC}"
    mkdir -p "$GIT_HOOKS_DIR"
fi

# Install pre-commit hook
echo -e "${GREEN}Installing pre-commit hook...${NC}"
cat > "$GIT_HOOKS_DIR/pre-commit" << 'EOF'
#!/bin/bash
# Git pre-commit hook that calls Claude Code hook

HOOK_SCRIPT=".claude/hooks/pre-commit.sh"

if [ -f "$HOOK_SCRIPT" ]; then
    bash "$HOOK_SCRIPT"
    exit $?
else
    echo "Claude Code pre-commit hook not found. Skipping..."
    exit 0
fi
EOF

chmod +x "$GIT_HOOKS_DIR/pre-commit"
echo -e "${GREEN}✅ Pre-commit hook installed${NC}"

# Create a post-merge hook for dependency updates
echo -e "${GREEN}Installing post-merge hook...${NC}"
cat > "$GIT_HOOKS_DIR/post-merge" << 'EOF'
#!/bin/bash
# Git post-merge hook for updating dependencies

# -T: git hooks run without a TTY, and `docker compose exec` fails allocating
# one in that context.

# Check if composer.json changed
if git diff HEAD@{1} --name-only | grep -q "api/composer.json"; then
    echo "📦 composer.json changed. Running composer install..."
    docker compose exec -T api composer install
fi

# Check if package.json changed
if git diff HEAD@{1} --name-only | grep -q "app/package.json"; then
    echo "📦 package.json changed. Running yarn install..."
    docker compose exec -T app yarn install
fi
EOF

chmod +x "$GIT_HOOKS_DIR/post-merge"
echo -e "${GREEN}✅ Post-merge hook installed${NC}"

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}✨ Claude Code hooks setup completed!${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""
echo -e "${YELLOW}The following hooks are now active:${NC}"
echo -e "  • ${GREEN}pre-commit${NC}: Runs PHP CS Fixer, PHPStan, ESLint, and TypeScript checks"
echo -e "  • ${GREEN}post-merge${NC}: Auto-updates dependencies when composer.json or package.json changes"
echo ""
echo -e "${BLUE}To disable hooks temporarily, use:${NC}"
echo -e "  git commit --no-verify"
echo ""
echo -e "${BLUE}To uninstall hooks, run:${NC}"
echo -e "  rm .git/hooks/pre-commit .git/hooks/post-merge"