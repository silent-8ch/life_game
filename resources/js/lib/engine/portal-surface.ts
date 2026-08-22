import * as THREE from 'three';
import {
    NEAR_PLANE,
    PANE_TEXELS_ACROSS,
    PANE_TEXELS_DOWN,
} from '@/lib/engine/constants';

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

/**
 * Pulls the tilted near plane a hair off the mouth, against seams at the edge.
 *
 * It is not free, and what it costs is the **far** plane. Lengyel's
 * substitution replaces the third row of the projection outright, so the far
 * plane afterwards is `row4 - row3` and lands at
 *
 *     d / (d / far + bias * (1 - d / far) / 2)
 *
 * where `d` is how far the camera stands off the plane it is clipped against.
 * With `d` small that is `2d / bias`, and it does not depend on `far` at all —
 * so a level asking for a hundred metres of view gets **sixteen and a half**
 * when the camera is five centimetres off a mouth, and everything past that
 * distance is clipped away and shows the background instead.
 *
 * That is what Paul saw as *it looks black where it should be a wall*: the
 * chamber's far wall, twenty-six metres from the pane's camera, at the end of a
 * sightline through the portal demo's long hall. Standing in that corridor and
 * looking at the same doorway directly draws it correctly, which is what said
 * the fault was in the pane's camera rather than in the room.
 *
 * So the bias is now the largest one that leaves the far plane where the level
 * asked for it, and only shrinks below this where it has to — see `biasFor`.
 */
const CLIP_BIAS = 0.005;

/**
 * How much of the level's asked-for view distance the tilt is allowed to cost.
 *
 * It has to cost something. Rearranged for the bias, the identity above says
 * the far plane sits at `far` only when the bias is exactly zero — any nudge at
 * all pulls it in, and a bias of zero is what the nudge exists to avoid. So
 * this is the trade written down rather than discovered: keep nine tenths of
 * the view distance, and spend the tenth on the seam.
 */
const FAR_KEPT = 0.9;

/**
 * The largest bias that still leaves the far plane at `FAR_KEPT` of `far`.
 *
 * Solving the identity in `CLIP_BIAS` for a target of `k * far` gives
 *
 *     bias = 2 * (1 - k) * off / (k * (far - off))
 *
 * which past about a quarter of a metre, at a hundred metres of view, exceeds
 * `CLIP_BIAS` and changes nothing. Closer in it tightens — which is exactly
 * where the far plane was being hauled in, and exactly where a seam has the
 * least room to show anyway.
 *
 * Giving up seam margin to keep the far plane is the right way round: a seam is
 * a line a pixel wide at the rim of an opening, and the alternative is a room
 * at the end of a sightline not being drawn at all.
 */
export function biasFor(off: number, far: number): number {
    return Math.min(
        CLIP_BIAS,
        (2 * (1 - FAR_KEPT) * off) / (FAR_KEPT * Math.max(far - off, off)),
    );
}

/**
 * How close the camera may come to the plane it is clipped against before the
 * tilt is dropped, in metres.
 *
 * There has to be some threshold. The oblique construction scales the clip
 * plane by the reciprocal of the camera's distance to it, so as that distance
 * goes to zero the whole depth range collapses and the far room falls out of
 * the picture — the pane goes flat black. Measured walking into the demo's
 * `loop`: black from about half a centimetre out to somewhere past one, sound
 * again by five.
 *
 * It was 0.002, which is smaller than the band that fails, so the band failed.
 * The number is now `NEAR_PLANE`, on the reasoning that whatever the tilt is
 * there to cut lies within `d` of the mouth's plane, so once `d` is inside the
 * near plane the ordinary near plane has already taken most of it.
 *
 * **Most, not all** — and the first version of this comment claimed all, which
 * was wrong. The near plane clips by depth along the view, and the `d` measured
 * here is a perpendicular distance to a plane: a wall twenty metres off to one
 * side can be three centimetres in front of the mouth and nowhere near the near
 * plane. So this is a measured threshold with a reason behind it rather than a
 * derivation, and it should be read as the first and not the second.
 *
 * What the tilt used to be relied on for at that range, `behind` now does
 * outright by taking the room out of the pass. That is why this can be raised
 * twenty-five-fold without the wall behind the mouth coming back.
 *
 * Do not close the gap by pushing the plane forward instead of dropping the
 * tilt: that was tried, and it wedges the card hard enough that the page stops
 * painting while its scripts carry on.
 */
