import * as THREE from 'three';
import { PANE_TEXELS_ACROSS, PANE_TEXELS_DOWN } from '@/lib/engine/constants';

/**
 * The pane that fills a portal mouth. It is drawn with the view from the far
 * mouth, rendered by a camera moved through the same transform the player is
 * moved through when they walk in — so what is on the pane lines up exactly
 * with what they arrive in, and stepping through is continuous.
 *
 * The far mouth's own pane is hidden while this one renders, because the camera
 * stands behind it; and the camera's near plane is tilted onto the far mouth's
 * plane, so whatever lies between the two is clipped away rather than drawn
 * across the view.
 */

/**
 * The pane is sampled by where its fragment lands on the screen, not by where
 * the far camera would have projected it. Both cameras share one projection and
 * the portal maps one frustum exactly onto the other, so a ray leaving the eye
 * through a point on the screen arrives, on the far side, through that same
 * point — which is the whole reason stepping through is continuous.
 *
 * (A mirror can get away with the far camera's projection, which is what three's
 * Reflector uses, because reflecting leaves the mirror's own plane where it was.
 * A portal moves its pane somewhere else entirely, so that would sample the far
 * view at a place the pane is not.)
 */
/**
 * Where a fragment reads the far view from. A portal pane reads by its own
 * position on the screen: both cameras share one projection and the portal maps
 * one frustum onto the other, so a ray leaving the eye through a point on the
 * screen arrives, on the far side, through that same point — which is the whole
 * reason stepping through is continuous.
 *
 * A mirror reads through the far camera's own projection instead. Reflecting
 * leaves the mirror's plane exactly where it was, so the points being shaded are
 * fixed by the transform and project the same either way; and the camera that
 * draws a reflection is built by looking from the reflected eye rather than by a
 * reflection matrix, because a matrix with a flip in it turns every surface in
 * the scene inside out.
 */
/**
 * How far in from its own edge a pane reads, in texels of the target it reads
 * from. Held in texels rather than as a fraction of the pane, so that a portal
 * across the room is pulled in by as much as one right in front of you — a
 * fraction of a small pane is a fraction of a pixel, and no use at all.
 */
const EDGE_BIAS_TEXELS = 1.5;

const VERTEX_SHADER = `
    #include <common>
    #include <logdepthbuf_pars_vertex>

    uniform mat4 textureMatrix;
    uniform vec2 paneTexels;
    uniform float edgeBias;
    uniform float shrink;
    varying vec4 vPane;

    void main() {
        vec4 clip = projectionMatrix * modelViewMatrix * vec4(position, 1.0);

        #ifdef READ_BY_FAR_CAMERA
            vPane = textureMatrix * vec4(position, 1.0);
        #else
            // Read a hair towards the middle of the pane rather than at its
            // very edge. The pane and the mouth it fills line up to within a
            // pixel, and on the far side of that pixel is the sky the tilted
            // near plane left behind, which shows as a bright hairline round
            // the portal — there whenever the edge falls the wrong side of a
            // pixel, gone again a step later, which is what makes it flicker
            // as the player walks.
            vec4 middle = projectionMatrix * modelViewMatrix * vec4(0.0, 0.0, 0.0, 1.0);
            vec2 at = clip.xy / clip.w;

            if (middle.w > 0.0) {
                vec2 centre = middle.xy / middle.w;
                float reach = length((at - centre) * paneTexels);

                // Pulled in by a set number of texels, whatever the pane's size
                // on screen; capped so a pane seen edge on cannot fold up.
                at = mix(at, centre, clamp(edgeBias / max(reach, 0.0001), 0.0, 0.25));

                // Reading wider than the pane is means the picture arrives
                // smaller, which is what makes it read as further off.
                at = centre + (at - centre) * shrink;
            }

            vPane = vec4((at * 0.5 + 0.5) * clip.w, clip.z, clip.w);
        #endif

        gl_Position = clip;

        #include <logdepthbuf_vertex>
    }
`;

