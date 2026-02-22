# Runtime startup safeguards for Railway + Docker

- **Date:** 2026-02-22
- **By:** Kaylee
- **Decision:** Keep Redis enabled for runtime, but force migration/setup CLI commands to run with `CACHE_ENGINE=apcu`; enforce a single Apache MPM (`prefork`) in the production image; and prefer explicit MySQL env vars (including port) during startup DB checks.
- **Why:** This prevents non-critical Redis bootstrap failures from breaking migrations, avoids Apache fatal startup on dual MPM load, and reduces Railway DB wiring fragility when DSN parsing is unreliable.
