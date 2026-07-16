# Contributing to Presenter

Presenter 2.0 is being developed for the latest WordPress release and PHP 8.3
or newer. Code should favor WordPress public APIs, native blocks, readable
implementation, and documented compatibility behavior over cleverness.

## Before changing code

1. Read the decisions in `docs/architecture/`.
2. Review the compatibility contract in `docs/compatibility/` when touching
   legacy content, themes, URLs, hooks, or Reveal.js integration.
3. Follow `docs/local-development.md` for environment setup.

Production-derived fixtures must never be committed. Do not connect development
or migration tooling to the live AaronDCampbell.com site.

## Quality checks

Install the pinned dependencies with `composer install` and `npm ci`, then run:

```sh
composer check
composer phpcs
composer check:audit
npm run check
npm run test:php
npm run build
npm audit --omit=dev
```

Run focused tests while developing and add regression coverage for every bug
fix. Changes to behavior should update the relevant documentation or create an
architecture decision record when the choice will constrain later work.

## Pull requests

Keep changes narrowly scoped and explain user-visible behavior, compatibility
impact, tests performed, and any follow-up work. Do not publish to WordPress.org
or update its SVN repository as part of ordinary development. A public release
requires completed migration rehearsal, full QA, and explicit maintainer
authorization.
