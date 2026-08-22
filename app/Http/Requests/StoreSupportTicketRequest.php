<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "This is wrong", raised from inside a game.
 *
 * **This is the first thing in the project that takes bytes from the public.**
 * Playing a published game needs no account — only the editor and the admin
 * panel sit behind a login — so anybody who can reach the site can post to it.
 * Everything here is written on that footing rather than on the friendlier one
 * where every reporter is somebody we know.
 *
 * The picture rules are the part that earns its keep: `image` alone trusts the
 * extension, so the mimes are named, and dimensions are capped as well as file
 * size — a 100-megapixel PNG can compress to very little and still exhaust
 * memory the moment anything tries to look at it.
 */
class StoreSupportTicketRequest extends FormRequest
{
    /** Bytes per picture. Three of them at a time, so this is the real limit. */
    public const MAX_SHOT_KILOBYTES = 4096;

    /** Beyond this a picture is not a screenshot of anybody's screen. */
    public const MAX_SHOT_PIXELS = 8000;

    /**
     * Which views a ticket may carry.
     *
     * Named rather than free, so a client cannot invent kinds and fill the disk
     * with them, and so the admin panel knows what it is looking at. Adding one
     * is a line here and a row in the table — not a migration.
     *
     * @var list<string>
     */
    public const KINDS = ['normal', 'wireframe', 'walls'];

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
            'note' => ['nullable', 'string', 'max:2000'],

            // The same spot a snapshot writes and `?at=` takes: the player's
            // own yaw and pitch in degrees, never a level's spawn angle.
            'at' => ['required', 'array'],
            'at.x' => ['required', 'numeric', 'between:-512,512'],
            'at.z' => ['required', 'numeric', 'between:-512,512'],
            'at.eye' => ['required', 'numeric', 'between:-64,64'],
            'at.yaw' => ['required', 'numeric', 'between:-3600,3600'],
            'at.pitch' => ['required', 'numeric', 'between:-90,90'],

            'standingIn' => ['nullable', 'string', 'max:255'],
            'lookingAt' => ['nullable', 'string', 'max:255'],
            'holding' => ['nullable', 'string', 'max:64'],
            'running' => ['required', 'boolean'],

            'screen' => ['required', 'array'],
            'screen.width' => ['required', 'integer', 'between:1,16384'],
            'screen.height' => ['required', 'integer', 'between:1,16384'],
            'screen.pixelRatio' => ['required', 'numeric', 'between:0.1,8'],
            'screen.touch' => ['required', 'boolean'],

            // Bounded hard. This is the one field whose size the reporter picks.
            'nearby' => ['present', 'array', 'max:64'],
            'nearby.*.distance' => ['required', 'numeric'],
            'nearby.*.rooms' => ['required', 'array', 'max:8'],
            'nearby.*.rooms.*' => ['required', 'string', 'max:255'],
            'nearby.*.open' => ['required', 'boolean'],

            // Not an extra. The colour-coded picture is undecodable without
            // it: paintWalls hands out colours by walking the scene graph with
            // a running counter, so which colour is which wall belongs to that
            // build of that level and cannot be recovered from the pixels.
            'legend' => ['nullable', 'array', 'max:512'],
            'legend.*.css' => ['required', 'string', 'max:32'],
            'legend.*.sector' => ['required', 'string', 'max:255'],
            'legend.*.index' => ['required', 'integer', 'min:0'],

            'shots' => ['required', 'array', 'max:'.count(self::KINDS)],
            'shots.*' => [
                'required',
                'file',
                'mimes:png,jpg,jpeg,webp',
                'mimetypes:image/png,image/jpeg,image/webp',
                'max:'.self::MAX_SHOT_KILOBYTES,
                'dimensions:max_width='.self::MAX_SHOT_PIXELS.',max_height='.self::MAX_SHOT_PIXELS,
            ],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var array<string, mixed> $shots */
                $shots = $this->file('shots') ?? [];

                foreach (array_keys($shots) as $kind) {
                    if (! in_array($kind, self::KINDS, strict: true)) {
                        $validator->errors()->add(
                            "shots.{$kind}",
                            'That is not a view a ticket carries: '.implode(', ', self::KINDS).'.'
                        );
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return [
            'shots.*.mimes' => 'A ticket carries pictures — png, jpg or webp.',
            'shots.*.max' => 'That picture is larger than '.(self::MAX_SHOT_KILOBYTES / 1024).' MB.',
        ];
    }

    /**
     * A rule matching one of the named views, for anywhere else that needs it.
     */
    public static function kindRule(): Rule|string
    {
        return Rule::in(self::KINDS);
    }
}
