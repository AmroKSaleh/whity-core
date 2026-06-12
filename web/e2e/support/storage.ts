import path from 'node:path';

/**
 * Filesystem locations for the persisted per-role auth storage states written
 * by the `auth.setup.ts` setup project. Kept under .auth/ (gitignored).
 */
const authDir = path.join(__dirname, '..', '.auth');

export const adminStatePath = path.join(authDir, 'admin.json');
export const userStatePath = path.join(authDir, 'user.json');
export const delegateStatePath = path.join(authDir, 'delegate.json');
