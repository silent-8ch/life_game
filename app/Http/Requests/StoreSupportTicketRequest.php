<?php

namespace App\Http\Requests;

use App\Enums\TicketSource;
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
    /**
     * Kilobytes per picture.
     *
     * Small because the pictures are small: the game draws into a buffer a
     * third the size of the canvas and upscales it, so a frame is a few hundred
     * pixels across before anything compresses it. A cap that only turns away
     * what the game could never have produced is not a cap at all.
     */
    public const MAX_SHOT_KILOBYTES = 1024;

    /** Wider than this and it did not come from the game. */
    public const MAX_SHOT_PIXELS = 2000;

    /**
     * Which views a ticket may carry.
     *
     * Named rather than free, so a client cannot invent kinds and fill the disk
     * with them, and so the admin panel knows what it is looking at. Adding one
     * is a line here and a row in the table — not a migration.
     *
     * @var list<string>
     */
    public const KINDS = [
        // Raised while playing: what they saw, the shape of it, and the
        // colour-coded view that lets a machine name the wall that is wrong.
        'normal',
        'wireframe',
        'walls',
        // Raised in the editor, which draws a floor plan and a section rather
        // than a scene, so it has neither of the first two to offer.
        'map',
        'section',
    ];

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
            'source' => ['required', Rule::enum(TicketSource::class)],
            'level' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],

            // The same spot a snapshot writes and `?at=` takes: the player's
            // own yaw and pitch in degrees, never a level's spawn angle.
            //
            // Optional as a whole, because a ticket from the editor has nowhere
            // to have been standing — but all of it or none of it, since half a
            // position is worse than no position: it looks like somewhere.
            'at' => ['nullable', 'array'],
            'at.x' => ['required_with:at', 'numeric', 'between:-512,512'],
            'at.z' => ['required_with:at', 'numeric', 'between:-512,512'],
            'at.eye' => ['required_with:at', 'numeric', 'between:-64,64'],
            'at.yaw' => ['required_with:at', 'numeric', 'between:-3600,3600'],
            'at.pitch' => ['required_with:at', 'numeric', 'between:-90,90'],

            'standingIn' => ['nullable', 'string', 'max:255'],
            'lookingAt' => ['nullable', 'string', 'max:255'],
            'holding' => ['nullable', 'string', 'max:64'],
            'running' => ['sometimes', 'boolean'],

            'screen' => ['nullable', 'array'],
            'screen.width' => ['required_with:screen', 'integer', 'between:1,16384'],
            'screen.height' => ['required_with:screen', 'integer', 'between:1,16384'],
            'screen.pixelRatio' => ['required_with:screen', 'numeric', 'between:0.1,8'],
            'screen.touch' => ['required_with:screen', 'boolean'],

            // Bounded hard. This is the one field whose size the reporter picks.
            'nearby' => ['nullable', 'array', 'max:64'],
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
                // `image` decodes the file rather than believing its name or
                // the content type the sender declared, which matters because
                // anybody may post here without an account.
                'image',
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
