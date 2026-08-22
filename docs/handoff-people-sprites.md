# Handoff: people sprites, and how to get a walk out of a generator

For whoever is generating the images. **Read `docs/handoff-hand-art.md` first** — its two
governing rules apply here unchanged.

This replaces the existing `realistic` sheets. Those were reverted in `147a3c5` because
successive attempts kept coming back with all frames looking the same. That is a predictable
failure and most of this brief is arranged around avoiding it rather than around drawing.

---

## Why the last attempts failed, because it decides the shape of the work

**Every frame was an independent generation of the same character, and nothing carried between
them.** Proportions, clothing and colour drift; ask for four frames of a walk and you get four
slightly different people, or — the failure actually seen — four frames so cautious they are
identical and nothing animates.

This project has already proved it at smaller scale. The hand cards are one file per pose,
generated fresh, and came back with **fists drawn 1.3× to 2.5× larger than the open hands, for
every person.** Same failure, fewer images.

**So the arrangement below reduces the number of independent generations rather than trying to
write a better prompt.** Three things do that work: heads are separate, only three poses exist,
and left/right directions are mirrored rather than drawn.

---

## What gets drawn

### Three poses, and only three

| Pose | Body |
| --- | --- |
| `stand` | **feet together, standing.** This is the base. |
| `stride-a` | **left leg forward, right arm forward.** |
| `stride-b` | **right leg forward, left arm forward.** The opposite of `stride-a`, drawn, not mirrored. |

**`stride-b` cannot be got by mirroring `stride-a`.** Mirroring a side-on figure turns it to
face the other way — it produces a valid *other direction*, not the other leg. That only works
for dead front and dead back, so all three are drawn for every direction.

### Five directions, drawn; three mirrored

Draw **0° (front), 45°, 90° (side), 135°, 180° (back)**. The engine mirrors those to get 315°,
270° and 225°. The angle is **where the viewer stands relative to the body**, not the way the
body is turned — those are opposites and the old sheets got it wrong in both directions.

**A mirrored direction has the opposite leg leading**, because mirroring swaps left and right.
The engine handles this by offsetting the mirrored frame by half a cycle. **Nothing is required
of you for it** — it is here so nobody "fixes" a mirrored figure that looks out of step.

### Heads are separate files

**Not mainly for emotion — for consistency.** A body whose jacket shifts slightly still reads
as the same person; a face that shifts reads as somebody else. Taking the head out removes the
hardest constraint from the images generated most often.

**Five expressions:** `neutral`, `happy`, `sad`, `angry`, `surprised`. Each in all five drawn
directions. A head at 45° is not a head at 0°.

---

## The count

Per person: **15 bodies** (5 directions × 3 poses) and **25 heads** (5 directions × 5
expressions). Across six people, **90 bodies and 150 heads**.

## ⚠ Deliver ONE person's body set first, and stop

**15 images, one person, nothing else.** Not one person's everything, not all six bodies — one
body set. Paul checks the directions and the animation, accepts or rejects, and only then does
the rest of the cast get generated.

**Why the batch is one person and not six.** Every failure this has had so far was systematic:
all frames identical, every fist oversized, every sheet drawn to its own order. A fault in the
approach shows in the first fifteen images exactly as clearly as in ninety, and costs a
fifteenth as much to find.

**Deliver it through the sprite checker.** `public/sprite-check.html` shows all eight directions
side by side and **plays the walk cycle in each**, including the three mirrored ones with the
half-cycle offset applied — which is the only way to see whether turning reads correctly, since
it cannot be judged from still images. **That page is where Paul accepts the sprites.**

## Naming, because the checker and the manifest both depend on it

Files go in **a versioned subfolder under `public/sprites/people/`**. The old `realistic`
sheets were a different format and were reverted; nothing should land beside them.

```
public/sprites/people/v1/paul-000-stand.png
                         paul-045-stride-a.png
                         paul-090-stride-b.png
                         paul-head-135-neutral.png
```

