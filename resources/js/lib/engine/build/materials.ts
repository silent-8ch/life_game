import * as THREE from 'three';
import { POLYGON_OFFSET, SOLID_TINT } from '@/lib/engine/build/constants';
import type { TextureLibrary } from '@/lib/engine/textures';
import type { Level } from '@/types';

/**
 * Every material and geometry the build makes, made once and thrown away
 * together. Surfaces sharing a colour or a texture share a material, so a level
 * of a hundred rooms still holds a handful of them.
 */
export type MaterialLibrary = {
    wallColor: THREE.Color;
    floorColor: THREE.Color;
    accentColor: THREE.Color;
    /** Hands back the geometry it was given, having noted it for disposal. */
    track: (geometry: THREE.BufferGeometry) => THREE.BufferGeometry;
    /** The same, for a material made on the spot rather than from the caches. */
    keep: <T extends THREE.Material>(material: T) => T;
    /** The dark backing an untextured surface gets, so walls still occlude. */
    backing: (color: THREE.Color) => THREE.MeshBasicMaterial;
    lines: (color: THREE.Color) => THREE.LineBasicMaterial;
    /** A textured surface, or null where there is no texture to draw with. */
    surface: (name: string | null) => THREE.MeshBasicMaterial | null;
    dispose: () => void;
};

export function createMaterialLibrary(
    level: Level,
    textures: TextureLibrary,
): MaterialLibrary {
    const geometries: THREE.BufferGeometry[] = [];
    const materials: THREE.Material[] = [];

    const track = (geometry: THREE.BufferGeometry): THREE.BufferGeometry => {
        geometries.push(geometry);

        return geometry;
    };

    const keep = <T extends THREE.Material>(material: T): T => {
        materials.push(material);

        return material;
    };

    const wallColor = new THREE.Color(level.wallColor);
    const floorColor = new THREE.Color(level.floorColor);
    const accentColor = new THREE.Color(level.accentColor);

    const untexturedMaterials = new Map<string, THREE.MeshBasicMaterial>();
    const lineMaterials = new Map<string, THREE.LineBasicMaterial>();

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

    const surface = (name: string | null): THREE.MeshBasicMaterial | null => {
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
        wallColor,
        floorColor,
        accentColor,
        track,
        keep,
        backing,
        lines,
        surface,
        dispose,
    };
}
