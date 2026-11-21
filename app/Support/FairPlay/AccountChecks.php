<?php

namespace App\Support\FairPlay;

use App\Models\AccountCheckAction;
use App\Models\ImpersonationHold;
use App\Models\User;
use App\Support\SeasonChain\TrustAdmin;
use App\Support\SeasonChain\TrustAdminRefused;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * The quick actions on an account's check view (plan "Profile standard and
 * impersonation", P3): each one an admin's explicit, confirmed decision,
 * written to `account_check_actions` and the log. The AI scan never calls
 * any of them.
 *
 * - **clear**: the account is fine; every open hold of it ends as released.
 * - **hold**: held from new rated play for review (an impersonation hold of
 *   kind `manual`, no matched account), until an admin decided. Holds count
 *   only while the impersonation check is switched on, so the action is
 *   refused while it is off instead of writing a hold that bars nobody.
 * - **link**: a second account of `$mainKey` ({@see AccountLinks::link()});
 *   every open hold ends as linked.
 * - **bar**: barred from rated play until a date or the season's end
 *   ({@see Bars::set()}); every open hold ends as barred.
 * - **exclude**: excluded from the trust ranks ({@see TrustAdmin::exclude()},
 *   board members only).
 *
 * Never about the admin's own accounts or clan (the guard of {@see Bars}).
 */
final class AccountChecks
{
    public const ACTIONS = ['clear', 'hold', 'link', 'bar', 'exclude'];

    public function __construct(private AccountLinks $links, private Bars $bars, private TrustAdmin $trust) {}

    /** @throws FairPlayRefused */
    public function clear(User $admin, User $player, string $note = ''): AccountCheckAction
    {
        $this->assertMayAct($admin, $player);
        $note = $this->note($note, required: false);

        return DB::transaction(function () use ($admin, $player, $note): AccountCheckAction {
            $this->endHolds($admin, $player, ImpersonationHold::RELEASED, $note);

            return $this->log($admin, $player, 'clear', $note);
        });
    }

    /** @throws FairPlayRefused */
    public function hold(User $admin, User $player, string $note): AccountCheckAction
    {
        $this->assertMayAct($admin, $player);
        $note = $this->note($note, required: true);

        if (! Impersonation::enabled()) {
            throw new FairPlayRefused(__('A hold counts only while the impersonation check is switched on. Bar the account instead.'));
        }

        return DB::transaction(function () use ($admin, $player, $note): AccountCheckAction {
            User::query()->whereKey($player->id)->lockForUpdate()->first();

            if (ImpersonationHold::query()->open()->where('pubkey', $player->pubkey)->exists()) {
                throw new FairPlayRefused(__('This account is held already.'));
            }

            ImpersonationHold::query()->create([
                'user_id' => $player->id,
                'pubkey' => $player->pubkey,
                'matched' => ImpersonationHold::MANUAL,
                'detected_at' => now(),
                'note' => $note,
            ]);

            return $this->log($admin, $player, 'hold', $note);
        });
    }

    /** @throws FairPlayRefused */
    public function link(User $admin, User $player, string $mainKey, string $reason): AccountCheckAction
    {
        $this->assertMayAct($admin, $player);
        $reason = $this->note($reason, required: true);

        return DB::transaction(function () use ($admin, $player, $mainKey, $reason): AccountCheckAction {
            $this->links->link($admin, $mainKey, $player->pubkey, $reason);
            $this->endHolds($admin, $player, ImpersonationHold::LINKED, $reason);

            return $this->log($admin, $player, 'link', $reason);
        }, 3);
    }

    /** @throws FairPlayRefused */
    public function bar(User $admin, User $player, string $reason, ?CarbonImmutable $until = null): AccountCheckAction
    {
        $this->assertMayAct($admin, $player);
        $reason = $this->note($reason, required: true);

        return DB::transaction(function () use ($admin, $player, $reason, $until): AccountCheckAction {
            $this->bars->set($admin, $player->pubkey, $reason, $until);
            $this->endHolds($admin, $player, ImpersonationHold::BARRED, $reason);

            return $this->log($admin, $player, 'bar', $reason);
        });
    }

    /** @throws FairPlayRefused */
    public function exclude(User $admin, User $player, string $reason): AccountCheckAction
    {
        $this->assertMayAct($admin, $player);
        $reason = $this->note($reason, required: true);

        return DB::transaction(function () use ($admin, $player, $reason): AccountCheckAction {
            try {
                $this->trust->exclude($admin, $player->pubkey, $reason);
            } catch (TrustAdminRefused $refused) {
                throw new FairPlayRefused($refused->getMessage());
            }

            return $this->log($admin, $player, 'exclude', $reason);
        });
    }

    /** End every open hold of the account with this decision. */
    private function endHolds(User $admin, User $player, string $decision, string $note): void
    {
        $ended = ImpersonationHold::query()->open()->where('pubkey', $player->pubkey)->update([
            'decided_at' => now(),
            'decided_by_id' => $admin->id,
            'decided_by_pubkey' => $admin->pubkey,
            'decision' => $decision,
            'note' => $note === '' ? null : $note,
        ]);

        if ($ended > 0) {
            Log::info('Impersonation holds decided from the check view', ['pubkey' => $player->pubkey, 'decision' => $decision, 'holds_ended' => $ended, 'admin' => $admin->pubkey]);
        }
    }

    private function log(User $admin, User $player, string $action, string $note): AccountCheckAction
    {
        Log::info('Account check action', ['pubkey' => $player->pubkey, 'action' => $action, 'admin' => $admin->pubkey]);

        return AccountCheckAction::query()->create([
            'user_id' => $player->id,
            'pubkey' => $player->pubkey,
            'action' => $action,
            'note' => $note === '' ? null : $note,
            'admin_id' => $admin->id,
            'admin_pubkey' => $admin->pubkey,
        ]);
    }

    /** @throws FairPlayRefused */
    private function assertMayAct(User $admin, User $player): void
    {
        if (! Gate::forUser($admin)->allows('admin')) {
            throw new FairPlayRefused(__('Only admins can act on an account check.'));
        }

        if ($admin->pubkey === $player->pubkey || TrustAdmin::isOwn($admin, [$player->pubkey])) {
            throw new FairPlayRefused(__('You cannot decide about yourself or your own clan. Another admin has to.'));
        }
    }

    /** @throws FairPlayRefused */
    private function note(string $note, bool $required): string
    {
        $note = trim($note);

        if (($required && $note === '') || mb_strlen($note) > Bars::REASON_MAX) {
            throw new FairPlayRefused(__('Give a reason, up to :max characters.', ['max' => Bars::REASON_MAX]));
        }

        return $note;
    }
}
