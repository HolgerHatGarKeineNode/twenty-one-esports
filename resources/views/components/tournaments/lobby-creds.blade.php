@props(['name' => '', 'password' => null, 'test' => 'lobby', 'big' => false])

{{--
    A lobby's league access as the boards TournamentLobby and TournamentDirectorLobbies draw it (plan "Refactor und
    Design-Revamp", P6): the name and the password each in a wide field, a copy button with its word beside it. The
    password is shown, never hidden (user, 2026-10-10: "Das PW muss nicht hidden sein!"); only the lobby's players and
    the directors get this block at all (LobbyResults::plays(), the card's access rule).

    `test`: the data-test prefix (`my-lobby`, `lobby`); `big`: the larger field of a player's own lobby.
    A name or password never breaks inside (it was "21-archive-2|-1" in the two-column lobby list, P1): the field
    keeps one line and scrolls if it must; in a card narrower than 28rem the copy button is its icon alone.
--}}
<div {{ $attributes->class('@container flex flex-col gap-3') }} x-data="{ copied: '' }" data-test="{{ $test }}-access">
    @foreach ([['name', __('Name'), (string) $name, __('Copy lobby name')], ['password', __('Password'), (string) ($password ?? '–'), __('Copy password')]] as [$key, $label, $value, $copy])
        <div class="flex flex-col gap-1.5">
            <span class="rv-m">{{ $label }}</span>
            <div class="flex gap-2">
                <b @class(['flex min-w-0 grow items-center rounded-[4px] border border-line bg-ground px-4 font-mono whitespace-nowrap overflow-x-auto [scrollbar-width:none]', 'min-h-14 text-[22px]' => $big, 'min-h-11 text-lg' => ! $big])
                   data-test="{{ $test }}-{{ $key }}">{{ $value }}</b>
                <button type="button" x-on:click="navigator.clipboard?.writeText(@js($value)); copied = '{{ $key }}'" data-test="{{ $test }}-copy-{{ $key }}"
                        @class(['rv-b2 flex-none @max-md:w-11 @max-md:px-0', 'h-14' => $big])>
                    <span x-show="copied !== '{{ $key }}'" class="inline-flex"><x-icon name="copy" :size="16" /></span><span x-show="copied === '{{ $key }}'" x-cloak class="inline-flex text-win"><x-icon name="check" :size="16" /></span><span class="@max-md:sr-only">{{ $copy }}</span>
                </button>
            </div>
        </div>
    @endforeach
</div>
