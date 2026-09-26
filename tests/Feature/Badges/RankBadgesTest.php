<?php

use App\Models\ChessGame;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\QuestBadgeAward;
use App\Models\RankBadge;
use App\Models\Rating;
use App\Models\User;
use App\Support\Badges\QuestBadges;
use App\Support\Badges\RankBadges;
use App\Support\Nostr\SignedEvent;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\TrustFacts;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

/**
 * NIP-58 rank badges (P11, NIP "Rank badges"): one definition per player,
 * game and mode, replaced on every rank change and signed by the badge key;
 * exactly one award; only from rated results.
 */
beforeEach(function () {
    $this->season = openSeason(['slug' => 'pre-season']);
    $this->badgeKey = new TestSigner;
    config(['esports.badges.nsec' => $this->badgeKey->secret]);
});

function rankedPlayer(int $rating, int $results = 5, string $game = 'chess', string $mode = 'blitz'): array
{
    $user = User::factory()->create();
    $row = Rating::query()->create([
        'pool' => Rating::RATED, 'season' => 'pre-season', 'game' => $game, 'mode' => $mode,
        'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => $rating, 'results' => $results,
    ]);

    return [$user, $row];
}

/** @return list<NostrEvent> */
function badgeEvents(int $kind, ?string $d = null): array
{
    return NostrEvent::query()->where('kind', $kind)->when($d !== null, fn ($query) => $query->where('d', $d))->orderBy('id')->get()->all();
}

function tagOf(NostrEvent $event, string $name): ?array
{
    foreach ($event->payload()['tags'] as $tag) {
        if ($tag[0] === $name) {
            return $tag;
        }
    }

    return null;
}

test('ten rank changes give ten versions of the same definition and one award', function () {
    [$user, $row] = rankedPlayer(1000);
    $badges = app(RankBadges::class);
    $d = 'rank/chess/blitz/'.$user->pubkey;
    // Ten tiers in a row, up and down: silver-3, gold-1, gold-2, gold-1, platinum-1 ...
    $ratings = [1000, 1030, 1060, 1040, 1110, 1180, 1130, 1210, 1260, 1330];

    foreach ($ratings as $rating) {
        $row->forceFill(['rating' => $rating])->save();
        expect($badges->sync($user, 'chess', 'blitz'))->not->toBeNull();
        // The same rating again, or a rating inside the same tier, signs nothing.
        expect($badges->sync($user, 'chess', 'blitz'))->toBeNull();
    }

    $definitions = badgeEvents(RankBadge::DEFINITION, $d);
    $awards = badgeEvents(RankBadge::AWARD);
    $address = '30009:'.$this->badgeKey->pubkey.':'.$d;

    expect($definitions)->toHaveCount(10)
        ->and(collect($definitions)->pluck('pubkey')->unique()->all())->toBe([$this->badgeKey->pubkey])
        ->and(collect($definitions)->pluck('signed_at')->all())->toBe(collect($definitions)->pluck('signed_at')->sort()->unique()->values()->all())
        ->and(array_map(fn (NostrEvent $event) => tagOf($event, 'name')[1], $definitions))->toBe([
            'Chess blitz · Silver III', 'Chess blitz · Gold I', 'Chess blitz · Gold II', 'Chess blitz · Gold I', 'Chess blitz · Platinum I',
            'Chess blitz · Diamond I', 'Chess blitz · Platinum II', 'Chess blitz · Diamond II', 'Chess blitz · Champion I', 'Chess blitz · Grand Champion I',
        ])
        // One image URL per game and tier, never per player.
        ->and(tagOf($definitions[9], 'image'))->toBe(['image', config('app.url').'/badges/rank/chess/grand-champion-1-v1.png', '1024x1024'])
        ->and(tagOf($definitions[9], 'thumb'))->toBe(['thumb', config('app.url').'/badges/rank/chess/grand-champion-1-v1-256.png', '256x256'])
        ->and(tagOf($definitions[9], 'p'))->toBe(['p', $user->pubkey])
        ->and(tagOf($definitions[9], 'a'))->toBe(['a', '32152:'.$this->season->league_pubkey.':chess/blitz/pre-season'])
        ->and(tagOf($definitions[9], 'description')[1])->toContain('Pre-Season')
        ->and($awards)->toHaveCount(1)
        ->and($awards[0]->pubkey)->toBe($this->badgeKey->pubkey)
        ->and(tagOf($awards[0], 'a'))->toBe(['a', $address])
        ->and(tagOf($awards[0], 'p'))->toBe(['p', $user->pubkey])
        ->and(RankBadge::query()->sole()->only(['tier', 'season', 'definition_event_id', 'award_event_id']))
        ->toBe(['tier' => 'grand-champion-1', 'season' => 'pre-season', 'definition_event_id' => $definitions[9]->id, 'award_event_id' => $awards[0]->id])
        ->and(RankBadge::query()->sole()->versions()->count())->toBe(10);

    // Every stored event verifies and is queued for the league relays.
    foreach ([...$definitions, ...$awards] as $event) {
        expect(SignedEvent::fromInput($event->payload())?->hasValidSignature())->toBeTrue()
            ->and($event->queued_at)->not->toBeNull();
    }
});

