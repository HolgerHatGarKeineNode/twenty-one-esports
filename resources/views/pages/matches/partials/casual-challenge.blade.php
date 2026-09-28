{{--
    A scheduled casual 1v1 challenge, still open (P23 S4, CasualChallenges):
    the challenged player picks a time and the platform they play on, or
    declines; the challenger may withdraw. Nothing is signed. Needs $m,
    $mySide, $viewer and $pickedStart from pages/matches/⚡room.
--}}
<section aria-labelledby="casual-answer-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="casual-challenge">
    <h2 id="casual-answer-h" class="m-0 text-[15px] font-bold">{{ __('1v1 challenge from :name', ['name' => $m->challenger_name]) }}</h2>
    @if ($m->message)<p class="m-0 text-[13px] text-ink-2">“{{ $m->message }}”</p>@endif
    <span class="text-xs text-ink-2">{{ __('Answer by :time', ['time' => \App\Support\Series\SeriesPresenter::time($m->respond_by, $viewer)]) }}</span>

    @if ($mySide === 'challenged')
        <div role="radiogroup" aria-label="{{ __('Suggested times') }}" class="flex flex-wrap gap-2">
            @foreach ($m->proposals as $proposal)
                <button type="button" role="radio" wire:click="$set('pickedStart', {{ $proposal }})" aria-checked="{{ $pickedStart === $proposal ? 'true' : 'false' }}" data-test="casual-time"
                        @class(['h-11 cursor-pointer rounded-md border bg-ground px-4 text-[13px] text-ink', 'border-btc' => $pickedStart === $proposal, 'border-line' => $pickedStart !== $proposal])>{{ \App\Support\Series\SeriesPresenter::time(now()->setTimestamp($proposal), $viewer) }}</button>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-4 text-[13px]">
            <label class="flex items-center gap-2 text-ink-2">{{ __('Platform') }}
                <select wire:model="casualPlatform" class="h-11 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink" data-test="casual-platform">
                    @foreach (\App\Enums\Platform::cases() as $platform)
                        <option value="{{ $platform->value }}">{{ $platform->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex items-center gap-2 text-ink-2"><input type="checkbox" wire:model="casualCrossplay" class="size-4 accent-btc" data-test="casual-crossplay">{{ __('Crossplay') }}</label>
        </div>
        <div class="flex flex-wrap gap-3">
            <x-button icon="check" wire:click="casualAccept" data-test="casual-accept">{{ __('Accept challenge') }}</x-button>
            <x-button variant="quiet" wire:click="casualDecline" data-test="casual-decline">{{ __('Decline') }}</x-button>
        </div>
        <p class="m-0 text-xs text-ink-3">{{ __('Both check in from :minutes minutes before the start. Whoever does not check in loses by forfeit.', ['minutes' => (int) config('esports.casual.checkin_before_minutes')]) }}</p>
    @elseif ($mySide === 'challenger')
        <p class="m-0 text-[13px] text-ink-2">{{ __(':name sees it right away. You can withdraw it while it\'s open.', ['name' => $m->challenged_name]) }}</p>
        <div><x-button variant="quiet" wire:click="casualWithdraw" data-test="casual-withdraw">{{ __('Withdraw challenge') }}</x-button></div>
    @endif
</section>
