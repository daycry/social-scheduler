import path from 'node:path';

type Reply = { ok: boolean; status?: number; json(): Promise<any> };
type Fetch = (url: string) => Promise<Reply>;

const norm = (p: string) => path.resolve(p).split(path.sep).join('/').toLowerCase();

/**
 * Before any test runs, make sure the server answering on `baseURL` belongs to THIS run (its storage path is our runRoot)
 * and not to another concurrent E2E run that grabbed the same port.
 */
export async function verifyRunIdentity(baseURL: string, runRoot: string, doFetch: Fetch = url => fetch(url) as Promise<Reply>): Promise<void> {
    const response = await doFetch(`${baseURL}/__general-isolation-proof`);
    if (!response.ok) throw new Error(`El servidor E2E de ${baseURL} respondió ${response.status} al comprobar su identidad.`);
    const proof = await response.json();
    if (typeof proof?.storage !== 'string' || norm(proof.storage) !== norm(runRoot)) {
        throw new Error(`El servidor de ${baseURL} pertenece a otra ejecución (storage=${proof?.storage}); se esperaba ${runRoot}. Relanza la batería.`);
    }
}
