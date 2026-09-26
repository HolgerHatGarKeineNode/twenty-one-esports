<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\User;
use App\Support\Cards\ShareCard;
use App\Support\Tournaments\TournamentChampion;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Share cards (P11): rank up, block mined, tournament win and Season Wrapped,
 * rendered with GD at 1200 × 630 and 1080 × 1920, English and German, cached
 * by fingerprint. SHARE_CARD_SHOTS=<dir> also writes every PNG there, to look
 * at them.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->season = openSeason(['slug' => 'pre-season']);
    $this->user = User::factory()->create(['name' => 'satsjäger']);
    $this->moments = shareMoments($this->user, $this->season);
});

/** @return array<string, ShareCard> */
function shareCards(array $moments, User $user, $season): array
{
    return [
        'rank-up' => ShareCard::rankUp($moments['versions'][1]),
        'block' => ShareCard::block($moments['block'], $user),
        'tournament' => ShareCard::tournament($moments['tournament'], app(TournamentChampion::class)->of($moments['tournament'])),
        'wrapped' => ShareCard::wrapped($season, $user),
    ];
}

test('every card renders at 1200 × 630 and 1080 × 1920 in English and German, served from its public URL', function () {
    $dir = getenv('SHARE_CARD_SHOTS');

    foreach (['en', 'de'] as $locale) {
        App::setLocale($locale);

        foreach (shareCards($this->moments, $this->user, $this->season) as $type => $card) {
            foreach (ShareCard::FORMATS as $format => [$width, $height]) {
                $url = $card->url($format);
                $response = $this->get(substr($url, strlen(config('app.url'))))->assertOk()->assertHeader('Content-Type', 'image/png');
                $info = getimagesizefromstring($response->getContent());

                expect([$type, $format, $info[0], $info[1]])->toBe([$type, $format, $width, $height])
                    ->and($url)->toContain('/cards/'.$locale.'/');

                if (is_string($dir) && $dir !== '') {
                    File::ensureDirectoryExists($dir);
                    File::put("{$dir}/{$type}-{$format}-{$locale}.png", $response->getContent());
                }
            }
        }
    }
});

test('a card is drawn once per content: the same card comes from the cache, a new name is a new file and URL', function () {
    $card = ShareCard::block($this->moments['block'], $this->user);
    $first = $card->png('wide');
    $url = $card->url('wide');

    expect(Storage::disk('local')->files('share-cards'))->toHaveCount(1)
        ->and($card->png('wide'))->toBe($first);

    $this->user->forceFill(['name' => 'hodlqueen'])->save();
    $renamed = ShareCard::block($this->moments['block'], $this->user->refresh());
    $renamed->png('wide');

    expect($renamed->url('wide'))->not->toBe($url)
        ->and(Storage::disk('local')->files('share-cards'))->toHaveCount(1);
});

test('only moments that happened are drawn', function () {
    $stranger = User::factory()->create();

    // The Silver III reveal is a rank up too; a block card for someone who did not mine it is not.
    $this->get(route('cards.rank-up', ['locale' => 'en', 'version' => $this->moments['versions'][0]->id, 'format' => 'wide'], false))->assertOk();
    $this->get(route('cards.block', ['locale' => 'en', 'block' => $this->moments['block']->id, 'npub' => $stranger->npub, 'format' => 'wide'], false))->assertNotFound();
    $this->get(route('cards.block', ['locale' => 'en', 'block' => $this->moments['block']->id, 'npub' => $this->user->npub, 'format' => 'square'], false))->assertNotFound();
    $this->get('/cards/fr/wrapped/pre-season/'.$this->user->npub.'-wide.png')->assertNotFound();

    $this->moments['tournament']->forceFill(['status' => TournamentStatus::Running])->save();
    $this->get(route('cards.tournament', ['locale' => 'en', 'tournament' => $this->moments['tournament']->id, 'format' => 'wide'], false))->assertNotFound();
});

test('the champion of a finished tournament is its best seed when the better seed always wins', function (TournamentFormat $format, int $players) {
    $tournament = runningChess($format, $players);
    playOutAsDirector($tournament);

    expect(app(TournamentChampion::class)->of($tournament->refresh())?->name)->toBe('Player 1');
})->with([
    'single elimination' => [TournamentFormat::SingleElimination, 8],
    'double elimination' => [TournamentFormat::DoubleElimination, 4],
    'round robin' => [TournamentFormat::RoundRobin, 4],
    'swiss' => [TournamentFormat::Swiss, 8],
]);

test('the wrapped card counts the player\'s blocks, sats and best rank of the season', function () {
    $facts = ShareCard::wrapped($this->season, $this->user)->facts;

    expect([$facts['blocks'], $facts['sats'], $facts['wins'], $facts['tournaments'], $facts['best']['tier']])->toBe([2, 10_000, 7, 1, 'gold-2'])
        ->and(ShareCard::block($this->moments['block'], $this->user)->facts['personal_height'])->toBe(2);
});
