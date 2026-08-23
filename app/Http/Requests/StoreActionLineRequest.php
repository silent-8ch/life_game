<?php

namespace App\Http\Requests;

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
 * has moved rather than gone: **only a listener may write a flag, and only the
 * name it declares.** Same guarantee. What the browser is allowed to say is
 * exactly *this listener's input changed*, which is the only thing it knows
 * that the server does not.
 */
class StoreActionLineRequest extends FormRequest
{
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

            $declared = $level !== null && LevelThing::query()
                ->where('level_id', $level->id)
                ->where('writes_flag', $this->string('flag')->toString())
                ->exists();

            if (! $declared) {
                $validator->errors()->add(
                    'flag',
                    'Nothing in that level writes that flag.',
                );
            }
        });
    }
}