const CLIP_MINIMUM = NEAR_PLANE;

/**
 * The most a hugged pane may be blown up to hold its outline still.
 *
 * The growth needed is `clearance / distance to the pane`, and that runs away
 * as the eye lands on the mouth — which at the moment of crossing it does. So
 * there has to be a cap, and where the cap bites the outline shifts a little
 * rather than not at all.
 *
 * 60 puts the bite at two millimetres out of a twelve-centimetre stand-off. At
 * a walk that is a single frame; at a run it is not even that, because a frame
 * covers 0.27 m and the whole window is 0.24 m across. Nobody has ever seen
 * the last two millimetres of a portal crossing and nobody ever will.
 *
 * What it buys is a pane held to a few hundred metres across instead of to
 * infinity, which is the difference between a large quad and a coordinate that
 * has stopped being a number.
 */
const HUG_GROWTH = 60;

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
    bias: number = CLIP_BIAS,
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
    projection.elements[10] = clip.z + 1 - bias;
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
    /**
     * The room on **this** pane's own far side, taken out of the picture while
     * the pane is hugged across the player's view.
     *
     * `behind` keeps a room out of the frame a pane *draws*. This keeps it out
     * of the frame the player is *shown*. Same problem, different frame, same
     * answer.
     */
    blocking: THREE.Object3D[];
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
     * Slides the pane back through its own mouth, far enough that the near
     * plane cannot reach it, and puts it back once the eye is clear.
     *
     * Without this the last few centimetres of approaching a portal show
     * whatever lies past the opening, which is nothing, so the sky — a mouth
     * builds no wall, and the pane standing in for one is the only thing there.
     *
     * It slides **along its own normal** rather than squaring up to the screen,
     * and that is the whole of the fix for ISSUE-101. Squared up, the pane is a
     * sheet across the whole view: right when a mouth is straight ahead and
     * filling it anyway, and wrong the moment the player is beside the opening
     * looking along it, where most of the screen is the near room and only a
     * wedge of it is the far one. Slid back, the pane keeps its own shape and
     * its own place in the world, so what it covers is what the mouth covers,
     * at every angle, with no case to get right.
     *
     * Nothing is lost by moving it: the pane reads the far view from where its
     * fragments land on the screen, so the picture stays put while the surface
     * carrying it goes back. What it costs is parallax — the far rim of the
     * opening shifts by the few centimetres of the slide, about two degrees at
     * a mouth's own width — which is a smaller lie than a hole.
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

    /** What a hug took out of the picture, and how each was before it did. */
    const hugHid = new Map<THREE.Object3D, boolean>();
    const toEye = new THREE.Vector3();
    const toFoot = new THREE.Vector3();
    const toPane = new THREE.Vector3();

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
        blocking: [],
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

            const off = Math.abs(exitPlane.distanceToPoint(beyond.position));

            if (off > CLIP_MINIMUM) {
                tiltNearPlaneOnto(
                    beyond.projectionMatrix,
                    exitPlane,
                    beyond.matrixWorldInverse,
                    scratch,
                    biasFor(off, camera.far),
                );
            }

            return beyond;
        },

        settle: () => {
            rest.copy(mesh.position);
            restTurn.copy(mesh.quaternion);
        },

        release: () => {
            for (const [what, was] of hugHid) {
                what.visible = was;
            }

            hugHid.clear();

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

            // How far along the opening the eye is, and how far up it. A mouth
            // is a rectangle in a wall, not the whole wall: measuring only the
            // distance to its plane moves the pane anywhere along that wall,
            // however far to one side the opening is. Level 8 has a portal in
            // the same wall as a wide doorway, so walking through the doorway
            // used to fill the screen with the portal's view.
            beside.copy(sideways).applyQuaternion(restTurn);
            upright.copy(vertical).applyQuaternion(restTurn);

            const along = Math.abs(toEye.dot(beside));
            const up = Math.abs(toEye.dot(upright));

            // Only while the eye is nearly on the pane and within the opening.
            //
            // There is no longer a test for whether the player is *looking* at
            // the mouth, and taking it out is part of the fix rather than an
            // oversight. It was there because a squared-up pane is pasted in
            // front of the camera whichever way the camera faces, so coming out
            // of a portal — which puts the player a couple of centimetres
            // inside the far room, against that room's own pane, walking away —
            // hauled a sheet of sky across their face. A pane that stays in its
            // own plane is simply behind them, and needs nobody to say so.
            //
            // Keeping the test would have been worse than useless here. It read
            // `look.dot(face) >= 0`, and looking *along* a mouth is exactly
            // where that dot product is zero, so the one angle that most needed
            // the pane moved was the one angle guaranteed not to move it.
            if (
                Math.abs(ahead) >= clearance ||
                along > size.x / 2 + clearance ||
                up > size.y / 2 + clearance
            ) {
                surface.release();

                return;
            }

            // The room on the other side of this mouth must not draw over the
            // pane that is standing in for it.
            //
            // The wall behind the mouth is that room's own face, nudged
            // WALL_INSET past the plane, so at 8 cm from the mouth it is 9 cm
            // away — and a pane slid back to sit `clearance` from the eye is
            // 12 cm away, so the wall wins the depth test and is drawn straight
            // across the portal. Measured at 180 pixels of 298 at the moment of
            // walking through, back when the buffer was that size.
            //
            // Sliding the pane nearer instead cannot fix it. A mouth carries no
            // collider, so the eye can come closer to it than to any wall, and
            // there is always a range where the wall is inside NEAR_PLANE and
            // no legal position for the pane is nearer still. Taking the room
            // out of the frame is the certain way — the same answer `behind`
            // already gives to the same problem inside a pane's own render.
            for (const what of surface.blocking) {
                if (!hugHid.has(what)) {
                    hugHid.set(what, what.visible);
                }

                what.visible = false;
            }

            // Back through the mouth, far enough that the eye stands
            // `clearance` off the pane and the near plane has nothing of it to
            // cut — and grown by exactly as much as it went back, about the
            // point on it nearest the eye, so that its outline on screen does
            // not move at all.
            //
            // Sliding alone is not enough, and the leftover is small and ugly:
            // the far end of the mouth is drawn a few centimetres further off
            // than it belongs, and where that end meets the corner of the room
            // it pulls away from it. Measured at eight pixels of nine hundred,
            // a sliver of sky down the middle of the view. Eight pixels of sky
            // is the same class of fault as the hairline this file already
            // fights round a pane's rim, and it is not worth trading a big one
            // for a small one when the small one has a closed form.
            //
            // The form: a point on the pane `L` from the foot of the
            // perpendicular subtends `atan(L / d)` where `d` is the eye's
            // distance to the plane. Moving the plane from `ahead` to
            // `clearance` and multiplying every in-plane offset by the same
            // ratio leaves every one of those angles alone. So the pane comes
            // back the same shape on screen, drawn on a rectangle that is
            // bigger and further away — which is all a portal pane ever was.
            // Measured to the **pane's** own plane rather than to the mouth's,
            // which are not always the same plane: a pane is recessed behind
            // its opening so that its rim cannot read outside it. The ratio
            // below is a stand-off divided by a stand-off, so a couple of
            // centimetres of difference between the two is a large error in it,
            // and a large error in it is the corners of the opening jumping as
            // the pane takes hold — the exact thing this is here to stop.
            const paneAhead = toPane.copy(eye).sub(rest).dot(face);

            // The floor under the divisor is `HUG_GROWTH` read the other way
            // round, so the cap and the thing it caps cannot disagree. It is
            // deliberately not `CLIP_MINIMUM`: that answers a different
            // question — how near the tilt stops being worth applying — and
            // sharing a number between two unrelated questions is how a change
            // to one of them silently becomes a change to the other.
            const grow =
                clearance /
                Math.max(Math.abs(paneAhead), clearance / HUG_GROWTH);
            const side = paneAhead < 0 ? -1 : 1;

            toFoot.copy(rest).sub(eye).addScaledVector(face, paneAhead);

            mesh.position
                .copy(eye)
                .addScaledVector(face, -side * clearance)
                .addScaledVector(beside, toFoot.dot(beside) * grow)
                .addScaledVector(upright, toFoot.dot(upright) * grow);
            mesh.quaternion.copy(restTurn);
            mesh.scale.set(grow, grow, 1);
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
