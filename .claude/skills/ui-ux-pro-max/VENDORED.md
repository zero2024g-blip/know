# Vendored skill

Source: https://github.com/nextlevelbuilder/ui-ux-pro-max-skill
Version: 2.13.0 (upstream commit `bc826e2`)
License: MIT — see `LICENSE`

Installed as a project skill under `.claude/skills/ui-ux-pro-max/`.

## Local changes

- Script paths in `SKILL.md` were rewritten from `${CLAUDE_PLUGIN_ROOT}/.claude/skills/...`
  to repo-root-relative `.claude/skills/...`, since this is a project skill rather than a
  marketplace plugin install (`CLAUDE_PLUGIN_ROOT` is not set in that case).
- `scripts/tests/` was dropped; it is upstream's dev-only test suite.

Nothing else was modified. To update, re-copy `.claude/skills/ui-ux-pro-max/` from upstream
and re-apply the two changes above.
