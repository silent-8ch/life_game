export type GameSummary = {
    slug: string;
    title: string;
    tagline: string;
    coverImage: string | null;
    inProgress: boolean;
    currentLocationName: string | null;
};

export type Game = {
    slug: string;
    title: string;
};

export type Scene = {
    slug: string;
    name: string;
    description: string;
    backgroundImage: string | null;
    backgroundColor: string;
};

export type Hotspot = {
    slug: string;
    name: string;
    x: number;
    y: number;
    width: number;
    height: number;
};

export type Item = {
    slug: string;
    name: string;
    description: string;
    icon: string | null;
};

export type Verb = {
    value: string;
    label: string;
    acceptsItem: boolean;
};

export type GameIndexProps = {
    games: GameSummary[];
};

export type GamePageProps = {
    game: Game;
    scene: Scene;
    hotspots: Hotspot[];
    inventory: Item[];
    verbs: Verb[];
    message: string | null;
};

export type ThingKind = 'prop' | 'door' | 'window' | 'fixture' | 'actor';

export type VerbName = 'look' | 'use' | 'take' | 'talk';

export type ConditionType =
    'has_item' | 'missing_item' | 'flag_is' | 'flag_is_not';

/**
 * Only the effects that mean anything in a level. Moving to a scene, and
 * revealing or hiding a hotspot, belong to the point-and-click game.
 */
export type EffectTypeName =
    | 'give_item'
    | 'remove_item'
    | 'set_flag'
    /** Turn the thing named by `subject` to `value` degrees about its hinge. */
    | 'rotate_thing'
    /** Whether the thing named by `subject` stops anybody walking through. */
    | 'set_blocking';

export type InteractionCondition = {
    type: ConditionType;
    /** An item slug, or a flag's name. */
    subject: string;
    /** What the flag has to read. Not used by the item conditions. */
    value: string | null;
};

export type InteractionEffect = {
    type: EffectTypeName;
    /** An item slug, or a flag's name. */
    subject: string;
    /** What to set the flag to. Not used by the item effects. */
    value: string | null;
};

/** What a verb does to a thing, once its conditions are met. */
export type ThingInteraction = {
    verb: VerbName;
    /** What the player is told when it fires. */
    response: string;
    /** Higher wins where more than one could fire. */
    priority: number;
    /** An item that has to be in hand for this one, by slug. */
    requiredItem: string | null;
    conditions: InteractionCondition[];
    effects: InteractionEffect[];
};

/**
 * One entry in the menu that opens when the player presses E on a thing. Only
 * the offer travels to a player — never the conditions or what it does.
 */
export type VerbOffer = {
    verb: VerbName;
    /** The item that has to be in hand, by slug, or null for none. */
    item: string | null;
    /**
     * The two effects the engine has to carry out for itself: turning a thing
     * about its hinge, and switching its collider.
     *
     * Nothing else travels. Those two are the only ones whose result the player
     * is standing inside — you walk through a door in the same frame it opens —
     * so waiting for the round trip would make every one of them feel broken.
     * An item appearing in a pocket or a flag being set can wait, and does.
     *
     * Empty for almost every offer in almost every level.
     */
    moves: VerbMove[];
};

/** One thing a verb moves, as the engine is told to move it. */
export type VerbMove = {
    does: 'rotate_thing' | 'set_blocking';
    /** The thing it moves, by slug. */
    subject: string;
    value: string | null;
};

/**
 * A corner of a sector's polygon. It also carries the edge that runs from it to
 * the next corner along, since an edge is only ever between two corners.
 */
export type SectorPoint = {
    x: number;
    z: number;
    wallTexture: string | null;
    /** A shared edge that is still a wall, rather than a way through. */
    blocks: boolean;
    /** Reflects the room back at the player instead of being drawn as a surface. */
    isMirror: boolean;
    /** Shows the sky rather than a surface, and hides whatever is beyond it. */
    isSky: boolean;
    /** Two walls naming the same link are the two ends of one portal. */
    portalLink: string | null;
};

