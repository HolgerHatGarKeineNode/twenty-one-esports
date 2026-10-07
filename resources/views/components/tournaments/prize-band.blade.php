@props(['game', 'next' => null, 'last' => null, 'lastPot' => null, 'lastPaid' => 0, 'podium' => [], 'watching' => null])

{{--
    What there is to win in a game, and what was won (plan "RL-Startseite",
    P2; artboard "Gewählt · Mischung"; a player in the chat, 2026-10-05: "Ich
    habe auf der RL Seite nach den "Preisen" gesucht"). Three cards and one
    primary button to this game's tournaments (`/tournaments?game=`), never
    to the global list:

    - the next tournament open for sign-up as its poster (with its pot), or
      "none open" with "Notify me of new <game> tournaments" (TournamentWatch;
      `watching`: null for a guest, who is asked to log in);
    - the last finished tournament with a prize pot: its pot, what was paid
      out (PrizePool: a prize passed on to the next pot is not paid) and its
      podium; "No tournament with prize money yet" without one;
    - the casual cups, said plainly: no prize, Elo only. They never look
      like a prize tournament (the feedback's core).

    The cards size by the band's own width (a container query): beside the
    game chat on a desktop the column is narrow, so they stack until there is
    room for two side by side.
--}}
@php
    $gameName = \App\Support\GameNames::game((string) $game);
    $sats = fn (int $value): string => \App\Support\Cards\ShareCard::sats($value);
    $card = 'flex min-w-0 flex-col gap-2 rounded-lg bg-card px-4 py-4 shadow-ring lg:px-5';
    $medal = [1 => 'text-btc', 2 => 'text-ink', 3 => 'text-ink-2'];
@endphp

<section id="game-prizes" aria-labelledby="game-prizes-h" {{ $attributes->class('@container flex scroll-mt-24 flex-col gap-3') }} data-test="prize-band">
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <h2 id="game-prizes-h" class="m-0 font-display text-lg leading-tight font-bold lg:text-xl">{{ __('Tournaments & prizes') }}</h2>
    </div>

    <div class="flex flex-col gap-2" data-test="game-next-tournament">
        @if ($next !== null)
            <x-tournaments.poster :tournament="$next" heading-id="game-next-h" />
        @else
            <x-tournaments.next-empty :game="$game" heading-id="game-next-h">
                @if ($watching === null)
                    <x-button variant="secondary" :href="route('login')" icon="bell" data-test="prize-watch-login">{{ __('Log in to get notified') }}</x-button>
                @elseif ($watching)
                    <x-button variant="secondary" wire:click="toggleTournamentWatch" icon="check" aria-pressed="true" data-test="prize-watch" data-watching="1">{{ __('Notifying you · Turn off') }}</x-button>
                @else
                    <x-button variant="secondary" wire:click="toggleTournamentWatch" icon="bell" aria-pressed="false" data-test="prize-watch" data-watching="0">{{ __('Notify me of new :game tournaments', ['game' => $gameName]) }}</x-button>
                @endif
            </x-tournaments.next-empty>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-3 @2xl:grid-cols-2">
        <article class="{{ $card }}" aria-labelledby="prize-last-h" data-test="prize-last">
            <h3 id="prize-last-h" class="m-0 text-xs font-bold tracking-wide text-ink-2 uppercase">{{ __('Last prize tournament') }}</h3>
            @if ($last === null)
                <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="prize-last-empty">{{ __('No tournament with prize money yet.') }}</p>
            @else
                <a href="{{ route('tournaments.show', $last) }}" class="text-[15px] leading-snug font-bold break-words" data-test="prize-last-name">{{ $last->name }}</a>
                <p class="m-0 flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-ink-2">
                    @if ($lastPot !== null)
                        <span>{{ __('Pot') }} <b class="font-display text-base text-btc-hi tabular-nums" data-test="prize-last-pot">{{ $sats($lastPot) }}</b> sats</span>
                    @endif
                    @if ($lastPaid > 0)
                        <span>{{ __('Paid out') }} <b class="text-ink tabular-nums" data-test="prize-last-paid">{{ $sats($lastPaid) }}</b> sats</span>
                    @endif
                </p>
                @if ($podium !== [])
                    <ol class="m-0 flex list-none flex-col gap-1 p-0 text-[13px]" aria-label="{{ __('Podium') }}" data-test="prize-podium">
                        @foreach ($podium as $row)
                            <li class="flex min-w-0 items-baseline gap-2" data-test="prize-podium-{{ $row['place'] }}">
                                <b class="w-6 shrink-0 font-display {{ $medal[$row['place']] ?? 'text-ink-2' }}">{{ $row['place'] }}.</b>
                                <span class="min-w-0 break-words">{{ implode(', ', $row['names']) }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            @endif
        </article>

        <article class="{{ $card }}" aria-labelledby="prize-cups-h" data-test="prize-cups">
            <h3 id="prize-cups-h" class="m-0 text-xs font-bold tracking-wide text-ink-2 uppercase">{{ __('Casual cups') }}</h3>
            <p class="m-0"><span class="inline-flex min-h-7 items-center rounded-tag bg-raised px-2.5 text-xs font-bold text-ink" data-test="prize-cups-none">{{ __('No prize, Elo only') }}</span></p>
            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('The league opens them on its own, for EU and US evenings. You play for casual Elo, not for sats.') }}</p>
            <a href="#game-cups" class="mt-auto inline-flex min-h-11 items-center text-[13px] font-bold">{{ __('See the cups') }}</a>
        </article>
    </div>

    <x-button :href="route('tournaments.index', ['game' => $game])" icon="trophy" class="w-full @xl:w-auto @xl:self-start" data-test="prize-band-all">{{ __('All :game tournaments', ['game' => $gameName]) }}</x-button>
</section>
