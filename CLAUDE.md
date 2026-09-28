# tamarackdb-php

PHP client for TamarackDB, published as `tamarackdb/tamarackdb-php`. The
server and its documentation live in <https://github.com/tamarackdb/tamarackdb>.
Where that repo is cloned locally differs from one machine to another: never
assume a path, ask for it when you need to read the server's code.

## Source of truth

The client follows the server's HTTP API as described in the integration
guide, <https://tamarackdb.github.io/docs/guides/integration/>. Read it
before changing how the client talks to the server. Link to the site, never
to the `.md` files of the server repo. When the guide and the Go code
(`internal/api/` in the server repo) disagree, the Go code is what the server
does: point out the gap instead of guessing.

The README states which server version the client is tested against. Update
it when the client moves to a new server version.

## Code conventions

- PHP 8.4 minimum. Every file starts with `declare(strict_types=1);`.
- Value objects are `final readonly class`. Other classes are `final`.
- No runtime dependency beyond `ext-curl` and `ext-json`. Dev tools only in
  `require-dev`.
- Payloads (events and projections) are opaque strings. The client never
  encodes or decodes them.
- Classes under `src/Internal/` are `@internal`: not part of the public API,
  free to change.
- Every exception the library throws on purpose implements
  `TamarackDB\Exception\TamarackDBException`. Each server `error` code has
  its own `ServerException` subclass, mapped in `ServerException::CLASSES`.
- Validate on the client only what fails early and cheaply (empty type, empty
  query item, repeated projection key). Leave server limits (sizes, counts,
  page size) to the server, since the operator configures them.

## Checks

Run all of these before calling a change done:

```sh
composer test:unit
composer analyse   # PHPStan, level max
composer cs        # PHP-CS-Fixer, PER-CS 2.0
TAMARACKDB_SERVER_BIN=/path/to/tamarackdb-server composer test:integration
```

Integration tests start their own servers (TCP on a free port, unix socket,
auth on) from `TAMARACKDB_SERVER_BIN`, with `devMode` on, and are skipped
without it. Build the binary from a clone of the server repo with
`make tamarackdb-server`, and check its version with `-version`: it must
match the server version the README states.

Fix PHPStan errors at their cause. Don't add `@phpstan-ignore` comments,
baseline entries, or casts just to silence one.

No CI workflow for now.

## Writing style

These rules apply to the README, PHPDoc comments, and code comments. Code,
comments, and the README are in English.

- Short sentences. Simple, direct English.
- Avoid avoidable technical jargon and business buzzwords (leverage,
  seamless, robust, best-in-class, synergy, etc.).
- Necessary technical terms stay (NDJSON, Sequence Position, Append
  Condition, generator, etc.): they are the real names of things. Simplify
  sentence length and phrasing around them, not the technical precision.
- Use the server's own terms, as the integration guide defines them:
  ticket, Sequence Position, Append Condition, projection, projection
  rebuild, decision model, event handler.
- Never use an em-dash ("—"). Use a comma, colon, semicolon, parentheses,
  or a new sentence instead.

### No references to prior designs

Write the current design as if it had always been the only one. Never say
"unlike its predecessor," "the old X," "this replaces Y," or similar, in
code comments or docs. If historical rationale needs to be preserved, put it
in a commit message, not in the doc or comment itself.

## Documentation

The README is the only documentation. Its audience is developers using the
library in their application, except the Development section, which is for
contributors. Don't repeat the server's integration guide at length: explain
what the client does, and link to <https://tamarackdb.github.io/> for the
concepts.

## Versioning context

The library is pre-1.0, like the server. Breaking changes to the public API
are expected before v1.0. Don't write migration guides or deprecation
notices.
