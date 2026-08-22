import * as THREE from 'three';
import type { LevelScene } from '@/lib/engine/build-scene';
import { tileUvs } from '@/lib/engine/textures';

/**
 * The things standing in a level, drawn as boxes: a wireframe crate where the
 * thing has no texture, a textured box where it has. Actors are left out —
 * those are drawn as sprites.
 */

const HIGHLIGHT_COLOR = '#ffffff';

/**
 * @return Paints one thing as picked out and the rest as they were, for the
 *         editor's hover.
 */
export function buildThings(scene: LevelScene): (slug: string | null) => void {
    const { palette } = scene;
    const thingLineMaterials = new Map<string, THREE.LineBasicMaterial>();
    const thingMaterials = new Map<string, THREE.MeshBasicMaterial>();

    for (const thing of scene.level.things) {
        if (thing.kind === 'actor') {
            continue;
        }

        const box = palette.track(
            new THREE.BoxGeometry(thing.width, thing.height, thing.depth),
        );

        const holder = new THREE.Group();
        holder.position.set(
            thing.x,
            thing.elevation + thing.height / 2,
            thing.z,
        );
        holder.rotation.y = -THREE.MathUtils.degToRad(thing.angle);

        const map = scene.textures.surface(thing.texture);

        if (map === null) {
            const lineMaterial = new THREE.LineBasicMaterial({
                color: palette.accentColor,
            });
            palette.keep(lineMaterial);
            thingLineMaterials.set(thing.slug, lineMaterial);

            const edges = palette.track(new THREE.EdgesGeometry(box));
            const mesh = new THREE.Mesh(
                box,
                palette.backing(palette.accentColor),
            );
            mesh.userData.thingSlug = thing.slug;

            holder.add(mesh, new THREE.LineSegments(edges, lineMaterial));
            scene.targets.push(mesh);
        } else {
            const material = new THREE.MeshBasicMaterial({ map });
            palette.keep(material);
            thingMaterials.set(thing.slug, material);

            tileUvs(box, Math.max(thing.width, thing.depth), thing.height);

            const mesh = new THREE.Mesh(box, material);
            mesh.userData.thingSlug = thing.slug;

            holder.add(mesh);
            scene.targets.push(mesh);
        }

        scene.group.add(holder);

        if (thing.isSolid) {
            scene.colliders.push({
                kind: 'box',
                x: thing.x,
                z: thing.z,
                halfWidth: thing.width / 2,
                halfDepth: thing.depth / 2,
                angle: THREE.MathUtils.degToRad(thing.angle),
            });
        }
    }

    return (slug: string | null): void => {
        for (const [candidate, material] of thingLineMaterials) {
            material.color.set(
                candidate === slug ? HIGHLIGHT_COLOR : palette.accentColor,
            );
        }

        for (const [candidate, material] of thingMaterials) {
            material.color.set(candidate === slug ? '#ffd9a0' : '#ffffff');
        }
    };
}
