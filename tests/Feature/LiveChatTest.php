<?php

use App\Models\ChatMute;
use App\Models\User;
use App\Support\Lightning\Lnurl;
use App\Support\Nostr\NostrKeys;
use App\Support\Prizes\PoolInvoices;
use App\Support\SeasonChain\LeagueKey;
use App\Support\StreamChat\StreamChat;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
 * The stream chat on /live (P24): the config the page hands the browser
 * (address, relays, bot, zap signers, the viewer's key and mutes), the mute
 * action, the guest's way in, and a page that never asks for a chat it
 * cannot have. The chat itself runs in the browser (tests/Browser/LiveChatTest.php).
 */

beforeEach(function () {
    $this->stream = new TestSigner;
    config([
        'twentyone.nostr.npub' => NostrKeys::hexToNpub($this->stream->pubkey),
        'twentyone.stream.event.d' => 'twentyone-247',
        'twentyone.stream.relays' => ['wss://stream.example', 'wss://second.example'],
        'esports.stream_bot.chat_relays' => ['wss://chat.example', 'wss://stream.example'],
        'esports.stream_chat.relays' => null,
        'esports.profile_relays' => ['wss://profiles.example'],
    ]);
});

test('the chat reads and posts under the stream address on the stream relays plus the bot chat relays, each once', function () {
    $chat = StreamChat::current()->config(null);

    expect($chat['address'])->toBe('30311:'.$this->stream->pubkey.':twentyone-247')
        ->and($chat['relays'])->toBe(['wss://stream.example', 'wss://second.example', 'wss://chat.example'])
        ->and($chat['relayHint'])->toBe('wss://stream.example')
        ->and($chat['emojiRelays'])->toBe(['wss://profiles.example', 'wss://stream.example', 'wss://second.example', 'wss://chat.example'])
        ->and($chat['me'])->toBeNull()
        ->and($chat['maxLength'])->toBe(280)
        ->and($chat['cooldownMs'])->toBe(2000)
        ->and($chat['avatarUrl'])->toContain(StreamChat::AVATAR_PLACEHOLDER);
});

/** The URL inside a bech32 `lnurl` (LUD-01). */
function lnurlUrl(?string $lnurl): string
{
    [$hrp, $data] = \BitWasp\Bech32\decodeRaw((string) $lnurl);

    return $hrp.' '.implode('', array_map('chr', \BitWasp\Bech32\convertBits($data, count($data), 5, 8, false)));
}

test('the stream shows the receipts of the league’s LNURL server and still getalby’s, each bound to its own address', function () {
    fakeWallet();
    $league = (string) LeagueKey::lnurl()?->pubkey();
    $chat = StreamChat::current()->config(null);

    expect($chat['zapSigners'])->toBe(['79f00d3f5a19ec806189fcab03c1be4ff81d18ee4f653c88fac41fe03570f432', $league])
        ->and(array_map(fn (?string $lnurl): string => lnurlUrl($lnurl), $chat['zapLnurls']))->toBe([
            '79f00d3f5a19ec806189fcab03c1be4ff81d18ee4f653c88fac41fe03570f432' => 'lnurl https://getalby.com/.well-known/lnurlp/theben',
            $league => 'lnurl '.route('lnurl.pay', ['username' => 'pool']),
        ])
        ->and($chat['zapLnurls'][$league])->toBe(strtolower((string) $chat['zapLnurls'][$league]))
        // The shared getalby key counts only receipts from before its cutoff; the league key has none (audit L1).
        ->and($chat['zapUntil'])->toBe(['79f00d3f5a19ec806189fcab03c1be4ff81d18ee4f653c88fac41fe03570f432' => 1791590400])
        // A stream zap pays the pool key (the profile key, whose lud16 is the pool).
        ->and($chat['zapRecipient'])->toBe(LeagueKey::poolPubkey());

    // Without the league's server only the old signers show; another recipient when configured.
    $other = new TestSigner;
    config(['esports.wallet.lnurl_nsec' => null, 'esports.wallet.pool_npub' => null, 'esports.stream_chat.zap_signers_lud16' => 'not an address']);
    expect(StreamChat::current()->config(null))->zapSigners->toBe(['79f00d3f5a19ec806189fcab03c1be4ff81d18ee4f653c88fac41fe03570f432'])
        ->zapLnurls->toBe(['79f00d3f5a19ec806189fcab03c1be4ff81d18ee4f653c88fac41fe03570f432' => null])
        ->zapRecipient->toBe($this->stream->pubkey);

    config(['esports.stream_chat.zap_recipient' => NostrKeys::hexToNpub($other->pubkey)]);
    expect(StreamChat::current()->config(null)['zapRecipient'])->toBe($other->pubkey);
});

test('the profile’s lud16 is the league’s pool address, and a zap request the stream checks names that same LNURL', function () {
    config(['app.url' => 'https://esports.einundzwanzig.space']);
    URL::forceRootUrl('https://esports.einundzwanzig.space');
    URL::forceScheme('https');
    fakeWallet();

    $lud16 = (string) config('twentyone.profile.lud16');

    expect($lud16)->toBe(PoolInvoices::address())->toBe('pool@esports.einundzwanzig.space')
        ->and(Lnurl::fromAddress($lud16))->toBe(strtolower(PoolInvoices::lnurl()))
        ->and(StreamChat::zapLnurls()[(string) LeagueKey::lnurl()?->pubkey()])->toBe(Lnurl::fromAddress($lud16))
        ->and(lnurlUrl(Lnurl::fromAddress($lud16)))->toBe('lnurl https://esports.einundzwanzig.space/.well-known/lnurlp/pool');
});

test('set chat relays replace the stream relays, an empty setting switches the chat off, and junk is dropped', function () {
    config(['esports.stream_chat.relays' => ['wss://own.example', 'https://not-a-relay.example', 'wss://own.example']]);
    expect(StreamChat::current()->relays)->toBe(['wss://own.example']);

    config(['esports.stream_chat.relays' => []]);
    expect(StreamChat::current()->relays)->toBe([]);
});

test('the bot is named by its own key, and without a stream address there is no chat', function () {
    $bot = new TestSigner;
    config(['esports.stream_bot.nsec' => $bot->secret]);

    expect(StreamChat::current()->config(null)['bot'])->toBe($bot->pubkey);

    config(['twentyone.nostr.npub' => null, 'twentyone.nostr.nsec' => null]);
    expect(StreamChat::current())->toBeNull();

    $this->get(route('live'))->assertOk()
        ->assertSee('The chat needs the stream to be announced on Nostr, and it is not yet.')
        ->assertDontSee('x-data="liveChat(', false);
});

test('a guest reads the chat and gets a way in that comes back to /live', function () {
    $this->get(route('live'))->assertOk()
        ->assertSee('x-data="liveChat(', false)
        ->assertSee('data-test="live-chat-guest"', false)
        ->assertSee(route('login', ['then' => 'live']), false)
        ->assertDontSee('data-test="live-chat-form"', false)
        // The page's own entry (built name), not in app.js.
        ->assertSee('/build/assets/liveChat-', false);

    $this->get(route('login', ['then' => 'live']))->assertOk();
    expect(session('url.intended'))->toBe(route('live'));

    // Any other value, and an array, set nothing.
    session()->forget('url.intended');
    $this->get('/login?then[]=live')->assertOk();
    $this->get('/login?then=elsewhere')->assertOk();
    expect(session('url.intended'))->toBeNull();
});

test('a player writes: the config carries her key, name and mutes, and the form is there', function () {
    $player = User::factory()->create();
    $muted = (new TestSigner)->pubkey;
    ChatMute::query()->create(['user_id' => $player->id, 'muted_pubkey' => $muted]);

    $chat = StreamChat::current()->config($player);
    expect($chat['me'])->toBe($player->pubkey)
        ->and($chat['meName'])->toBe($player->displayName())
        ->and($chat['muted'])->toBe([$muted]);

    $this->actingAs($player)->get(route('live'))->assertOk()
        ->assertSee('data-test="live-chat-form"', false)
        ->assertSee('data-test="live-switch"', false)
        ->assertDontSee('data-test="live-chat-guest"', false);
});

test('mutes are the viewer\'s own: set, unset, never for herself, a guest or a malformed key', function () {
    $player = User::factory()->create();
    $other = (new TestSigner)->pubkey;

    $page = Livewire::actingAs($player)->test('pages::live');
    expect($page->instance()->setMuted($other, true))->toBeTrue()
        ->and($page->instance()->setMuted($other, true))->toBeTrue()
        ->and(ChatMute::query()->where('user_id', $player->id)->pluck('muted_pubkey')->all())->toBe([$other])
        ->and($page->instance()->setMuted($player->pubkey, true))->toBeFalse()
        ->and($page->instance()->setMuted('npub1notahexkey', true))->toBeFalse()
        ->and($page->instance()->setMuted($other, false))->toBeTrue()
        ->and(ChatMute::query()->count())->toBe(0);
});

test('a guest\'s mute stays in her browser: the server refuses it', function () {
    $guest = Livewire::test('pages::live');

    expect($guest->instance()->setMuted((new TestSigner)->pubkey, true))->toBeFalse()
        ->and(ChatMute::query()->count())->toBe(0);
});

test('the page survives its own poll with the chat on it', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::live')
        ->call('$refresh')->assertOk()
        ->call('setMuted', (new TestSigner)->pubkey, true)->assertOk();
});
