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
            // Not `present`, and that is about the transport rather than the
            // field. A snapshot carrying pictures arrives as a multipart form,
            // and a form has no way to write an empty array — the flattener
            // writes one entry per item, so nought items writes nothing at all.
            // Insisting it be present would refuse every snapshot taken
            // somewhere with no edge nearby, which is the middle of any room.
            'edgesNearby' => ['nullable', 'array', 'max:64'],
            'lookingAt' => ['nullable', 'string', 'max:255'],
            'holding' => ['nullable', 'string', 'max:64'],
            'running' => ['required', 'boolean'],
            'screen' => ['required', 'array'],
            'note' => ['nullable', 'string', 'max:2000'],

            // What the spot looked like, when the frame could be read back.
            // Optional throughout: a snapshot with no pictures still carries
            // the spot and the room, which is most of what diagnoses one, and
            // refusing it because a readback failed would lose the lot.
            'shots' => ['nullable', 'array', 'max:8'],
            'shots.*' => ['file', 'image', 'max:8192'],
            // Without this the walls view decodes to nothing: `paintWalls`
            // hands out colours by walking the scene with a running counter, so
            // which colour is which wall belongs to that build of that level.
            'legend' => ['nullable', 'array', 'max:512'],
            // Why there are none, when there are none. A note that arrives
            // looking complete teaches whoever reads it that the feature is
            // broken, rather than that this one frame could not be read.
            'trouble' => ['nullable', 'string', 'max:500'],
            // Only ever sent by a machine standing on a spot; a person pressing
            // F sends none.
            'panes' => ['nullable', 'array', 'max:64'],
        ];
    }
}
