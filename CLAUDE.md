# Instructions for Claude (and other AI assistants) in the AuBi-One fork

## Commits

- Commit as the **human you are working for**, with their own git identity. Never as "Claude".
- No AI attribution in commit messages or PR descriptions: no `Co-Authored-By: Claude`,
  no "Generated with Claude Code", no session links.
- Why: several people work on this fork with AI assistants; the commit author must show
  which person did the work.
- Use the GitHub no-reply address, never a private email.
- On the shared dev VM `gald-dev` (`/srv/gaeld`), the repo identity is set to David; anyone
  else sets their own per commit: `git -c user.name=… -c user.email=… commit …`.

## Branches

- `main` mirrors upstream Scanix/Gaeld untouched.
- `aubi` is AuBi-One's line, based on the upstream **public** release (see README
  "Current public release"), not the newest tag — later tags are staging candidates.
- Own features go into plugins under `plugins/` where possible, to keep upstream merges clean.
- Upstream conventions in AGENTS.md still apply.
