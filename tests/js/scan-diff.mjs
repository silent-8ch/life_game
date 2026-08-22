/**
 * Diffs two sets of frame readbacks.
 *
 *     node tests/js/scan-diff.mjs before.json after.json
 *
 * Each file is one JSON object of captures, keyed by the label in
 * scan-spots.json. A capture is what `window.scanCapture` holds after loading
 * a level with `?scan` — see that file's `how`.
 *
 * Exits 0 when the two describe the same pictures and 1 when they do not,
 * printing what moved: which spot, which row, and which run of the row.
 *
 * The point of it is that a refactor of the renderer can be checked against
 * what it actually draws rather than against what it builds. Every other test
 * in this project asserts on structure; the rules that matter most describe
 * failures whose only symptom is on screen.
 */

import { readFileSync } from 'node:fs';
import process from 'node:process';

const [, , beforePath, afterPath] = process.argv;

if (beforePath === undefined || afterPath === undefined) {
    console.error('usage: node tests/js/scan-diff.mjs before.json after.json');
    process.exit(2);
}

const read = (path) => JSON.parse(readFileSync(path, 'utf8'));

const before = read(beforePath);
const after = read(afterPath);

/** One run written the way a person reads it. */
const say = (run) => `${run.from}-${run.to} ${run.wall ?? 'unpainted'}`;

const problems = [];

for (const label of Object.keys(before)) {
    const was = before[label];
    const now = after[label];

    if (now === undefined) {
        problems.push(`${label}: missing from ${afterPath}`);

        continue;
    }

    // A capture is a number of pixels across a particular canvas. Two taken at
    // different window sizes are not comparable and saying so is the only
    // honest answer — a diff of them would be all noise.
    if (was.width !== now.width || was.height !== now.height) {
        problems.push(
            `${label}: taken at ${was.width}x${was.height} and ${now.width}x${now.height}. Recapture both at one size.`,
        );

        continue;
    }

    if (was.spot !== now.spot || was.level !== now.level) {
        problems.push(
            `${label}: stood somewhere else — ${was.level} ${was.spot} then ${now.level} ${now.spot}`,
        );

        continue;
    }

    for (const [index, wasRow] of was.readings.entries()) {
        const nowRow = now.readings[index];

        if (nowRow === undefined || nowRow.row !== wasRow.row) {
            problems.push(`${label}: row ${wasRow.row} was not read again`);

            continue;
        }

        const width = Math.max(wasRow.runs.length, nowRow.runs.length);

        for (let at = 0; at < width; at++) {
            const wasRun = wasRow.runs[at];
            const nowRun = nowRow.runs[at];

            if (wasRun === undefined) {
                problems.push(
                    `${label} row ${wasRow.row}: gained ${say(nowRun)}`,
                );

                continue;
            }

            if (nowRun === undefined) {
                problems.push(
                    `${label} row ${wasRow.row}: lost ${say(wasRun)}`,
                );

                continue;
            }

            if (
                wasRun.from !== nowRun.from ||
                wasRun.to !== nowRun.to ||
                wasRun.wall !== nowRun.wall ||
                wasRun.css !== nowRun.css
            ) {
                problems.push(
                    `${label} row ${wasRow.row}: ${say(wasRun)} -> ${say(nowRun)}`,
                );
            }
        }
    }
}

for (const label of Object.keys(after)) {
    if (before[label] === undefined) {
        problems.push(`${label}: only in ${afterPath}`);
    }
}

const spots = Object.keys(before).length;

if (problems.length === 0) {
    console.log(`${spots} spots, every row the same picture.`);
    process.exit(0);
}

console.log(`${problems.length} differences across ${spots} spots:\n`);

for (const problem of problems) {
    console.log(`  ${problem}`);
}

process.exit(1);
