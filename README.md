# Seismo 0.5

Consolidated codebase, being built gradually from [seismo_0.4](../seismo_0.4). **0.5 is mid-consolidation** — this README will become the final architecture doc at the end of the process. For now, it's deliberately short.

## Status

- **Reference (running):** `seismo_0.4` at `https://www.hektopascal.org/seismo-staging/`
- **Build target (live):** `seismo_0.5` at `https://www.hektopascal.org/seismo/`
- Both share the same MariaDB. Read-only slices of 0.5 run safely against live data while 0.4 remains the authoritative writer until the consolidation is complete.

## Where to look

- **[`README-REORG.md`](README-REORG.md)** — the migration log. One section per slice: what moved from 0.4 → 0.5, why, and how the new wiring works. Written live as each slice lands. **This is the doc to read during the consolidation.**
- **[`docs/consolidation-plan.md`](docs/consolidation-plan.md)** — the architectural plan and slice order. The "why" and "what next".
- **[`docs/setup-wizard-notes.md`](docs/setup-wizard-notes.md)** — running list of portability and install concerns for a future first-run wizard.
- **[`.cursor/rules/`](.cursor/rules/)** — the working agreements for this consolidation (architecture guardrails, webspace testing, documentation strategy, deployment portability).

## Relationship to 0.4

- 0.4 remains the working reference until features are migrated and verified here.
- Moves happen in small, testable slices with clear commits. Every slice ends with a webspace smoke test and a `README-REORG.md` entry.
- No slice ships without its reorg entry.

## When this README becomes useful

At the end of consolidation, this file gets a single polish pass and becomes the real 0.5 README (install, directory map, extension points, production setup). Until then, use `README-REORG.md`.
