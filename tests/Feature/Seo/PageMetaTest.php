<?php

use App\Enums\InviteLinkType;
use App\Enums\SeriesStatus;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Invites\InviteLinks;

/*
|--------------------------------------------------------------------------
| Search tags of the pages (P14)
|--------------------------------------------------------------------------
|
| Every public page describes itself (title, description, canonical, hreflang,
| JSON-LD) in the first response; every private page says noindex and names
| no canonical. Indexing is opt-in (App\Support\PageMeta), so a page that is
| missing here is noindex, not silently indexable.
|
*/

// Search engines may index only production on the APP_URL host (App\Support\Seo\SearchIndexing);
// the test requests go to that host, so production is what these tests describe.
beforeEach(function () {
    $this->app['env'] = 'production';
});

/**
 * The public pages with their fixtures: name => fn () => [url, JSON-LD types beyond Organization].
 *
 * @return array<string, Closure(): array{0: string, 1: list<string>}>
 */
function publicPages(): array
{
    return [
        'home' => fn () => [route('home').'/', []],
        'clans' => fn () => [route('clans.index'), []],
        'clan' => fn () => [route('clans.show', Clan::factory()->create(['name' => 'Hodl Squad'])), ['BreadcrumbList']],
        'matches' => fn () => [route('matches.index'), []],
        'series' => fn () => [route('matches.show', SeriesMatch::factory()->accepted()->create()->number), ['SportsEvent', 'BreadcrumbList']],
        'chess game' => fn () => [route('games.show', ChessGame::factory()->finished()->create(['number' => 77])), ['SportsEvent', 'BreadcrumbList']],
        'chess lobby' => fn () => [route('chess.lobby'), []],
        'rocket league' => fn () => [route('games.rocket-league'), []],
        'mining' => fn () => [route('mining'), []],
        'chess ladder' => fn () => [route('ladder.show', ['chess', 'blitz']), []],
        'rocket league ladder' => fn () => [route('ladder.show', ['rocket-league', '2v2']), []],
        'player' => fn () => [route('players.show', User::factory()->create(['name' => 'satoshi'])->npub), ['ProfilePage']],
    ];
}

/**
 * The JSON-LD graph of a page, decoded (fails the test if it is not JSON).
 *
 * @return list<array<string, mixed>>
 */
function jsonLdGraph(string $html): array
{
    expect(preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches))->toBe(1);

    $document = json_decode($matches[1][0], true, flags: JSON_THROW_ON_ERROR);

    expect($document['@context'])->toBe('https://schema.org');

    return $document['@graph'];
}

function titleOf(string $html): string
{
    preg_match('#<title>(.*?)</title>#s', $html, $match);

    return html_entity_decode($match[1] ?? '');
}

function descriptionOf(string $html): ?string
{
    return preg_match('#<meta name="description" content="([^"]*)">#', $html, $match) === 1 ? html_entity_decode($match[1]) : null;
}

test('a public page describes itself in english and german', function (array $page) {
    [$url, $types] = $page;

    $en = $this->get($url)->assertOk()->getContent();
    $de = $this->get($url.'?lang=de')->assertOk()->getContent();

    foreach ([[$en, 'en', $url], [$de, 'de', $url.'?lang=de']] as [$html, $locale, $canonical]) {
        expect($html)
            ->toContain('<html lang="'.$locale.'"')
            ->toContain('<link rel="canonical" href="'.$canonical.'">')
            ->toContain('<link rel="alternate" hreflang="en" href="'.$url.'">')
            ->toContain('<link rel="alternate" hreflang="de" href="'.$url.'?lang=de">')
            ->toContain('<link rel="alternate" hreflang="x-default" href="'.$url.'">')
            ->toContain('<meta property="og:url" content="'.$canonical.'">')
            ->toContain('<meta property="og:image" content="')
            ->not->toContain('name="robots"')
            ->and(descriptionOf($html))->not->toBeNull()
            ->and(mb_strlen((string) descriptionOf($html)))->toBeBetween(50, 320)
            ->and(titleOf($html))->toEndWith(' – TWENTY ONE esports');

        $graph = jsonLdGraph($html);
        expect(array_column($graph, '@type'))->toBe(['Organization', ...$types]);
    }

    expect(descriptionOf($de))->not->toBe(descriptionOf($en));
})->with(publicPages());

