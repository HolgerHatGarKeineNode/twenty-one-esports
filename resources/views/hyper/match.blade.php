{{--
    A Hyperbitcoinization match, full-screen (plan "Hyperbitcoinization", P2): its own document without the league's
    header, navigation and footer (user: "Vollbild, nicht die Shell unserer Seite; ein Match geht als neuer Tab auf").
    The table is the prototype v21's, driven by the server (resources/js/hyper/match.js and game.js): the snapshot
    (HyperMatches::snapshot()) is in the page as JSON (#hyper-snapshot), the rest of the page's config (endpoints,
    texts, chat, clips) in #hyper-config (HyperMatchController::show()).

    The replay of a finished match (P3, HyperMatchController::replay()) is this page with `config.replay`: the same
    table, read-only, every hand open, and a bar to play, pause, step, change the pace and scrub through the plies.

    Order matters: three.js, GSAP, d3 and the map geometry are classic scripts at the end of the body; the Vite entry
    is a module and runs after them.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#040b1d">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if (request()->hasSession())
        <meta name="nostr-session" content="{{ auth()->user()?->pubkey ?? '' }}">
    @endif
    @php($reverb = config('broadcasting.connections.reverb'))
    @if (filled($reverb['key'] ?? null))
        <meta name="reverb" content="{{ json_encode(['key' => $reverb['key'], 'host' => $reverb['options']['host'] ?? request()->getHost(), 'port' => (int) ($reverb['options']['port'] ?? 443), 'scheme' => $reverb['options']['scheme'] ?? 'https']) }}">
    @endif
    @php($replay = isset($config['replay']))
    <title>{{ $replay ? __('Hyperbitcoinization · Replay') : __('Hyperbitcoinization · Round :round', ['round' => $snapshot['round']]) }} – TWENTY ONE esports</title>
    {{-- The link preview (P6, HyperMatchController::preview()): the match's card, the result once over. --}}
    @if ($preview ?? null)
        <meta property="og:site_name" content="TWENTY ONE esports">
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ $preview['title'] }}">
        <meta property="og:description" content="{{ $preview['description'] }}">
        <meta property="og:image" content="{{ $preview['image'] }}">
        <meta property="og:image:type" content="image/png">
        <meta property="og:image:width" content="{{ \App\Support\Cards\PageCard::WIDTH }}">
        <meta property="og:image:height" content="{{ \App\Support\Cards\PageCard::HEIGHT }}">
        <meta property="og:image:alt" content="{{ $preview['alt'] }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="{{ $preview['image'] }}">
    @endif
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @fonts
    @vite(['resources/css/hyper.css', 'resources/js/hyper/match.js'])
</head>
<body data-test="hyper-match" data-match="{{ $snapshot['id'] }}" data-me="{{ $snapshot['me'] ?? '' }}" @if ($replay) data-replay="1" @endif @class(['spectator' => $snapshot['me'] === null, 'replay' => $replay])>

<div id="boot" role="status" aria-label="{{ __('Loading') }}">
    <div class="boot-box">
        <div class="boot-logo">HYPER<i>₿</i>ITCOINIZATION</div>
        <div class="ring"></div>
        <small>{{ __('Setting up the table') }}</small>
    </div>
</div>

<div id="world">
    <canvas id="board" aria-hidden="true"></canvas>
    <svg id="map" viewBox="0 0 1600 860" preserveAspectRatio="xMidYMid meet" aria-label="{{ __('World map: 53 territories in 10 currency spaces') }}">
        <g id="viewport"></g>
    </svg>
</div>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" fill="none"/></symbol>
    <symbol id="i-sword" viewBox="0 0 24 24"><path d="M14.5 17.5L3 6V3h3l11.5 11.5M13 19l6-6M16 16l4 4M19 21l2-2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
    <symbol id="i-move" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><path d="M5 12l5 5L20 7" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
    <symbol id="i-bolt" viewBox="0 0 24 24"><path d="M13 2L4 14h7l-1 8 9-12h-7z" fill="currentColor"/></symbol>
    <symbol id="i-back" viewBox="0 0 24 24"><path d="M9 14L4 9l5-5M4 9h11a5 5 0 010 10h-3" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></symbol>
    <symbol id="i-dice" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="8" cy="8" r="1.6" fill="currentColor"/><circle cx="16" cy="16" r="1.6" fill="currentColor"/><circle cx="12" cy="12" r="1.6" fill="currentColor"/></symbol>
    <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 11v6M12 7.5v.5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></symbol>
    <symbol id="i-close" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" fill="none"/></symbol>
</svg>

<header class="hud" id="topbar">
    <div class="logo frame shadowed"><b>HYPER<i>₿</i>ITCOINIZATION</b><span class="logo-row"><small id="round-lbl">{{ __('Round :round', ['round' => $snapshot['round']]) }}</small>@unless ($replay)<small class="rated-chip" data-test="hyper-rated" data-rated="{{ $snapshot['rated'] ? '1' : '0' }}">{{ $snapshot['rated'] ? __('Rated') : __('Unrated') }}</small>@endunless</span></div>
    <span class="grow"></span>
    <div id="stepper">
        <div class="stepper-row">
            <div class="steps frame shadowed" role="list" aria-label="{{ __('Phases of a turn') }}">
                <div class="step" data-ph="buy" role="listitem"><img class="phi" src="/hyper/art/ph-recruit.webp?v=1" alt="">{{ __('Recruit') }}</div>
                <div class="step" data-ph="attack" role="listitem"><img class="phi" src="/hyper/art/ph-attack.webp?v=1" alt="">{{ __('Attack') }}</div>
                <div class="step" data-ph="fortify" role="listitem"><img class="phi" src="/hyper/art/ph-fortify.webp?v=1" alt="">{{ __('Fortify') }}</div>
            </div>
            <div id="turn-clock" class="frame shadowed" data-test="hyper-clock" hidden>
                <svg viewBox="0 0 36 36" aria-hidden="true"><circle class="ring-bg" cx="18" cy="18" r="15.9"/><circle class="ring-on" cx="18" cy="18" r="15.9" pathLength="100"/></svg>
                <b>90</b>
            </div>
        </div>
        <div id="instruct" class="frame shadowed" aria-live="polite"><svg><use href="#i-info"/></svg><span class="spectating">{{ __('Spectator') }}</span><span id="instruct-t">–</span></div>
    </div>
    <span class="grow"></span>
    <div class="tools frame shadowed">
        <button class="icon-btn" id="mode-btn" type="button" title="{{ __('Map view: currency spaces or owners (M)') }}" aria-label="{{ __('Switch the map view') }}"><svg viewBox="0 0 24 24"><path d="M3 6l6-3 6 3 6-3v15l-6 3-6-3-6 3z"/><path d="M9 3v15M15 6v15"/></svg></button>
        <button class="icon-btn" id="zoom-in" type="button" aria-label="{{ __('Zoom in') }}"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M11 8v6M8 11h6M21 21l-4.5-4.5"/></svg></button>
        <button class="icon-btn" id="zoom-out" type="button" aria-label="{{ __('Zoom out') }}"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M8 11h6M21 21l-4.5-4.5"/></svg></button>
        <button class="icon-btn" id="scenes-btn" type="button" aria-pressed="true" aria-label="{{ __('Cinematics (3D battles and card scenes)') }}" title="{{ __('Cinematics on/off (K)') }}"><svg viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M7 6l2 4M12 6l2 4M17 6l2 4"/></svg></button>
        <button class="icon-btn" id="music-btn" type="button" aria-pressed="true" aria-label="{{ __('Music') }}"><svg viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></button>
        <button class="icon-btn" id="sound-btn" type="button" aria-pressed="true" aria-label="{{ __('Effects (dice, clicks, explosions)') }}" title="{{ __('Effects on/off') }}"><svg viewBox="0 0 24 24"><path d="M11 5L6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 010 7M19 5a10 10 0 010 14"/></svg></button>
        <button class="icon-btn" id="board-btn" type="button" aria-pressed="true" aria-label="{{ __('Soundboard clips') }}" title="{{ __('Soundboard clips on/off') }}"><svg viewBox="0 0 24 24"><path d="M3 10v4h3l7 5V5L6 10z"/><path d="M17 9l4-2M17 15l4 2M17 12h5"/></svg></button>
        <label class="vol" for="vol"><span class="sr">{{ __('Volume') }}</span><input id="vol" type="range" min="0" max="100" value="80"></label>
        @if ($snapshot['me'] !== null)
            <button class="icon-btn" id="emote-btn" type="button" aria-label="{{ __('Emotes and soundboard') }}" title="{{ __('Emotes and soundboard') }}" data-test="hyper-emote-open"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.5a4.5 4.5 0 007 0M9 9.5h.01M15 9.5h.01"/></svg></button>
        @endif
        @if (! empty($config['poll']))
            {{-- The spectators' "Who wins?" (P5, HyperPoll): opens on a click, never by itself. --}}
            <button class="icon-btn" id="poll-btn" type="button" aria-label="{{ __('Who wins?') }}" title="{{ __('Who wins?') }}" data-test="hyper-poll-open"><svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg></button>
        @endif
        <button class="icon-btn" id="chat-btn" type="button" aria-label="{{ __('Table chat') }}" title="{{ __('Table chat') }}" data-test="hyper-chat-open"><svg viewBox="0 0 24 24"><path d="M21 12a8 8 0 01-11.6 7.1L4 20l1-4.6A8 8 0 1121 12z"/></svg><span class="badge" id="chat-unread" hidden>0</span></button>
        @if ($snapshot['me'] !== null && $snapshot['status'] === 'active')
            {{-- Leaving in plain sight (user 2026-10-09: it hid in the help dialog); the first click asks, the second leaves. --}}
            <button class="icon-btn" id="leave-btn" type="button" aria-label="{{ __('Leave the match') }}" title="{{ __('Leave the match') }}" data-test="hyper-leave"><svg viewBox="0 0 24 24"><path d="M14 4h5v16h-5"/><path d="M10 8l-4 4 4 4M6 12h10"/></svg></button>
        @endif
        <button class="icon-btn" id="help-btn" type="button" aria-label="{{ __('How to play') }}"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 015 .5c0 1.7-2.5 2-2.5 4M12 17h.01"/></svg></button>
    </div>
</header>

<aside class="hud" id="roster" aria-label="{{ __('Players') }}"></aside>

<section class="hud frame shadowed" id="treasury" aria-label="{{ __('Your treasury') }}">
    <div class="wallet">
        <div class="coin fiat"><img class="cimg" src="/hyper/art/ico-fiat.webp?v=1" alt=""><small>Fiat <em>−15 %</em></small><b id="fiat">0</b></div>
        <div class="coin sats"><img class="cimg" src="/hyper/art/ico-sats.webp?v=1" alt=""><small>{{ __('M sats') }} <em>+3 %</em></small><b id="sats">0</b></div>
    </div>
    <div class="goal"><span>{{ __('BANKS') }}</span><div class="seg" id="goal-seg"></div><span id="goal-num">0</span></div>
    <div id="recruit-ui">
        <div class="free" id="free-lbl" hidden></div>
        <div class="recruit">
            <button class="unit on" type="button" data-unit="pleb"><span class="up" data-por="pleb"></span><b>{{ __('Fiat pleb') }}</b><small id="pleb-cost">1 Fiat</small><span class="why">{{ __('Mass over class') }}</span></button>
            <button class="unit" type="button" data-unit="maxi"><span class="up" data-por="maxi"></span><b>{{ __('Bitcoin maxi') }}</b><small>2 Sats</small><span class="why">{{ __('HODL: +1 defence') }}</span></button>
            <button class="unit" type="button" data-unit="asic"><span class="up" data-por="asic"></span><b>{{ __('ASIC rig') }}</b><small>3 Sats</small><span class="why">{{ __('+1 attack, cracks banks') }}</span></button>
        </div>
        <div class="qty" role="group" aria-label="{{ __('Amount per click') }}"><button type="button" data-q="1" class="on">×1</button><button type="button" data-q="3">×3</button><button type="button" data-q="5">×5</button><button type="button" data-q="max">Max</button></div>
    </div>
</section>

<div class="hud" id="hand" aria-label="{{ __('Your event cards') }}"></div>

<div class="hud" id="cta">
    <div class="speed frame shadowed" id="speed" role="group" aria-label="{{ __('Pace of the other turns') }}"><button type="button" data-s="1" class="on">{{ __('Others 1×') }}</button><button type="button" data-s="3">3×</button><button type="button" data-s="20">{{ __('Instant') }}</button></div>
    <button class="ghost" id="undo-btn" type="button" hidden><svg><use href="#i-back"/></svg>{{ __('Undo placement') }}</button>
    <button class="big" id="main-btn" type="button" title="{{ __('Next (space)') }}" data-test="hyper-main"><span id="main-t">{{ __('Next step') }}</span><svg id="main-i"><use href="#i-move"/></svg></button>
</div>

@if ($replay)
    <div class="hud frame shadowed" id="replay-bar" role="group" aria-label="{{ __('Replay') }}" data-test="hyper-replay-bar">
        <button class="big" id="rp-play" type="button" data-test="hyper-replay-play" aria-label="{{ __('Play') }}"><svg><use href="#i-move"/></svg><span id="rp-play-t">{{ __('Play') }}</span></button>
        <button class="ghost" id="rp-step" type="button" data-test="hyper-replay-step" title="{{ __('One step') }}"><svg><use href="#i-check"/></svg>{{ __('Step') }}</button>
        <div class="speed" id="rp-speed" role="group" aria-label="{{ __('Replay pace') }}"><button type="button" data-s="1" class="on">1×</button><button type="button" data-s="3">3×</button><button type="button" data-s="20">{{ __('Instant') }}</button></div>
        <label class="rp-scrub"><span class="sr">{{ __('Jump to a move') }}</span><input id="rp-scrub" type="range" min="0" max="{{ $config['replay']['last'] }}" value="0" data-test="hyper-replay-scrub"></label>
        <output id="rp-ply" data-test="hyper-replay-ply">0 / {{ $config['replay']['last'] }}</output>
    </div>
@endif

<aside class="hud frame shadowed" id="legend" aria-label="{{ __('Currency spaces') }}"></aside>
<aside class="hud frame shadowed" id="ticker" aria-label="{{ __('Chronicle') }}" aria-live="polite"></aside>

<div id="tip" class="frame shadowed" role="tooltip"></div>
<div id="flash"></div>
<div id="banner" hidden data-test="hyper-banner"><div class="bx"><div class="bp" id="banner-p"></div><div class="bt" id="banner-t"></div><div class="bs" id="banner-s"></div></div><button class="ghost bh-tap" type="button" data-test="hyper-banner-continue">{{ __('Tap to continue') }}</button></div>
<div id="emote-layer" aria-live="polite"></div>

<div id="battle" hidden role="dialog" aria-modal="true" aria-labelledby="battle-h" data-test="hyper-battle">
    <canvas id="arena3d" aria-hidden="true"></canvas>
    <div class="b-vig"></div>
    <div class="cine-hint">{{ __('Tap to skip') }}</div>
    <div class="b-load" id="b-load" hidden><div class="ring"></div><b>{{ __('Preparing the battlefield') }}</b><span id="b-load-p">0 %</span><div class="bar"><i id="b-load-bar"></i></div></div>
    <div class="ev frame shadowed" id="ev" hidden><div class="ev-pic" id="ev-pic"></div><div class="ev-txt"><small>{{ __('Event card') }}</small><h3 id="ev-h">–</h3><p id="ev-t">–</p><em id="ev-j">–</em></div></div>
    <div class="bh"><small id="b-kicker">{{ __('Battle for') }}</small><h2 id="battle-h">–</h2><div class="meme" id="b-meme"></div></div>
    <div class="army" id="b-att"></div>
    <div class="army" id="b-def"></div>
    <div class="felt" id="felt" hidden><div class="lane" id="dice-a"></div><div class="vsmark">VS</div><div class="lane" id="dice-d"></div></div>
    <div class="b-dock">
        <div class="b-result" id="b-result" aria-live="polite"></div>
        <div class="frame" id="b-mods"></div>
        <div class="tug"><span>{{ __('Offence') }}</span><div class="bar" id="tug"><i id="odds-bar" style="width:50%"></i><b id="odds-n">–</b></div><span>{{ __('Defence') }}</span></div>
        <div class="bbtns">
            <button class="big" id="roll-btn" type="button" title="{{ __('Roll (W)') }}" data-test="hyper-roll"><svg><use href="#i-dice"/></svg>{{ __('Roll') }}</button>
            <button class="ghost" id="blitz-btn" type="button" title="{{ __('Until the conquest (E)') }}" data-test="hyper-blitz"><svg><use href="#i-bolt"/></svg>{{ __('Until the conquest') }}</button>
            <button class="ghost" id="retreat-btn" type="button" title="{{ __('Retreat (Esc)') }}"><svg><use href="#i-back"/></svg>{{ __('Retreat') }}</button>
            <button class="ghost" id="skip-btn" type="button" hidden><svg><use href="#i-bolt"/></svg>{{ __('Skip') }}</button>
        </div>
    </div>
</div>

<div class="overlay" id="move" hidden data-test="hyper-move">
    <div class="panel frame" role="dialog" aria-modal="true" aria-labelledby="move-h">
        <h2 id="move-h">{{ __('Move troops in') }}</h2>
        <p class="muted" id="move-sub">–</p>
        <div class="move-num" id="move-n">1</div>
        <label class="sr" for="move-range">{{ __('Number of troops') }}</label>
        <input type="range" id="move-range" min="1" max="1" value="1">
        <div class="row-btn"><button class="big" id="move-ok" type="button" data-test="hyper-move-ok"><svg><use href="#i-check"/></svg>{{ __('Confirm') }}</button><button class="ghost" id="move-cancel" type="button">{{ __('Cancel') }}</button></div>
    </div>
</div>

<div class="overlay" id="help" hidden>
    <div class="panel frame" role="dialog" aria-modal="true" aria-labelledby="help-h" style="width:min(780px,100%)">
        <h2 id="help-h">{{ __('How to play') }}</h2>
        <div class="how" style="margin-top:12px" id="help-how"></div>
        <div class="keys" aria-label="{{ __('Keyboard shortcuts') }}"><span><kbd>{{ __('Space') }}</kbd> {{ __('next') }}</span><span><kbd>W</kbd> {{ __('roll') }}</span><span><kbd>E</kbd> {{ __('until the conquest') }}</span><span><kbd>Esc</kbd> {{ __('retreat / cancel') }}</span><span><kbd>Z</kbd> {{ __('undo') }}</span><span><kbd>1</kbd><kbd>3</kbd><kbd>5</kbd><kbd>0</kbd> {{ __('troop amount') }}</span><span><kbd>M</kbd> {{ __('map view') }}</span><span><kbd>K</kbd> {{ __('cinematics') }}</span><span><kbd>H</kbd> {{ __('help') }}</span></div>
        <p class="muted" style="margin-top:12px">{{ __('Central banks (columns) defend with +1 unless an ASIC rig attacks along. Switzerland is a vault: always +1. A maxi in the territory gives +1. Whoever conquers in a turn draws an event card at the end of it. Dashed lines are sea routes. A turn has :seconds seconds.', ['seconds' => $snapshot['turn_seconds']]) }}</p>
        <div class="row-btn">
            <button class="big" id="help-close" type="button"><svg><use href="#i-check"/></svg>{{ __('Got it') }}</button>
        </div>
    </div>
</div>

<div class="overlay" id="end" hidden data-test="hyper-end"><div class="end-art" id="end-art"></div>
    <div class="panel frame" role="dialog" aria-modal="true" aria-labelledby="end-h" style="text-align:center">
        <div class="end-team" id="end-team" hidden data-test="hyper-end-team"></div>
        <h2 id="end-h">–</h2>
        <p class="muted" id="end-sub">–</p>
        <p class="end-loot" id="end-loot" data-test="hyper-end-loot"></p>
        <div class="row-btn" style="justify-content:center">
            @if (! $replay && $snapshot['me'] !== null)
                <button class="big" id="rematch-btn" type="button" data-test="hyper-rematch"><svg><use href="#i-dice"/></svg><span id="rematch-t">{{ __('Rematch') }}</span></button>
                {{-- P6: any player says no; the rematch closes for everybody (game.js shows it once somebody asked). --}}
                <button class="ghost" id="rematch-decline" type="button" hidden data-test="hyper-rematch-decline">{{ __('Decline the rematch') }}</button>
            @endif
            <a @class(['ghost' => ! $replay && $snapshot['me'] !== null, 'big' => $replay || $snapshot['me'] === null]) id="again-btn" href="{{ $back }}">{{ __('Another match') }}</a>
            @unless ($replay)
                <a class="ghost" id="replay-link" href="{{ $config['urls']['replay'] }}" target="_blank" rel="noopener" data-test="hyper-replay-link">{{ __('Watch the replay') }}</a>
            @endunless
            <button class="ghost" id="stats-btn" type="button" hidden data-test="hyper-stats-open">{{ __('Match statistics') }}</button>
            @if (! $replay && $snapshot['me'] !== null)
                {{-- A win or loot (P6): posted from the lobby page's moments, in a new tab (game.js shows it). --}}
                <a class="ghost" id="share-link" href="{{ route('hyper.index') }}#hyper-moments" target="_blank" rel="noopener" hidden data-test="hyper-share-link">{{ __('Share it') }}</a>
            @endif
            <button class="ghost" id="end-map" type="button">{{ __('Look at the map') }}</button>
        </div>
        <p class="muted" id="rematch-note" aria-live="polite" data-test="hyper-rematch-note"></p>
    </div>
</div>

{{-- The end-of-match statistics (P3b, resources/js/hyper/stats.js): charts, leaderboards, teams (P4), a scene per moment. --}}
<div class="overlay" id="stats" hidden data-test="hyper-stats" role="dialog" aria-modal="true" aria-labelledby="stats-h">
    <div class="st-box frame shadowed">
        <header class="st-head">
            <h2 id="stats-h">{{ __('Match statistics') }}</h2>
            <nav class="st-tabs" id="st-tabs" role="tablist" aria-label="{{ __('Match statistics') }}"></nav>
            <button class="ghost" id="st-skip" type="button" data-test="hyper-stats-skip">{{ __('Skip all') }}</button>
        </header>
        <div class="st-progress" aria-hidden="true"><i id="st-bar"></i></div>
        <div class="st-page" id="st-page" aria-live="polite"></div>
        <div class="st-foot">
            <button class="ghost" id="st-prev" type="button" aria-label="{{ __('Previous') }}" data-test="hyper-stats-prev"><svg><use href="#i-back"/></svg></button>
            <output id="st-count">1 / 1</output>
            {{-- Shows once the page's animation is over (stats.js): no page turns by itself. --}}
            <button class="big" id="st-next" type="button" data-test="hyper-stats-next" hidden><span>{{ __('Next step') }}</span><svg><use href="#i-move"/></svg></button>
        </div>
    </div>
</div>

<div id="emotes" class="frame shadowed" hidden data-test="hyper-emotes" role="dialog" aria-labelledby="emotes-h">
    <header><h2 id="emotes-h">{{ __('Emotes') }}</h2><button class="icon-btn" id="emote-close" type="button" aria-label="{{ __('Close') }}"><svg viewBox="0 0 24 24"><use href="#i-close"/></svg></button></header>
    <div id="emote-stickers"></div>
    @if (! empty($config['clips']))
    <input id="emote-filter" type="search" placeholder="{{ __('Find a soundboard clip') }}" aria-label="{{ __('Find a soundboard clip') }}">
    @endif
    <div id="emote-clips" role="list"></div>
    <p id="emote-note" aria-live="polite"></p>
</div>

@if (! empty($config['poll']))
    <section id="poll" class="frame shadowed" hidden data-test="hyper-poll" aria-labelledby="poll-h">
        <header><h2 id="poll-h">{{ __('Who wins?') }}</h2><button class="icon-btn" id="poll-close" type="button" aria-label="{{ __('Close') }}"><svg viewBox="0 0 24 24"><use href="#i-close"/></svg></button></header>
        <ol id="poll-options" aria-label="{{ __('Answers') }}"></ol>
        <p id="poll-total" data-test="hyper-poll-total"></p>
        <p id="poll-note" aria-live="polite" data-test="hyper-poll-note"></p>
    </section>
@endif

@php($team = $config['teamChat'] ?? null)
<aside id="chat" class="frame shadowed" hidden data-test="hyper-chat" aria-labelledby="chat-h">
    <header><h2 id="chat-h">{{ __('Table chat') }}</h2><small id="chat-sub">{{ __('Public on Nostr') }}</small><button class="icon-btn" id="chat-close" type="button" aria-label="{{ __('Close the chat') }}"><svg viewBox="0 0 24 24"><use href="#i-close"/></svg></button></header>
    @if ($team)
        {{-- A team match (P4): the public table chat and the team's private one, as two tabs. --}}
        <div class="chat-tabs" role="tablist" aria-label="{{ __('Chats') }}">
            <button type="button" role="tab" id="tab-table" aria-selected="true" aria-controls="table-pane" data-test="hyper-chat-tab-table">{{ __('Table') }}</button>
            <button type="button" role="tab" id="tab-team" aria-selected="false" aria-controls="team-pane" data-test="hyper-chat-tab-team">{{ __('Team') }}<span class="badge" id="team-unread" hidden>0</span></button>
        </div>
    @endif
    <div id="table-pane" class="chat-pane" role="tabpanel" aria-labelledby="{{ $team ? 'tab-table' : 'chat-h' }}">
        <p id="chat-off" hidden data-test="hyper-chat-off">{{ __('The table chat is off here: no chat channel or relay is set up.') }}</p>
        <ol id="chat-list" role="log" aria-label="{{ __('Chat messages') }}" data-test="hyper-chat-list"></ol>
        <form id="chat-form" autocomplete="off"><label class="sr" for="chat-input">{{ __('Message') }}</label><input id="chat-input" type="text" enterkeyhint="send" placeholder="{{ __('Write to the table …') }}" data-test="hyper-chat-input"><button class="ghost" type="submit" data-test="hyper-chat-send">{{ __('Send') }}</button></form>
        <p id="chat-note" aria-live="polite"></p>
    </div>
    @if ($team)
        <div id="team-pane" class="chat-pane" role="tabpanel" aria-labelledby="tab-team" hidden data-test="hyper-team-chat">
            <ol id="team-list" role="log" aria-label="{{ __('Team messages') }}" data-test="hyper-team-list"></ol>
            <form id="team-form" autocomplete="off"><label class="sr" for="team-input">{{ __('Message to your team') }}</label><input id="team-input" type="text" enterkeyhint="send" placeholder="{{ __('Write to your team …') }}" data-test="hyper-team-input"><button class="ghost" type="submit" data-test="hyper-team-send">{{ __('Send') }}</button></form>
            <p id="team-note" aria-live="polite" data-test="hyper-team-note"></p>
        </div>
    @endif
</aside>

<script type="application/json" id="hyper-snapshot">@json($snapshot)</script>
<script type="application/json" id="hyper-config">@json($config)</script>
<script>window.HYPER_ASSETS = '/hyper/';</script>
<script src="/hyper/vendor/gsap.min.js"></script>
<script src="/hyper/vendor/d3.min.js"></script>
<script src="/hyper/vendor/three.min.js"></script>
<script src="/hyper/mapdata.js?v=6"></script>
</body>
</html>
