import { dirname, resolve as join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

/**
 * Node resolves ES module imports the way a browser does: `./sectors` is a file
 * called exactly that, and there is no such file. The engine is written for a
 * bundler, which fills the extension in and knows what `@/` means, so a test
 * running a piece of it under plain node has to do both itself.
 *
 * Only relative imports that node itself could not find are retried with an
 * extension, so a real missing file still reports as missing rather than as a
 * missing `.ts`.
 */
const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

export async function resolve(specifier, context, next) {
    if (specifier.startsWith('@/')) {
        const url = pathToFileURL(
            join(root, 'resources', 'js', specifier.slice(2)),
        ).href;

        return resolve(url, context, next);
    }

    try {
        return await next(specifier, context);
    } catch (error) {
        if (!specifier.startsWith('.') && !specifier.startsWith('file:')) {
            throw error;
        }

        return next(`${specifier}.ts`, context);
    }
}
