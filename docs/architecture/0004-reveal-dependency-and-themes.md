# ADR 0004: Reveal.js dependency and Presenter themes

Status: Accepted  
Date: 2026-07-15

## Context

Presenter 1.x stores Reveal.js as a Git submodule and discovers CSS themes by
filesystem path. Submodules complicate ordinary plugin clones and WordPress.org
release packaging. Paths are also poor persistent identities for themes supplied
by another plugin or moved between directories.

## Decision

- Pin a stable Reveal.js npm release and its lockfile.
- Build or copy only required runtime assets and bundled Reveal plugins into the
  installable Presenter artifact.
- Include upstream licenses and reproducible source/build instructions.
- Remove the Git submodule only when the replacement build is verified.
- Register Presenter themes through stable IDs with labels and stylesheet
  metadata instead of using a path as identity.
- Preserve existing public theme and Reveal filters through a documented 2.0
  compatibility adapter.
- Treat the separate `aarondcampbell-presenter-themes` plugin and its
  `aaron-purple` theme as required integration fixtures.

## Consequences

Fresh clones and release builds no longer depend on submodule initialization.
Theme selections survive file moves, and extensions gain a documented registry
while existing integrations have a controlled migration path.
