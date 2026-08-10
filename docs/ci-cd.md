# Continuous integration and delivery

ChargeTrackr uses GitHub Actions because GitHub is the authoritative source repository. The
workflow is stored in `.github/workflows/ci.yml` and runs on pull requests, pushes to `main`, manual
dispatches and a weekly schedule.

## Continuous integration gates

| Job | Blocking checks |
|---|---|
| Backend | Composer metadata and audit, Pint, the complete SQLite suite, PostgreSQL migrations and critical compatibility tests, Redis connectivity |
| Frontend | pnpm audit, Oxlint, Node tests and the production Vite build |
| Infrastructure | Compose rendering, infrastructure policy tests, OCPP tooling tests and repository secret policy |
| OCPP gateway | Python tests in the gateway's pinned Docker test image |
| Docker images | Production-shaped Laravel runtime, Laravel web and frontend image builds |

The Docker image job starts only after every functional and policy job succeeds. The expensive SAP
simulator source build is excluded from each push because its upstream revision is already pinned;
its wrapper and configuration are covered by infrastructure tests. It remains validated during
release preparation and explicit simulator changes.

All referenced GitHub actions are pinned to immutable commit hashes. Workflow permissions are
read-only, test credentials are non-production placeholders, and no deployment credential is used
by CI.

## Local equivalent

Run the principal gates from the repository root before pushing:

```powershell
cd backend
composer validate --strict --no-check-publish
composer audit --locked --no-interaction
C:/php/php.exe vendor/bin/pint --test
C:/php/php.exe artisan test
cd ..

pnpm audit --audit-level high
pnpm lint:frontend
pnpm test:frontend
pnpm build:frontend
npm run test:infra-config
npm run test:ocpp-tools
npm run test:ocpp-gateway
```

The GitHub backend job is stricter than the default local test command. The complete suite remains
fast on isolated SQLite, while migrations, foreign-key indexes, availability queries and critical
billing tests also run against PostgreSQL 18. A Redis cache probe confirms the deployed driver
contract. This matrix catches database-specific regressions without repeating every feature test on
the slower database engine.

## Branch protection

After the first successful workflow run, protect `main` in the GitHub repository settings and
require these checks before merging:

- `Backend / PostgreSQL and Redis`;
- `Frontend / lint, tests and build`;
- `Infrastructure / configuration policy`;
- `OCPP gateway / Python tests`;
- `Docker / application images`.

Direct pushes can remain available during the internship, but pull requests with required checks
provide the clearest audit trail for final delivery.

## Delivery boundary

This workflow implements continuous integration only. Continuous delivery will be added after a
hosting target, domain, TLS termination strategy and secret store are selected. Production
deployment credentials must be stored as protected GitHub environment secrets and must never be
committed to the repository.
