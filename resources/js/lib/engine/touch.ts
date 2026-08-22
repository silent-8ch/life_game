/**
 * Playing on a phone.
 *
 * A mouse and keyboard give the engine two things it cannot otherwise have: a
 * pointer that can be locked and swept forever, and a set of keys held down. A
 * touch screen has neither, so this stands in for both — the left of the screen
 * is a stick that appears wherever a thumb lands, the right is a pad that turns
 * the view as it is dragged, and everything a key did gets a button.
 *
 * It is only ever built on a device that wants it. On a machine with a mouse
 * nothing here runs and nothing is drawn.
 */

/** How far from the middle of the stick counts as all the way over, in pixels. */
const STICK_REACH = 56;

/** Under this much of a push and the stick reads as centred. */
const DEAD_ZONE = 0.18;

/** Pushed past this much of the way over, the player runs. */
const RUN_AT = 0.82;

/** How far a drag turns the view, in radians per pixel. */
const LOOK_SENSITIVITY = 0.004;

export type TouchButton = {
    /** What the button says. Kept to a word, since there is no room for two. */
    label: string;
    /** Read out to anyone who cannot see it. */
    title: string;
    press: () => void;
};

export type TouchControls = {
    /** False on anything with a mouse, where none of this is built. */
    active: boolean;
    /** Which way the stick is pushed, in the player's own frame. */
    walk: () => { forward: number; strafe: number };
    /** Whether the stick is far enough over to be a run. */
    running: () => boolean;
    /** How far the pad has been dragged since this was last asked, in radians. */
    takeLook: () => { yaw: number; pitch: number };
    /**
     * Shows or hides the whole set. Hidden, it takes no touches at all, so the
     * tap that starts the level reaches the frame underneath rather than being
     * swallowed as the first push of the stick.
     */
    show: (on: boolean) => void;
    dispose: () => void;
};

/**
 * Whether this is a device that needs them: a coarse pointer that cannot hover,
 * which is a touch screen and not a trackpad.
 */
export function wantsTouchControls(): boolean {
    if (typeof window === 'undefined' || window.matchMedia === undefined) {
        return false;
    }

    return window.matchMedia('(pointer: coarse)').matches;
}

/** Nothing at all, for the machines with a mouse. */
function noControls(): TouchControls {
    return {
        active: false,
        walk: () => ({ forward: 0, strafe: 0 }),
        running: () => false,
        takeLook: () => ({ yaw: 0, pitch: 0 }),
        show: () => undefined,
        dispose: () => undefined,
    };
}

function element<K extends keyof HTMLElementTagNameMap>(
    tag: K,
    className: string,
): HTMLElementTagNameMap[K] {
    const made = document.createElement(tag);

    made.className = className;

    return made;
}

