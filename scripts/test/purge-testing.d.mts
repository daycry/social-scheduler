export const DEFAULT_KEEP: number;
export const DEFAULT_MIN_AGE_DAYS: number;
export const RUN_PATTERNS: RegExp[];
export const DOCS_DIR: string;
export function resolveOptions(options?: { keep?: unknown; minAgeDays?: unknown }): { keep: number; minAgeDays: number; warnings: string[] };
export function purge(options: { root: string; keep?: unknown; minAgeDays?: unknown; dryRun?: boolean; now?: number; protectedPaths?: Array<string | undefined>; docsDir?: string }): { kept: string[]; removed: string[]; failed: Array<{ path: string; error: string }>; warnings: string[] };
