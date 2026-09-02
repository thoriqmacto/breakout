#!/bin/bash
#
# Installs what the API and web test/lint commands need, so a web session can
# run them without a manual setup pass first.
#
# The one non-obvious decision here is that this writes no `.env`.
# `config/jwt.php` resolves its secret as
#
#     env('JWT_SECRET', env('APP_KEY', 'fallback-jwt-secret'))
#
# so with no `.env` at all the chain lands on a working fallback. Copying
# `.env.example` breaks that: it sets `JWT_SECRET=` and `APP_KEY=` to the
# *empty string*, which is a value rather than an absence, so the defaults
# never apply and `AppServiceProvider::boot()` throws while resolving
# JwtService -- taking every test with it. `phpunit.xml` already pins the rest
# of the test environment (sqlite in-memory, array cache and session), which
# leaves APP_KEY as the only genuine gap, and a throwaway one exported into the
# session covers it without putting a key in the repository.
#
# This is the same reasoning the CI workflow documents, kept deliberately in
# step with it: if the two ever diverge, a green session stops predicting a
# green pipeline.

set -euo pipefail

# Local machines have their own setup and their own .env; this exists for the
# ephemeral remote container, where nothing is installed yet.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  echo "Not a remote session; leaving the local environment alone."
  exit 0
fi

ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"

echo "==> Installing API (composer) dependencies"
# `install`, not `ci`-style flags: dev dependencies carry PHPUnit and Pint,
# which are the whole point of this hook. Composer is idempotent and the
# container image is cached after the hook completes, so a re-run is cheap.
(cd "$ROOT/apps/api" && composer install --prefer-dist --no-interaction --no-progress)

echo "==> Installing web (npm) dependencies"
# `npm install` rather than `npm ci`: ci deletes node_modules first and so
# throws away exactly the cache this hook is trying to warm.
(cd "$ROOT/apps/web" && npm install --no-audit --no-fund)

# An *empty* .env, and only when there is none.
#
# Laravel's dotenv loader warns on every single test when the file is absent,
# which buries any warning worth reading under seven hundred that are not. An
# empty file silences it and sets no variables at all, so the env() fallback
# chain above is untouched -- verified against the auth suite, which is the
# part that would break first if JWT_SECRET stopped resolving.
#
# Never overwritten: a real .env belongs to whoever put it there.
if [ ! -f "$ROOT/apps/api/.env" ]; then
  echo "==> Creating an empty apps/api/.env to quiet the dotenv warning"
  : > "$ROOT/apps/api/.env"
fi

# APP_KEY is the only variable phpunit.xml does not already set. Generated per
# session and never written to disk: nothing is encrypted across sessions, so a
# fresh throwaway key is both sufficient and safer than a committed one.
#
# Deliberately not `artisan key:generate`, which cannot run on a fresh checkout
# -- it boots the application, and booting is what throws while APP_KEY is
# unset.
if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
  echo "==> Exporting a throwaway APP_KEY for this session"
  echo "export APP_KEY=\"base64:$(head -c 32 /dev/urandom | base64)\"" >> "$CLAUDE_ENV_FILE"
else
  echo "No CLAUDE_ENV_FILE; export APP_KEY yourself before running the API tests."
fi

echo "==> Ready. php artisan test, ./vendor/bin/pint, npm run lint"
