# Claude Code Configuration

Claude Code configuration and quality hooks for this project.

## Structure

```
.claude/
├── README.md           # This file
├── settings.json       # Claude Code hooks (PostToolUse → post-edit.sh)
├── setup-hooks.sh      # Installation script for Git hooks
└── hooks/
    ├── post-edit.sh    # Runs after editing PHP files in api/
    └── pre-commit.sh   # Runs before Git commits
```

## Quick Setup

`settings.json` is read by Claude Code automatically — nothing to install. The
**Git** hooks need one command:

```bash
bash .claude/setup-hooks.sh
```

## Two hook systems, on purpose

They fire at different moments and are not redundant:

| | Trigger | Installed by |
|---|---|---|
| `settings.json` → `post-edit.sh` | Claude Code writes a `.php` file under `api/` | nothing (read directly) |
| `.git/hooks/pre-commit` → `pre-commit.sh` | any commit, whoever makes it | `setup-hooks.sh` |

The first gives immediate feedback while editing; the second is the gate that
catches everything, including changes you made by hand.

## Hooks

### Post-Edit (`hooks/post-edit.sh`)
- **Triggers**: after Claude Code edits/writes any PHP file under `api/`
- **Actions**: PHP CS Fixer (auto-fix) → PHPStan (level 8)
- **On PHPStan failure**: exits 2, which feeds the errors back to Claude Code as
  context so they get fixed rather than ignored
- **No Docker running**: exits 0 silently — it never blocks you

It can also be run by hand with a path argument:

```bash
bash .claude/hooks/post-edit.sh api/src/Entity/Example.php
```

### Pre-Commit (`hooks/pre-commit.sh`)
- **Triggers**: before any Git commit
- **Staged PHP under `api/`**: PHP CS Fixer (auto-fix + re-stage) → PHPStan → PHPUnit
- **Staged TS/JS under `app/`**: ESLint → `tsc --noEmit`
- **Blocks the commit on**: PHPStan errors, failing tests, lint errors, type errors

`setup-hooks.sh` also installs a **post-merge** hook that reinstalls dependencies
when `api/composer.json` or `app/package.json` changed in the merge.

## Disabling Hooks

Commit without running the Git hooks:
```bash
git commit --no-verify -m "Your message"
```

Uninstall them completely:
```bash
rm .git/hooks/pre-commit .git/hooks/post-merge
```

To disable the Claude Code hook, remove the `hooks` block from `settings.json`.

## Requirements

- Docker and Docker Compose running
- The `api` and `app` containers available (the hooks start them if needed)
- `jq` is optional — `post-edit.sh` falls back to a grep-based parse without it

## Troubleshooting

**Docker not running.** Start Docker/OrbStack. `post-edit.sh` skips silently;
`pre-commit.sh` refuses to run.

**Container not found.** The hooks start containers automatically. If that fails:
`docker compose up -d`.

**PHPStan errors.** Fix them manually — static-analysis errors cannot be auto-fixed.

**Permission denied.**
```bash
chmod +x .claude/hooks/*.sh .claude/setup-hooks.sh
```
