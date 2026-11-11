export const DEFAULT_KEEP: number;
export const DEFAULT_MIN_AGE_DAYS: number;
export function purge(options: { root: string; keep?: number; minAgeDays?: number; dryRun?: boolean; now?: number; protectedPaths?: Array<string | undefined> }): { kept: string[]; removed: string[]; failed: Array<{ path: string; error: string }> };
