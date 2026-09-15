# Contributing

FotoArchief is an early foundation, not a supported public release.
Public contribution terms and the source-code licence are not yet selected.
Do not assume an open-source grant or submit third-party code without first
agreeing ownership and licence terms with the repository owner.

For authorised development:

1. Read [AGENTS.md](AGENTS.md) and [the architecture](docs/ARCHITECTURE.md).
2. Work on a scoped branch; keep both deployment formats on the same codebase.
3. Add regression tests for changed behavior and run the checks documented
   in [README.md](README.md).
4. Bump [VERSION](VERSION) for runtime/build/deployment/CI changes.
5. Describe validation actually performed and any unavailable runtime checks.
6. Include exact operator changes for environment/Compose changes.

Never include credentials, database exports, customer media or personal data
in a change, screenshot, issue or test fixture. See
[the repository policy](docs/REPOSITORY.md) for publication gates.