export function createTouchControls(options: {
    container: HTMLElement;
    buttons: TouchButton[];
}): TouchControls {
    if (!wantsTouchControls()) {
        return noControls();
    }

    const { container, buttons } = options;

    const layer = element(
        'div',
        'pointer-events-none absolute inset-0 z-20 touch-none select-none',
    );

    // The stick is hidden until a thumb lands, then drawn where it landed. A
    // stick painted in one fixed corner is a stick you have to look down at.
    const ring = element(
        'div',
        'pointer-events-none absolute hidden h-28 w-28 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-amber-100/30 bg-black/20',
    );
    const knob = element(
        'div',
        'pointer-events-none absolute hidden h-12 w-12 -translate-x-1/2 -translate-y-1/2 rounded-full border border-amber-100/50 bg-amber-100/25',
    );

    const tray = element(
        'div',
        'pointer-events-none absolute right-3 bottom-3 flex max-w-[45%] flex-wrap justify-end gap-2',
    );

    for (const button of buttons) {
        const made = element(
            'button',
            'pointer-events-auto touch-none rounded-full border border-amber-100/40 bg-black/55 px-4 py-3 text-xs tracking-widest text-amber-100 uppercase active:bg-amber-100/25',
        );

        made.type = 'button';
        made.textContent = button.label;
        made.title = button.title;
        made.setAttribute('aria-label', button.title);

        // Pressed on the way down, so it answers as fast as a key does, and
        // swallowed so the pad underneath does not read it as a look.
        const press = (event: Event): void => {
            event.preventDefault();
            event.stopPropagation();
            button.press();
        };

        made.addEventListener('pointerdown', press);
        tray.appendChild(made);
    }

    layer.append(ring, knob, tray);
    layer.classList.add('hidden');
    container.appendChild(layer);

    /** The thumb driving the stick, and the one driving the look. */
    let stickTouch: number | null = null;
    let lookTouch: number | null = null;

    let stickFrom = { x: 0, y: 0 };
    let push = { x: 0, y: 0 };
    let turned = { yaw: 0, pitch: 0 };
    let lookFrom = { x: 0, y: 0 };

    const showStick = (at: { x: number; y: number }): void => {
        const box = container.getBoundingClientRect();

        ring.style.left = `${at.x - box.left}px`;
        ring.style.top = `${at.y - box.top}px`;
        ring.classList.remove('hidden');
        knob.classList.remove('hidden');

        moveKnob(at);
    };

    const moveKnob = (at: { x: number; y: number }): void => {
        const box = container.getBoundingClientRect();

        knob.style.left = `${at.x - box.left}px`;
        knob.style.top = `${at.y - box.top}px`;
    };

    const hideStick = (): void => {
        ring.classList.add('hidden');
        knob.classList.add('hidden');
        push = { x: 0, y: 0 };
    };

    const isTouch = (event: PointerEvent): boolean =>
        event.pointerType === 'touch' || event.pointerType === 'pen';

    let showing = false;

    const handleDown = (event: PointerEvent): void => {
        if (!showing || !isTouch(event)) {
            return;
        }

        const box = container.getBoundingClientRect();
        const onTheLeft = event.clientX - box.left < box.width / 2;

        if (onTheLeft && stickTouch === null) {
            stickTouch = event.pointerId;
            stickFrom = { x: event.clientX, y: event.clientY };
            showStick(stickFrom);
        } else if (!onTheLeft && lookTouch === null) {
            lookTouch = event.pointerId;
            lookFrom = { x: event.clientX, y: event.clientY };
        } else {
            return;
        }

        container.setPointerCapture(event.pointerId);
        event.preventDefault();
    };

    const handleMove = (event: PointerEvent): void => {
        if (event.pointerId === stickTouch) {
            const awayX = event.clientX - stickFrom.x;
            const awayY = event.clientY - stickFrom.y;
            const distance = Math.hypot(awayX, awayY);

            // Past the edge of the ring the stick stays at the edge, so a thumb
            // that wanders does not lose the level's forward.
            const held = Math.min(distance, STICK_REACH);
            const scale = distance === 0 ? 0 : held / distance;

            push = {
                x: (awayX * scale) / STICK_REACH,
                y: (awayY * scale) / STICK_REACH,
            };

            moveKnob({
                x: stickFrom.x + awayX * scale,
                y: stickFrom.y + awayY * scale,
            });

            event.preventDefault();

            return;
        }

        if (event.pointerId === lookTouch) {
            turned = {
                yaw:
                    turned.yaw -
                    (event.clientX - lookFrom.x) * LOOK_SENSITIVITY,
                pitch:
                    turned.pitch -
                    (event.clientY - lookFrom.y) * LOOK_SENSITIVITY,
            };

            lookFrom = { x: event.clientX, y: event.clientY };
            event.preventDefault();
        }
    };

    const handleUp = (event: PointerEvent): void => {
        if (event.pointerId === stickTouch) {
            stickTouch = null;
            hideStick();
        }

        if (event.pointerId === lookTouch) {
            lookTouch = null;
        }
    };

    container.addEventListener('pointerdown', handleDown);
    container.addEventListener('pointermove', handleMove);
    container.addEventListener('pointerup', handleUp);
    container.addEventListener('pointercancel', handleUp);

    /** How far over the stick is, with the dead zone taken out. */
    const reach = (): number => {
        const distance = Math.hypot(push.x, push.y);

        return distance < DEAD_ZONE ? 0 : distance;
    };

    return {
        active: true,

        walk: () => {
            if (reach() === 0) {
                return { forward: 0, strafe: 0 };
            }

            // Up the screen is forward, and the engine's forward is -z.
            return { forward: -push.y, strafe: push.x };
        },

        running: () => reach() > RUN_AT,

        takeLook: () => {
            const since = turned;

            turned = { yaw: 0, pitch: 0 };

            return since;
        },

        show: (on) => {
            showing = on;
            layer.classList.toggle('hidden', !on);

            if (!on) {
                stickTouch = null;
                lookTouch = null;
                turned = { yaw: 0, pitch: 0 };
                hideStick();
            }
        },

        dispose: () => {
            container.removeEventListener('pointerdown', handleDown);
            container.removeEventListener('pointermove', handleMove);
            container.removeEventListener('pointerup', handleUp);
            container.removeEventListener('pointercancel', handleUp);
            layer.remove();
        },
    };
}
