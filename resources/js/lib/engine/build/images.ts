import * as THREE from 'three';
import type { BuildContext } from '@/lib/engine/build/context';
import { reflectionIn } from '@/lib/engine/build/mirrors';

/**
 * The room again, hung behind each mirror where its reflection would be.
 *
 * ## What this is for
 *
 * A chain of reflections has to stop somewhere, and where it stops the mirror
 * comes out of the picture — a pane cannot show a level nobody drew without
 * showing a picture taken from the wrong viewpoint, which is the one thing this
 * renderer will not do. What is behind it then is the wall it hangs on, and
 * Paul's report after everything else was fixed is exactly that and only that:
 * *no black mirrors or stretching, only bare walls where mirrors should be.*
 *
 * Going deeper does not answer it. The number of levels can be pushed until the
 * openings close on their own — measured, twenty-three in his eight-metre room —
 * and there is still a wall at the end, a little smaller. Fading the far end out
 * would hide it and he has ruled that out twice, rightly: a mirror that loses
 * light is not the thing he asked for.
 *
 * So put the room there instead. **A mirror's image of a room is a real place**
 * — the method of images, which is the same fact the mirror camera is built
 * from — and a reflected copy of the room's own geometry, sitting where that
 * image is, is exactly what should be seen through the glass. It is not an
 * approximation and it is not a fade: it is the continuation, in world space,
 * correct from every camera at every depth, because the virtual cameras do all
 * the work and this is just geometry standing where geometry belongs.
 *
 * It costs no passes at all. That is the whole point — depth costs a render per
 * level per pane, and this costs one draw of some cloned meshes, only in the
 * passes where a mirror has actually come out.
 *
 * ## What it does not do
 *
 * The copy has no mirrors in it, so one level further back there is a wall
 * again. This buys one level, not infinity. What makes that worth having is
 * *where* the level is bought: at the very back of a chain, where the picture is
 * a few pixels and a room reading as "more room" is the whole difference from a
 * flat slab of plaster.
 *
 * It also steadies the flicker, which was the other half of the same report. A
 * chain that crosses the opening threshold as the player moves swaps between
 * "one more reflection" and "the end" from frame to frame; when the end is a
 * copy of the room those two look nearly the same, so the swap stops being
 * something the eye can catch.
 */
export function buildMirrorImages(ctx: BuildContext): void {
    const { scene } = ctx;

    // A sky lid paints nothing and writes depth, so a copy of one hangs a hole
    // in the middle of the image where everything behind it is cut away. The
    // room it belongs to already has its own; the copy has no business there.
    const lids = new Set(scene.skyLids.map((lid) => lid.mesh));

    for (const mirror of scene.mirrors) {
        const drawn = scene.drawnByRoom.get(mirror.home) ?? [];

        const image = new THREE.Group();

        // Cloned rather than rebuilt, and `clone` shares geometry and material
        // with the original — so a room's image costs a handful of objects and
        // no buffers at all. It also means retexturing the room retextures its
        // reflections, with nothing to keep in step.
        for (const what of drawn) {
            if (lids.has(what)) {
                continue;
            }

            image.add(what.clone());
        }

        if (image.children.length === 0) {
            continue;
        }

        // The same reflection the camera for this mirror is built from. If the
        // two ever disagree, the room beyond the glass is not the room the
        // glass shows — which is why both come from `reflectionIn`.
        //
        // `mesh.position` is the middle of the pane and `facing` its normal,
        // both as `buildMirrorPane` set them. Read here rather than passed
        // through because this runs after the whole level is built: a room's
        // image needs everything the room drew, and the last of that is not
        // there until the last edge is done.
        image.matrixAutoUpdate = false;
        image.matrix.copy(reflectionIn(mirror.mesh.position, mirror.facing));
        image.updateMatrixWorld(true);

        // Hidden until a pass says otherwise. Seen from inside the real room it
        // is behind the walls, so it would mostly be occluded — but "mostly" is
        // not a thing to leave in a renderer, and a room open to the sky has
        // sight-lines over its own walls.
        //
        // A reflection is left-handed, so every triangle in here is wound the
        // other way. Three compensates for that per object, from the sign of
        // the object's own `matrixWorld` determinant — which is the one place
        // it does look, and the reason this can be a plain group rather than a
        // rebuild with the winding reversed.
        image.visible = false;

        scene.group.add(image);
        mirror.image = [image];
    }
}