**Degrees zero-padded to three** so they sort. Directions drawn: `000 045 090 135 180`. Poses:
`stand stride-a stride-b`. Moods: `neutral happy sad angry surprised`.

### Versions, and why they exist

**A new attempt goes in a new folder — `v2`, `v3` — and never overwrites the last.** This is
not tidiness. The previous set was *reverted in git* (`147a3c5`) because it was worse than what
it replaced, and a revert is a bad way to say no to art: it rewrites history to undo something
that was never wrong to have tried. **With versions, a batch that misses simply does not get
promoted, and it stays on disk to compare against.**

**The checker shows versions side by side**, which is the point — "is v2 better than v1" is a
question you can only answer with both walking at once.

**Which version the game draws is a config setting**, named explicitly. Not the highest number:
a half-delivered `v3` would otherwise go live the moment its first file landed. **So delivering
a version changes nothing until somebody names it**, and reverting is editing one line rather
than moving files.

**Start at `v1`.** There is no live version yet — the reverted sheets were the old format.

---

## Rule 1 — one image per generation. Never a sheet.

**This is the rule that was broken last time and it is the whole diagnosis.** A generator asked
for four frames in one canvas stops attending partway through, and the later cells come back as
copies of the earlier ones. That is exactly the reported symptom — *all the frames are the
same*.

One pose, one direction, one image, every time. **The sheet is assembled programmatically
afterwards, never drawn as a sheet.**

## Rule 2 — describe the body, never the frame number

A generator has no idea what "frame 2 of 4" means and will not admit it. It understands a body.

> weight on the left foot, right leg swinging forward, left arm forward, right arm back

Every pose described as a **standalone body position**, with the contact points said out loud —
which foot is flat on the ground. A figure with no planted foot reads as floating rather than
walking, and that is the difference between a walk cycle and three pictures of a person.

## Rule 3 — the reference is for identity, not for copying

Use the character reference so it is recognisably the same person. **It should not come back
looking exactly like the reference.**

- **The outfit goes in the prompt**, so most of what is drawn is new rather than copied.
- **No gear** — no backpacks, no bags, nothing carried or strapped on.
- **Tennis shoes**, of a kind that suits the person.
- **Neutral expression on every body.** The face is coming from the head file; a body generated
  with a strong expression fights the head that lands on it.

## Rule 4 — the neck must land in the same place every time

A separated head has to register to the same point across **all 15 body poses in every
direction**, or heads jump about as people walk.

**This project has solved this shape of problem once already.** `docs/tools/normalise_hand.py`
exists because hand cards had to align at the wrist. A head-to-neck registration tool is the
same job in a different place, and it is the tool to write before the heads are generated
rather than after.

---

## Size — undecided, and decided by one person's worth of work

Generating small and upscaling programmatically **genuinely helps consistency** — less detail
means less to drift, and the upscale is deterministic so it introduces none. It also pulls
against a decision made hours earlier: `PIXEL_SCALE` was removed so the game draws at the size
it is shown at, and the surface textures went to 768.

**So do not choose it in the abstract. Generate one person's 15 bodies twice** — once small and
upscaled, once at target size — put both in the game, and look. Then commit the other five
people to whichever won.

---

## Done means

There is **no batch gate for people sprites**, and one should be written before the set is
judged. `verify_hands.py` is hand-specific, `verify_props.py` prop-specific, `verify_textures.py`
texture-specific. The equivalent here checks the count is right, every file is present for every
person, direction and pose, dimensions match, and — the only interesting one — **the neck
registers within a pixel or two across every body of a given person and direction.**

Two tools went with the revert and may need rebuilding: `extract_sprite_alpha.py` and
`normalise_sprite_sheet.py`. **Check whether either was worth keeping before writing anything
new** — they may already be most of the registration job.

**Hand back the files, and a note of anything you had to change from this brief.**
