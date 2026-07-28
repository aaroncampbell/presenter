# Security policy

## Supported versions

Security fixes are developed for the current Presenter release line and the
active modernization branch. After Presenter 2.0 is released, only the latest
2.0.x release is guaranteed to receive security fixes. The retained Presenter
1.x compatibility runtime inside 2.0 remains in scope, but separately installed
older releases may need to be upgraded to receive a fix.

## Reporting a vulnerability

Do not open a public issue, discussion, or pull request containing vulnerability
details, exploit steps, private deck content, credentials, database exports, or
production URLs.

Use the repository's private **Report a vulnerability** workflow when it is
available:

<https://github.com/aaroncampbell/presenter/security/advisories/new>

If GitHub private reporting is unavailable, use Aaron D. Campbell's contact
form and identify the report as a **Presenter plugin security issue**, not a
WordPress Core vulnerability:

<https://aarondcampbell.com/contact/>

Include the affected Presenter version, WordPress and PHP versions, required
permissions or user role, impact, reproduction steps, and the smallest safe
proof of concept. Remove secrets and replace authored presentation content with
synthetic values whenever possible.

## Coordinated disclosure

Please allow time to reproduce, fix, test, and distribute an update before
public disclosure. The maintainer will acknowledge the report, coordinate
status and disclosure details through the private channel, and credit the
reporter when requested and appropriate. Do not test against sites or data you
do not own or have explicit permission to assess.

## Security boundaries

Presenter treats slide content, notes, URLs, block attributes, legacy metadata,
theme registrations, and extension filters as untrusted input. Migration and
snapshot reports must remain content-free. Production-derived fixtures,
passwords, signing material, backups, and private capture artifacts must never
be committed or attached to a public report.
