<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A spot in a level worth coming back to look at.
 *
 * Loosely checked on purpose: this is a note somebody takes while playing when
 * something looks wrong, and it is worth more with a field missing than not
 * taken at all. Only the shape is checked, and only enough that the file that
 * comes out of it is readable.
 */
class StoreDebugSnapshotRequest extends FormRequest
{
    /**
     * Only ever on the machine the game is being built on. Nothing here is
     * authenticated and it writes files, so anywhere else it does not exist.
     */
    public function authorize(): bool
    {
        abort_unless(app()->environment('local'), 404);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'takenAt' => ['required', 'string', 'max:64'],
            'level' => ['required', 'array'],
            'level.slug' => ['required', 'string', 'max:255'],
            'level.name' => ['nullable', 'string', 'max:255'],

            'at' => ['required', 'array'],
            'at.x' => ['required', 'numeric'],
            'at.z' => ['required', 'numeric'],
            'at.eye' => ['required', 'numeric'],
            'at.yaw' => ['required', 'numeric'],
            'at.pitch' => ['required', 'numeric'],

            'standingIn' => ['nullable', 'array'],
            'edgesNearby' => ['present', 'array', 'max:64'],
            'lookingAt' => ['nullable', 'string', 'max:255'],
            'holding' => ['nullable', 'string', 'max:64'],
            'running' => ['required', 'boolean'],
            'screen' => ['required', 'array'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
