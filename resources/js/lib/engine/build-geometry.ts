import * as THREE from 'three';
import type { Sector } from '@/types';

/**
 * The geometry every part of a level build needs, and the two distances that
 * decide where a surface actually sits: how far a wall is nudged into its own
 * room, and where the lids that hide the sky come in the draw order.
 */

/** How far a wall is nudged into its own sector, in metres. */
export const WALL_INSET = 0.01;

/**
 * Where the lids on open-to-sky rooms come in the draw order: after the sky,
 * which is at -1 and lays down no depth of its own, and before the rooms, which
 * are at 0 and are what the lids are there to hide.
 */
export const SKY_CEILING_ORDER = -0.5;

/** A grid of lines filling a rectangle centred on the origin of the XY plane. */
export function gridGeometry(
    width: number,
    height: number,
    spacing: number,
): THREE.BufferGeometry {
    const points: number[] = [];

    const positionsAlong = (extent: number): number[] => {
        const half = extent / 2;
        const values = [-half];

        for (let at = -half + spacing; at < half - 1e-4; at += spacing) {
            values.push(at);
        }

        values.push(half);

        return values;
    };

    for (const x of positionsAlong(width)) {
        points.push(x, -height / 2, 0, x, height / 2, 0);
    }

    for (const y of positionsAlong(height)) {
        points.push(-width / 2, y, 0, width / 2, y, 0);
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute(
        'position',
        new THREE.Float32BufferAttribute(points, 3),
    );

    return geometry;
}

/** The polygon of a sector, as a shape the triangulator can fill. */
export function shapeOf(sector: Sector): THREE.Shape {
    const shape = new THREE.Shape();

    sector.points.forEach((point, index) => {
        if (index === 0) {
            shape.moveTo(point.x, -point.z);
        } else {
            shape.lineTo(point.x, -point.z);
        }
    });

    shape.closePath();

    return shape;
}
