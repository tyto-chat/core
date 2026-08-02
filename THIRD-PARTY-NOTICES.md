# Third-Party Notices — tyto.chat core

This product (MIT-licensed, © 2026 Mateusz Bieniek) bundles or depends on
third-party software. Their licenses are listed below. Full per-package license
texts ship inside each package under `vendor/` after `composer install`.

## PHP dependencies (Composer)

All production Composer dependencies are permissive and compatible with MIT.
The dependency tree is **MIT** except the following, attributed here as their
licenses require:

| Package | License | Copyright |
|---|---|---|
| `firebase/php-jwt` | BSD-3-Clause | © 2011 Neuman Vong |
| `google/protobuf` | BSD-3-Clause | © 2019 Protocol Buffers |
| `lcobucci/jwt` | BSD-3-Clause | © Luís Cobucci |
| `twig/twig` | BSD-3-Clause | © Fabien Potencier, Armin Ronacher, the Twig Team |

BSD-3-Clause requires that the above copyright notices and the license text
(shipped in each package's `vendor/` directory) be retained in redistributions.

A current machine-readable inventory can be regenerated with:

```
composer licenses --no-dev
```

## Bundled runtime services (Docker)

The deployment stack runs these as **separate containers**. They communicate
with the application over network/process boundaries (not linked into the
MIT-licensed code), so they do not affect the application's MIT license. When
redistributed (e.g. in published images), each is governed by its own license:

| Service | Image | License |
|---|---|---|
| FrankenPHP (app runtime) | `dunglas/frankenphp` | MIT (embeds Caddy — Apache-2.0; PHP — PHP License 3.01) |
| Valkey (cache/presence) | `valkey/valkey` | BSD-3-Clause |
| Meilisearch (search) | `getmeili/meilisearch` | MIT |
| LiveKit (voice) | `livekit/livekit-server` | Apache-2.0 |
| MariaDB (database) | `mariadb` | GPL-2.0 (server; used as a separate service via the wire protocol) |
| Mercure (realtime hub) | `dunglas/mercure` | AGPL-3.0 — the same Caddy module bundled by the MIT-licensed FrankenPHP runtime above (a commercial high-availability edition is offered separately by Mercure.rocks) |

Notes for redistributors and enterprise users:

- **MariaDB** is GPL-2.0 but used as a standalone database server reached over
  its network protocol. This is aggregation, not linking; it does not impose
  GPL terms on the application. (PostgreSQL — permissive — is a drop-in
  alternative if a GPL-free database is preferred.)
- **Mercure hub** is AGPL-3.0 and is used as a standalone realtime hub — the
  same Caddy module the MIT-licensed FrankenPHP runtime ships embedded. Running
  the unmodified hub (as a sidecar or via the embedded module) is aggregation
  and does not affect the application's MIT license. The AGPL-3.0 §13
  source-availability obligation only applies to a party that *modifies* the
  hub and offers the modified hub over a network; it does not reach the
  application. The `symfony/mercure` PHP client used by the application is MIT.
