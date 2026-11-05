<?php

use App\Models\User;

test('switching to german renders home in german', function () {
    $this->from(route('login'))
        ->get(route('locale.switch', 'de'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('locale', 'de');

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('<html lang="de"', false)
        ->assertSee('aria-label="Hauptnavigation"', false);
});

test('unsupported locales are rejected', function () {
    $this->get('/locale/fr')->assertNotFound();
});

test('a 404 of a missing model answers in the session language: SetLocale runs before route-model binding', function () {
    $this->withSession(['locale' => 'de'])
        ->get('/tournaments/999999')
        ->assertNotFound()
        ->assertSee('<html lang="de"', false)
        ->assertSee('Diese Seite gibt es nicht');
});

test('an unsupported locale in the session falls back to english', function () {
    $this->withSession(['locale' => 'fr'])
        ->get(route('home'))
        ->assertSee('<html lang="en"', false)
        ->assertSee('aria-label="Main navigation"', false);
});

test('a guest who never chose reads the first language of their browser the league speaks', function (string $header, string $lang, string $navigation) {
    $this->withHeader('Accept-Language', $header)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('<html lang="'.$lang.'"', false)
        ->assertSee('aria-label="'.$navigation.'"', false);

    // Read on every request, never stored: the switch and ?lang= alone set the session.
    $this->assertFalse(session()->has('locale'));
})->with([
    'a German phone' => ['de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7', 'de', 'Hauptnavigation'],
    'German after French' => ['fr-FR,fr;q=0.9,de;q=0.8,en;q=0.5', 'de', 'Hauptnavigation'],
    'only a region, as Safari sends it' => ['de-DE', 'de', 'Hauptnavigation'],
    'a region in capitals' => ['DE-at', 'de', 'Hauptnavigation'],
    'an underscore' => ['de_DE', 'de', 'Hauptnavigation'],
    'English before German' => ['en-GB,en;q=0.9,de;q=0.8', 'en', 'Main navigation'],
    'only French' => ['fr-FR,fr;q=0.9', 'en', 'Main navigation'],
    'German refused with q=0' => ['de;q=0,fr', 'en', 'Main navigation'],
    'no language at all, as Googlebot' => ['', 'en', 'Main navigation'],
]);

test('a language the visitor chose beats the browser: ?lang=, the switch, the account', function () {
    $this->withHeader('Accept-Language', 'de-DE,de;q=0.9');

    $this->get(route('home', ['lang' => 'en']))->assertSee('<html lang="en"', false);
    $this->get(route('home'))->assertSee('<html lang="en"', false);

    $this->flushSession();
    $this->get(route('home'))->assertSee('<html lang="de"', false);

    $this->actingAs(User::factory()->create(['locale' => 'en']))
        ->get(route('home'))
        ->assertSee('<html lang="en"', false);
});