const FRAGMENT_SHADER = `
    #include <common>
    #include <logdepthbuf_pars_fragment>

    uniform sampler2D pane;
    uniform vec3 tint;
    varying vec4 vPane;

    void main() {
        #include <logdepthbuf_fragment>

        gl_FragColor = vec4(texture2DProj(pane, vPane).rgb * tint, 1.0);

        #include <tonemapping_fragment>
        #include <colorspace_fragment>
    }
`;

/** Pulls the tilted near plane a hair off the mouth, against seams at the edge. */
const CLIP_BIAS = 0.005;

/**
 * How close the camera may come to the plane it is clipped against before the
 * tilt is dropped, in metres.
 *
 * There has to be some threshold. The oblique construction scales the clip
 * plane by the reciprocal of the camera's distance to it, so as that distance
 * goes to zero the whole depth range collapses and the far room falls out of
 * the picture. But every millimetre of the threshold is a millimetre where the
 * pane draws whatever stands between its camera and the mouth — the wall beside
 * the opening, the room over the way — and the pane is hugged across the whole
 * screen at exactly that range, so it is all anyone can see.
 *
 * 0.15 was a guess, and it was wide enough to walk through and notice: the
 * snapshots of the fault were all taken between 1 and 2 cm out. The right value
 * is much smaller than the usual advice, because that advice assumes a normal
 * depth buffer and this renderer uses a logarithmic one, which does not lose
 * precision the same way when the near plane tilts hard. Measured on screen at
 * the recorded fault spots: 0.002 renders the far room correctly right up
 * against the mouth. Lower it further only with the same check, on a machine
 * with a different GPU as well.
 *
 * Do not close it entirely by pushing the plane forward instead of dropping the
 * tilt: that was tried, and it wedges the card hard enough that the page stops
 * painting while its scripts carry on.
 */
const CLIP_MINIMUM = 0.002;

/**
 * Tilts a camera's near plane onto a plane in front of it, so everything behind
 * that plane is clipped rather than drawn. Used to stop whatever stands between
 * the portal camera and the far mouth from being drawn across the view.
 *
 * The plane arrives in world space and is taken into the camera's own space
 * here. Lengyel's method, the same one three's Reflector uses:
 * http://www.terathon.com/lengyel/Lengyel-Oblique.pdf
 */
export function tiltNearPlaneOnto(
    projection: THREE.Matrix4,
    worldPlane: THREE.Plane,
    cameraWorldInverse: THREE.Matrix4,
    scratch: { plane: THREE.Plane; clip: THREE.Vector4; corner: THREE.Vector4 },
): void {
    const { plane, clip, corner } = scratch;

    plane.copy(worldPlane).applyMatrix4(cameraWorldInverse);
    clip.set(plane.normal.x, plane.normal.y, plane.normal.z, plane.constant);

    corner.x =
        (Math.sign(clip.x) + projection.elements[8]) / projection.elements[0];
    corner.y =
        (Math.sign(clip.y) + projection.elements[9]) / projection.elements[5];
    corner.z = -1;
    corner.w = (1 + projection.elements[10]) / projection.elements[14];

    clip.multiplyScalar(2 / clip.dot(corner));

    projection.elements[2] = clip.x;
    projection.elements[6] = clip.y;
    projection.elements[10] = clip.z + 1 - CLIP_BIAS;
    projection.elements[14] = clip.w;
}

