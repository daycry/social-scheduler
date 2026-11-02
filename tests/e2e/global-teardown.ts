import { deleteE2eUsers } from './helpers';

/** Remove only this run's four dedicated identities; retain disposable artifacts for review. */
export default async function globalTeardown() {
    deleteE2eUsers();
}
