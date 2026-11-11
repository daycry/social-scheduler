import path from 'node:path';
import { deleteE2eUsers } from './helpers';
import { purge } from '../../scripts/test/purge-testing.mjs';

/** Remove only this run's four dedicated identities; retain this run's disposable artifacts for review. */
export default async function globalTeardown() {
    deleteE2eUsers();
    // Older runs pile up (7,6 GB once): keep the newest N or anything younger than X days, never the current run.
    // E2E_PURGE=0 disables it; E2E_PURGE_KEEP / E2E_PURGE_MIN_AGE_DAYS tune it. A failure never fails the suite.
    if (process.env.E2E_PURGE === '0') return;
    try {
        const result = purge({
            root: path.resolve('storage/framework/testing'),
            keep: Number(process.env.E2E_PURGE_KEEP ?? 5), minAgeDays: Number(process.env.E2E_PURGE_MIN_AGE_DAYS ?? 2),
            protectedPaths: [process.env.E2E_GENERAL_RUN_ROOT, process.env.T43_RUN_ROOT],
        });
        if (result.failed.length) console.warn(`purge-testing: ${result.failed.length} entradas no se pudieron borrar`);
    } catch (error) { console.warn(`purge-testing: ${(error as Error).message}`); }
}
