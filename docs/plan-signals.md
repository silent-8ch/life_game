# Signals

## What Paul asked for

> maybe we steal from redstone. Have an interactions level where things trigger
> other things. Invisible non-solid things triggering more complex things. All
> lines are either on or off and things interact with it being on or off.

The hinge was one motion, authored once. This is the general case: **named lines
that are on or off, things that put them on, and things that do something while
they are on.**

## What is already here

More than it looks, and the design should not invent a second version of any of
it.

- **A game flag is already a named line that is on or off.** `SetFlag` writes
  one, `FlagIs` and `FlagIsNot` read one, and presence is already the test —
  `.ai/rules` and `GameController::flags` both say a flag nobody has touched is
  absent rather than empty, so *is this set* is a question about the keys.
- **A thing's appearance already reacts to one.** `alt_flag` plus `texture_alt`
  swap the picture while a flag is set. That is a light switch that lights up,
  and it has worked since prop rendering landed.
- **A thing already responds to being acted on.** `RotateThing` and
  `SetBlocking` turn a thing about its hinge and switch its collider, and
  neither knows what it is holding.

So signals are not a new idea to bolt on. They are the same idea evaluated
**per frame** rather than per interaction, and with something other than a verb
allowed to raise them.

## The model

Four nouns. Only the first is new as a concept.

### A signal is a name

A string, on or off, and nothing else. **Signals and flags share one namespace**
rather than sitting beside each other, which is the single most valuable
decision in this document:

- `alt_flag` starts working for signals for free, so a lamp lights while its
  line is on with no new column and no new code.
- `FlagIs` conditions start reading signals for free, so an interaction can be
  gated on one — a door that only opens while the power is on.
- There is one answer to *is `kitchen-door` on*, rather than two that can
  disagree.

The cost is that one name space now has two writers, and the ruling below says
which wins.

### An emitter puts a signal on

A thing gains `emits` (a signal name) and `emit_when`:

| `emit_when` | On while | Redstone |
| --- | --- | --- |
| `used` | Toggled by a `Use`, and stays | a lever |
| `stood_on` | Something is inside its footprint on the floor plan | a pressure plate |
| `inside` | Something is inside its box, in three dimensions | a trigger volume |
| `always` | Always | a torch |

`stood_on` and `inside` are the invisible non-solid things Paul asked for. They
are ordinary things with `is_solid` off and no texture — and an invisible room
(A-22) is the same idea at the scale of a sector, which is worth noticing before
either grows a second implementation.

**Who counts as *something* is a property of the emitter**, not a global rule:
`triggered_by` is the player, actors, or both. A plate only the people can work
is a thing Paul will want within a day of having plates.

### A responder does something while a signal is on

A thing gains a list of **bindings**, and a binding is the whole of the
authoring:

    while <signal> is <on|off>, <response> me to <value>

| Response | Value | Notes |
| --- | --- | --- |
| `rotate` | degrees about the hinge | exists, as `RotateThing` |
| `blocking` | on or off | exists, as `SetBlocking` |
| `visible` | on or off | new, and trivial |
| `move` | an offset in metres | new; a lift, a sliding door |
| `teleport` | a spot | new, and the one that may name the player |

**A binding names a sense** — while on, or while off — and that is what gives
inversion. Inversion plus chaining is real logic: a responder may itself be an
emitter, so a thing that is visible while A is off and emits B while visible is
a NOT gate. Nothing else has to be built for that; it falls out of the two lists
being about the same things.

### A target is a thing, or the player

`subject` on a binding is a thing's slug, and the reserved name `player` is the
option. That is the ruling already made, and it is what makes teleport a signal
response rather than a second kind of portal.

## The rulings already made

1. **The engine owns signal state while the level is being played; the server is
   told.** A plate flips its line *this frame*, not a round trip later. This is
   ISSUE-48's ruling about doors, generalised — and it is why signals cannot
   simply be flags refreshed by the existing per-interaction reload.
2. **Targets are things by default and the player by exception.**

## The frame, which is the one real engine question

