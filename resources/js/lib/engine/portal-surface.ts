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
 * Where a fragment reads the far view from. **Both kinds read the same way**:
 * by where the fragment lands on the screen. Both cameras share one projection
 * and each transform maps one frustum exactly onto the other, so a ray leaving
 * the eye through a point on the screen arrives, on the far side, through that
 * same point — which is the whole reason stepping through a portal is
 * continuous, and the same reason a mirror's edge never disagrees with the wall
 * it is set into.
 *
 * A mirror adds one thing to that: its camera is the player's reflected in the
 * wall and then turned left-for-right, and the read flips back. See `mirrored`.
 */
/**
 * How far in from its own edge a pane reads, in texels of the target it reads
 * from. Held in texels rather than as a fraction of the pane, so that a portal
 * across the room is pulled in by as much as one right in front of you — a
 * fraction of a small pane is a fraction of a pixel, and no use at all.
 */
const EDGE_BIAS_TEXELS = 1.5;

/**
 * Cuts a projection down to a rectangle of its own picture.
 *
 * The first two rows carry x and y, so scaling them and adding a multiple of
 * the w row moves the chosen rectangle onto the whole of the frame. Lengyel's
 * oblique tilt replaces the **third** row, so the two never meet and the order
 * they are applied in does not matter.
 */
function crop(
    projection: THREE.Matrix4,
    acrossBy: number,
    downBy: number,
    shift: { x: number; y: number },
): void {
    const at = projection.elements;

    // Column-major: element 4n+m is row m of column n.
    for (let column = 0; column < 4; column++) {
        const x = column * 4;

        at[x] = at[x] * acrossBy + at[x + 3] * shift.x;
        at[x + 1] = at[x + 1] * downBy + at[x + 3] * shift.y;
    }
}

/**
 * A rectangle of the pass being drawn, in NDC — the same shape as an `Aperture`
 * and named separately only so that this file does not have to depend on the
 * recursion that computes them.
 *
 * A pane pass no longer draws the whole frustum. It draws the window its
 * picture will actually be read through, into a target sized for that window at
 * the density of the screen — which is what makes a reflection thirty levels
 * down as sharp as one at the front.
 */
export type PaneWindow = {
    left: number;
    right: number;
    bottom: number;
    top: number;
};

/**
 * How many frames a level's target is kept after nothing has drawn it.
 *
 * Generous on purpose: a chain that comes and goes as the player turns must not
 * free and remake its buffers every second, which is the reallocation churn
 * that stopped the page painting once already. Five seconds at sixty a frame.
 */
const KEPT_FOR = 300;

/** How often the camera pool is walked looking for viewpoints nobody wants. */
const SWEEP_EVERY = 60;

/** The whole picture: what the player's own camera is drawn through. */
const WHOLE_VIEW: PaneWindow = { left: -1, right: 1, bottom: -1, top: 1 };

/**
 * The narrowest window a pass may be drawn through, in NDC.
 *
 * **This is a guard against wedging the GPU, not a quality setting.** Cropping
 * a projection onto a window divides by that window's span, and the openings
 * handed down the recursion have no floor under them: `narrow` returns a
 * rectangle whenever two overlap *at all*, so a chain grazing the edge of a
 * mirror produces a span of a millionth and a crop factor in the millions. What
 * comes out is a projection whose numbers no longer mean anything, and the
 * symptom is not a wrong picture — it is the page ceasing to paint while its
 * scripts carry on. Paul: *the game crashed... looks like the game halts at
 * some point.* This engine has met that failure once before, from pushing a
 * clip plane forward, and the note left then says the same thing.
 *
 * A fiftieth of NDC is about twenty pixels across a 1080p screen, which is also
 * about the smallest buffer worth allocating. Below it the window is widened
 * about its own middle: the pass then draws a little more than will be read,
 * which costs nothing and cannot divide by nearly nothing.
 */
const NARROWEST = 0.02;

/** A window widened to something a projection can safely be cropped onto. */
function safely(window: PaneWindow, into: PaneWindow): PaneWindow {
    const spread = (low: number, high: number): [number, number] => {
        const span = high - low;

        if (span >= NARROWEST) {
            return [low, high];
        }

        const middle = (low + high) / 2;

        return [middle - NARROWEST / 2, middle + NARROWEST / 2];
    };

    const [left, right] = spread(window.left, window.right);
    const [bottom, top] = spread(window.bottom, window.top);

    into.left = left;
    into.right = right;
    into.bottom = bottom;
    into.top = top;

    return into;
}

