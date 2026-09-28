{{--
    The open protocol (P29, /protocol): what the league publishes on Nostr
    and how anyone checks it. The kinds are read from the NIP document of
    this repository, the keys and relays from the config the server runs
    with (App\Support\Pages\ProtocolPage); nothing here is typed twice.
--}}
@php
    use App\Support\Pages\ProtocolPage;

    $kinds = ProtocolPage::kinds();
    $keys = ProtocolPage::keys();
    $relays = ProtocolPage::relays();
    $commands = ProtocolPage::commands();
    $league = collect($keys)->first()['npub'] ?? null;
    $sections = [
        ['published', __('What the league publishes')],
        ['other-kinds', __('Kinds from other NIPs')],
        ['keys', __('The league’s keys')],
        ['relays', __('Relays')],
        ['verify', __('Check it yourself')],
        ['document', __('The full protocol')],
    ];
    app(\App\Support\PageMeta::class)->describe(__('Open protocol'), __('What the TWENTY ONE esports league publishes on Nostr, with which keys and on which relays, and how to check every result yourself.'));
@endphp
<x-layouts::app :title="__('Open protocol')">
    <div class="flex flex-col gap-6 px-4 pb-10 lg:gap-8 lg:px-12 lg:pb-12" data-test="protocol-page">
        <header class="flex max-w-[64ch] flex-col gap-3">
            <h1 class="m-0 font-display text-[28px] leading-[1.1] font-bold lg:text-4xl">{{ __('Open protocol') }}</h1>
            <p class="m-0 text-[15px] leading-relaxed text-ink-2">{{ __('Results, ladders and tournaments are signed Nostr events. Anyone can read them from the relays and check every signature, without asking the league.') }}</p>
        </header>

        <x-doc-page :sections="$sections" :nav-label="__('Protocol sections')">
            <x-doc-section id="published" :title="__('What the league publishes')">
                <p class="m-0 max-w-[68ch] text-[15px] leading-relaxed">{{ __('The league’s own event kinds, as the NIP defines them.') }}</p>
                <ul class="m-0 flex list-none flex-col p-0" data-test="protocol-kinds">
                    @foreach ($kinds['own'] as $row)
                        <li class="grid grid-cols-[4.5rem_minmax(0,1fr)] gap-x-3 gap-y-0.5 border-b border-hairline py-2.5 text-[13px] last:border-0 lg:grid-cols-[5rem_12rem_minmax(0,1fr)_7rem]">
                            <b class="font-display text-[15px] text-btc-hi lg:row-span-1">{{ $row['kind'] }}</b>
                            <span class="font-bold">{{ $row['name'] }}</span>
                            <span class="col-start-2 text-ink-2 lg:col-start-auto">{{ $row['signer'] }}</span>
                            <span class="col-start-2 text-xs text-ink-3 lg:col-start-auto lg:text-right">{{ $row['class'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-doc-section>

            <x-doc-section id="other-kinds" :title="__('Kinds from other NIPs')">
                <p class="m-0 max-w-[68ch] text-[15px] leading-relaxed">{{ __('Kinds of other NIPs the league reads or writes, with the meaning the league gives them.') }}</p>
                <ul class="m-0 flex list-none flex-col p-0" data-test="protocol-reused">
                    @foreach ($kinds['reused'] as $row)
                        <li class="grid grid-cols-[4.5rem_minmax(0,1fr)] gap-x-3 gap-y-0.5 border-b border-hairline py-2.5 text-[13px] last:border-0 lg:grid-cols-[7rem_minmax(0,1fr)_minmax(0,16rem)_5rem]">
                            <b class="font-display text-[15px] break-words text-btc-hi">{{ $row['kind'] }}</b>
                            <span>{{ $row['use'] }}</span>
                            <span class="col-start-2 text-ink-2 lg:col-start-auto">{{ $row['signer'] }}</span>
                            <span class="col-start-2 text-xs text-ink-3 lg:col-start-auto lg:text-right">NIP-{{ $row['nip'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-doc-section>

            <x-doc-section id="keys" :title="__('The league’s keys')">
                <p class="m-0 max-w-[68ch] text-[15px] leading-relaxed">{{ __('Each key signs one kind of thing. Only the league key can sign a result, a ladder or a tournament.') }}</p>
                <ul class="m-0 grid list-none grid-cols-1 gap-2 p-0 lg:grid-cols-2" data-test="protocol-keys">
                    @foreach ($keys as $key)
                        <li class="flex min-w-0 flex-col gap-2 rounded-md bg-well px-4 py-3">
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <b class="text-[14px]">{{ $key['name'] }}</b>
                                <span class="text-xs leading-snug text-ink-2">{{ $key['signs'] }}</span>
                            </span>
                            @if ($key['npub'])
                                <span class="flex min-w-0 items-center gap-2">
                                    <code class="min-w-0 truncate rounded-sm bg-ground px-2 py-1 text-xs text-ink" title="{{ $key['npub'] }}" data-test="protocol-npub">{{ $key['npub'] }}</code>
                                    <x-copy-npub :npub="$key['npub']" :name="$key['name']" variant="button" />
                                    <a href="https://njump.me/{{ $key['npub'] }}" rel="noopener noreferrer" target="_blank" class="inline-flex min-h-11 shrink-0 items-center text-xs">{{ __('Profile') }}</a>
                                </span>
                            @else
                                <span class="text-xs text-ink-3" data-test="protocol-key-missing">{{ __('Not set up on this server.') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-doc-section>

            <x-doc-section id="relays" :title="__('Relays')">
                @foreach ([['league', __('League events go to'), __('No relay is set up on this server.')], ['chat', __('Encrypted chats and notifications use'), __('The chat is off on this server.')]] as [$group, $label, $empty])
                    <div class="flex flex-col gap-2" data-test="protocol-relays-{{ $group }}">
                        <b class="text-[13px] text-ink-2">{{ $label }}</b>
                        @if ($relays[$group] === [])
                            <p class="m-0 text-[13px] text-ink-3">{{ $empty }}</p>
                        @else
                            <ul class="m-0 flex list-none flex-wrap gap-2 p-0">
                                @foreach ($relays[$group] as $relay)
                                    <li><code class="inline-flex h-8 items-center rounded-md bg-well px-3 text-xs">{{ $relay }}</code></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
                <p class="m-0 max-w-[68ch] text-[13px] leading-relaxed text-ink-2">{{ __('The two sets are separate on purpose: a relay chosen for encrypted chats is not a place for league events, and the other way round.') }}</p>
            </x-doc-section>

            <x-doc-section id="verify" :title="__('Check it yourself')">
                <p class="m-0 max-w-[68ch] text-[15px] leading-relaxed">{{ __('With nak, a command-line Nostr tool, you read the league’s events straight from a relay and check their signatures.') }}</p>
                <ol class="m-0 flex list-none flex-col gap-4 p-0" data-test="protocol-commands">
                    @foreach ($commands as [$what, $command])
                        <li class="flex min-w-0 flex-col gap-1.5" x-data="{ copied: false }">
                            <span class="text-[13px] text-ink-2">{{ $what }}</span>
                            <span class="flex min-w-0 items-stretch gap-2">
                                <code class="block min-w-0 grow overflow-x-auto rounded-md bg-ground px-3 py-2.5 text-xs leading-relaxed whitespace-pre text-ink shadow-ring" data-test="protocol-command">{{ $command }}</code>
                                <button type="button" x-on:click="navigator.clipboard?.writeText(@js($command)).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                                        aria-label="{{ __('Copy command') }}" class="btn-w inline-flex size-11 shrink-0 cursor-pointer items-center justify-center self-center rounded-md border border-line bg-well text-ink-2">
                                    <x-icon name="copy" :size="16" x-show="! copied" /><x-icon name="check" :size="16" x-show="copied" x-cloak class="text-btc" />
                                </button>
                            </span>
                        </li>
                    @endforeach
                </ol>
                <p class="m-0 max-w-[68ch] text-[13px] leading-relaxed text-ink-2">{{ __('nak verify prints nothing when every signature holds, and names the event that fails.') }}
                    <a href="https://github.com/fiatjaf/nak" rel="noopener noreferrer" target="_blank" class="inline-flex min-h-11 items-center">{{ __('Get nak') }}</a></p>
            </x-doc-section>

            <x-doc-section id="document" :title="__('The full protocol')">
                <p class="m-0 max-w-[68ch] text-[15px] leading-relaxed">{{ __('The NIP describes every event, tag and rule, and how a client checks them. It lives in the league’s open source repository, with its full history.') }}</p>
                <p class="m-0 flex flex-wrap gap-x-5 text-[13px]">
                    <a href="{{ ProtocolPage::nipUrl() }}" rel="noopener noreferrer" target="_blank" class="inline-flex min-h-11 items-center" data-test="protocol-nip">{{ __('Read the NIP') }}</a>
                    @if ($league)<a href="https://njump.me/{{ $league }}" rel="noopener noreferrer" target="_blank" class="inline-flex min-h-11 items-center">{{ __('The league on Nostr') }}</a>@endif
                    <a href="{{ route('tournaments.index') }}" class="inline-flex min-h-11 items-center">{{ __('Tournaments') }}</a>
                    <a href="{{ route('rules') }}" class="inline-flex min-h-11 items-center">{{ __('Rules') }}</a>
                </p>
            </x-doc-section>
        </x-doc-page>
    </div>
</x-layouts::app>
