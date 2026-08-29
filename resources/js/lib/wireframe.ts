import { SOLID_TINT } from '@/lib/engine/build/constants';

/**
 * How dark an untextured surface really is.
 *
 * The engine paints one twice: a solid fill of the colour knocked down to
 * `SOLID_TINT` of its brightness, and the grid lines over the top at full
 * strength. Both editors preview that, and a preview is only worth having if it
 * agrees with the engine — so the arithmetic lives here on its own, out of the
 * React component, where the node harness can reach it and
 * `ConstantsMatchTest` can hold it against the PHP copy in
 * `App\Services\WireframePreview`.
 */

/**
 * A colour knocked down the way the engine knocks it down.
 *
 * **Not** simply `hex * SOLID_TINT`. Three converts a hex colour to linear
 * light on the way in, multiplies there, and encodes back to sRGB on the way
 * out, so `#7fe0c9` lands on `#2a5148` — a good deal lighter than the
 * `#0d1714` a naive multiply gives. Getting this wrong makes the preview far
 * darker than the game, which is the one thing a preview must not be.
 */
export function dimmed(color: string): string {
    let hex = color.replace('#', '');

    if (hex.length === 3) {
        hex = hex
            .split('')
            .map((digit) => digit + digit)
            .join('');
    }

    if (!/^[0-9a-fA-F]{6}$/.test(hex)) {
        return '#000000';
    }

    const channels = [0, 2, 4].map((at) => {
        const channel = parseInt(hex.slice(at, at + 2), 16) / 255;

        const linear =
            channel <= 0.04045
                ? channel / 12.92
                : ((channel + 0.055) / 1.055) ** 2.4;

        const dim = linear * SOLID_TINT;

        const encoded =
            dim <= 0.0031308 ? dim * 12.92 : 1.055 * dim ** (1 / 2.4) - 0.055;

        return Math.round(Math.min(1, Math.max(0, encoded)) * 255)
            .toString(16)
            .padStart(2, '0');
    });

    return `#${channels.join('')}`;
}
