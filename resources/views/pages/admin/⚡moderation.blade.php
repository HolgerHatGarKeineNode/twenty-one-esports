<?php

use App\Enums\ModerationAction;
use App\Models\PubkeyModeration;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Moderation\SiteModeration;
use App\Support\Moderation\SiteModerationRefused;
use App\Support\Nostr\NostrKeys;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Site-wide moderation (App\Support\Moderation\SiteModeration): the Nostr
 * keys muted for everyone or banned from the site, with reason, admin and
 * date, and an Undo for each; below, what was undone. A key can be muted or
 * banned here too (the chat's message menu does the same). Admins only, and
 * never shown anywhere else.
 */
new #[Title('Moderation')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component
{
    /** The hex pubkey picked in <x-player-picker allow-npub>. */
    public ?string $key = null;

    public string $action = 'mute';

    public string $reason = '';

    public string $notice = '';

    public function mount(): void
    {
        Gate::authorize('admin');
    }

    /**
     * Mutes and bans in force, newest first.
     *
     * @return Collection<int, PubkeyModeration>
     */
    #[Computed]
    public function active(): Collection
    {
        return PubkeyModeration::query()->active()->orderByDesc('id')->get();
    }

    /**
     * The newest steps undone.
     *
     * @return Collection<int, PubkeyModeration>
     */
    #[Computed]
    public function history(): Collection
    {
        return PubkeyModeration::query()->whereNotNull('lifted_at')->orderByDesc('lifted_at')->orderByDesc('id')->limit(50)->get();
    }

    /** @return array<string, User> pubkey => account */
    #[Computed]
    public function users(): array
    {
        $pubkeys = [];

        foreach ([...$this->active, ...$this->history] as $row) {
            array_push($pubkeys, $row->pubkey, $row->actor_pubkey, (string) $row->lifted_by_pubkey);
        }

        return User::query()->whereIn('pubkey', array_values(array_unique($pubkeys)))->get()->keyBy('pubkey')->all();
    }

    public function name(string $pubkey): string
    {
        return ($this->users[$pubkey] ?? null)?->displayName() ?? (NostrKeys::isHexPubkey($pubkey) ? Str::limit(NostrKeys::hexToNpub($pubkey), 16, '…') : '–');
    }

    public function add(SiteModeration $moderation): void
    {
        $admin = $this->admin();
        $this->validate([
            'key' => ['required', 'string', 'max:100'],
            'action' => ['required', 'in:mute,ban'],
        ], ['key.required' => __('Pick a player from the suggestions or paste a full npub.')]);

        try {
            $this->action === 'ban'
                ? $moderation->ban($admin, (string) $this->key, $this->reason)
                : $moderation->mute($admin, (string) $this->key, $this->reason);
        } catch (SiteModerationRefused $refused) {
            $this->addError($refused->reason === 'reason' ? 'reason' : 'key', $refused->getMessage());

            return;
        }

        $this->notice = $this->action === 'ban' ? __('Banned from the site.') : __('Muted for everyone.');
        $this->reset(['key', 'reason']);
        unset($this->active, $this->history, $this->users);
    }

    public function lift(int $id, SiteModeration $moderation): void
    {
        try {
            $row = $moderation->lift($this->admin(), $id);
        } catch (SiteModerationRefused $refused) {
            $this->notice = $refused->getMessage();
            unset($this->active, $this->history, $this->users);

            return;
        }

        $this->notice = $row->action === ModerationAction::Ban ? __('Ban undone.') : __('Mute undone.');
        unset($this->active, $this->history, $this->users);
    }

    private function admin(): User
    {
        Gate::authorize('admin');
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php($input = 'h-11 w-full rounded-md border border-edge bg-ground px-3 text-[13px] text-ink')

<x-admin.page active="moderation" :title="__('Moderation')" :lead="__('Keys muted for everyone or banned from the site. Nobody but admins sees this list.')" :notice="$notice ?: null" notice-test="moderation-notice" data-test="admin-moderation">
    <x-admin.panel :title="__('Mute or ban a key')" :meta="__('also from the menu on any chat message')">
        <form wire:submit="add" class="flex flex-col gap-3" data-test="moderation-form">
            <x-player-picker id="moderation-key" wire:model="key" allow-npub :label="__('Player or npub')" :exclude="[(int) auth()->id()]" class="max-w-xl" />
            @error('key')<p class="m-0 text-[13px] text-loss" role="alert" data-test="moderation-key-error">{{ $message }}</p>@enderror
            <fieldset class="m-0 flex flex-wrap gap-x-5 gap-y-2 border-0 p-0 text-[13px]">
                <legend class="sr-only">{{ __('Action') }}</legend>
                <label class="inline-flex min-h-11 cursor-pointer items-center gap-2"><input type="radio" wire:model="action" value="mute" data-test="moderation-action-mute"> {{ __('Mute for everyone') }}</label>
                <label class="inline-flex min-h-11 cursor-pointer items-center gap-2"><input type="radio" wire:model="action" value="ban" data-test="moderation-action-ban"> {{ __('Ban from the site') }}</label>
            </fieldset>
            <label class="flex max-w-xl flex-col gap-1.5 text-xs text-ink-2">{{ __('Reason (only admins read it)') }}
                <input type="text" wire:model="reason" maxlength="500" class="{{ $input }}" data-test="moderation-reason">
            </label>
            @error('reason')<p class="m-0 text-[13px] text-loss" role="alert" data-test="moderation-reason-error">{{ $message }}</p>@enderror
            <span class="flex flex-wrap gap-3">
                <x-button type="submit" icon="shield-check" data-test="moderation-submit">{{ __('Save') }}</x-button>
            </span>
        </form>
    </x-admin.panel>

    <x-admin.panel :title="__('In force')" :meta="trans_choice(':count key|:count keys', $this->active->count())" flush data-test="moderation-active">
        @forelse ($this->active as $row)
            <div class="flex flex-col gap-2 border-t border-hairline py-3 first:border-t-0 sm:flex-row sm:items-center sm:justify-between" wire:key="m-{{ $row->id }}" data-test="moderation-row" data-pubkey="{{ $row->pubkey }}">
                <span class="flex min-w-0 flex-col gap-0.5 text-[13px]">
                    <span class="flex flex-wrap items-baseline gap-x-2">
                        <b class="[overflow-wrap:anywhere]">{{ $this->name($row->pubkey) }}</b>
                        <span @class(['rounded-tag px-1.5 text-[11px] leading-5', 'bg-loss/15 text-loss' => $row->action === ModerationAction::Ban, 'bg-raised text-ink-2' => $row->action === ModerationAction::Mute])>{{ $row->action === ModerationAction::Ban ? __('Banned') : __('Muted site-wide') }}</span>
                    </span>
                    <span class="text-xs break-words text-ink-2">{{ __(':admin on :time: :reason', ['admin' => $this->name($row->actor_pubkey), 'time' => LeagueTime::stamp($row->created_at ?? now()), 'reason' => $row->reason]) }}</span>
                </span>
                <x-button variant="secondary" wire:click="lift({{ $row->id }})" wire:confirm="{{ $row->action === ModerationAction::Ban ? __('Undo this ban? The key can sign in and is seen again.') : __('Undo this mute? Its messages are seen again.') }}" data-test="moderation-undo">{{ __('Undo') }}</x-button>
            </div>
        @empty
            <x-admin.empty :text="__('Nobody is muted or banned.')" />
        @endforelse
    </x-admin.panel>

    <x-admin.panel :title="__('Undone')" :meta="__('every step stays in the record')" flush data-test="moderation-history">
        @forelse ($this->history as $row)
            <div class="flex flex-col gap-0.5 border-t border-hairline py-2 text-[13px] first:border-t-0" wire:key="h-{{ $row->id }}" data-test="moderation-history-row">
                <span class="[overflow-wrap:anywhere]"><b>{{ $this->name($row->pubkey) }}</b> · {{ $row->action === ModerationAction::Ban ? __('Banned') : __('Muted site-wide') }}</span>
                <span class="text-xs break-words text-ink-2">{{ __(':admin on :time: :reason', ['admin' => $this->name($row->actor_pubkey), 'time' => LeagueTime::stamp($row->created_at ?? now()), 'reason' => $row->reason]) }}</span>
                <span class="text-xs text-ink-2">{{ __('Undone by :admin on :time', ['admin' => $this->name((string) $row->lifted_by_pubkey), 'time' => LeagueTime::stamp($row->lifted_at ?? now())]) }}</span>
            </div>
        @empty
            <x-admin.empty :text="__('Nothing was undone yet.')" />
        @endforelse
    </x-admin.panel>
</x-admin.page>
