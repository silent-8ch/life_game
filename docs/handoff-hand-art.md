# Handoff: first-person hand cards

For whoever is generating the images. Read the two rules first — they are the
ones that make the difference between a set that works and a set that drifts.

---

## Rule 1 — never feed your own output back in

**Every image is a fresh generation from the fixed prompt and the fixed real reference.
Never from an image you produced.**

Concretely, all of these are forbidden:

- img2img, inpainting, or a "refine/upscale/fix the hand" pass over a card you just made
- using a card you made as a style or character reference for the next card
- generating a sheet of several poses in one image and cutting it up
- "make another one like that one"

Each of the 24 cards is an independent generation. Same prompt template, same real
reference, different pose sentence. If a card comes out wrong, **throw it away and generate
again from the prompt** — do not correct it from itself. Small errors compound into invented
anatomy: six-fingered hands, thumbs on both sides, a watch that migrates up the arm.

The one thing that *is* allowed to touch an output is `normalise_hand.py` below, which is
deterministic arithmetic with no model in it.

The batching ban is not theoretical. The previous set was made as 1774×887 two-pose sheets
and cut into 887×887 halves at the wrong offsets — `paul-fist.png` ended up containing the
right half of a palm-open hand and the left half of a fist, both sliced through the middle.
One image per generation avoids the whole class of problem.

## Rule 2 — measure, don't look

Everything checkable is checked by a script in `docs/tools/`. Do not judge size, centring,
transparency or framing by eye; you will be wrong in ways that only show up once the card is
hanging in front of a moving camera.

```
python3 docs/tools/pnginspect.py  <file.png>        # what is actually in this file
python3 docs/tools/normalise_hand.py <raw> <out>    # put it on spec (deterministic)
python3 docs/tools/verify_hands.py public/sprites/hands   # the gate; exit 0 means done
```

They are standard-library Python only — no Pillow, no ImageMagick, both of which are absent
on this machine. `verify_hands.py` exits with the number of problems, so it drops into a
loop or CI directly.

**The workflow per card is: generate → `normalise_hand.py` → `verify_hands.py`.** If verify
still complains after normalising, the complaint will say `REGENERATE` — that means pixels
are missing and no amount of arithmetic will bring them back.

---

## What these are

`resources/js/lib/engine/hands.ts` hangs two cards off the camera at the bottom of the
first-person view — the player's own hands. One card is the drawing as made, the other is
the same drawing mirrored, so **one image serves both hands**. They swing on a tally of
metres walked.

The card is 0.42 m wide, held 0.6 m from the eye. Because both hands come from one image and
every pose is drawn on an identically sized card, **a hand that is a different size or sits
in a different place from one pose to the next visibly jumps** when the pose changes. That is
the single most important thing this spec exists to prevent.

Six people: `krystal`, `luke`, `luna`, `paul`, `wade`, `william`.

---

## The spec

| Property | Value | Why |
| --- | --- | --- |
| Canvas | **887 × 887** | what the engine already loads |
| Format | **PNG, colour type 6 (RGBA)**, 8-bit | type 2 or a white background renders as a white box over the view |
| Background | **fully transparent, alpha exactly 0** | see the halo note |
| Wrist width | **140 px ± 6** | the cross-pose anchor |
| Wrist centre x | **443 px ± 6** (canvas centre) | so poses do not slide sideways |
| Lowest drawn row | **y = 850 ± 4** | the hand enters from the bottom of the view |
| Nothing touching any edge | left, right, top clear | a clipped hand cannot be repaired |
| Colour count | **> 200 distinct** | see the style note |

"Wrist width" is the mean silhouette width over the bottom tenth of the drawing. It is the
anchor because it is the one feature every pose shares — a fist is much shorter than an open
hand, so normalising on overall height would make the fist enormous.

140 rather than something rounder because it is set by the tallest hand in the set: at a
wider target, an open hand with a narrow wrist scales up until its fingers run off the top of
the canvas, and those pixels cannot be recovered.

**Draw every pose of a person at the same scale.** Measured across the delivered `edge` and
`edge-open` sets, fists came back drawn 1.3× to 2.5× larger than open hands — for all six
people. The normaliser corrects it, but only by shrinking the fist a long way, which throws
away resolution that was drawn. Nothing about a fist makes the wrist wider than the same
person's open hand; keep the framing consistent and the normaliser barely has to touch it.

`normalise_hand.py` hits all of the numeric rows automatically. You are responsible for the
format, the transparency, and nothing being clipped.

### Style

The existing cards are **not** indexed pixel art, despite looking like it. Measured:
17,000–30,000 distinct colours per file, with no consistent pixel-block grid (best-fit block
size 2 px, explaining barely 60% of the silhouette steps). They are smooth high-resolution
renders *in a chunky pixel-art style* — heavy black outline, flat-ish cel shading, a few
highlight steps.

So: **do not produce true low-resolution indexed pixel art.** It will not match the twelve
cards already in the game. `verify_hands.py` fails anything under 200 colours for this
reason.

Every hand wears a **dark grey/black wristwatch band** across the wrist, just above the crop.
It is on every existing card and is part of the silhouette.

### Skin, per person