/** A room: a closed polygon with its own floor and ceiling. */
export type Sector = {
    slug: string;
    name: string;
    /**
     * How high the floor is **along its hinge wall**, not everywhere.
     *
     * With `floorSlope` at zero — which is every sector that has not been
     * sloped — that is the whole story and the floor is flat at this height.
     */
    floorHeight: number;
    ceilingHeight: number;
    /** Rise in metres per metre, measured straight into the room. */
    floorSlope: number;
    /**
     * Which wall the floor is hinged on, as an index into `points`. Null when
     * the floor is flat.
     *
     * Stored as an index but maintained by coordinate: every editor operation
     * that rewrites the point list re-finds it, because a bare index silently
     * points at a different wall the moment a corner is inserted.
     */
    floorSlopeEdge: number | null;
    ceilingSlope: number;
    ceilingSlopeEdge: number | null;
    floorTexture: string | null;
    ceilingTexture: string | null;
    wallTexture: string | null;
    /** Open to the sky: no ceiling is drawn and the backdrop shows through. */
    isSky: boolean;
    /** The floor runs the water animation and the player wades in it. */
    isWater: boolean;
    /**
     * The camera sees straight through it: the floor draws and nothing else
     * does — no ceiling, no walls, no props, and nobody standing in it.
     *
     * Not a quieter `isSky`. A sky room drops its ceiling and gets an invisible
     * lid instead, so that a sight-line cannot run out of the level. This is the
     * opposite ruling and deliberately so: whatever lies beyond just shows, and
     * where nothing lies beyond you see the backdrop.
     *
     * Collision is untouched. You walk in as you always did; you are simply not
     * drawn while you are there.
     */
    isInvisible: boolean;
    points: SectorPoint[];
};

export type Sky = {
    /** sky-day, sky-night, sky-sunset. */
    image: string;
    /** Which of the four cells in the sky strip. */
    variant: number;
    /** hills, skyline, forest, and so on. */
    theme: string | null;
    /** Which numbered layers of the theme to stack, furthest first. */
    layers: number[];
};

/**
 * What a person is made of: SPECIAL, in canonical order. Nothing reads these
 * yet — they are defined and shipped, and what they do comes later.
 */
export type Stats = {
    strength: number;
    perception: number;
    endurance: number;
    charisma: number;
    intelligence: number;
    agility: number;
    luck: number;
};

/** A box in the level, positioned by its centre on the floor plan. */
/**
 * How a thing is put on the screen.
 *
 * A box is right for furniture and wrong for anything with a silhouette: a pot
 * plant drawn on the side of a cube reads as a cube with a picture of a plant
 * on it. A billboard turns to face whoever is looking; a cross stands two or
 * three quads in a star and stays put, so a row of plants does not swivel
 * together as the player walks past.
 */
export type ThingRender = 'box' | 'billboard' | 'cross' | 'flat';

/**
 * Which edge a flat thing turns about, seen from its front.
 *
 * A door hinges at a side, a drawbridge at the bottom, a hatch at the top. None
 * of those needed a kind of its own; they needed an edge and an angle.
 */
export type ThingHinge = 'left' | 'right' | 'top' | 'bottom';

/**
 * Whether a thing's texture repeats or is stretched to fit.
 *
 * Tiling suits a surface that carries on past what you can see. Fitting suits a
 * picture of a particular object — a door tiled at the wall scale shows the
 * middle 45% of a door, which looks like bad art rather than bad UVs.
 */
export type ThingUvMode = 'tile' | 'fit';

export type LevelThing = {
    slug: string;
    name: string;
    description: string;
    kind: ThingKind;
    /** Sprite sheet name for an actor, such as krystal. */
    sprite: string | null;
    /** How an actor moves: still, wander. */
    behaviour: string | null;
    /**
     * A person's own numbers, or null to take their sprite's. While playing,
     * this is the resolved block; in the map editor it is the override alone,
     * which is what a save sends back.
     */
    stats: Stats | null;
    /**
     * What their sprite starts with, whether or not they override it. Only the
     * map editor is sent this, to show what is being inherited.
     */
    inheritedStats?: Stats;
    speed: number;
    texture: string | null;
    /** How it is put on the screen. */
    render: ThingRender;
    /** Quads in the star, for a cross prop. 2 or 3; ignored otherwise. */
    planeCount: number;
    /** Whether the texture repeats at the wall scale or fits each face once. */
    uvMode: ThingUvMode;
    /**
     * Drawn instead of `texture` while `altFlag` is set. Null unless `altFlag`
     * is too — the pair is all or nothing, since either alone means nothing.
     */
    textureAlt: string | null;
    /** The game flag that swaps in `textureAlt`. */
    altFlag: string | null;
    /** Frames across the texture strip. 1 is a still picture. */
    animationFrames: number;
    /** How fast those frames advance. */
    animationFps: number;
    x: number;
    z: number;
    elevation: number;
    width: number;
    depth: number;
    height: number;
    angle: number;
    /**
     * Which edge a flat thing turns about, or null for one that does not turn.
     *
     * On the thing rather than on whatever turns it, which is what makes the
     * `rotate` effect generic: it never has to know that it is holding a door
     * rather than a drawbridge or a hatch. Named from the front, the face the
     * thing's own `angle` points at.
     */
    hinge: ThingHinge | null;
    isSolid: boolean;
    /** What the player may try on it. */
    verbs: VerbOffer[];
    /**
     * The whole tree, conditions and effects and all. Only the map editor is
     * sent this; while playing, `verbs` is all there is.
     */
    interactions?: ThingInteraction[];
};

