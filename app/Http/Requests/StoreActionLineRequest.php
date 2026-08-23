<?php

namespace App\Http\Requests;

use App\Enums\EmitWhen;
use App\Models\Game;
use App\Models\Level;
use App\Models\LevelThing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A listener's flag, on its way to being remembered.
 *
 * ## Why this is not a flag endpoint
 *
 * Line names and flag names share a namespace, so an endpoint that took a name
 * and wrote it would let the browser set **any** flag — including the ones
 * every interaction's conditions are gated on, which is every lock in every
 * game.
 *
 * The first slice checked that some thing in the level emitted that name by
 * being used. Drawn lines have no names, so that check had nowhere to stand and
 * has moved rather than gone: **a flag may only be written by the thing that
 * answers for it** — a listener writing the name it declares, or a lever
 * writing its own latch. Same guarantee. What the browser is allowed to say is
 * exactly *this particular thing changed*, which is the only thing it knows
 * that the server does not.
 */
class StoreActionLineRequest extends FormRequest
{
    /** What a lever's own latch is remembered as, ahead of its slug. */
    private const LEVER = 'lever:';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'level' => ['required', 'string', 'max:255'],
            'flag' => ['required', 'string', 'max:255'],
            'on' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Game $game */
            $game = $this->route('game');

            $level = Level::query()
                ->where('game_id', $game->id)
                ->where('slug', $this->string('level')->toString())
                ->first();

            if ($level === null || ! $this->declaredIn($level)) {
                $validator->errors()->add(
                    'flag',
                    'Nothing in that level writes that flag.',
                );
            }
        });
    }

    /**
     * Whether something in this level really writes the flag being posted.
     *
     * Two kinds do, and only two. A **listener** writes the name it declares,
     * which is the whole of what a listener is for. And a **lever** writes its
     * own latch under `lever:` and its own slug — because a lever is the one
     * node that holds its state rather than working it out from the frame, so
     * it is the one whose state a save has to carry, and the engine's `restore`
     * looks for exactly that name.
     *
     * Both are the same guarantee the class comment makes: the browser may say
     * *this particular thing changed*, and may not name a flag no thing in the
     * level answers for.
     */
    private function declaredIn(Level $level): bool
    {
        $flag = $this->string('flag')->toString();

        if (str_starts_with($flag, self::LEVER)) {
            return LevelThing::query()
                ->where('level_id', $level->id)
                ->where('slug', substr($flag, strlen(self::LEVER)))
                ->where('emit_when', EmitWhen::Used)
                ->exists();
        }

        return LevelThing::query()
            ->where('level_id', $level->id)
            ->where('writes_flag', $flag)
            ->exists();
    }
}