Measured off each person's own existing `-edge-open.png`, so these are the house values, not
guesses. Match the dominant tone within about 10% per channel.

| Person | Dominant | Shadow | Highlight |
| --- | --- | --- | --- |
| krystal | `#F8B880` | `#A84840` | `#F8B880` |
| luke | `#F8B070` | `#983828` | `#F8D0A8` |
| luna | `#F8C8A0` | `#904028` | `#F8D0B0` |
| paul | `#F08838` | `#481800` | `#F8B068` |
| wade | `#F8B888` | `#380800` | `#F8C8A8` |
| william | `#F8B068` | `#983810` | `#F8D8B0` |

Paul's is noticeably more saturated and orange than the rest. That is how the existing art
is; keep it, so his hands still read as his.

The full-body sprite sheets in `public/sprites/realistic/{who}-realistic-cardinal-aligned-4step.png`
(1024×1024) are the **real source material** for what each person looks like. Use those as
reference. Do not sample skin tone from them automatically — a naive sampler picks Krystal's
hair, not her skin; that is why the table above comes from the hand art.

---

## What to make

24 new cards — six people × four poses. Existing files are listed for reference; do not
regenerate them.

| Pose suffix | What it shows | Status |
| --- | --- | --- |
| `edge-open` | flat hand seen edge on, fingers straight, as it swings while walking | **exists** — normalise only |
| `edge` | fist seen edge on | **exists** — normalise only |
| `back` | open hand, **back of the hand to the viewer**, fingers up | **make** |
| `back-fist` | fist, back of the hand to the viewer, knuckles toward the viewer | **make** |
| `palm` | open hand, **palm to the viewer**, fingers up | **make** |
| `palm-fist` | fist seen from the palm/front side | **make** |

Filenames: `public/sprites/hands/{person}-{suffix}.png`, all lower case.

Both hands come from one drawing, so **draw one hand only**, never a pair.

The engine also loads `public/sprites/hands/overlays/{wand,pistol,tablet,phone}.png` for
held items. Those are not part of this job.

### The twelve that already exist

All twelve pass on format and none is actually clipped, but every one of them fails the
framing spec. Measured: wrist width ranges **118 px to 229 px** (nearly a factor of two),
wrist centre x from 459 to 549, lowest row from 738 to 845. Each also carries 1,700–5,600
pixels of near-invisible alpha (1–24) — a halo left by a soft eraser or a resize, which
inflates the apparent bounding box and can show as a faint fringe.

Fix them with the normaliser. **No regeneration, no model involved:**

```
for f in public/sprites/hands/*-edge.png public/sprites/hands/*-edge-open.png; do
    python3 docs/tools/normalise_hand.py "$f" "$f.norm" && mv "$f.norm" "$f"
done
```

(Verified on three of them: wrist lands on 179–180 px, centre on 443–444, bottom on 850,
halo zero, art undamaged.)

---

## Prompt template

One generation per card. Fill in the three braces; change nothing else between cards of the
same person.

> A single {LEFT/RIGHT} human hand, {POSE SENTENCE}, drawn in a chunky retro video-game
> style: heavy solid black outline, flat cel shading in three or four tones, small bright
> highlight steps. Skin tone {DOMINANT HEX}, shadows {SHADOW HEX}. A dark grey wristwatch
> band across the wrist. The wrist is cut off square at the bottom edge of the frame. One
> hand only, no arm beyond the wrist, no second hand. Centred, fully transparent
> background, nothing touching the edges of the frame.

Pose sentences:

| Pose | Sentence |
| --- | --- |
| `back` | fingers together and pointing up, palm away from the viewer, showing the back of the hand and the fingernails |
| `back-fist` | closed in a fist with the knuckles toward the viewer, seen from the back of the hand |
| `palm` | fingers together and pointing up, palm flat toward the viewer, thumb out to one side |
| `palm-fist` | closed in a fist seen from the palm side, fingers curled toward the viewer, thumb across the front |

Character likeness comes from the person's sheet in `public/sprites/realistic/` — match age
and build, not just skin tone. William is the smallest (1.55 m), Paul the tallest (1.85 m).

### Handedness

The engine mirrors the drawing for the other hand, so whichever hand you draw, **be
consistent within a person and record which one it was.** The existing art is not
consistent — `hands.ts` carries a `DRAWN` table with an entry per person *per pose* because
Paul's and Wade's fists came back facing the opposite way to their own open hands, and
William's pair face the opposite way to everyone else's.

Do not try to fix that by mirroring the old files. Just report, per card you make, which
hand it is — left or right — and the table gets set from your list. A back-of-hand or palm
view shows the thumb clearly, so this is unambiguous for the four new poses in a way it was
not for the edge-on ones.

Deliver that as a plain table alongside the files:

```
paul       back        right
paul       back-fist   right
paul       palm        right
...
```

---

## Done means

```
python3 docs/tools/verify_hands.py public/sprites/hands
```

prints `0 failing, 0 missing, 36 good` and exits 0. Nothing else counts as done — not "looks
right", not "close enough".

Hand back:

1. The 24 new PNGs in `public/sprites/hands/`.
2. The 12 existing ones, normalised in place.
3. The handedness table above, one line per new card.
4. The verify output.