export type PortalSurface = {
    mesh: THREE.Mesh;
    /** The pane at the far end of the same portal, hidden while this one draws. */
    partner: THREE.Mesh | null;
    /**
     * What the room behind the far mouth drew on this camera's side of it,
     * hidden while this pane draws.
     *
     * The camera stands in that room, so the whole of it is between the camera
     * and the opening: the wall across the mouth, the walls meeting it at the
     * corners, the floor and the ceiling. The tilted near plane is meant to cut
     * all of it away, but anything touching the mouth's plane is inside the
     * slack CLIP_BIAS leaves and survives — as the back of a wall filling the
     * portal, or as a sliver down the edge of the opening. Taking the room out
     * of the pass is the certain way.
     */
    behind: THREE.Object3D[];
    /** Where in the world the pane is, for judging whether it is worth drawing. */
    bounds: THREE.Sphere;
    /** Takes the pane's present position as the one it belongs in. */
    settle: () => void;
    /**
     * Puts the pane back where it belongs. Has to happen before the panes are
     * drawn: one held in front of the player's face is a wall-sized sheet in
     * the middle of the room as far as every other pane's camera is concerned.
     */
    release: () => void;
    /**
     * Squares the pane up to the screen when the eye comes too close for the
     * near plane to keep it, and puts it back once the eye is clear. Without
     * this, the last few centimetres of walking into a portal show whatever
     * lies past the opening, which is nothing, so the sky.
     *
     * Covering the screen is not a cheat at that range: a mouth two metres
     * across fills the whole view from closer than about seventy centimetres,
     * so there is nothing of the near room left to see around it. And nothing
     * is lost by moving the pane, because it reads the far view by where its
     * fragments land on the screen — the picture stays put while the surface
     * carrying it comes forward.
     */
    hug: (camera: THREE.PerspectiveCamera, clearance: number) => void;
    /** The room this pane stands in. */
    home: string;
    /**
     * The rooms whose panes can turn up in this one's view: the room it looks
     * into, and whatever can be seen from there through an open doorway.
     */
    onto: string[];
    /**
     * Sets up the camera that draws the far side, without drawing anything, and
     * hands it back so the caller can see what that camera would see.
     */
    aim: (camera: THREE.PerspectiveCamera) => THREE.PerspectiveCamera;
    /** Draws the view from the far mouth into this pane's target for a depth. */
    render: (
        renderer: THREE.WebGLRenderer,
        scene: THREE.Scene,
        camera: THREE.PerspectiveCamera,
        depth: number,
    ) => void;
    /**
     * Which depth's view the pane shows. Zero is what the player sees; deeper
     * ones are what the pane shows when it is itself being looked at through a
     * pane, which is what turns a portal hung to face itself into a corridor.
     *
     * `shrink` pulls the view in from the edges, so the same picture reads as
     * one further away. It is what stands in for the level that was never
     * drawn, at the end of the tunnel.
     */
    show: (depth: number, shrink?: number) => void;
    /** Where the player's eye lands once carried through, for facing sprites. */
    viewerAt: (camera: THREE.PerspectiveCamera) => {
        x: number;
        z: number;
        yaw: number;
    };
    /**
     * The picture this pane is holding at a depth, or null if nothing has ever
     * been drawn into it.
     *
     * For debugging only, and it exists because there was no way to see it. A
     * hugged pane is blown up to cover the whole screen, so what reaches the
     * canvas is the far camera's entire frustum rather than the mouth's
     * silhouette — which means reading the canvas cannot tell you what the pane
     * itself holds. Two sessions spent an evening arguing about the contents of
     * a buffer neither could look at.
     */
    peek: (depth: number) => THREE.WebGLRenderTarget | null;
    dispose: () => void;
};

export type PortalSurfaceOptions = {
    geometry: THREE.BufferGeometry;
    /**
     * Places the camera that draws this surface, given where it is being looked
     * at from. A portal carries it through to the far mouth; a mirror reflects
     * it. Everything else about the two is the same.
     */
    aim: (from: THREE.PerspectiveCamera, out: THREE.PerspectiveCamera) => void;
    /** Where the viewer stands as far as the far side is concerned. */
    viewerAt: (from: THREE.PerspectiveCamera) => {
        x: number;
        z: number;
        yaw: number;
    };
    /** True for a mirror: read the far view through the far camera, not the screen. */
    readByFarCamera: boolean;
    /** Multiplied into what the surface shows; a mirror gives back less light. */
    tint: THREE.Color;
    /**
     * The plane the camera's near plane is tilted onto, so nothing between it
     * and the surface is drawn. A portal's is its far mouth; a mirror's is
     * itself. The normal points into the room being looked at.
     */
    exitPoint: THREE.Vector3;
    exitNormal: THREE.Vector3;
    /**
     * This pane's own plane, and which way it faces into the room it is seen
     * from. A pane that can be walked into needs it; one that cannot may leave
     * it out and will never move.
     */
    facePoint?: THREE.Vector3;
    faceNormal?: THREE.Vector3;
    textureWidth: number;
    textureHeight: number;
    /** How many times this pane may appear inside another pane's view. */
    bounces: number;
    /** The room this pane stands in, and the ones it can see into. */
    home: string;
    onto: string[];
};

