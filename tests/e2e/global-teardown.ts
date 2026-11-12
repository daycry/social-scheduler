import path from 'node:path';
import { deleteE2eUsers } from './helpers';
import { slots } from './isolated/runtime';
import { purge } from '../../scripts/test/purge-testing.mjs';

/** Remove only this run's four dedicated identities in each worker slot; retain this run's disposable artifacts for review. */
export default async function globalTeardown() {
    // Independent blocks: a failed user cleanup must not skip the purge (and the other way round).
    for (const slot of slots) {
        try { deleteE2eUsers(slot); } catch (error) { console.warn(`teardown: no se pudieron borrar los usuarios E2E del worker ${slot.index}: ${(error as Error).message}`); }
    }
    // Older runs pile up (7,6 GB once): the purge only touches this suite's run-* directories, keeps the newest N or
    // anything younger than X days, never the current run nor anything cited in docs/. E2E_PURGE=0 disables it.
    // E2E_PURGE_KEEP / E2E_PURGE_MIN_AGE_DAYS are validated by the purge module (invalid or empty -> default + warning).
    if (process.env.E2E_PURGE === '0') return;
    try {
        const result = purge({
            root: path.resolve('storage/framework/testing'),
            keep: process.env.E2E_PURGE_KEEP, minAgeDays: process.env.E2E_PURGE_MIN_AGE_DAYS,
            protectedPaths: [...slots.map(slot => slot.runRoot), process.env.E2E_GENERAL_RUN_ROOT, process.env.T43_RUN_ROOT],
        });
        for (const warning of result.warnings) console.warn(warning);
        if (result.failed.length) console.warn(`purge-testing: ${result.failed.length} entradas no se pudieron borrar`);
    } catch (error) { console.warn(`purge-testing: ${(error as Error).message}`); }
}
