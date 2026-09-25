@props(['user'])

{{--
    A player's name that opens the player card (resources/js/profiles.js):
    hover or Enter on desktop, a tap on touch screens. A click still opens
    the player page. The slot replaces the plain name (e.g. avatar + name).
--}}
<a href="{{ route('players.show', $user->npub) }}" data-player-card="{{ $user->npub }}" data-pubkey="{{ $user->pubkey }}"
   data-player-name="{{ $user->displayName() }}" aria-haspopup="dialog"
   @if (App\Support\Nostr\ProfileCache::isStale($user)) data-profile-stale @endif
   {{ $attributes->class('text-ink hover:text-ink') }}>{{ $slot->isEmpty() ? $user->displayName() : $slot }}</a>