export function createPortalSurface(
    options: PortalSurfaceOptions,
): PortalSurface {
    // One target for what the player sees, and one more for each bounce past
    // it. Made when a depth is first asked for: most panes in a level are never
    // seen through another, and a target apiece for depth they never reach is
    // several megabytes each of nothing.
    const depths = Math.max(1, options.bounces + 1);
    const targets: (THREE.WebGLRenderTarget | null)[] = new Array(depths).fill(
        null,
    );

    /**
     * How big the panes are drawn at, which is not a constant.
     *
     * A mirror hangs on a wall and is looked at from across the room, so a
     * coarse buffer is fine and is half the point of how they look. A portal is
     * walked up to: within CLIP_MINIMUM of the mouth the pane is hugged across
     * the entire screen, and then the buffer *is* the picture. Stretched from
     * 512 wide to a retina display that is a six-fold magnification, and every
     * edge in the far room turns into a band several pixels across that crawls
     * as the player moves — which is what has been reported as flashing along
     * room boundaries near a portal.
     *
     * So the size asked for is a floor, not a fixed size: panes grow to match
     * the surface they are drawn on and never shrink below what was asked.
     * Capped, because this is a target per pane per depth and they are several
     * megabytes each.
     */
    const wanted = new THREE.Vector2(
        options.textureWidth,
        options.textureHeight,
    );

    const drawnAt = new THREE.Vector2();

    const fitTo = (renderer: THREE.WebGLRenderer): void => {
        renderer.getDrawingBufferSize(drawnAt);

        const width = Math.min(
            PANE_TEXELS_ACROSS,
            Math.max(options.textureWidth, Math.round(drawnAt.x)),
        );
        const height = Math.min(
            PANE_TEXELS_DOWN,
            Math.max(options.textureHeight, Math.round(drawnAt.y)),
        );

        if (width === wanted.x && height === wanted.y) {
            return;
        }

        wanted.set(width, height);

        for (const target of targets) {
            target?.setSize(width, height);
        }

        material.uniforms.paneTexels.value.set(width / 2, height / 2);
    };

    /** Which depth a level of nesting actually reads, once clamped. */
    const indexOf = (depth: number): number =>
        Math.min(Math.max(depth, 0), depths - 1);

    /**
     * Whether each depth has ever had a frame drawn into it.
     *
     * A target is made the first time it is asked for and is blank until
     * something renders into it, so asking for one that has never been drawn
     * hands the shader an empty texture — which reads as a black pane, not as a
     * stale one.
     */
    const drawn: boolean[] = new Array(depths).fill(false);

    const targetAt = (depth: number): THREE.WebGLRenderTarget => {
        const at = indexOf(depth);

        targets[at] ??= new THREE.WebGLRenderTarget(wanted.x, wanted.y, {
            samples: 0,
        });

        return targets[at];
    };

    /**
     * The nearest depth to the one asked for that has something in it.
     *
     * Every pane in the level is shown one level further in while another pane's
     * pass is drawn, but only the panes that pass `onto` are recursed into and
     * so only those have that level drawn. `seenFrom` is one hop — a room plus
     * whatever an open doorway lets it see — so a mirror two rooms beyond a
     * portal's far mouth is shown at a depth nothing ever wrote, and a blank
     * target is black.
     *
     * That is level 8's stairs portal exactly: it comes out in `room-48`, and
     * the nearest mirror is in `room-58`, which is reached through `room-65` —
     * two hops, so it is never redrawn for that view.
     *
     * The rule already written down for the far end of a tunnel of portals is
     * the right one here too: show the view from a level that was drawn, which
     * is at worst a frame old. Stale is a decision this engine has taken
     * deliberately and says so; black is just an unwritten buffer.
     */
    const readable = (depth: number): number => {
        const at = indexOf(depth);

        for (let nearer = at; nearer >= 0; nearer--) {
            if (drawn[nearer]) {
                return nearer;
            }
        }

        for (let deeper = at + 1; deeper < depths; deeper++) {
            if (drawn[deeper]) {
                return deeper;
            }
        }

        // Nothing has ever been drawn for this pane. Only reachable on the very
        // first frame, before any pass has run.
        return at;
    };

    const textureMatrix = new THREE.Matrix4();

    /** The pane's own across and up, once it is turned into place. */
    const sideways = new THREE.Vector3(1, 0, 0);
    const vertical = new THREE.Vector3(0, 1, 0);
    const beside = new THREE.Vector3();
    const upright = new THREE.Vector3();

    const material = new THREE.ShaderMaterial({
        name: 'ViewPane',
        defines: options.readByFarCamera ? { READ_BY_FAR_CAMERA: '' } : {},
        uniforms: {
            pane: { value: targetAt(0).texture },
            textureMatrix: { value: textureMatrix },
            tint: { value: options.tint },
            shrink: { value: 1 },
            // Half the target's size: NDC spans two, so this turns the reach
            // from the pane's middle into a count of texels.
            paneTexels: {
                value: new THREE.Vector2(
                    options.textureWidth / 2,
                    options.textureHeight / 2,
                ),
            },
            edgeBias: { value: EDGE_BIAS_TEXELS },
        },
        vertexShader: VERTEX_SHADER,
        fragmentShader: FRAGMENT_SHADER,
    });

    const mesh = new THREE.Mesh(options.geometry, material);

    const beyondCameras = new WeakMap<
        THREE.PerspectiveCamera,
        THREE.PerspectiveCamera
    >();

    const beyondFor = (
        camera: THREE.PerspectiveCamera,
    ): THREE.PerspectiveCamera => {
        const held = beyondCameras.get(camera);

        if (held !== undefined) {
            return held;
        }

        const made = new THREE.PerspectiveCamera();
        made.matrixAutoUpdate = false;
        made.matrixWorldAutoUpdate = false;
        beyondCameras.set(camera, made);

        return made;
    };

    const exitPlane = new THREE.Plane();
    const scratch = {
        plane: new THREE.Plane(),
        clip: new THREE.Vector4(),
        corner: new THREE.Vector4(),
    };

    const rest = new THREE.Vector3();
    const restTurn = new THREE.Quaternion();
    const toEye = new THREE.Vector3();
    const look = new THREE.Vector3();

    // How big the pane is in its own right, so covering the screen is a matter
    // of scaling it rather than building another one.
    options.geometry.computeBoundingBox();
    const box = options.geometry.boundingBox as THREE.Box3;
    const size = new THREE.Vector2(
        box.max.x - box.min.x,
        box.max.y - box.min.y,
    );

    const surface: PortalSurface = {
        mesh,
        partner: null,
        behind: [],
        bounds: new THREE.Sphere(),
        home: options.home,
        onto: options.onto,

        viewerAt: options.viewerAt,

        aim: (camera) => {
            const beyond = beyondFor(camera);

            options.aim(camera, beyond);

            beyond.projectionMatrix.copy(camera.projectionMatrix);
            beyond.projectionMatrixInverse
                .copy(beyond.projectionMatrix)
                .invert();
            beyond.far = camera.far;

            // Worked out before the near plane is tilted, since the tilt only
            // moves things along z and this reads x, y and w.
            textureMatrix.set(
                0.5,
                0.0,
                0.0,
                0.5,
                0.0,
                0.5,
                0.0,
                0.5,
                0.0,
                0.0,
                0.5,
                0.5,
                0.0,
                0.0,
                0.0,
                1.0,
            );
            textureMatrix.multiply(beyond.projectionMatrix);
            textureMatrix.multiply(beyond.matrixWorldInverse);
            textureMatrix.multiply(mesh.matrixWorld);

            exitPlane.setFromNormalAndCoplanarPoint(
                options.exitNormal,
                options.exitPoint,
            );

            if (
                Math.abs(exitPlane.distanceToPoint(beyond.position)) >
                CLIP_MINIMUM
            ) {
                tiltNearPlaneOnto(
                    beyond.projectionMatrix,
                    exitPlane,
                    beyond.matrixWorldInverse,
                    scratch,
                );
            }

            return beyond;
        },

        settle: () => {
            rest.copy(mesh.position);
            restTurn.copy(mesh.quaternion);
        },

        release: () => {
            mesh.position.copy(rest);
            mesh.quaternion.copy(restTurn);
            mesh.scale.set(1, 1, 1);
            mesh.updateMatrixWorld(true);
        },

        hug: (camera, clearance) => {
            const face = options.faceNormal;
            const point = options.facePoint;

            if (face === undefined || point === undefined) {
                return;
            }

            const eye = camera.position;

            toEye.copy(eye).sub(point);

            const ahead = toEye.dot(face);

            look.set(0, 0, -1).applyQuaternion(camera.quaternion);

            // How far along the opening the eye is, and how far up it. A mouth
            // is a rectangle in a wall, not the whole wall: measuring only the
            // distance to its plane hauls the pane across the view anywhere
            // along that wall, however far to one side the opening is. Level 8
            // has a portal in the same wall as a wide doorway, so walking
            // through the doorway filled the screen with the portal's view.
            beside.copy(sideways).applyQuaternion(restTurn);
            upright.copy(vertical).applyQuaternion(restTurn);

            const along = Math.abs(toEye.dot(beside));
            const up = Math.abs(toEye.dot(upright));

            // Only while the eye is nearly on the pane, within the opening, AND
            // looking at it. The last matters on the way out: a portal puts the
            // player down a couple of centimetres inside the far room, right
            // against that room's own pane, walking away from it. Without the
            // check that pane is hauled across their face for the first few
            // steps — and what it holds is the view from behind the mouth they
            // just came out of, which is to say the sky.
            if (
                ahead >= clearance ||
                ahead <= -clearance ||
                along > size.x / 2 + clearance ||
                up > size.y / 2 + clearance ||
                look.dot(face) >= 0
            ) {
                surface.release();

                return;
            }

            // Big enough to reach the corners of the view at that distance,
            // with a little to spare so no edge of it can creep in.
            const tall =
                2 *
                clearance *
                Math.tan(THREE.MathUtils.degToRad(camera.fov) / 2) *
                1.3;

            mesh.position.copy(eye).addScaledVector(look, clearance);
            mesh.quaternion.copy(camera.quaternion);
            mesh.scale.set((tall * camera.aspect) / size.x, tall / size.y, 1);
            mesh.updateMatrixWorld(true);
        },

        peek: (depth) => {
            const at = indexOf(depth);

            return drawn[at] ? targets[at] : null;
        },

        show: (depth, shrink = 1) => {
            material.uniforms.pane.value = targetAt(readable(depth)).texture;
            material.uniforms.shrink.value = shrink;
        },

        render: (renderer, scene, camera, depth) => {
            fitTo(renderer);

            const beyond = surface.aim(camera);
            const target = targetAt(depth);
            const partnerWasVisible = surface.partner?.visible ?? false;
            const behindWasVisible = surface.behind.map((what) => what.visible);

            // The far mouth stands right in front of the camera, so its pane is
            // taken out of the view. This one stays in: a portal hung to look
            // back at itself has to be able to turn up in its own view, and that
            // is what puts one opening inside the last.
            if (surface.partner !== null) {
                surface.partner.visible = false;
            }

            for (const what of surface.behind) {
                what.visible = false;
            }

            const wasTarget = renderer.getRenderTarget();
            const wasShadowAutoUpdate = renderer.shadowMap.autoUpdate;

            renderer.shadowMap.autoUpdate = false;
            renderer.setRenderTarget(target);
            renderer.state.buffers.depth.setMask(true);

            if (!renderer.autoClear) {
                renderer.clear();
            }

            renderer.render(scene, beyond);

            // This level has a picture in it now, so `show` may read it back
            // rather than falling forward to one that has.
            drawn[indexOf(depth)] = true;

            renderer.shadowMap.autoUpdate = wasShadowAutoUpdate;
            renderer.setRenderTarget(wasTarget);

            if (surface.partner !== null) {
                surface.partner.visible = partnerWasVisible;
            }

            surface.behind.forEach((what, at) => {
                what.visible = behindWasVisible[at];
            });
        },

        dispose: () => {
            for (const held of targets) {
                held?.dispose();
            }

            material.dispose();
        },
    };

    return surface;
}
