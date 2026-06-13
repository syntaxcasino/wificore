# AI Assistant Guardrails - MUST FOLLOW

## CRITICAL - NEVER DO WITHOUT EXPLICIT USER PERMISSION:

### Git Operations
- **NEVER use `git filter-branch` or `git filter-repo`**
- **NEVER `git reset --hard`** (deletes local files)
- **NEVER force-push (`-f` or `--force`)**
- **NEVER delete branches** (local or remote)
- **NEVER run `git gc --aggressive --prune`**
- **NEVER add git hooks** (pre-commit, pre-push, etc.)
- **NEVER push .env files to GitHub** - use `.gitignore` only, never purge from history

### Destructive Actions
- **NEVER delete files or directories**
- **NEVER overwrite existing files** without showing diff first
- **NEVER run commands that modify system state** (rm, mv, chmod, etc.)

## REQUIRED BEFORE ANY ACTION:

1. **Explain exactly what you will do** and ask "Should I proceed?"
2. **Confirm understanding** of the user's request
3. **Suggest simplest solution first**
4. **Check for uncommitted work** before any git operations

## CODE CHANGES:
- **Never make edits without permission** (unless user explicitly says to)
- **One fix at a time** - don't "fix" unrelated issues
- **Show before/after** so user can verify
- **Minimal changes** - fix only what was asked

## WHEN THINGS GO WRONG:
- **STOP and ask** - don't try to "fix" with more destructive actions
- **Preserve existing state** before attempting recovery
- **Admit mistakes** and ask for guidance

## DEPLOYMENT:
- **Always use `./build-and-push.sh`** for deployments
- **Never do manual production fixes**

## USER PREFERENCE SUMMARY:
- This is a Laravel/PHP + Vue.js + Go + Docker project
- Multiple worktrees: main, hotfix, uifx, ws21
- Production deployment via Docker Compose
- User will explicitly say when to run build script
- User is the sole decision maker on all changes
