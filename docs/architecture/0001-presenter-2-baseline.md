# ADR 0001: Presenter 2.0 baseline

Status: Accepted  
Date: 2026-07-15

## Context

Presenter 1.5.2 is the last released code on `main`. Two historical branches,
`new-editor-support` and `block-editor-conversion`, explored block-editor
modernization but did not reach a tested release.

The copied production snapshot contains a large legacy-data surface, including
1,111 `_presenter_slides` records. Code quality, migration safety, and native
WordPress behavior take priority over preserving an unfinished implementation.

## Decision

- Build Presenter 2.0 on `modernization/2.0` from `main` commit
  `9bb2352c44ca4173b912a3204ed04ee2e0fae0cf`.
- Keep the historical modernization branches and `Previous Attempt/` unchanged
  as reference material.
- Do not cherry-pick the historical conversion commits wholesale.
- Reimplement useful concepts behind characterization tests and current
  WordPress APIs.
- Require WordPress 7.0 or newer and PHP 8.3 or newer.

## Consequences

Presenter 2.0 can adopt a deliberate class structure, reproducible dependency
packaging, comprehensive tests, and a reversible migration design without
carrying forward prototype architecture. Some previously written code will be
reimplemented, but historical work remains available for comparison.
