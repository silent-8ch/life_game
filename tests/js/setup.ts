import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

/**
 * What every component test gets before it runs.
 *
 * `jest-dom/vitest` adds the matchers that make an assertion about the DOM
 * readable — `toBeInTheDocument`, `toHaveValue` — rather than a comparison
 * against a node's innards.
 *
 * The cleanup matters more than it looks. Testing Library renders into a real
 * document that persists between tests in the same file, so without it the
 * second test in a file queries a page holding two copies of the component and
 * `getBy` throws about finding multiple matches — which reads as a broken test
 * rather than a missing teardown.
 */
afterEach(() => {
    cleanup();
});
