<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Where the player has got to.
 *
 * The same four numbers `?at=` takes and a debug snapshot writes, in the same
 * units and the same sign: the angle is the player's own yaw in degrees, not
 * the level's spawn angle, which is its negative.
 *
 * Bounds match the level editor's, so a position can only be somewhere a level
 * could have been authored. That is not really a security boundary — anybody
 * can post whatever they like about their own save — it is a way of making a
 * bug loud rather than storing a player at x = 1e30 and puzzling over it later.
 */
class StorePositionRequest extends FormRequest
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
            'x' => ['required', 'numeric', 'between:-512,512'],
            'z' => ['required', 'numeric', 'between:-512,512'],
            'facing' => ['required', 'numeric', 'between:-3600,3600'],
            'pitch' => ['required', 'numeric', 'between:-90,90'],
        ];
    }
}