test('every public page has a title and description of its own', function () {
    $titles = [];
    $descriptions = [];

    foreach (publicPages() as $page) {
        $html = $this->get($page()[0])->getContent();
        $titles[] = titleOf($html);
        $descriptions[] = descriptionOf($html);
    }

    expect(array_unique($titles))->toHaveCount(count($titles))
        ->and(array_unique($descriptions))->toHaveCount(count($descriptions));
});

test('private and unfinished pages are noindex and name no canonical', function (Closure $request) {
    $html = $request($this)->assertOk()->getContent();

    expect($html)->toContain('<meta name="robots" content="noindex, nofollow">')
        ->not->toContain('rel="canonical"')
        ->not->toContain('rel="alternate" hreflang=');
})->with([
    'login' => fn ($test) => $test->get(route('login')),
    'coming soon' => fn ($test) => $test->get(route('rules')),
    'invite link' => fn ($test) => $test->get(app(InviteLinks::class)->create(User::factory()->create(), InviteLinkType::Blitz)->url()),
    'own page' => fn ($test) => $test->actingAs(User::factory()->create())->get(route('dashboard')),
    'settings' => fn ($test) => $test->actingAs(User::factory()->create())->get(route('gaming.edit')),
    'new clan' => fn ($test) => $test->actingAs(User::factory()->create())->get(route('clans.create')),
    'admin' => function ($test) {
        $admin = User::factory()->create();
        Admin::query()->create(['pubkey' => $admin->pubkey]);

        return $test->actingAs($admin)->get(route('admin.admins'));
    },
]);

test('a page that does not exist is not indexed', function () {
    expect($this->get('/matches/999999')->assertNotFound()->getContent())
        ->toContain('<meta name="robots" content="noindex, nofollow">');
});

test('the login page keeps its link preview but is noindex', function () {
    expect($this->get(route('login'))->getContent())
        ->toContain('<meta name="robots" content="noindex, nofollow">')
        ->toContain('<meta name="description" content="Log in to TWENTY ONE esports with Google or Nostr');
});

test('the canonical url drops filters and tabs but keeps the page of a list', function () {
    $html = $this->get(route('matches.index', ['game' => 'chess', 'status' => 'done', 'page' => 2]))->getContent();

    expect($html)->toContain('<link rel="canonical" href="'.route('matches.index').'?page=2">')
        ->toContain('<link rel="alternate" hreflang="de" href="'.route('matches.index').'?page=2&amp;lang=de">');

    expect($this->get(route('ladder.show', ['chess', 'blitz', 'pool' => 'casual']))->getContent())
        ->toContain('<link rel="canonical" href="'.route('ladder.show', ['chess', 'blitz']).'">');
});

test('?lang picks the language and keeps it for the next page', function () {
    $this->get(route('clans.index').'?lang=de')->assertSessionHas('locale', 'de');

    $this->get(route('home'))
        ->assertSee('<html lang="de"', false)
        ->assertSee('<link rel="canonical" href="'.route('home').'/?lang=de">', false);

    $this->get(route('home').'?lang=en')
        ->assertSee('<html lang="en"', false)
        ->assertSee('<link rel="canonical" href="'.route('home').'/">', false);
});

test('an unsupported ?lang is ignored', function () {
    $this->get(route('home').'?lang=fr')
        ->assertSessionMissing('locale')
        ->assertSee('<html lang="en"', false);
});

test('the organization is TWENTY ONE esports, the esports arm of EINUNDZWANZIG', function () {
    $organization = jsonLdGraph($this->get(route('home'))->getContent())[0];

    expect($organization)->toMatchArray([
        '@type' => 'Organization',
        'name' => 'TWENTY ONE esports',
        'url' => route('home'),
        'logo' => asset('images/twentyone/avatar-1024.png'),
        'parentOrganization' => ['@type' => 'Organization', 'name' => 'EINUNDZWANZIG', 'url' => 'https://einundzwanzig.space'],
    ]);
});

