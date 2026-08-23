<?php

namespace App\Http\Requests;

use App\Enums\ActorBehaviour;
use App\Enums\BindingResponse;
use App\Enums\ConditionType;
use App\Enums\EffectType;
use App\Enums\EmitWhen;
use App\Enums\LineLogic;
use App\Enums\ThingHinge;
use App\Enums\ThingKind;
use App\Enums\ThingRender;
use App\Enums\ThingUvMode;
use App\Enums\TriggeredBy;
use App\Enums\Verb;
use App\Models\Item;
use App\Models\Level;
use App\Models\LevelSector;
use App\Models\LevelSectorEdge;
use App\Models\LevelVertex;
use App\Services\LevelAssets;
use App\Services\PersonStats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * A whole map as the editor drew it: the level's own settings, and every sector
 * with its corners. What arrives replaces what is stored.
 */
class UpdateLevelMapRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Level $level */
        $level = $this->route('level');

        return $this->user()?->can('update', $level) ?? false;
    }

    /**
     * Say why, and do not take the page away.
     *
     * The default is a 403 page, which is the one answer you must not give
     * somebody holding unsaved work — the editor would navigate and the draft
     * would go with it. A validation failure keeps them where they are and
     * arrives in the editor's `onError` with something to read.
     */
    protected function failedAuthorization(): never
    {
        /** @var Level $level */
        $level = $this->route('level');

        throw ValidationException::withMessages([
            'save' => $level->owner === null
                ? 'That did not save. Sign in and try again — your work is still on this page.'
                : "{$level->owner->name} drew this level, so only they can save changes to it. Your work is still on this page.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $assets = app(LevelAssets::class);
        $textures = Rule::in($assets->textures());
        $props = Rule::in($assets->props());

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'playerSprite' => ['required', 'string', Rule::in($assets->sprites())],
            'spriteStyle' => ['required', 'string', Rule::in($assets->styles())],
            'spawn' => ['required', 'array'],
            'spawn.x' => ['required', 'numeric'],
            'spawn.z' => ['required', 'numeric'],
            'spawn.angle' => ['required', 'numeric'],
            'ceilingHeight' => ['required', 'numeric', 'min:0.5', 'max:64'],

            'sky' => ['nullable', 'array'],
            'sky.image' => ['required_with:sky', 'string', Rule::in($assets->skies())],
            'sky.variant' => ['required_with:sky', 'integer', 'between:0,3'],
            'sky.theme' => ['nullable', 'string', Rule::in(array_keys($assets->backdrops()))],
            'sky.layers' => ['nullable', 'array'],
            'sky.layers.*' => ['integer', 'between:1,9'],

            'sectors' => ['present', 'array', 'max:200'],
            'sectors.*.slug' => ['required', 'string', 'max:255'],
            'sectors.*.name' => ['required', 'string', 'max:255'],
            'sectors.*.floorHeight' => ['required', 'numeric', 'between:-64,64'],
            'sectors.*.ceilingHeight' => ['required', 'numeric', 'between:-64,64'],
            'sectors.*.floorSlope' => ['sometimes', 'numeric', 'between:-8,8'],
            'sectors.*.floorSlopeEdge' => ['nullable', 'integer', 'min:0'],
            'sectors.*.ceilingSlope' => ['sometimes', 'numeric', 'between:-8,8'],
            'sectors.*.ceilingSlopeEdge' => ['nullable', 'integer', 'min:0'],
            'sectors.*.floorTexture' => ['nullable', 'string', $textures],
            'sectors.*.ceilingTexture' => ['nullable', 'string', $textures],
            'sectors.*.wallTexture' => ['nullable', 'string', $textures],
            'sectors.*.isSky' => ['required', 'boolean'],
            'sectors.*.isWater' => ['required', 'boolean'],
            // Nullable rather than required, unlike the two above it. Those
            // have been in the payload since the editor could draw a room; a
            // map saved by anything that has not been reloaded since invisible
            // rooms landed will not mention this one, and refusing that save
            // would lose somebody's work over a flag they never set.
            'sectors.*.isInvisible' => ['nullable', 'boolean'],
            'sectors.*.points' => ['required', 'array', 'min:3', 'max:64'],
            'sectors.*.points.*.x' => ['required', 'numeric', 'between:-512,512'],
            'sectors.*.points.*.z' => ['required', 'numeric', 'between:-512,512'],
            'sectors.*.points.*.wallTexture' => ['nullable', 'string', $textures],
            'sectors.*.points.*.blocks' => ['required', 'boolean'],
            'sectors.*.points.*.isMirror' => ['required', 'boolean'],
            'sectors.*.points.*.isSky' => ['nullable', 'boolean'],
            'sectors.*.points.*.portalLink' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],

            'things' => ['present', 'array', 'max:200'],
            'things.*.slug' => ['required', 'string', 'max:255'],
            'things.*.name' => ['required', 'string', 'max:255'],
            'things.*.description' => ['required', 'string'],
            'things.*.kind' => ['required', Rule::enum(ThingKind::class)],
            'things.*.sprite' => ['nullable', 'string', Rule::in($assets->sprites())],
            'things.*.behaviour' => ['nullable', Rule::enum(ActorBehaviour::class)],

            // A person's own numbers, or nothing at all and they get their
            // sprite's. All seven or none: a half-written block reads fine in
            // the editor and surprises you two months later.
            'things.*.stats' => ['nullable', 'array', 'size:'.count(PersonStats::ATTRIBUTES)],
            'things.*.stats.*' => ['required', 'integer', 'between:'.PersonStats::MINIMUM.','.PersonStats::MAXIMUM],
            'things.*.speed' => ['required', 'numeric', 'between:0,10'],
            // Either kind of picture. A box wants a tiling texture; a billboard
            // or a cross wants cutout art from the props folder, and which one
            // is right depends on how the thing is drawn rather than on what it
            // is. Accepting both here and letting the picker offer the sensible
            // one beats two columns that mean the same thing.
            'things.*.texture' => ['nullable', 'string', Rule::in([...$assets->textures(), ...$assets->props()])],
            // Omittable rather than required, the way stats already are. Every
            // one has a column default that is what a thing was doing before
            // there was a choice, so a payload that says nothing about how a
            // thing is drawn still saves and still draws the same. Checked
            // strictly when they are sent.
            'things.*.render' => ['sometimes', Rule::enum(ThingRender::class)],
            'things.*.hinge' => ['nullable', Rule::enum(ThingHinge::class)],

            'things.*.emitWhen' => ['nullable', Rule::enum(EmitWhen::class)],
            'things.*.triggeredBy' => ['nullable', Rule::enum(TriggeredBy::class)],
            'things.*.logic' => ['nullable', Rule::enum(LineLogic::class)],

            // A listener is the only bridge between drawn wiring and the flag
            // namespace, and `writesFlag` is the only way a flag is written
            // from the browser at all. See `StoreActionLineRequest`.
            'things.*.readsFlag' => ['nullable', 'string', 'max:255'],
            'things.*.writesFlag' => ['nullable', 'string', 'max:255'],

            'things.*.bindings' => ['sometimes', 'array', 'max:16'],
            'things.*.bindings.*.response' => ['required', Rule::enum(BindingResponse::class)],
            // Strings rather than numbers because the two responses want
            // different kinds: degrees for a rotate, on or off for a blocking.
            // The engine reads each one the way its own response means it.
            'things.*.bindings.*.on' => ['required', 'string', 'max:32'],
            'things.*.bindings.*.off' => ['required', 'string', 'max:32'],

            // The wiring, by slug. That both ends name a thing in this same
            // save is a question about the map as a whole, so it is checked
            // after the things rather than here.
            'lines' => ['sometimes', 'array', 'max:512'],
            'lines.*.from' => ['required', 'string', 'max:255'],
            'lines.*.to' => ['required', 'string', 'max:255'],
            'things.*.planeCount' => ['sometimes', 'integer', 'in:2,3'],
            'things.*.uvMode' => ['sometimes', Rule::enum(ThingUvMode::class)],
            'things.*.textureAlt' => ['nullable', 'string', $props],
            'things.*.altFlag' => ['nullable', 'string', 'max:255'],
            'things.*.animationFrames' => ['sometimes', 'integer', 'between:1,16'],
            'things.*.animationFps' => ['sometimes', 'numeric', 'between:0.1,60'],
            'things.*.x' => ['required', 'numeric', 'between:-512,512'],
            'things.*.z' => ['required', 'numeric', 'between:-512,512'],
            'things.*.elevation' => ['required', 'numeric', 'between:-64,64'],
            'things.*.width' => ['required', 'numeric', 'between:0.05,64'],
            'things.*.depth' => ['required', 'numeric', 'between:0.05,64'],
            'things.*.height' => ['required', 'numeric', 'between:0.05,64'],
            'things.*.angle' => ['required', 'numeric'],
            'things.*.isSolid' => ['required', 'boolean'],

            // What the player can do to a thing. Absent means the thing has
            // none, which is most of them; present and empty clears them.
            'things.*.interactions' => ['nullable', 'array', 'max:32'],
            'things.*.interactions.*.verb' => ['required', Rule::enum(Verb::class)],
            'things.*.interactions.*.response' => ['required', 'string', 'max:2000'],
            'things.*.interactions.*.priority' => ['required', 'integer', 'between:0,255'],
            'things.*.interactions.*.requiredItem' => ['nullable', 'string', 'max:255'],
            'things.*.interactions.*.conditions' => ['nullable', 'array', 'max:16'],
            'things.*.interactions.*.conditions.*.type' => ['required', Rule::enum(ConditionType::class)],
            'things.*.interactions.*.conditions.*.subject' => ['required', 'string', 'max:255'],
            'things.*.interactions.*.conditions.*.value' => ['nullable', 'string', 'max:255'],
            'things.*.interactions.*.effects' => ['nullable', 'array', 'max:16'],
            'things.*.interactions.*.effects.*.type' => ['required', Rule::enum(EffectType::class)],
            'things.*.interactions.*.effects.*.subject' => ['required', 'string', 'max:255'],
            'things.*.interactions.*.effects.*.value' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * A portal is a pair, so a link name has to be used exactly twice. One end
     * on its own would put the player somewhere there is nothing to arrive at.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var array<int, array<string, mixed>> $sectors */
                $sectors = $this->input('sectors', []);
                $ends = [];

                foreach ($sectors as $index => $sector) {
                    $this->checkSlopes($validator, $index, $sector);

                    /** @var array<int, array<string, mixed>> $points */
                    $points = $sector['points'] ?? [];

                    foreach ($points as $point) {
                        $link = $point['portalLink'] ?? null;

                        if (is_string($link) && $link !== '') {
                            $ends[$link] = ($ends[$link] ?? 0) + 1;
                        }
                    }
                }

                /** @var array<int, array<string, mixed>> $things */
                $things = $this->input('things', []);
                $slugs = [];

                foreach ($things as $index => $thing) {
                    $slug = $thing['slug'] ?? null;

                    if (is_string($slug) && isset($slugs[$slug])) {
                        $validator->errors()->add(
                            "things.{$index}.slug",
                            "Two things are both called \"{$slug}\"."
                        );
                    }

                    if (is_string($slug)) {
                        $slugs[$slug] = true;
                    }

                    // A person is drawn from a sprite sheet, not built as a box.
                    if (($thing['kind'] ?? null) === ThingKind::Actor->value
                        && ($thing['sprite'] ?? null) === null) {
                        $validator->errors()->add(
                            "things.{$index}.sprite",
                            'A person needs a sprite to be drawn from.'
                        );
                    }

                    $this->checkStats($validator, $index, $thing);
                    $this->checkAltTexture($validator, $index, $thing);
                }

                $this->checkItemsExist($validator, $things);
                $this->checkLinesJoinThings($validator, $things);

                foreach ($ends as $link => $count) {
                    if ($count !== 2) {
                        $validator->errors()->add(
                            'sectors',
                            "The portal \"{$link}\" needs exactly two walls; it has {$count}."
                        );
                    }
                }
            },
        ];
    }

    /**
     * A sloped room has to hinge on a wall it has, and has to stay a room.
     *
     * Both surfaces are planes, so the largest and smallest gap between them
     * are always at a corner. Sampling the corners is therefore exact rather
     * than an approximation, and no amount of walking the interior would find
     * anything the corners miss.
     *
     * The flat clamp in prepareForValidation still handles the flat case by
     * quietly raising the ceiling. A slope cannot be fixed that way — there is
     * no single number to raise — so this refuses instead of guessing.
     *
     * @param  array<string, mixed>  $sector
     */
    private function checkSlopes(Validator $validator, int|string $index, array $sector): void
    {
        /** @var array<int, array<string, mixed>> $points */
        $points = $sector['points'] ?? [];
        $corners = count($points);

        $floorSlope = (float) ($sector['floorSlope'] ?? 0);
        $ceilingSlope = (float) ($sector['ceilingSlope'] ?? 0);
        $floorHinge = $sector['floorSlopeEdge'] ?? null;
        $ceilingHinge = $sector['ceilingSlopeEdge'] ?? null;

        foreach ([
            ['floorSlope', 'floorSlopeEdge', $floorSlope, $floorHinge],
            ['ceilingSlope', 'ceilingSlopeEdge', $ceilingSlope, $ceilingHinge],
        ] as [$slopeKey, $hingeKey, $slope, $hinge]) {
            if ($slope !== 0.0 && $hinge === null) {
                $validator->errors()->add(
                    "sectors.{$index}.{$hingeKey}",
                    'A sloped surface has to name the wall it hinges on.'
                );
            }

            if ($hinge !== null && $corners > 0 && (int) $hinge >= $corners) {
                $validator->errors()->add(
                    "sectors.{$index}.{$hingeKey}",
                    "This room has {$corners} walls, so it has no wall {$hinge}."
                );
            }
        }

        if ($floorSlope === 0.0 && $ceilingSlope === 0.0) {
            return;
        }

        if ($corners < 3 || ! $this->hingesAreUsable($corners, $floorHinge, $ceilingHinge)) {
            return;
        }

        $sample = new LevelSector([
            'floor_height' => (float) ($sector['floorHeight'] ?? 0),
            'ceiling_height' => (float) ($sector['ceilingHeight'] ?? 0),
            'floor_slope' => $floorSlope,
            'floor_slope_edge' => $floorHinge,
            'ceiling_slope' => $ceilingSlope,
            'ceiling_slope_edge' => $ceilingHinge,
        ]);

        $sample->setRelation('edges', collect($points)->map(
            fn (array $point): LevelSectorEdge => tap(
                new LevelSectorEdge,
                fn (LevelSectorEdge $edge) => $edge->setRelation(
                    'vertex',
                    new LevelVertex(['x' => (float) $point['x'], 'z' => (float) $point['z']]),
                )
            )
        ));

        foreach ($sample->corners() as [$x, $z]) {
            if ($sample->ceilingAt($x, $z) < $sample->floorAt($x, $z)) {
                $validator->errors()->add(
                    "sectors.{$index}.ceilingSlope",
                    'That slope puts the ceiling under the floor in one of the corners.'
                );

                return;
            }
        }
    }

    private function hingesAreUsable(int $corners, mixed $floorHinge, mixed $ceilingHinge): bool
    {
        foreach ([$floorHinge, $ceilingHinge] as $hinge) {
            if ($hinge !== null && (int) $hinge >= $corners) {
                return false;
            }
        }

        return true;
    }

    /**
     * An alternate texture and the flag that swaps it in are a pair.
     *
     * Either one alone means nothing — a second texture nothing can reach, or a
     * flag naming a texture that does not exist — and both failures are silent
     * at render time. Turned away rather than stored.
     *
     * @param  array<string, mixed>  $thing
     */
    private function checkAltTexture(Validator $validator, int|string $index, array $thing): void
    {
        $texture = $thing['textureAlt'] ?? null;
        $flag = $thing['altFlag'] ?? null;

        if (($texture === null) === ($flag === null)) {
            return;
        }

        $validator->errors()->add(
            $texture === null ? "things.{$index}.textureAlt" : "things.{$index}.altFlag",
            'An alternate texture and the flag that shows it go together, or neither does.'
        );
    }

    /**
     * A stat block belongs to a person, and it names the seven attributes and
     * nothing else. A typo is turned away rather than stored, since nothing
     * reads these yet and a misspelled key would sit there unnoticed.
     *
     * @param  array<string, mixed>  $thing
     */
    private function checkStats(Validator $validator, int|string $index, array $thing): void
    {
        $stats = $thing['stats'] ?? null;

        if ($stats === null) {
            return;
        }

        if (($thing['kind'] ?? null) !== ThingKind::Actor->value) {
            $validator->errors()->add(
                "things.{$index}.stats",
                'Only a person has stats.'
            );

            return;
        }

        if (! is_array($stats)) {
            return;
        }

        $named = array_keys($stats);
        sort($named);

        $wanted = PersonStats::ATTRIBUTES;
        sort($wanted);

        if ($named !== $wanted) {
            $validator->errors()->add(
                "things.{$index}.stats",
                'A stat block names exactly: '.implode(', ', PersonStats::ATTRIBUTES).'.'
            );
        }
    }

    /**
     * Both ends of every drawn line have to be things in this same map.
     *
     * A line to a slug nothing carries is a wire to nowhere, and it would save
     * and then be silently ignored for ever — which is worse than a refusal,
     * because the editor would go on drawing it.
     *
     * @param  array<int, array<string, mixed>>  $things
     */
    private function checkLinesJoinThings(Validator $validator, array $things): void
    {
        /** @var array<int, array<string, mixed>> $lines */
        $lines = $this->input('lines', []);

        $slugs = array_column($things, 'slug');

        foreach ($lines as $index => $line) {
            foreach (['from', 'to'] as $end) {
                if (! in_array($line[$end] ?? null, $slugs, strict: true)) {
                    $validator->errors()->add(
                        "lines.{$index}.{$end}",
                        'A line has to join two things in this level.',
                    );
                }
            }
        }
    }

    /**
     * Every item slug an interaction names has to be one the game really has,
     * so that a typo breaks here rather than at the moment a child tries the
     * door and nothing happens.
     *
     * @param  array<int, array<string, mixed>>  $things
     */
    private function checkItemsExist(Validator $validator, array $things): void
    {
        /** @var Level $level */
        $level = $this->route('level');

        $known = Item::query()
            ->where('game_id', $level->game_id)
            ->pluck('slug')
            ->flip();

        $wantsAnItem = [
            EffectType::GiveItem->value,
            EffectType::RemoveItem->value,
        ];

        foreach ($things as $thingIndex => $thing) {
            /** @var array<int, array<string, mixed>> $interactions */
            $interactions = $thing['interactions'] ?? [];

            foreach ($interactions as $index => $interaction) {
                $where = "things.{$thingIndex}.interactions.{$index}";
                $required = $interaction['requiredItem'] ?? null;

                if (is_string($required) && $required !== '' && ! $known->has($required)) {
                    $validator->errors()->add(
                        "{$where}.requiredItem",
                        "There is no item called \"{$required}\" in this game."
                    );
                }

                /** @var array<int, array<string, mixed>> $effects */
                $effects = $interaction['effects'] ?? [];

                foreach ($effects as $at => $effect) {
                    $subject = $effect['subject'] ?? null;

                    if (! in_array($effect['type'] ?? null, $wantsAnItem, strict: true)) {
                        continue;
                    }

                    if (is_string($subject) && ! $known->has($subject)) {
                        $validator->errors()->add(
                            "{$where}.effects.{$at}.subject",
                            "There is no item called \"{$subject}\" in this game."
                        );
                    }
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sectors.*.points.min' => 'A room needs at least three corners.',
        ];
    }

    protected function prepareForValidation(): void
    {
        /** @var array<int, array<string, mixed>> $sectors */
        $sectors = $this->input('sectors', []);

        // A ceiling below its own floor would turn the room inside out.
        foreach ($sectors as $index => $sector) {
            $floor = (float) ($sector['floorHeight'] ?? 0);
            $ceiling = (float) ($sector['ceilingHeight'] ?? 0);

            if ($ceiling < $floor) {
                $sectors[$index]['ceilingHeight'] = $floor;
            }
        }

        $this->merge(['sectors' => $sectors]);
    }
}