const VERTEX_SHADER = `
    #include <common>
    #include <logdepthbuf_pars_vertex>

    uniform vec2 paneTexels;
    uniform float edgeBias;
    varying vec4 vPane;

    void main() {
        vec4 clip = projectionMatrix * modelViewMatrix * vec4(position, 1.0);

            // Read a hair towards the middle of the pane rather than at its
            // very edge. The pane and the mouth it fills line up to within a
            // pixel, and on the far side of that pixel is the sky the tilted
            // near plane left behind, which shows as a bright hairline round
            // the portal — there whenever the edge falls the wrong side of a
            // pixel, gone again a step later, which is what makes it flicker
            // as the player walks.
            // Carried linear in clip space, so the hardware interpolates and
            // near-plane-clips it like any other attribute, and the divide
            // happens per fragment where w is the depth of a pixel that is
            // genuinely on screen.
            //
            // Dividing here instead would poison the whole quad: a wall long
            // enough to run past the viewer has corners with a negative w, and
            // the clipped edge takes its value from that corner.
            vPane = vec4(clip.xy * 0.5 + clip.w * 0.5, clip.z, clip.w);

        gl_Position = clip;

        #include <logdepthbuf_vertex>
    }
`;

const FRAGMENT_SHADER = `
    #include <common>
    #include <logdepthbuf_pars_fragment>

    uniform sampler2D pane;
    uniform vec2 paneTexels;
    uniform float edgeBias;
    uniform vec2 paneScale;
    uniform vec2 paneOffset;
    varying vec4 vPane;

    void main() {
        #include <logdepthbuf_fragment>

            // Where this pixel is on the screen. Safe because it is this
            // camera's w, and a fragment that reached here is in front of this
            // camera by definition.
            vec2 at = vPane.xy / vPane.w;

            // From where this fragment lands in the pass being drawn, to
            // where that is in the target being read.
            //
            // One affine step, and it carries three things at once: the
            // window this pass was rendered through, the window the target
            // was rendered through, and the mirror camera's left-for-right
            // turn (which is a negative x scale here and nothing more). All
            // three are fixed for a given pane at a given level, so they are
            // multiplied out on the way in.
            at = at * paneScale + paneOffset;

            // Keep the read a hair inside the target.
            //
            // A pane and the mouth it fills line up to within a pixel, and on
            // the far side of that pixel is whatever the tilted near plane left
            // outside the opening — which shows as a bright hairline round the
            // rim, there whenever the edge falls the wrong side of a pixel and
            // gone a step later, so it flickers as the player walks.
            //
            // **A clamp, not a pull towards the middle**, and that difference
            // is the whole of Paul's *it looks like the shape is warped into a
            // circle around a point.* This used to mix the read towards the
            // pane's own centre by up to a quarter, sized in texels of the
            // target. That is a radial shrink, it is applied once per level, and
            // levels nest — so it compounds, and it grows as the targets get
            // smaller with depth. Worked out down his corridor of two facing
            // mirrors: about a third of a per cent at the first bounce, three
            // per cent at ten, five at fourteen, near enough thirty per cent
            // accumulated. His words for it were also *i see a distortion much
            // sooner than the vanishing lines converging*, which is what a fault
            // that grows with depth looks like from inside the tunnel.
            //
            // Clamping does the same job with no radial term at all: the target
            // is cropped to exactly the window this pane is read through, so
            // straying past the rim *is* straying outside nought to one, and
            // stopping half a texel short of the edge is the whole fix.
            vec2 inset = edgeBias / max(paneTexels * 2.0, vec2(1.0));

            at = clamp(at, inset, 1.0 - inset);

            gl_FragColor = vec4(texture2D(pane, at).rgb, 1.0);

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
    /**
     * Whether this is a mirror rather than a portal mouth.
     *
     * Asked outright because the two want opposite things at the last bounce. A
     * mirror comes out of the picture and the wall behind it is drawn instead,
     * which is how the corridor ends on a surface rather than on a loop. A
     * portal mouth has no wall behind it — it is an opening — so it stays in
     * and reads a level further out, which is what makes a tunnel of portals
     * keep going.
     *
     * This was read off `partner` before, which is a mesh doing two jobs and
     * only accidentally distinguishes the two.
     */
    mirrored: boolean;
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
     * The room again, standing where this mirror's reflection of it is.
     *
     * Shown in the passes where this pane has come out of the picture, which is
     * where a chain of reflections stopped. What is behind a mirror otherwise
     * is the wall it hangs on, and a wall at the back of a reflection is the
     * one complaint left after everything else was fixed. A mirror's image of a
     * room is a real place, so a reflected copy of the room's geometry standing
     * there is the continuation rather than a stand-in for it. See
     * `build/images.ts`. Empty for a portal, which has a room of its own on the
     * far side already.
     */
    image: THREE.Object3D[];
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
    /**
     * Lets go of any depth nothing has drawn for a while, and tells the surface
     * which frame it is. Called once a frame per pane, before anything draws.
     */
    tidy: (now: number) => void;
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
     * Which way this surface faces.
     *
     * For working out which pane *continues* another one. Two panes facing each
     * other are a corridor; the depth a tunnel needs should go down that pair
     * rather than sideways into a wall beside it.
     */
    facing: THREE.Vector3;
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
        /** The rectangle of this pass's own picture that will be read back. */
        window: PaneWindow,
    ) => void;
    /**
     * Which depth's view the pane shows. Zero is what the player sees; deeper
     * ones are what the pane shows when it is itself being looked at through a
     * pane, which is what turns a portal hung to face itself into a corridor.
     *
     */
    show: (
        depth: number,
        /**
         * Where the pass about to display this pane was itself drawn through.
         *
         * A pane reads its target by where its fragment lands on the screen —
         * but "the screen" is now the window the displaying pass was cropped
         * to, and the target holds only the window *it* was cropped to. The two
         * are told apart here: `show` composes them, with the mirror's
         * left-for-right turn, into the one affine step the shader does.
         *
         * Left out for the player's own view, which is cropped to nothing.
         */
        seenThrough?: PaneWindow,
    ) => void;
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
    /**
     * True for a mirror, and the whole of what makes one different here.
     *
     * A reflection is left-handed. Built honestly, as `R · M`, the camera that
     * draws it has a determinant of −1, and three reverses the winding of every
     * triangle drawn through it — `WebGLRenderer` compensates for a negative
     * determinant on an **object**, never on a camera. Every single-sided
     * material in the scene is then culled inside out: the panes themselves,
     * which is a mirror with no other mirror in it; the sky, which is
     * `BackSide` and so goes black; and any prop that did not think to ask for
     * two sides.
     *
     * So the camera carries a further flip in its own x, which makes it
     * right-handed again, and the pane reads back flipped. The picture is
     * identical — measured to six figures across the frame, and on the mirror's
     * own plane it lands on the same pixel as the player's camera, which is the
     * identity the whole screen-space read rests on.
     */
    mirrored?: boolean;
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

        targets.forEach((target, at) => {
            const size = sizeFor(at);

            target?.setSize(size.width, size.height);
        });
    };

    /**
     * How big a level's target is, in texels.
     *
     * **Not a fraction of the screen decided by depth, which is what this was
     * and which was the whole of Paul's *walls with distorted or stretched
     * images far from the camera into the mirror*.**
     *
     * The old rule halved a target every couple of levels down to a sixteenth,
     * reasoning that a reflection nine rooms away is a few pixels across and
     * need not be drawn at the size of the screen. That reasoning is right for
     * a pane that reads its target *projectively* — mapping the whole target
     * onto the whole pane — and this one does not. It reads by **screen
     * position**, so the pane's own shrinking and the target's shrinking
     * compound, and what comes out is a magnification. Worked out for a
     * four-mirror room: at twelve levels a pane 53 by 30 pixels across read six
     * texels; at thirty, a patch 21 by 12 pixels was drawn from **one**.
     *
     * A target is sized to the window it will be read through instead, at the
     * density of the screen. A pane covering 21 by 12 pixels gets 21 by 12
     * texels whatever level it is at, and the picture is as sharp at the back
     * of the tunnel as at the front. It costs less rather than more: the old
     * scheme paid full screen size for the first three levels of every pane
     * whatever they covered.
     *
     * Rounded up to powers of two, because the window moves with the player and
     * a render target that resizes is a render target that reallocates. Sixteen
     * at the smallest: below that the edge bias has nothing to bite on.
     */
    const SMALLEST = 16;

    const texelsFor = (
        window: PaneWindow,
    ): { width: number; height: number } => {
        const across = ((window.right - window.left) / 2) * wanted.x;
        const down = ((window.top - window.bottom) / 2) * wanted.y;

        const upTo = (want: number, most: number): number => {
            const bounded = Math.min(most, Math.max(SMALLEST, Math.ceil(want)));

            return Math.min(most, 2 ** Math.ceil(Math.log2(bounded)));
        };

        return {
            width: upTo(across, wanted.x),
            height: upTo(down, wanted.y),
        };
    };

    /**
     * The window each level was last drawn through, so that `show` can hand the
     * pane the same one to read back by.
     */
    const windows: PaneWindow[] = Array.from({ length: depths }, () => ({
        left: -1,
        right: 1,
        bottom: -1,
        top: 1,
    }));

    const sizeFor = (depth: number): { width: number; height: number } =>
        texelsFor(windows[Math.min(Math.max(depth, 0), depths - 1)]);

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

    /**
     * The frame each depth was last drawn on, and which frame it is now.
     *
     * A target is made the first time a depth is reached and, until this, was
     * kept until the level was torn down. That is fine while a pane goes eight
     * levels deep and is not fine now: a room of four mirrors reaches
     * forty-odd, so it holds forty-odd targets a pane, each grown to the
     * largest window that level has ever needed. Measured by walking that room
     * — twenty-five spots, twenty-four headings — 172 targets and 79 MB of
     * colour, and as much again in depth buffers. Paul: *4 mirrors room
     * crashes now.*
     *
     * Nothing about that memory is being looked at. Turning on the spot changes
     * which chains exist, and the ones that have gone are still holding their
     * buffers. So a depth nobody has drawn for a while gives its target back.
     */
    const drawnOn = new Int32Array(depths).fill(-1);

    let clock = 0;

    const targetAt = (depth: number): THREE.WebGLRenderTarget => {
        const at = indexOf(depth);
        const size = sizeFor(at);
        const held = targets[at];

        if (held === null || held === undefined) {
            targets[at] = new THREE.WebGLRenderTarget(size.width, size.height, {
                samples: 0,
            });

            return targets[at] as THREE.WebGLRenderTarget;
        }

        // **Grows, never shrinks**, and powers of two on top of that.
        //
        // `setSize` throws the texture away and makes another one. The window
        // moves with the player, so a size that tracked it exactly would
        // reallocate every frame, for every level of every pane — hundreds of
        // textures a frame, which a driver will not forgive and which is the
        // other half of Paul's *the game halts at some point*.
        //
        // Growing only means a target settles on the largest that level has
        // ever needed and then stops. It costs a little memory in a room the
        // player has walked all over, and buys never reallocating in one they
        // are walking through now.
        if (held.width < size.width || held.height < size.height) {
            held.setSize(
                Math.max(held.width, size.width),
                Math.max(held.height, size.height),
            );
        }

        return held;
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

    /** The pane's own across and up, once it is turned into place. */
    const sideways = new THREE.Vector3(1, 0, 0);
    const vertical = new THREE.Vector3(0, 1, 0);
    const beside = new THREE.Vector3();
    const upright = new THREE.Vector3();

    const material = new THREE.ShaderMaterial({
        name: 'ViewPane',
        uniforms: {
            pane: { value: targetAt(0).texture },
            // Half the target's size: NDC spans two, so this turns the reach
            // from the pane's middle into a count of texels.
            paneTexels: {
                value: new THREE.Vector2(
                    options.textureWidth / 2,
                    options.textureHeight / 2,
                ),
            },
            edgeBias: { value: EDGE_BIAS_TEXELS },
            // Identity until a pass says otherwise, but for a mirror the
            // identity still carries the flip.
            paneScale: {
                value: new THREE.Vector2(options.mirrored === true ? -1 : 1, 1),
            },
            paneOffset: {
                value: new THREE.Vector2(options.mirrored === true ? 1 : 0, 0),
            },
        },
        vertexShader: VERTEX_SHADER,
        fragmentShader: FRAGMENT_SHADER,
    });

    const mesh = new THREE.Mesh(options.geometry, material);

    /**
     * One camera per viewpoint this pane is ever aimed from, and when it was
     * last wanted.
     *
     * ## Why this cannot be a WeakMap, which is what it was
     *
     * A WeakMap keeps its **value** alive for as long as its **key** lives, and
     * the key here is the camera one level out. Follow that back and the root of
     * every chain is the player's own camera, which lives for the whole level —
     * so a depth-one camera never dies, and it is the key for depth two, which
     * never dies, and so on. **Every camera ever made for any chain was retained
     * until the level was torn down.**
     *
     * That would be harmless if the set of chains were fixed. It is not: the
     * opening test picks different ones as the player moves, so every step makes
     * chains that have never been walked before and each leaves its cameras
     * behind. Measured by walking a four-mirror room: seven thousand cameras
     * after a second of movement, twenty-one thousand after ten, climbing by
     * about a thousand a second and never coming down. Paul: *it freezes after
     * moving a little while.*
     *
     * A plain Map holds its keys too, so this leaks in the same way until
     * something prunes it — which `tidy` does, on the same rule as the render
     * targets: a viewpoint nothing has asked for in five seconds is a chain that
     * is no longer being walked.
     */
    const beyondCameras = new Map<
        THREE.PerspectiveCamera,
        { camera: THREE.PerspectiveCamera; wantedOn: number }
    >();

    const beyondFor = (
        camera: THREE.PerspectiveCamera,
    ): THREE.PerspectiveCamera => {
        let held = beyondCameras.get(camera);

        if (held === undefined) {
            const made = new THREE.PerspectiveCamera();
            made.matrixAutoUpdate = false;
            made.matrixWorldAutoUpdate = false;
            held = { camera: made, wantedOn: clock };
            beyondCameras.set(camera, held);
        }

        held.wantedOn = clock;

        return held.camera;
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
        mirrored: options.mirrored === true,
        partner: null,
        behind: [],
        blocking: [],
        image: [],
        bounds: new THREE.Sphere(),
        home: options.home,
        onto: options.onto,
        facing: options.exitNormal.clone(),

        viewerAt: options.viewerAt,

        aim: (camera) => {
            const beyond = beyondFor(camera);

            options.aim(camera, beyond);

            exitPlane.setFromNormalAndCoplanarPoint(
                options.exitNormal,
                options.exitPoint,
            );

            const off = Math.abs(exitPlane.distanceToPoint(beyond.position));

            // **The far plane has to reach the room, and down a tunnel that is
            // a long way further than the player's own does.**
            //
            // A chain of reflections marches its camera away from the room by
            // the width of the room per bounce: in Paul's eight-metre pair of
            // facing mirrors the camera stands 96 m off at twelve levels and
            // 192 m at twenty-four, while `FAR_PLANE` is 100. Everything it is
            // meant to draw is then **past its own far plane** and cut away,
            // and what is left is the far end of the tunnel dissolving.
            //
            // He found it by building the one room that could show it: *it only
            // has two mirrors, each facing each other... i see the same
            // distortion as the facing portals.* A facing pair and a
            // wrap-around portal are the same shape — a single chain that goes
            // straight out — and nothing else in the game marches a camera like
            // that. Four mirrors branch, so the deep chains there are short.
            //
            // It only started showing when the draw budget went and the depth
            // went from about sixteen to the mid thirties, which took most of
            // the tunnel past the line.
            //
            // Only the third row of a projection depends on the far plane, so
            // this leaves x and y exactly as the player's own — which is the
            // identity the whole screen-space read rests on, and is why this
            // can be rebuilt rather than copied.
            beyond.fov = camera.fov;
            beyond.aspect = camera.aspect;
            beyond.near = camera.near;
            beyond.far = camera.far + off;
            beyond.updateProjectionMatrix();

            beyond.projectionMatrixInverse
                .copy(beyond.projectionMatrix)
                .invert();

            if (off > CLIP_MINIMUM) {
                tiltNearPlaneOnto(
                    beyond.projectionMatrix,
                    exitPlane,
                    beyond.matrixWorldInverse,
                    scratch,
                    biasFor(off, beyond.far),
                );
            }

            return beyond;
        },

        /**
         * Lets go of any depth nothing has drawn for a while.
         *
         * Called once a frame per pane, before anything is drawn, so `clock` is
         * this frame's number for the passes that follow.
         *
         * `KEPT_FOR` is generous on purpose. A chain that comes and goes as the
         * player turns should not be freeing and remaking buffers every second —
         * that is the reallocation churn that made the page stop painting once
         * already. Several seconds of not being looked at is a chain that has
         * really gone.
         */
        tidy: (now) => {
            clock = now;

            // Viewpoints nobody has aimed from in a while. Swept now and then
            // rather than every frame: there can be thousands of them, and
            // walking the whole map sixty times a second to find a handful is
            // its own kind of waste.
            if (now % SWEEP_EVERY === 0) {
                for (const [from, held] of beyondCameras) {
                    if (now - held.wantedOn >= KEPT_FOR) {
                        beyondCameras.delete(from);
                    }
                }
            }

            for (let at = 0; at < depths; at++) {
                const held = targets[at];

                if (
                    held === null ||
                    held === undefined ||
                    drawnOn[at] < 0 ||
                    now - drawnOn[at] < KEPT_FOR
                ) {
                    continue;
                }

                // Never the one the player is being shown, whatever the clock
                // says: it is drawn every frame, so it cannot be stale, and
                // taking it would be a black pane for a frame.
                if (at === 0) {
                    continue;
                }

                held.dispose();
                targets[at] = null;
                drawn[at] = false;
                drawnOn[at] = -1;
            }
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

        show: (depth, seenThrough = WHOLE_VIEW) => {
            const at = readable(depth);
            const size = sizeFor(at);
            const held = windows[at];

            material.uniforms.pane.value = targetAt(at).texture;

            // **From where a fragment lands in the pass being drawn, to where
            // that is in the target being read.**
            //
            // A fragment arrives at `at` in [0,1] of the displaying pass's own
            // cropped viewport. Undo that crop to get where it is in the
            // picture as a whole; turn it left-for-right if this is a mirror,
            // whose camera draws flipped so that its basis stays right-handed;
            // then apply the crop the *target* was drawn through. Three affine
            // steps, composed here once per pane per pass rather than per
            // fragment.
            const acrossSeen = seenThrough.right - seenThrough.left;
            const downSeen = seenThrough.top - seenThrough.bottom;
            const acrossHeld = held.right - held.left;
            const downHeld = held.top - held.bottom;

            const turn = surface.mirrored ? -1 : 1;

            material.uniforms.paneScale.value.set(
                (turn * acrossSeen) / acrossHeld,
                downSeen / downHeld,
            );
            material.uniforms.paneOffset.value.set(
                (turn * seenThrough.left - held.left) / acrossHeld,
                (seenThrough.bottom - held.bottom) / downHeld,
            );

            // The edge bias is held in texels, so it has to be told which
            // target it is reading.
            material.uniforms.paneTexels.value.set(
                size.width / 2,
                size.height / 2,
            );
        },

        render: (renderer, scene, camera, depth, window) => {
            fitTo(renderer);

            // What this level is drawn through, remembered so that `show` can
            // hand the pane the mapping to read it back by, and so that
            // `targetAt` sizes the buffer for it.
            const held = safely(window, windows[indexOf(depth)]);

            const beyond = surface.aim(camera);
            const target = targetAt(depth);

            // **Only the window, not the whole frustum.**
            //
            // Lengyel's tilt has already been applied to this projection and
            // touches the third row only, so cropping x and y commutes with it.
            // The crop maps the window onto the whole of the target: what was a
            // sliver of a screen-sized picture becomes the entire buffer, at
            // the density of the screen.
            crop(
                beyond.projectionMatrix,
                2 / (held.right - held.left),
                2 / (held.top - held.bottom),
                {
                    x: -(held.right + held.left) / (held.right - held.left),
                    y: -(held.top + held.bottom) / (held.top - held.bottom),
                },
            );
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
            drawnOn[indexOf(depth)] = clock;

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