test('a player page is a ProfilePage about a Person, from the profile only', function () {
    $user = User::factory()->create(['name' => 'satoshi', 'about' => 'Plays the Sicilian.', 'website' => 'https://example.org']);
    $clan = Clan::factory()->create(['owner_id' => $user->id, 'owner_pubkey' => $user->pubkey]);
    $url = route('players.show', $user->npub);

    $page = jsonLdGraph($this->get($url)->getContent())[1];

    expect($page)->toMatchArray(['@type' => 'ProfilePage', 'url' => $url])
        ->and($page['mainEntity'])->toMatchArray([
            '@type' => 'Person',
            'name' => 'satoshi',
            'url' => $url,
            'identifier' => $user->npub,
            'description' => 'Plays the Sicilian.',
            'sameAs' => ['https://example.org'],
            'memberOf' => ['@type' => 'SportsTeam', 'name' => $clan->name, 'url' => route('clans.show', $clan)],
        ]);
});

test('a series is a SportsEvent between two clan teams', function () {
    $match = SeriesMatch::factory()->accepted()->create();
    $url = route('matches.show', $match->number);

    [, $event, $breadcrumbs] = jsonLdGraph($this->get($url)->getContent());

    expect($event)->toMatchArray([
        '@type' => 'SportsEvent',
        'url' => $url,
        'sport' => 'Rocket League',
        'startDate' => $match->start_at->toIso8601String(),
        'eventStatus' => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
        'location' => ['@type' => 'VirtualLocation', 'url' => $url],
    ])
        ->and($event)->not->toHaveKey('endDate')
        ->and($event['competitor'])->toBe([
            ['@type' => 'SportsTeam', 'name' => $match->challenger_name, 'url' => route('clans.show', $match->lineup('challenger')->clan)],
            ['@type' => 'SportsTeam', 'name' => $match->challenged_name, 'url' => route('clans.show', $match->lineup('challenged')->clan)],
        ])
        ->and(array_column($breadcrumbs['itemListElement'], 'item'))->toBe([route('home').'/', route('matches.index'), $url]);
});

test('a withdrawn series is a cancelled event', function () {
    $match = SeriesMatch::factory()->create(['status' => SeriesStatus::Withdrawn]);

    expect(jsonLdGraph($this->get(route('matches.show', $match->number))->getContent())[1]['eventStatus'])
        ->toBe('https://schema.org/EventCancelled');
});

test('a chess game is a SportsEvent between two players', function () {
    $game = ChessGame::factory()->finished('0-1')->create(['number' => 12]);
    $url = route('games.show', $game);

    $html = $this->get($url)->getContent();
    $event = jsonLdGraph($html)[1];

    expect($event)->toMatchArray(['@type' => 'SportsEvent', 'url' => $url, 'sport' => 'Chess', 'name' => 'Game #12: '.$game->white->displayName().' vs '.$game->black->displayName()])
        ->and($event['competitor'])->toBe([
            ['@type' => 'Person', 'name' => $game->white->displayName(), 'url' => route('players.show', $game->white->npub)],
            ['@type' => 'Person', 'name' => $game->black->displayName(), 'url' => route('players.show', $game->black->npub)],
        ])
        ->and(descriptionOf($html))->toEndWith('Result: 0-1.');
});

test('a name cannot close the JSON-LD script', function () {
    $user = User::factory()->create(['name' => '</script><script>alert(1)</script>']);

    $html = $this->get(route('players.show', $user->npub))->getContent();

    expect($html)->not->toContain('</script><script>alert(1)')
        ->and(jsonLdGraph($html)[1]['mainEntity']['name'])->toBe('</script><script>alert(1)</script>');
});

test('outside production, or on another host, every page is noindex', function (string $env, string $host) {
    $this->app['env'] = $env;

    expect($this->get('http://'.$host.'/clans')->assertOk()->getContent())
        ->toContain('<meta name="robots" content="noindex, nofollow">')
        ->not->toContain('rel="canonical"')
        ->not->toContain('rel="alternate" hreflang=');
})->with([
    'interim domain' => ['production', 'esports-twentyone.on-forge.com'],
    'staging environment' => ['staging', 'localhost'],
]);
