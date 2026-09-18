#!/bin/bash

# Pre-commit hook — runs the same checks CI runs, against the staged files.
# Installed as .git/hooks/pre-commit by .claude/setup-hooks.sh.
#
# Every check that can fail sets CHECKS_PASSED=false and blocks the commit.
# Bypass with `git commit --no-verify`.

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}🚀 Running pre-commit checks...${NC}"

# Track if any checks fail
CHECKS_PASSED=true

# Check if Docker is running
if ! docker info > /dev/null 2>&1; then
    echo -e "${RED}❌ Docker is not running. Please start Docker first.${NC}"
    exit 1
fi

# Get list of staged PHP files in /api
STAGED_PHP_FILES=$(git diff --cached --name-only --diff-filter=ACM | grep "^api/.*\.php$")

if [ -n "$STAGED_PHP_FILES" ]; then
    echo -e "${YELLOW}📝 Found staged PHP files in /api directory${NC}"

    # Ensure API container is running (`--services --status running` prints bare
    # service names; the STATUS column never contains the word "running")
    if ! docker compose ps --status running --services | grep -qx api; then
        echo -e "${YELLOW}⚠️  Starting API container...${NC}"
        docker compose up -d --wait api
    fi

    # Run PHP CS Fixer
    echo -e "${GREEN}✨ Running PHP CS Fixer...${NC}"
    if ! docker compose exec -T api vendor/bin/php-cs-fixer fix --diff --dry-run; then
        echo -e "${YELLOW}⚠️  PHP CS Fixer found issues. Auto-fixing...${NC}"
        docker compose exec -T api vendor/bin/php-cs-fixer fix --diff

        # Re-add fixed files to staging
        for file in $STAGED_PHP_FILES; do
            git add "$file"
        done

        echo -e "${GREEN}✅ PHP CS Fixer: Issues fixed and files re-staged${NC}"
    else
        echo -e "${GREEN}✅ PHP CS Fixer: No issues${NC}"
    fi

    # Run PHPStan
    echo -e "${GREEN}🔬 Running PHPStan (level 8)...${NC}"
    if ! docker compose exec -T api vendor/bin/phpstan analyse; then
        echo -e "${RED}❌ PHPStan found errors${NC}"
        CHECKS_PASSED=false
    else
        echo -e "${GREEN}✅ PHPStan: No issues${NC}"
    fi

    # Run tests
    echo -e "${GREEN}🧪 Running PHPUnit tests...${NC}"
    if docker compose exec -T api bin/phpunit; then
        echo -e "${GREEN}✅ All tests passed${NC}"
    else
        echo -e "${RED}❌ Tests failed${NC}"
        CHECKS_PASSED=false
    fi
fi

# Check for staged TypeScript/JavaScript files in /app
STAGED_TS_FILES=$(git diff --cached --name-only --diff-filter=ACM | grep "^app/.*\.\(ts\|tsx\|js\|jsx\)$")

if [ -n "$STAGED_TS_FILES" ]; then
    echo -e "${YELLOW}📝 Found staged TypeScript/JavaScript files in /app directory${NC}"

    # Ensure app container is running
    if ! docker compose ps --status running --services | grep -qx app; then
        echo -e "${YELLOW}⚠️  Starting app container...${NC}"
        docker compose up -d --wait app
    fi

    # Run ESLint
    echo -e "${GREEN}🔍 Running ESLint...${NC}"
    if docker compose exec -T app yarn lint; then
        echo -e "${GREEN}✅ ESLint: No issues${NC}"
    else
        echo -e "${RED}❌ ESLint found issues${NC}"
        CHECKS_PASSED=false
    fi

    # Run TypeScript compiler check
    echo -e "${GREEN}📘 Running TypeScript compiler check...${NC}"
    if docker compose exec -T app yarn typecheck; then
        echo -e "${GREEN}✅ TypeScript: No type errors${NC}"
    else
        echo -e "${RED}❌ TypeScript found type errors${NC}"
        CHECKS_PASSED=false
    fi
fi

# Final status
if [ "$CHECKS_PASSED" = true ]; then
    echo -e "${GREEN}✨ All pre-commit checks passed!${NC}"
    exit 0
else
    echo -e "${RED}❌ Pre-commit checks failed. Please fix the issues before committing.${NC}"
    exit 1
fi
