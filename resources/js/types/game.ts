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
export type EffectTypeName = 'give_item' | 'remove_item' | 'set_flag';

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
    floorHeight: number;
    ceilingHeight: number;
    floorTexture: string | null;
    ceilingTexture: string | null;
    wallTexture: string | null;
    /** Open to the sky: no ceiling is drawn and the backdrop shows through. */
    isSky: boolean;
    /** The floor runs the water animation and the player wades in it. */
    isWater: boolean;
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
export type ThingRender = 'box' | 'billboard' | 'cross';

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

export type ExplorePageProps = {
    game: Game;
    level: Level;
    inventory: Item[];
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
