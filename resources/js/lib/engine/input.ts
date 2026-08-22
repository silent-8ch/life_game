import { MOUSE_SENSITIVITY } from '@/lib/engine/constants';
import { HELD_ITEMS } from '@/lib/engine/hands';
import type { HeldItem } from '@/lib/engine/hands';
import type { Push } from '@/lib/engine/player';
import { createTouchControls } from '@/lib/engine/touch';

/**
 * Everything the player does with their hands: keys, the mouse, the pointer
 * lock, full screen, and the buttons a phone gets instead of all of that.
 *
 * It knows nothing about three.js and nothing about what any of it means. It
 * reports how hard the player is pushing and which way they have turned, and
 * calls back for the things that are not movement.
 *
 * The touch controls are built in here rather than handed in, because the
 * knot is otherwise unpickable: the Stop button has to call `stop()`, and
 * `stop()` has to hide the controls.
 */

const FORWARD_KEYS = ['KeyW', 'ArrowUp'];
const BACKWARD_KEYS = ['KeyS', 'ArrowDown'];
const LEFT_KEYS = ['KeyA', 'ArrowLeft'];
const RIGHT_KEYS = ['KeyD', 'ArrowRight'];

/** What input does that is not walking or looking. */
export type InputActions = {
    examine: () => void;
    /** Null where there is no magic — nobody but the wizard gets the keys. */
    markHere: (() => void) | null;
    recall: (() => void) | null;
    takeInHand: (item: HeldItem | null) => void;
    takeSnapshot: () => void;
    /** A click while already playing: casting, if the player can cast. */
    fire: () => void;
    /** A turn of the head, applied at once rather than at the next frame. */
    look: (turned: { yaw: number; pitch: number }) => void;
    /** Whether something on top of the level is holding the keys. */
    held: () => boolean;
    onLockChange: (locked: boolean) => void;
    onPlaying: (playing: boolean) => void;
    onFullscreen: (full: boolean) => void;
};

export type GameInput = {
    /**
     * What the player is asking for this frame, and whether anything on top of
     * the level has the keys instead.
     */
    read: () => {
        holding: boolean;
        push: Push;
        turned: { yaw: number; pitch: number };
    };
    /** Whether the player is asking to run, for anything outside the frame. */
    running: () => boolean;
    dispose: () => void;
};

