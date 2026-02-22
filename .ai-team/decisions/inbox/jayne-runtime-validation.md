# Decision: Runtime validation focus for Redis/update_database/Apache warnings

**Date:** 2026-02-22  
**By:** Jayne

## Context
Production logs reported:
1) `RedisEngine` typed property not initialized,  
2) `update_database` fallback warnings,  
3) Apache `More than one MPM loaded`.

## Decision
Treat these as runtime wiring/deployment validation issues first, with command-level verification gates:

1. **Redis path:** verify `CACHE_ENGINE=redis` is only enabled when runtime Redis connectivity and URL resolution are valid.
2. **Database bootstrap path:** require clean `bin/cake update_database` execution (or expected fallback behavior explicitly documented) during empty DB startup.
3. **Apache module path:** require exactly one enabled MPM module in the final runtime container.

No new test was added in this pass because current repository test patterns do not cover these shell/runtime deployment paths directly.

## Verification gates (must pass after fix)
```bash
# 1) Redis reachable and usable by Cake cache
cd /var/www/html && php -r 'require "config/bootstrap.php"; \Cake\Cache\Cache::write("jayne_probe","ok","default"); echo \Cake\Cache\Cache::read("jayne_probe","default"), PHP_EOL;'

# 2) update_database succeeds cleanly (or fallback behavior explicitly expected)
cd /var/www/html && bin/cake update_database

# 3) Only one Apache MPM module enabled
apachectl -M 2>/dev/null | grep -E "mpm_(event|worker|prefork)_module" | wc -l
```
