@props(['user' => null, 'name' => null, 'size' => 24, 'src' => null, 'background' => 'linear-gradient(135deg, #F9B25F, #B9640A)'])

{{--
    A player's picture. Pass `user`: the kind-0 picture (https only) or our own
    upload, and the Blockpile avatar (GeneratedAvatar.dc.html) when there is
    none or it fails to load. Never initials for a player. Corners come from
    the caller's classes (rounded-full by default).

    `data-avatar` lets resources/js/profiles.js swap in a picture that arrives
    after the page loaded; `data-profile-stale` asks it to read the profile
    from the relays (ProfileCache::isStale()).

    Without `user` (name and src only) the old initial disc remains, for the
    few places that do not hand a player over yet.
--}}
@if ($user)
    @php
        $name ??= $user->displayName();
        $picture = $user->avatarUrl();
        $generated = App\Support\Nostr\PlayerProfile::generatedAvatarUrl($user->pubkey);
        $generatedAlt = __(':name avatar, generated', ['name' => $name]);
        // Two radius utilities on one element: the stylesheet order would decide, not the caller.
        $shape = str_contains((string) $attributes->get('class'), 'rounded') ? '' : 'rounded-full';
    @endphp
    <img src="{{ $picture ?? $generated }}" width="{{ $size }}" height="{{ $size }}"
         alt="{{ $picture ? __(':name avatar', ['name' => $name]) : $generatedAlt }}"
         loading="lazy" decoding="async" referrerpolicy="no-referrer"
         data-avatar="{{ $user->pubkey }}" data-fallback="{{ $generated }}" data-fallback-alt="{{ $generatedAlt }}"
         @if (App\Support\Nostr\ProfileCache::isStale($user)) data-profile-stale @endif
         @if ($picture) onerror="this.onerror=null;this.src=this.dataset.fallback;this.alt=this.dataset.fallbackAlt" @endif
         {{ $attributes->class(['block shrink-0 bg-raised object-cover', $shape]) }}
         style="width: {{ $size }}px; height: {{ $size }}px">
@else
    <span aria-hidden="true"
          {{ $attributes->class('relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full font-display font-extrabold text-on-btc') }}
          style="width: {{ $size }}px; height: {{ $size }}px; font-size: {{ $size >= 28 ? 11 : 10 }}px; background: {{ $background }}">{{ mb_strtoupper(mb_substr((string) $name, 0, 1)) }}@if ($src)<img src="{{ $src }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="absolute inset-0 size-full object-cover" onerror="this.remove()">@endif</span>
@endif