Nothing in this engine evaluates a flag per frame today, and plates need it.
Here is what it costs and where it goes.

### The order within a frame

```
walk the player          (already)
walk the actors          (already)
read the emitters        <- new
diff against last frame  <- new
apply what changed       <- new
ease the responders      (already, as the hinge easing)
draw
```

Emitters are read **after** movement, so a plate answers about where people
actually are this frame rather than where they were last frame. What changed is
applied immediately, so a collider that stops blocking does so before the next
frame's movement reads it — the same *state, not animation* rule the hinge
already keeps.

### Why it is cheap

Two indexes built once, at level build:

- **Emitters**, as a flat list. There are a handful in a level and each is one
  point-in-box test per candidate. A level with ten plates and six people is
  seventy tests a frame, which is nothing next to one wall.
- **Signal name → bindings**, as a map. This is the answer to *how does the
  update find the things that care*: it does not look, it is told. Only signals
  that **changed** are looked up, so a frame in which nothing moves costs the
  emitter reads and one set comparison.

The work is therefore proportional to what changed rather than to how many
things a level has, which is the property that makes it safe to leave running
every frame.

### Chains, and the loop they invite

A responder may emit, so signals can drive signals, and a cycle can be authored
by accident or on purpose — redstone clocks are cycles on purpose.

**Settle in a bounded number of passes and stop.** `resolveCollisions` already
does exactly this with `RESOLVE_PASSES`, for the same reason and with the same
honesty: some arrangements do not settle, and the answer is to stop rather than
to hang. A cycle then oscillates at the pass limit rather than freezing the tab,
which is the behaviour a clock wants anyway.

## What persists, and what must not

The distinction is the one redstone already makes, and getting it wrong would be
felt immediately.

- **Latching** signals persist: a lever you threw is still thrown next session.
- **Momentary** signals do not: you are not standing on the plate next session,
  and restoring it would open a door in an empty room.

`emit_when` decides it rather than a separate column — `used` and `always` latch,
`stood_on` and `inside` are momentary. One less thing to author, and no way to
author the pair inconsistently.

Persistence rides the existing flag machinery: a latching signal is a flag, and
`game_flags` already stores it. **The engine posts a change rather than a
frame** — coalesced on transition, so a lever costs one request and a plate
costs two whatever the frame rate.

## The data model

| Where | What |
| --- | --- |
| `level_things` | `emits`, `emit_when`, `triggered_by` |
| `level_thing_bindings` | `thing_id`, `signal`, `sense`, `response`, `subject`, `value` |
| `game_flags` | unchanged; latching signals are flags |

The bindings table rather than columns on the thing, because a thing may respond
to several signals and one signal drives several things — which is the whole
point, and columns cannot hold it.

`level_thing_bindings` is authored in the inspector beside the interaction panel,
which is where somebody already goes to say what a thing does.

## What I would build first

One slice that is useful on its own and proves the frame loop:

1. `emits` / `emit_when` with `used` and `stood_on` only.
2. Bindings with `rotate` and `blocking` only — the two responses that exist.
3. The per-frame read, diff and apply, with the pass limit.
4. A plate in the House that opens the utility door while stood on.

That makes a pressure plate open a door with no new response types and no new
persistence, and everything after it is another row in two tables.

## Open questions for Paul

These change the shape rather than the size, so they are worth settling before
any of it is written.

1. **Should a binding's value be authored per sense, or one value and a
   default?** *While on, rotate to 90* implies *while off, rotate to 0* — but
   only if there is a resting position to return to. Two values per binding is
   more to type and says exactly what happens.
2. **Should an actor be able to work a lever**, or only plates? Wanderers open
   doors already by ruling; a wanderer flipping a switch is a different feeling.
3. **Is a momentary signal a pulse or a level?** A plate held down is a level. A
   button is a pulse. Redstone has both and the difference is a column.
4. **Do signals need to cross levels?** A lever in one room lighting a lamp in
   another is free; a lever in one *level* is not, and the answer decides whether
   a signal name is scoped to a level or to a game.
