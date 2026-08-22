<?php

use App\Models\Game;
use App\Models\Item;
use App\Models\User;
use Database\Seeders\LifeSeeder;

/**
 * Items had no screen of their own: they existed only in seeders, so a level
 * author could write "give the shed key" in the map editor and there was no way
 * to make a shed key. This is that way.
 */
beforeEach(function (): void {
    $this->seed(LifeSeeder::class);

    $this->game = Game::query()->where('slug', 'life')->sole();
    $this->editor = User::factory()->create();

    // Filament only lets anybody at all into a panel while app.env is local;
    // anywhere else it wants a User implementing FilamentUser to say so. That
    // rule is not what these are about, so they run as the machine the editor
    // is actually used on does.
    config(['app.env' => 'local']);
});

it('keeps the item list behind a login', function (): void {
    $this->get('/admin/items')->assertRedirect(route('filament.admin.auth.login'));
});

it('lists the items of every game', function (): void {
    Item::query()->create([
        'game_id' => $this->game->id,
        'slug' => 'shed-key',
        'name' => 'shed key',
        'description' => 'A small key on a loop of string.',
    ]);

    $this->actingAs($this->editor)
        ->get('/admin/items')
        ->assertOk()
        ->assertSee('shed key');
});

it('offers a form for making one', function (): void {
    $this->actingAs($this->editor)
        ->get('/admin/items/create')
        ->assertOk();
});