export type Level = {
    slug: string;
    name: string;
    description: string;
    spawn: { x: number; z: number; angle: number };
    ceilingHeight: number;
    /** Which folder of sprite sheets people are drawn from. */
    spriteStyle: string;
    /** Which of them the player themselves is drawn from. */
    playerSprite: string;
    /** What the player starts with, from whoever they are drawn from. */
    playerStats: Stats;
    wallColor: string;
    floorColor: string;
    accentColor: string;
    sky: Sky | null;
    sectors: Sector[];
    things: LevelThing[];
};

/**
 * Every flag the save has set, as names against their values.
 *
 * Only what has been set: a flag nobody has touched is absent rather than
 * empty, so asking whether one is set is a question about the keys and never
 * about telling an unset flag from one set to nothing.
 *
 * This is what makes `LevelThing.altFlag` mean anything. The column names a
 * flag; without this there was no way to find out what that flag read, so the
 * alternate texture could never be shown.
 */
export type Flags = Record<string, string>;

/**
 * Where the player was standing when they last put the game down.
 *
 * The same four numbers `?at=` takes and a debug snapshot writes, in the same
 * units and the same sign — `facing` is the player's own yaw in degrees, **not**
 * a level's `spawn.angle`, which is its negative. Keeping one encoding of "a
 * place in a level" is the point; two that disagree by a sign is how a saved
 * game ends up facing a wall.
 */
export type StandingAt = {
    x: number;
    z: number;
    facing: number;
    pitch: number;
};

/**
 * What a save has done to the things in a level, by slug.
 *
 * Only things something has acted on are here at all, so absent means *as
 * authored* — the same reading `Flags` gets, and the reason neither field needs
 * a value that means nothing.
 *
 * The engine does both of these the instant a verb is chosen rather than
 * waiting for this to arrive, because you walk through a door in the same frame
 * it opens. This is what confirms it afterwards, and what quietly puts back one
 * the save refused.
 */
export type Moved = Record<
    string,
    {
        /** Degrees about the thing's hinge, or null for as authored. */
        turned: number | null;
        /** Whether it stops anybody walking through, or null for as authored. */
        blocking: boolean | null;
    }
>;

export type ExplorePageProps = {
    game: Game;
    level: Level;
    inventory: Item[];
    /**
     * Refreshed after every interaction, alongside the inventory — a switch you
     * flip should light up when you use it rather than next time the level
     * loads. The level itself is deliberately not refreshed with them.
     */
    flags: Flags;
    /**
     * Where to put the player, or null to use the level's spawn. Only ever set
     * when it is this level they were last standing in.
     */
    standingAt: StandingAt | null;
    /** Refreshed after every interaction, alongside the flags and inventory. */
    moved: Moved;
    message: string | null;
};

/** What the map editor has to build levels out of. */
export type LevelAssets = {
    textures: string[];
    /**
     * Cutout art for props, listed apart from the tiling textures because they
     * are a different kind of picture and belong in a different picker.
     */
    props: string[];
    skies: string[];
    /** Backdrop themes, and the numbered layers each one has. */
    backdrops: Record<string, number[]>;
    /** The people who can be placed in a level, tallest first. */
    sprites: string[];
    /** The game's items, for what a verb needs and what an effect hands over. */
    items: { slug: string; name: string }[];
};

export type EditorPageProps = {
    game: Game;
    level: Level;
    levelId: number;
    assets: LevelAssets;
};
