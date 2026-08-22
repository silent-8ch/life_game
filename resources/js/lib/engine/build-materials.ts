import * as THREE from 'three';
import type { TextureLibrary } from '@/lib/engine/textures';
import type { Level } from '@/types';

/**
 * The materials a level is drawn with, made once and handed out by colour or by
 * texture name, so that a level of a hundred walls holds a handful of materials
 * rather than one apiece — and so that disposing the level disposes all of them.
 *
 * Surfaces with a texture are drawn with it; surfaces without one fall back to
 * the wireframe, so a half-built level still reads.
 */

/** How much of its line colour an untextured surface keeps. */
const SOLID_TINT = 0.11;

const POLYGON_OFFSET = 1;

export type SurfacePalette = {
    wallColor: THREE.Color;
    floorColor: THREE.Color;
    accentColor: THREE.Color;
    /** Hands back the geometry, and disposes it with the level. */
    track: (geometry: THREE.BufferGeometry) => THREE.BufferGeometry;
    /** A material made for one surface, disposed with the level. */
    keep: (material: THREE.Material) => void;
    /** The dark backing an untextured surface gets, so walls still occlude. */
    backing: (color: THREE.Color) => THREE.MeshBasicMaterial;
    lines: (color: THREE.Color) => THREE.LineBasicMaterial;
    surfaceMaterial: (name: string | null) => THREE.MeshBasicMaterial | null;
    dispose: () => void;
};

export function createSurfacePalette(
    level: Level,
    textures: TextureLibrary,
): SurfacePalette {
    const geometries: THREE.BufferGeometry[] = [];
    const materials: THREE.Material[] = [];

    const track = (geometry: THREE.BufferGeometry): THREE.BufferGeometry => {
        geometries.push(geometry);

        return geometry;
    };

    const keep = (material: THREE.Material): void => {
        materials.push(material);
    };

    const untexturedMaterials = new Map<string, THREE.MeshBasicMaterial>();
    const lineMaterials = new Map<string, THREE.LineBasicMaterial>();

    /** The dark backing an untextured surface gets, so walls still occlude. */
    const backing = (color: THREE.Color): THREE.MeshBasicMaterial => {
        const key = color.getHexString();
        const existing = untexturedMaterials.get(key);

        if (existing !== undefined) {
            return existing;
        }

        const material = new THREE.MeshBasicMaterial({
            color: color.clone().multiplyScalar(SOLID_TINT),
            side: THREE.DoubleSide,
            polygonOffset: true,
            polygonOffsetFactor: POLYGON_OFFSET,
            polygonOffsetUnits: POLYGON_OFFSET,
        });

        materials.push(material);
        untexturedMaterials.set(key, material);

        return material;
    };

    const lines = (color: THREE.Color): THREE.LineBasicMaterial => {
        const key = color.getHexString();
        const existing = lineMaterials.get(key);

        if (existing !== undefined) {
            return existing;
        }

        const material = new THREE.LineBasicMaterial({ color });
        materials.push(material);
        lineMaterials.set(key, material);

        return material;
    };

    const textured = new Map<string, THREE.MeshBasicMaterial>();

    const surfaceMaterial = (
        name: string | null,
    ): THREE.MeshBasicMaterial | null => {
        if (name === null) {
            return null;
        }

        const existing = textured.get(name);

        if (existing !== undefined) {
            return existing;
        }

        const map = textures.surface(name);

        if (map === null) {
            return null;
        }

        const material = new THREE.MeshBasicMaterial({
            map,
            side: THREE.DoubleSide,
        });

        materials.push(material);
        textured.set(name, material);

        return material;
    };

    const dispose = (): void => {
        for (const geometry of geometries) {
            geometry.dispose();
        }

        for (const material of materials) {
            material.dispose();
        }
    };

    return {
        wallColor: new THREE.Color(level.wallColor),
        floorColor: new THREE.Color(level.floorColor),
        accentColor: new THREE.Color(level.accentColor),
        track,
        keep,
        backing,
        lines,
        surfaceMaterial,
        dispose,
    };
}
