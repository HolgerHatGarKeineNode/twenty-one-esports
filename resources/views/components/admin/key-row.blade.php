@props(['npub', 'user' => null, 'note' => null])

{{--
    One key of a role list (admins, organizers): the player when the key has
    an account here (picture, name, short npub), else the npub alone; the copy
    button either way, a quiet note ("that's you") and the row's action (slot).
--}}
<li {{ $attributes->class('flex min-h-14 items-center gap-3 border-t border-hairline py-2 first:border-t-0') }}>
    @if ($user)
        <x-avatar :user="$user" :size="32" />
        <span class="flex min-w-0 grow flex-col">
            <span class="flex flex-wrap items-baseline gap-x-2">
                <x-player-link :user="$user" class="inline-flex min-h-6 items-center text-[13px] font-bold [overflow-wrap:anywhere]" />
                @if ($note)<span class="text-xs text-ink-3">{{ $note }}</span>@endif
            </span>
            <span class="text-xs text-ink-3">{{ $user->shortNpub() }}</span>
        </span>
    @else
        <span class="size-8 shrink-0 rounded-full border-[1.5px] border-dashed border-dash" aria-hidden="true"></span>
        <span class="flex min-w-0 grow flex-col">
            <span class="font-mono text-xs break-all text-ink-2">{{ $npub }}</span>
            <span class="text-xs text-ink-3">{{ $note ?? __('No account here yet') }}</span>
        </span>
    @endif
    <x-copy-npub :npub="$npub" :name="$user?->displayName()" />
    @if ($slot->isNotEmpty())
        <span class="shrink-0">{{ $slot }}</span>
    @endif
</li>
