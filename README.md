# tyto.chat — Core

Backend API for the tyto.chat platform. Built with Symfony 7.4 LTS and API Platform 4, it exposes a JSON-LD REST API consumed by the standalone React SPA ([client](https://github.com/tyto-chat/client)).

> This document will be most useful for developers working on the codebase. If you just want to install and run your own Tyto server, [tyto.chat](https://tyto.chat) has you covered — see below.

## Running Tyto in production

Production runs prebuilt images (`ghcr.io/tyto-chat/tyto-core`) with Docker
Compose — three env values, auto-generated secrets, auto-HTTPS. Follow the
[Quick Start](https://tyto.chat/quickstart); operational guides (reverse
proxy, backups, updates, troubleshooting) are in the
[documentation](https://tyto.chat/docs).

## Development setup

### Tech stack

- **PHP 8.5** / **Symfony 7.4 LTS**
- **API Platform 4** — JSON-LD REST API
- **MariaDB 11.8** via Doctrine ORM
- **JWT authentication** — `lexik/jwt-authentication-bundle` (+ refresh tokens)
- **Mercure** — real-time push (SSE) for live message delivery
- **Meilisearch** — per-channel / per-conversation message search
- **Redis (Valkey)** — presence tracking (online/away/DND)
- **LiveKit** — voice channels (WebRTC)
- **Mailer** — password-reset + notification emails

All of the above (MariaDB, Mercure, Meilisearch, Valkey, LiveKit) run as DDEV
sidecars — nothing to install locally.

### Requirements

- [DDEV](https://ddev.readthedocs.io/en/stable/users/install/ddev-installation/) v1.24+
- Docker

That's it — PHP, Composer and MariaDB are all provided by DDEV.

### Setup

```bash
# 1. Clone the repository into a directory named "core"
git clone https://github.com/tyto-chat/core.git core
cd core

# 2. Start DDEV (first run will pull Docker images)
ddev start

# 3. Install dependencies, run migrations, generate JWT + Mercure keys, create an admin user
ddev init
```

`ddev init` will prompt you for the admin user's email and password.

The API is now available at **https://core.ddev.site/api**.

> The project name is derived from the directory name. Clone into `core` to match the URLs above and to ensure the client's Mercure proxy connects to the right container.

### Full stack (core + client) — first run

The platform is two repos. Bring up the backend first, then the SPA:

```bash
# Backend
git clone https://github.com/tyto-chat/core.git core && cd core
ddev start && ddev init                 # deps, DB, keys, admin user, bot

# Frontend (sibling directory)
git clone https://github.com/tyto-chat/client.git client && cd ../client
ddev start && ddev setup                # deps, .env.local (points VITE at core), Playwright
```

App: **https://client.ddev.site** · API: **https://core.ddev.site/api**.

## Useful commands

| Command | Description |
|---|---|
| `ddev start` | Start the development environment |
| `ddev stop` | Stop containers |
| `ddev init` | First-time setup (safe to re-run — skips steps already done) |
| `ddev reset-db` | Drop the database, recreate it and replay the migrations |
| `ddev composer <cmd>` | Run Composer inside the container |
| `ddev php <cmd>` | Run PHP inside the container |
| `ddev mysql` | Open a MariaDB shell |
| `ddev logs` | Tail container logs |

## Running tests

Use the `ddev test` command, which handles test database setup automatically (creates the database, runs migrations, and generates JWT keys on the first run):

```bash
# Run all tests
ddev test

# Run only functional tests
ddev test tests/Functional/

# Run only unit tests
ddev test tests/Unit/

# Run a specific test file
ddev test tests/Functional/Api/MessageTest.php

# Run a specific test method
ddev test tests/Functional/Api/MessageTest.php --filter=testSendMessageAsMember
```

Any options after `ddev test` are passed directly to PHPUnit.

The test suite has three suites (`--testsuite <name>` to run one):
- **Unit** (`tests/Unit/`) — isolated tests for entities, services, validators and state processors
- **Functional** (`tests/Functional/`) — API integration tests that hit real endpoints against the MariaDB test database; each test runs inside a transaction that is rolled back afterwards (via the DAMA Doctrine test bundle) for isolation without re-seeding
- **Integration** (`tests/Integration/`) — tests that deliberately hit the **real Redis** sidecar (presence, voice participants, health probes); Unit + Functional stub Redis and Meilisearch and need only MariaDB

## Code quality

```bash
# Static analysis
ddev composer phpstan

# Check code style
ddev composer checkcs

# Fix code style
ddev composer fixcs
```

## Environment variables

The `.env` file documents all available variables. Local overrides go in `.env.local` (git-ignored). `ddev init` creates `.env.local` automatically with the correct DDEV database URL.

Notable variables:

| Variable | Description |
|---|---|
| `DATABASE_URL` | Doctrine connection string |
| `MAILER_DSN` | Transport for outgoing emails (default: `null://null` in dev) |
| `MERCURE_URL` | Internal Mercure hub URL (used by the Symfony publisher) |
| `MERCURE_PUBLIC_URL` | Public Mercure hub URL (sent to clients) |
| `MERCURE_JWT_SECRET` | Shared secret for signing Mercure JWTs |

The full production environment reference lives in
[`.env.prod.example`](.env.prod.example) and the
[advanced configuration docs](https://tyto.chat/docs/advanced-configuration).

## Contributing

See the [contribution guide](https://tyto.chat/docs/contributing) for the
fork-PR workflow and the [code guidelines](https://tyto.chat/docs/code-guidelines)
this codebase is written by. CI runs the full gate (tests, PHPStan, code
style) on every pull request.

## License

[MIT](LICENSE).
