{{--
    The face of a tournament entry on the TV (P19): the player's avatar, or
    for a lineup the clan logo (the tag when there is none), or for a drawn
    mix team the first players' avatars. Sized by the caller through
    `--face` (in stage units, resources/css/tv.css).

    $entry: TournamentTv::entry() or null (an open side)
    $class: extra classes from the caller
--}}
@php($class ??= '')
@if ($entry === null)
    <span class="tv-face is-open {{ $class }}" aria-hidden="true"></span>
@elseif ($entry['user'])
    <x-avatar :user="$entry['user']" :size="160" class="tv-face rounded-[18%] {{ $class }}" />
@elseif ($entry['clan'])
    <x-clan-tag :clan="$entry['clan']" :tile="160" class="tv-face is-clan {{ $class }}" />
@elseif ($entry['users'] !== [])
    <span class="tv-face is-team {{ $class }}">
        @foreach (array_slice($entry['users'], 0, 3) as $member)
            <x-avatar :user="$member" :size="96" class="rounded-[18%]" />
        @endforeach
    </span>
@else
    <span class="tv-face is-open {{ $class }}" aria-hidden="true"></span>
@endif
