import { register } from 'node:module';

/** Passed to node as `--import`, to put the hooks below in place first. */
register('./typescript-hooks.mjs', import.meta.url);
