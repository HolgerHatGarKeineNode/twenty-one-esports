{{--
    A4 · Arena · join in three steps. Full orange frame, three numbered steps (a real sequence), the real counts
    in the footer. The footer sentence drops what is missing and disappears when nothing is known. The brand backdrop
    shows faintly through the orange.

    Data contract:
      $stats     array{players?: int, clans?: int, …}
      $backdrop  ?string, optional: as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $players = K::count($stats ?? [], 'players');
    $clans = K::count($stats ?? [], 'clans');
    $parts = array_filter([K::plural($players, 'player', 'players'), K::plural($clans, 'clan', 'clans')]);
    $one = count($parts) === 1 && (($players ?? $clans) === 1);
    $already = $parts === [] ? null : implode(' and ', $parts).(count($parts) > 1 || ! $one ? ' are' : ' is').' already in.';
    $steps = [
        ['1', 'Open the site', ['On your phone or laptop.']],
        ['2', 'Log in', ['Google or Nostr.', 'No password to remember.']],
        ['3', 'Play', ['Chess right in the browser.', 'Rocket League, EA FC, AoE2', 'as a casual 1v1.']],
    ];
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.2])
<rect width="1280" height="720" fill="#F7931A" fill-opacity="{{ K::backdropUri($backdrop ?? null) ? 0.94 : 1 }}"/>
<use href="#mark-dark" xlink:href="#mark-dark" x="40" y="48" width="64" height="64"/>
<text x="124" y="92" font-family="Unbounded" font-weight="800" font-size="32" fill="#17120A">Join in three steps</text>
{{-- COPY-CHECK: step 2 per copy-check.md (c): Google or Nostr (extension or remote signer), "No password to remember." (login.blade.php:26). --}}
{{-- COPY-CHECK: step 3: chess plays on the site; the casual 1v1 (queue or invite, with a ready check) runs Rocket League, both EA Sports FC editions and Age of Empires II (config/esports.php 'casual' => games); the AoE2 queue stays a casual 1v1 even though its tournaments are one lobby match (P10, user decision 2026-10-01). The board games stay out: they are behind their own switch and this scene does not know it. --}}
@foreach ($steps as $i => [$num, $title, $lines])
@php($sx = 40 + $i * 413)
<text x="{{ $sx }}" y="376" font-family="Unbounded" font-weight="800" font-size="200" fill="#17120A">{{ $num }}</text>
<text x="{{ $sx }}" y="438" font-family="Unbounded" font-weight="800" font-size="32" fill="#17120A">{{ $title }}</text>
@foreach ($lines as $li => $line)<text data-unit="step-{{ $i }}-{{ $li }}" data-box="{{ $sx - 1 }} {{ 460 + $li * 30 }} {{ $sx + 373 }} {{ 486 + $li * 30 }}" x="{{ $sx }}" y="{{ 480 + $li * 30 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#17120A">{{ $line }}</text>@endforeach

@endforeach
@if ($already)<text data-unit="already" data-box="39 650 860 680" x="40" y="672" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">{{ $already }}</text>@endif
<text x="1240" y="672" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A" text-anchor="end">esports.einundzwanzig.space</text>
@php($vb = K::viewerBadge($viewers ?? null, 1024, 92, K::DISPLAY, 24, 18))
@if ($vb)@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#17120A', 'countInk' => '#17120A', 'wordInk' => '#17120A'])@endif
</svg>
