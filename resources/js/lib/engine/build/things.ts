import * as THREE from 'three';
import { HIGHLIGHT_COLOR } from '@/lib/engine/build/constants';
import type { BuildContext } from '@/lib/engine/build/context';
import { tileUvs } from '@/lib/engine/textures';

/**
 * The boxes: everything on the plan that is not a room. Actors are left out,
 * since they are drawn as sprites rather than as geometry.
 *
 * @return  Paints one thing as looked-at and everything else as it was.
 */
export function buildThings(ctx: BuildContext): (slug: string | null) => void {
    const { level, scene, materials, textures } = ctx;

    const thingLineMaterials = new Map<string, THREE.LineBasicMaterial>();
    const thingMaterials = new Map<string, THREE.MeshBasicMaterial>();

    for (const thing of level.things) {
        if (thing.kind === 'actor') {
            continue;
        }

        const box = materials.track(
            new THREE.BoxGeometry(thing.width, thing.height, thing.depth),
        );

        const holder = new THREE.Group();
        holder.position.set(
            thing.x,
            thing.elevation + thing.height / 2,
            thing.z,
        );
        holder.rotation.y = -THREE.MathUtils.degToRad(thing.angle);

        const map = textures.surface(thing.texture);

        if (map === null) {
            const lineMaterial = materials.keep(
                new THREE.LineBasicMaterial({ color: materials.accentColor }),
            );
            thingLineMaterials.set(thing.slug, lineMaterial);

            const edges = materials.track(new THREE.EdgesGeometry(box));
            const mesh = new THREE.Mesh(
                box,
                materials.backing(materials.accentColor),
            );
            mesh.userData.thingSlug = thing.slug;

            holder.add(mesh, new THREE.LineSegments(edges, lineMaterial));
            scene.targets.push(mesh);
        } else {
            const material = materials.keep(
                new THREE.MeshBasicMaterial({ map }),
            );
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
                candidate === slug ? HIGHLIGHT_COLOR : materials.accentColor,
            );
        }

        for (const [candidate, material] of thingMaterials) {
            material.color.set(candidate === slug ? '#ffd9a0' : '#ffffff');
        }
    };
}
