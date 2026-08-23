<?php

namespace App\Http\Requests;

use App\Enums\EmitWhen;
use App\Models\Game;
use App\Models\Level;
use App\Models\LevelThing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A lever thrown, on its way to being remembered.
 *
 * ## Why this is not just a flag endpoint
 *
 * A line name and a flag name are the same namespace, so an endpoint that
 * took a name and wrote it would let the browser set **any** flag — including
 * the ones an interaction's conditions are gating on, which is every lock in
 * every game.
 *
 * So the name is not taken on trust. It has to be an action line some thing in the
 * named level actually emits, and emits **by being used** — a plate's line is
 * momentary and has no business being written to a save at all. What the
 * browser is allowed to say is therefore exactly *this lever moved*, which is
 * the only thing it knows that the server does not.
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
            'line' => ['required', 'string', 'max:255'],
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

            $thrown = $level === null ? false : LevelThing::query()
                ->where('level_id', $level->id)
                ->where('emits', $this->string('line')->toString())
                ->where('emit_when', EmitWhen::Used)
                ->exists();

            if (! $thrown) {
                $validator->errors()->add(
                    'line',
                    'Nothing in that level throws that line.',
                );
            }
        });
    }
}
