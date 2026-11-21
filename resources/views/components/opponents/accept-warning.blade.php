@props(['warning', 'name', 'accept' => true])

{{--
    Plan "Profile standard and impersonation", P4 and D17: why rated play
    with this player stays closed for now ({@see App\Support\FairPlay\AcceptWarnings}),
    shown before the member accepts their request (`accept`) or adds them
    first. A hold says only that the account is being checked, never why or
    whom it resembles. Used by the request cards on /settings/opponents and
    by livewire:opponent-button (player page, match room, lobby notices).
--}}
<div {{ $attributes->class('flex max-w-[68ch] items-start gap-2 rounded-md bg-btc-tint px-3 py-2 text-[13px] leading-normal text-btc-hi') }} role="note" data-test="opponent-request-warning">
    <x-icon name="alert" :size="16" class="mt-0.5 shrink-0" />
    <div class="flex min-w-0 flex-col gap-1">
        <p class="m-0 font-bold [overflow-wrap:anywhere]">{{ __(':name cannot play rated yet:', ['name' => $name]) }}</p>
        <ul class="m-0 flex list-disc flex-col gap-1 pl-4">
            @if ($warning['held'])
                <li data-test="opponent-request-warning-held">{{ __('This account is being checked.') }}</li>
            @endif
            @foreach ($warning['missing'] as $mark)
                <li data-test="opponent-request-warning-{{ $mark['key'] }}">{{ $mark['text'] }}</li>
            @endforeach
        </ul>
        <p class="m-0">{{ $accept ? __('You can still accept. Rated games between you open only after that.') : __('You can still add them. Rated games between you open only after that.') }}</p>
    </div>
</div>