export function createInput(
    container: HTMLElement,
    touch: boolean,
    actions: InputActions,
): GameInput {
    const pressed = new Set<string>();

    // A jump is an event, not a held key: set by the keydown or the button and
    // taken by the next `read()`. Held down, Space would otherwise ask to jump
    // on every frame and the player would bounce off the floor for as long as
    // the finger was there.
    let jumpAsked = false;

    // On a phone there is no pointer to lock, so playing is just a flag, set by
    // the tap that starts the level and cleared by the Stop button.
    let started = false;

    // What the level thought the answer to "is something on top of me" was last
    // frame, so the controls are only shown or hidden when it changes.
    let waiting = false;

    // On a phone there is nothing to lock, so playing is the flag itself.
    const isLocked = (): boolean =>
        touch ? started : document.pointerLockElement === container;

    const isFullscreen = (): boolean =>
        document.fullscreenElement === container;

    const handleKeyDown = (event: KeyboardEvent): void => {
        if (!isLocked() || actions.held()) {
            return;
        }

        pressed.add(event.code);

        if (event.code.startsWith('Arrow') || event.code === 'Space') {
            event.preventDefault();
        }

        if (event.code === 'Digit0' && !event.repeat) {
            actions.takeInHand(null);
        }

        HELD_ITEMS.forEach((item, index) => {
            if (event.code === `Digit${index + 1}` && !event.repeat) {
                actions.takeInHand(item);
            }
        });

        if (event.code === 'Space' && !event.repeat) {
            jumpAsked = true;
        }

        if (event.code === 'KeyM' && !event.repeat) {
            actions.markHere?.();
        }

        if (event.code === 'KeyR' && !event.repeat) {
            actions.recall?.();
        }

        if (event.code === 'KeyE' && !event.repeat) {
            actions.examine();
        }

        if (event.code === 'KeyF' && !event.repeat) {
            actions.takeSnapshot();
        }
    };

    const handleKeyUp = (event: KeyboardEvent): void => {
        pressed.delete(event.code);
    };

    const handleMouseMove = (event: MouseEvent): void => {
        if (!isLocked() || actions.held()) {
            return;
        }

        actions.look({
            yaw: -event.movementX * MOUSE_SENSITIVITY,
            pitch: -event.movementY * MOUSE_SENSITIVITY,
        });
    };

    const handleBlur = (): void => {
        pressed.clear();
        jumpAsked = false;
    };

    /** Play and full screen are the same state, so they come and go together. */
    const handleLockChange = (): void => {
        const locked = isLocked();

        if (!locked) {
            pressed.clear();
            jumpAsked = false;

            if (isFullscreen()) {
                void document.exitFullscreen().catch(() => undefined);
            }
        }

        actions.onLockChange(locked);
    };

    const handleFullscreenChange = (): void => {
        const full = isFullscreen();

        actions.onFullscreen(full);

        // Escape leaves full screen first; let go of the mouse as well.
        if (!full && isLocked()) {
            document.exitPointerLock();
        }
    };

    /**
     * Puts the phone down: back to the page, controls off.
     *
     * Reaches forward to `controls`, which is built below it, because the Stop
     * button in `controls` reaches back to this. One of the two has to; the
     * button cannot be pressed before the thing it sits on exists, so this is
     * the side that is safe.
     */
    const stop = (): void => {
        started = false;
        controls.show(false);
        actions.onPlaying(false);
        pressed.clear();
        jumpAsked = false;
        actions.onLockChange(false);

        if (isFullscreen()) {
            void document.exitFullscreen().catch(() => undefined);
        }
    };

    const handleClick = (): void => {
        if (isLocked()) {
            // Already playing, so a click is a click in the game rather than a
            // click to start it.
            actions.fire();

            return;
        }

        if (touch) {
            // No pointer to lock and, on a phone, often no full screen to ask
            // for either. The frame fills the screen itself instead.
            started = true;
            controls.show(true);
            actions.onPlaying(true);
            actions.onLockChange(true);
        } else {
            try {
                const request: unknown = container.requestPointerLock();

                if (request instanceof Promise) {
                    // A refused lock is the browser's business, not an app
                    // error.
                    request.catch(() => undefined);
                }
            } catch {
                // Older browsers throw here instead of rejecting.
            }
        }

        if (!isFullscreen() && container.requestFullscreen !== undefined) {
            // Refused full screen is no reason to stop the player playing.
            void container.requestFullscreen().catch(() => undefined);
        }
    };

    /**
     * The keys, as buttons. Only built on a touch screen; on anything else
     * createTouchControls hands back a set that draws nothing.
     */
    const controls = createTouchControls({
        container,
        buttons: [
            {
                label: 'Look',
                title: 'Examine what you are looking at',
                press: actions.examine,
            },
            ...(actions.markHere === null || actions.recall === null
                ? []
                : [
                      {
                          label: 'Mark',
                          title: 'Leave a mark here',
                          press: actions.markHere,
                      },
                      {
                          label: 'Recall',
                          title: 'Go back to the mark',
                          press: actions.recall,
                      },
                  ]),
            ...HELD_ITEMS.map((item) => ({
                label: item,
                title: `Take the ${item}`,
                press: () => actions.takeInHand(item),
            })),
            {
                label: 'Empty',
                title: 'Empty your hands',
                press: () => actions.takeInHand(null),
            },
            {
                label: 'Snap',
                title: 'Save a snapshot of this spot',
                press: actions.takeSnapshot,
            },
            {
                label: 'Jump',
                title: 'Jump',
                press: () => {
                    jumpAsked = true;
                },
            },
            { label: 'Stop', title: 'Stop playing', press: stop },
        ],
    });

    container.addEventListener('click', handleClick);
    window.addEventListener('keydown', handleKeyDown);
    window.addEventListener('keyup', handleKeyUp);
    window.addEventListener('blur', handleBlur);
    document.addEventListener('mousemove', handleMouseMove);
    document.addEventListener('pointerlockchange', handleLockChange);
    document.addEventListener('fullscreenchange', handleFullscreenChange);

    /** A stick reads anywhere between nothing and all the way over; a key is
     * only ever one or the other. Whichever is pushed harder wins. */
    const pick = (keyed: number, pushed: number): number =>
        Math.abs(pushed) > Math.abs(keyed) ? pushed : keyed;

    /** Hands over a jump if one was asked for, and forgets it either way. */
    const takeJump = (): boolean => {
        const asked = jumpAsked;
        jumpAsked = false;

        return asked;
    };

    const running = (): boolean =>
        pressed.has('ShiftLeft') ||
        pressed.has('ShiftRight') ||
        controls.running();

    const read = (): ReturnType<GameInput['read']> => {
        // Whatever is on top of the level has the keys while it is open. Let go
        // of anything still held down and throw away any drag, so that the
        // player does not walk off while they are reading. The level itself
        // carries on: people keep walking, spells keep burning.
        const holding = actions.held();

        if (holding !== waiting) {
            waiting = holding;

            // The stick and the buttons sit over the level, so they would
            // swallow the taps meant for whatever is asking.
            controls.show(started && !holding);
        }

        if (holding) {
            pressed.clear();
            jumpAsked = false;
            controls.takeLook();
        }

        const pushed = holding ? { forward: 0, strafe: 0 } : controls.walk();
        const turned = holding ? { yaw: 0, pitch: 0 } : controls.takeLook();

        return {
            holding,
            turned,
            push: {
                forward: pick(
                    (FORWARD_KEYS.some((key) => pressed.has(key)) ? 1 : 0) -
                        (BACKWARD_KEYS.some((key) => pressed.has(key)) ? 1 : 0),
                    pushed.forward,
                ),
                strafe: pick(
                    (RIGHT_KEYS.some((key) => pressed.has(key)) ? 1 : 0) -
                        (LEFT_KEYS.some((key) => pressed.has(key)) ? 1 : 0),
                    pushed.strafe,
                ),
                running: running(),
                jumping: takeJump(),
            },
        };
    };

    return {
        read,
        running,
        dispose: () => {
            if (isLocked()) {
                document.exitPointerLock();
            }

            if (isFullscreen()) {
                void document.exitFullscreen().catch(() => undefined);
            }

            container.removeEventListener('click', handleClick);
            window.removeEventListener('keydown', handleKeyDown);
            window.removeEventListener('keyup', handleKeyUp);
            window.removeEventListener('blur', handleBlur);
            document.removeEventListener('mousemove', handleMouseMove);
            document.removeEventListener('pointerlockchange', handleLockChange);
            document.removeEventListener(
                'fullscreenchange',
                handleFullscreenChange,
            );
            controls.dispose();
        },
    };
}
