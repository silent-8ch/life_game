<?php

namespace App\Http\Requests;

use App\Models\Level;
use App\Services\LevelAssets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Changing one wall from inside the level.
 *
 * The same authority as the map editor, because it is the same act — this
 * writes to the level everybody sees, not to a save file. So it sits behind the
 * same login and the same policy, and playing stays read-only.
 *
 * A wall is named by its room's slug and its position in that room's point
 * list, which is what the engine already puts on every wall it draws. That pair
 * survives a save — both halves are authored, and `LevelWriter` rebuilds rows
 * but not slugs or orderings. It does **not** survive an *edit*: splitting a
 * wall, welding a corner a neighbour landed on, or carving all rewrite the
 * point list and move every index after the change.
 *
 * Hence `expect`. The client already holds the wall's two corners — the engine
 * tags every wall with them — so sending them back turns "somebody carved this
 * room while you were looking at it" from a silent edit of the wrong wall into
 * a refusal. Optional, because a client that does not have them is still better
 * off editing than not.
 */
class UpdateWallRequest extends FormRequest
{
    public function authorize(): bool
    {
        $level = $this->route('level');

        return $level instanceof Level
            && Gate::allows('update', $level);
    }

    /**
     * Preview editing answers in JSON, so the refusal does too.
     *
     * A bare 403 reaches the viewport as a failed fetch and shows the player
     * nothing at all — they press the wall again and nothing happens twice.
     */
    protected function failedAuthorization(): never
    {
        $level = $this->route('level');
        $owner = $level instanceof Level ? $level->owner?->name : null;

        throw new HttpResponseException(response()->json([
            'message' => $owner === null
                ? 'That wall could not be changed.'
                : "{$owner} drew this level, so only they can change it.",
        ], 403));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $assets = app(LevelAssets::class);

        return [
            'sector' => ['required', 'string', 'max:255'],
            'index' => ['required', 'integer', 'min:0'],

            // The wall this was meant to be, as the client last saw it.
            'expect' => ['nullable', 'array'],
            'expect.from.x' => ['required_with:expect', 'numeric'],
            'expect.from.z' => ['required_with:expect', 'numeric'],
            'expect.to.x' => ['required_with:expect', 'numeric'],
            'expect.to.z' => ['required_with:expect', 'numeric'],

            // Everything that can be changed from inside the level. Textures
            // and the three per-side flags; nothing that moves geometry, since
            // there is no way to draw from in there.
            'wallTexture' => ['sometimes', 'nullable', 'string', Rule::in($assets->textures())],
            'blocks' => ['sometimes', 'boolean'],
            'isMirror' => ['sometimes', 'boolean'],
            'isSky' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->changes() === []) {
                    $validator->errors()->add(
                        'wallTexture',
                        'Say what to change about the wall.'
                    );
                }
            },
        ];
    }

    /**
     * What this asks to change, in the columns' own names.
     *
     * Only what was actually sent. A request saying nothing about `blocks` must
     * leave `blocks` alone rather than setting it false, which is the whole
     * reason these are `sometimes` and not `nullable`.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = [];

        foreach ([
            'wallTexture' => 'wall_texture',
            'blocks' => 'blocks',
            'isMirror' => 'is_mirror',
            'isSky' => 'is_sky',
        ] as $sent => $column) {
            if ($this->has($sent)) {
                $changes[$column] = $sent === 'wallTexture'
                    ? $this->input($sent)
                    : $this->boolean($sent);
            }
        }

        return $changes;
    }
}
