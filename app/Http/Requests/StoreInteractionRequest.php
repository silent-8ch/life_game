<?php

namespace App\Http\Requests;

use App\Enums\Verb;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInteractionRequest extends FormRequest
{
    /**
     * A verb is applied either to a hotspot in a scene or to a thing standing in
     * a level, never to both.
     *
     * The item is only checked for shape here; that the player is carrying it
     * — and that it belongs to this game — is settled against the save file.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hotspot' => ['required_without:thing', 'missing_with:thing', 'string'],
            'thing' => ['required_without:hotspot', 'string'],
            'level' => ['required_with:thing', 'string'],
            'verb' => ['required', Rule::enum(Verb::class)],
            'item' => ['nullable', 'string'],
        ];
    }

    /** Whether the verb is aimed at something standing in a first-person level. */
    public function isInALevel(): bool
    {
        return $this->string('thing')->value() !== '';
    }

    public function verb(): Verb
    {
        return Verb::from($this->string('verb')->value());
    }
}
