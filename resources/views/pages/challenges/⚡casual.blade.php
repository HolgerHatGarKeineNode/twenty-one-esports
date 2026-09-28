<?php

use App\Enums\Platform;
use App\Models\User;
use App\Support\GameNames;
use App\Support\PreSeason;
use App\Support\Series\CasualChallenges;
use App\Support\Series\SeriesRuleViolation;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Challenge a player to a scheduled casual 1v1 (P23 S4), no clan needed:
 * a game, the platform you play on, one to three suggested times and a
 * reply deadline (CasualChallenges). The opponent comes from `?to=` (a
 * user id, as a player card links it) or from their npub. Kept plain on
 * purpose: the casual 1v1 design (S3) restyles it.
 */
new #[Title('New 1v1 challenge')] #[Layout('layouts::app', ['section' => 'matches'])] class extends Component
{
    #[Url(as: 'to', except: null)]
    public ?int $opponentId = null;

    #[Url(as: 'game', except: null)]
    public ?string $game = null;

    public string $npub = '';

    public string $platform = '';

    public bool $crossplay = true;

    /** @var list<array{date: string, time: string}> */
    public array $times = [];

    public string $replyDate = '';

    public string $replyTime = '';

    public string $message = '';

    public string $error = '';

    public function mount(): void
    {
        $games = (array) config('esports.casual.games');
        $this->game = in_array($this->game, $games, true) ? $this->game : ($games[0] ?? null);
        $this->platform = $this->user()->platform?->value ?? Platform::Pc->value;

        $first = CarbonImmutable::now($this->zone())->addDay()->setTime(20, 0);
        $this->times = [['date' => $first->format('Y-m-d'), 'time' => $first->format('H:i')]];
        $reply = $first->subHours(2);
        $this->replyDate = $reply->format('Y-m-d');
        $this->replyTime = $reply->format('H:i');
    }

    #[Computed]
    public function opponent(): ?User
    {
        if ($this->opponentId !== null) {
            return User::query()->find($this->opponentId);
        }

        $npub = trim($this->npub);

        return $npub === '' ? null : User::query()->where('npub', $npub)->first();
    }

    public function updatedNpub(): void
    {
        $this->opponentId = null;
        unset($this->opponent);
    }

    public function addTime(): void
    {
        if (count($this->times) < 3) {
            $this->times[] = end($this->times) ?: ['date' => '', 'time' => ''];
        }
    }

    public function removeTime(int $index): void
    {
        if (count($this->times) > 1) {
            unset($this->times[$index]);
            $this->times = array_values($this->times);
        }
    }

    public function send(CasualChallenges $challenges): void
    {
        $this->error = '';
        $opponent = $this->opponent;
        $platform = Platform::tryFrom($this->platform);

        if ($opponent === null || $platform === null || $this->game === null) {
            $this->error = __('Pick an opponent, a game and your platform first.');

            return;
        }

        $proposals = [];

        foreach ($this->times as $row) {
            $time = $this->parse($row['date'], $row['time']);

            if ($time === null) {
                $this->error = __('Every suggested time needs a date and a time.');

                return;
            }

            $proposals[] = $time;
        }

        $reply = $this->parse($this->replyDate, $this->replyTime);

        if ($reply === null) {
            $this->error = __('Set a reply deadline.');

            return;
        }

        try {
            $match = $challenges->challenge($this->user(), $opponent, $this->game, $platform, $this->crossplay, $proposals, $reply, $this->message);
        } catch (SeriesRuleViolation $violation) {
            $this->error = $violation->getMessage();

            return;
        }

        session()->flash('status', __('Challenge :number sent to :name.', ['number' => $match->label(), 'name' => $match->challenged_name]));
        $this->redirectRoute('matches.room', $match);
    }

    private function parse(string $date, string $time): ?int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$time}", $this->zone())?->getTimestamp();
    }

    private function zone(): string
    {
        return PreSeason::timezoneFor($this->user());
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php($opponent = $this->opponent)
@php($field = 'h-11 min-w-0 rounded-lg border border-edge bg-ground px-3 text-sm text-ink')

<div class="flex grow flex-col gap-5 px-4 pt-5 pb-28 lg:mx-auto lg:w-full lg:max-w-[720px] lg:px-4 lg:pt-8 lg:pb-10" data-test="casual-challenge-form">
    <h1 class="m-0 font-display text-[28px] font-bold">{{ __('New 1v1 challenge') }}</h1>

    <section class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6">
        <label class="flex flex-col gap-1.5 text-[13px] text-ink-2">{{ __('Opponent') }}
            @if ($opponentId !== null && $opponent !== null)
                <b class="text-sm text-ink" data-test="casual-opponent">{{ $opponent->displayName() }}</b>
            @else
                <input type="text" wire:model.live.debounce.400ms="npub" placeholder="npub1…" class="{{ $field }}" data-test="casual-npub">
                @if (trim($npub) !== '')<span class="text-xs {{ $opponent === null ? 'text-loss' : 'text-win' }}">{{ $opponent?->displayName() ?? __('No player with this npub.') }}</span>@endif
            @endif
        </label>

        {{-- The game as its cover (P53: the choice read as a text select); a radio group, the cover is the target. --}}
        <fieldset class="m-0 flex min-w-0 flex-col gap-1.5 border-0 p-0" data-test="casual-game">
            <legend class="mb-1.5 p-0 text-[13px] text-ink-2">{{ __('Game title') }}</legend>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                @foreach ((array) config('esports.casual.games') as $slug)
                    <label class="flex cursor-pointer flex-col gap-1.5 rounded-md border border-line bg-well p-1.5 text-[13px] text-ink-2 hover:border-edge has-checked:border-btc has-checked:text-ink has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-btc" data-test="casual-game-{{ $slug }}">
                        <input type="radio" wire:model="game" name="casual-game" value="{{ $slug }}" class="sr-only">
                        <x-game-cover :game="$slug" size="card" class="w-full rounded-xs" />
                        <span class="flex items-center justify-between gap-2 px-0.5 font-bold">{{ GameNames::game($slug) }} <span class="font-normal text-ink-3">1v1</span></span>
                    </label>
                @endforeach
            </div>
        </fieldset>
        <div class="flex flex-wrap gap-4">
            <label class="flex flex-col gap-1.5 text-[13px] text-ink-2">{{ __('Platform') }}
                <select wire:model="platform" class="{{ $field }}">
                    @foreach (Platform::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex items-center gap-2 self-end pb-3 text-[13px] text-ink-2"><input type="checkbox" wire:model="crossplay" class="size-4 accent-btc">{{ __('Crossplay') }}</label>
        </div>

        <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
            <legend class="mb-1.5 text-[13px] text-ink-2">{{ __('Suggested times') }}</legend>
            @foreach ($times as $index => $row)
                <div class="flex flex-wrap items-center gap-2">
                    <input type="date" wire:model="times.{{ $index }}.date" aria-label="{{ __('Date of time :n', ['n' => $index + 1]) }}" class="{{ $field }}">
                    <input type="time" wire:model="times.{{ $index }}.time" aria-label="{{ __('Hour of time :n', ['n' => $index + 1]) }}" class="{{ $field }}">
                    @if (count($times) > 1)<x-button variant="quiet" wire:click="removeTime({{ $index }})">{{ __('Remove') }}</x-button>@endif
                </div>
            @endforeach
            @if (count($times) < 3)<div><x-button variant="quiet" wire:click="addTime">{{ __('Add a time') }}</x-button></div>@endif
        </fieldset>

        <div class="flex flex-col gap-1.5 text-[13px] text-ink-2">{{ __('Reply by') }}
            <div class="flex flex-wrap gap-2">
                <input type="date" wire:model="replyDate" aria-label="{{ __('Reply by, date') }}" class="{{ $field }}">
                <input type="time" wire:model="replyTime" aria-label="{{ __('Reply by, time') }}" class="{{ $field }}">
            </div>
        </div>

        <label class="flex flex-col gap-1.5 text-[13px] text-ink-2">{{ __('Message (optional)') }}
            <input type="text" wire:model="message" maxlength="140" class="{{ $field }}">
        </label>

        @if ($error !== '')<p class="m-0 rounded-md bg-loss-tint px-4 py-3 text-[13px] text-loss" role="alert" data-test="casual-challenge-error">{{ $error }}</p>@endif

        <div><x-button icon="check" wire:click="send" data-test="casual-challenge-send">{{ __('Send challenge') }}</x-button></div>
    </section>
</div>
