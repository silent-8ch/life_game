export type SpriteDirection = {
    sheet: 'cardinal' | 'diagonal';
    row: number;
    /** Drawn the other way round, to stand in for a drawing that is wrong. */
    mirror: boolean;
};

/**
 * Which drawing to show for each 45-degree step around a body, starting with
 * the viewer stood in front of it and turning towards the body's right.
 *
 * There is a table per person because the sheets were not drawn to one order.
 * Paul's diagonals run backwards against Wade's; Krystal's cardinals run
 * backwards against Paul's. Guessing one order for all of them is what made
 * people turn the wrong way as you walked around them. Each of these was read
 * off the artwork by eye with public/sprite-directions.html.
 *
 * `c` is a row of the cardinal sheet, `d` a row of the diagonal one, listed for
 * 0°, 45°, 90°, 135°, 180°, 225°, 270° and 315°. A `~` in front means the
 * drawing is flipped left to right.
 *
 * Flipping is a last resort, not an economy. A flipped person wears their watch
 * on the wrong wrist and leads with the other foot, and where the walk meets an
 * unflipped view of the same body it visibly changes step. It is used only
 * where the sheet has no drawing for a direction — where two of its cells were
 * drawn facing the same way, leaving another way round the body unpainted.
 */
const ORDERS: Record<string, string> = {
    //          0°  45°  90° 135° 180° 225° 270° 315°
    paul: 'c0 d3 c1 d2 c2 d1 c3 d0',
    krystal: 'c0 d0 c3 d2 c2 d1 c1 d3',
    luna: 'c0 d0 c3 d2 c2 d1 c1 d3',

    // The stylised newcomers, whose sheets were built to the base person's row
    // order in assemble_style_sheet.py, so they read off the same table.
    'paul-toon': 'c0 d3 c1 d2 c2 d1 c3 d0',
    'krystal-toon': 'c0 d0 c3 d2 c2 d1 c1 d3',

    // The later toons are built to a straight order: cardinal rows 0/90/180/270,
    // diagonal rows 45/135/225/315.
    'william-toon': 'c0 d0 c1 d1 c2 d2 c3 d3',
    'luke-toon': 'c0 d0 c1 d1 c2 d2 c3 d3',
    'luna-toon': 'c0 d0 c1 d1 c2 d2 c3 d3',
    'wade-toon': 'c0 d0 c1 d1 c2 d2 c3 d3',
    boots: 'c0 d0 c1 d1 c2 d2 c3 d3',

    // Wade's and Luke's diagonal row 0 was drawn facing the same way as row 3,
    // so neither sheet has a 45°. It is flipped from their 315° instead.
    wade: 'c0 ~d3 c1 d1 c2 d2 c3 d3',
    luke: 'c0 ~d3 c1 d1 c2 d2 c3 d3',

    // William's rows 0 and 1 repeat his 315° and 225°, so both of the views
    // turned towards his right are flipped from the two turned to his left.
    william: 'c0 ~d3 c1 ~d2 c2 d2 c3 d3',
};

/**
 * What to use for a sheet nobody has checked yet. It will be wrong as often as
 * it is right, so a new person belongs in the table above rather than here.
 */
const UNCHECKED = ORDERS.paul;

function parse(order: string): SpriteDirection[] {
    return order.split(' ').map((step) => {
        const mirror = step.startsWith('~');
        const cell = mirror ? step.slice(1) : step;

        return {
            sheet: cell[0] === 'c' ? 'cardinal' : 'diagonal',
            row: Number(cell.slice(1)),
            mirror,
        };
    });
}

const DIRECTIONS: Record<string, SpriteDirection[]> = Object.fromEntries(
    Object.entries(ORDERS).map(([sprite, order]) => [sprite, parse(order)]),
);

const FALLBACK = parse(UNCHECKED);

export function spriteDirection(sprite: string, turn: number): SpriteDirection {
    const order = DIRECTIONS[sprite] ?? FALLBACK;
    const step = (Math.PI * 2) / order.length;
    const index = Math.round(turn / step + order.length) % order.length;

    return order[index];
}