test('a rated result signs the badge through the rating service, a casual one never does', function () {
    app()->bind(TrustFacts::class, TrustedFacts::class);
    [$white] = rankedPlayer(1040);
    [$black] = rankedPlayer(1000);

    app(RatingService::class)->applyChessGame(ChessGame::factory()->finished('1-0')->create(['white_id' => $white->id, 'black_id' => $black->id]));

    expect(badgeEvents(RankBadge::DEFINITION))->toBe([])
        ->and(badgeEvents(RankBadge::AWARD))->toBe([]);

    app(RatingService::class)->applyChessGame(ChessGame::factory()->rated()->finished('1-0')->create(['white_id' => $white->id, 'black_id' => $black->id]));

    // 1040 + 16 = 1056 (Gold II), 1000 - 16 = 984 (Silver II): both players get a badge.
    expect(collect(badgeEvents(RankBadge::DEFINITION))->map(fn (NostrEvent $event) => [$event->d, tagOf($event, 'name')[1]])->all())->toBe([
        ['rank/chess/blitz/'.$white->pubkey, 'Chess blitz · Gold II'],
        ['rank/chess/blitz/'.$black->pubkey, 'Chess blitz · Silver II'],
    ])->and(badgeEvents(RankBadge::AWARD))->toHaveCount(2);
});

test('no badge for a provisional player, without the badge key, or outside a live season', function () {
    [$provisional] = rankedPlayer(1300, 4);
    [$ranked] = rankedPlayer(1300);
    $badges = app(RankBadges::class);

    expect($badges->sync($provisional, 'chess', 'blitz'))->toBeNull();

    config(['esports.badges.nsec' => null]);
    expect($badges->sync($ranked, 'chess', 'blitz'))->toBeNull();

    config(['esports.badges.nsec' => $this->badgeKey->secret]);
    $this->season->forceFill(['ends_at' => now()->subMinute()])->save();
    expect($badges->sync($ranked, 'chess', 'blitz'))->toBeNull()
        ->and(NostrEvent::query()->whereIn('kind', [RankBadge::DEFINITION, RankBadge::AWARD])->count())->toBe(0);
});

test('a lineup ladder gives every active player of the lineup the lineup\'s tier', function () {
    $lineup = Lineup::factory()->mode('2v2')->ready()->create();
    Rating::query()->create([
        'pool' => Rating::RATED, 'season' => 'pre-season', 'game' => 'rocket-league', 'mode' => '2v2',
        'subject' => 'lineup:'.$lineup->id, 'lineup_id' => $lineup->id, 'rating' => 1110, 'results' => 6,
    ]);

    app(RankBadges::class)->syncSubjects('rocket-league', '2v2', ['lineup:'.$lineup->id]);

    $players = $lineup->seats()->with('user')->get()->pluck('user.pubkey')->sort()->values()->all();

    expect($players)->toHaveCount(2)
        ->and(collect(badgeEvents(RankBadge::DEFINITION))->map(fn (NostrEvent $event) => tagOf($event, 'p')[1])->sort()->values()->all())->toBe($players)
        ->and(tagOf(badgeEvents(RankBadge::DEFINITION)[0], 'name')[1])->toBe('Rocket League 2v2 · Platinum I');
});

test('a quest badge is one shared definition with one award per player, however often it is called', function () {
    [$first, $second] = User::factory()->count(2)->create();
    $quests = app(QuestBadges::class);
    $award = fn (User $user) => $quests->award($user, 'first-block', 'First block', 'Mined a first block in TWENTY ONE Esports.', config('app.url').'/badges/quest/first-block-v1.png');

    $award($first);
    $award($first);
    $award($second);

    expect(badgeEvents(RankBadge::DEFINITION, 'quest/first-block'))->toHaveCount(1)
        ->and(collect(badgeEvents(RankBadge::AWARD))->map(fn (NostrEvent $event) => [tagOf($event, 'a')[1], tagOf($event, 'p')[1]])->all())->toBe([
            ['30009:'.$this->badgeKey->pubkey.':quest/first-block', $first->pubkey],
            ['30009:'.$this->badgeKey->pubkey.':quest/first-block', $second->pubkey],
        ])
        ->and(QuestBadgeAward::query()->count())->toBe(2)
        ->and($quests->award($first, 'Not A Slug', 'x', 'x', 'https://x'))->toBeNull();
});

test('the badge artwork is a square PNG per game and tier, 1024 and 256', function () {
    foreach (['' => 1024, '-256' => 256] as $suffix => $size) {
        $response = $this->get('/badges/rank/chess/gold-2-v1'.$suffix.'.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $info = getimagesizefromstring($response->getContent());

        expect([$info[0], $info[1]])->toBe([$size, $size]);
    }

    $this->get('/badges/rank/chess/provisional-v1.png')->assertNotFound();
    $this->get('/badges/rank/tetris/gold-2-v1.png')->assertNotFound();
});
