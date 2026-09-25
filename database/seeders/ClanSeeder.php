<?php

namespace Database\Seeders;

use App\Enums\ClanRole;
use App\Enums\InviteStatus;
use App\Enums\LineupRole;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The eight clans of the sample ledger (SAMPLE-LEDGER.md sections 1.1, 2,
 * 3.3-3.5) with their players and Rocket League lineups, for local
 * development only. Players get throwaway random pubkeys and nobody can log
 * in as them; no event is signed, so the Proof blocks show addresses only.
 * Idempotent: running it twice changes nothing.
 */
class ClanSeeder extends Seeder
{
    /** tag => [name, founded, meetup [name, city, lat, lng] or null, players (owner first)] */
    private const CLANS = [
        'LSR' => ['Laser Eyes', '2026-07-03', ['EINUNDZWANZIG Kempten', 'Kempten', 47.7286, 10.3158], ['satsjäger', 'hodlqueen', 'nonce_nick']],
        'HDL' => ['HODL Rockets', '2026-07-03', null, ['rocketman21', 'halvinghans', 'lena.k', 'moonfee']],
        'MMP' => ['Mempool Maniacs', '2026-07-03', ['EINUNDZWANZIG Leipzig', 'Leipzig', 51.3397, 12.3731], ['mempoolmax', 'feebump', 'rbf_rita', 'blockbert']],
        'OPS' => ['Orange Pill Squad', '2026-07-03', null, ['pillpusher', 'kai_blitz', 'orangina', 'satoshi_sue']],
        'B21' => ['Block 21', '2026-07-03', ['EINUNDZWANZIG Wien', 'Wien', 48.2082, 16.3738], ['blockzeit', 'dezentral_dani', 'utxo_uwe']],
        'STK' => ['Stack Sats Crew', '2026-07-04', null, ['stackstefan', 'dca_doris', 'kaltlager']],
        'LNB' => ['Lightning Boost', '2026-07-06', null, ['channel_chris', 'invoice_ivo', 'zap_zoe']],
        'NCE' => ['Nonce Hunters', '2026-08-20', ['EINUNDZWANZIG Zürich', 'Zürich', 47.3769, 8.5417], ['asic_anna', 'hashing_hugo', 'sha_sebi']],
    ];

    /** Further clan captains besides the owner (ClanManage: hodlqueen). */
    private const CAPTAINS = ['hodlqueen'];

    /** Paid EINUNDZWANZIG members (ledger 1.1). */
    private const MEMBERS = ['satsjäger', 'mempoolmax', 'hodlqueen', 'moonfee', 'blockzeit'];

    /** tag => mode => [players..., 'sub' => substitute] (ledger 3.3-3.5) */
    private const LINEUPS = [
        'LSR' => ['3v3' => ['satsjäger', 'hodlqueen', 'nonce_nick'], '2v2' => ['satsjäger', 'hodlqueen'], '1v1' => ['nonce_nick']],
        'HDL' => ['3v3' => ['rocketman21', 'moonfee', 'halvinghans'], '2v2' => ['rocketman21', 'moonfee']],
        'MMP' => ['3v3' => ['mempoolmax', 'feebump', 'rbf_rita', 'sub' => 'blockbert'], '2v2' => ['mempoolmax', 'feebump', 'sub' => 'rbf_rita']],
        'OPS' => ['3v3' => ['pillpusher', 'orangina', 'satoshi_sue'], '1v1' => ['satoshi_sue']],
        'B21' => ['3v3' => ['blockzeit', 'dezentral_dani', 'utxo_uwe'], '2v2' => ['blockzeit', 'dezentral_dani'], '1v1' => ['utxo_uwe']],
        'STK' => ['3v3' => ['stackstefan', 'dca_doris', 'kaltlager'], '2v2' => ['stackstefan', 'dca_doris'], '1v1' => ['kaltlager']],
        'LNB' => ['3v3' => ['channel_chris', 'zap_zoe', 'invoice_ivo'], '2v2' => ['channel_chris', 'zap_zoe'], '1v1' => ['invoice_ivo']],
        'NCE' => ['3v3' => ['asic_anna', 'sha_sebi', 'hashing_hugo'], '2v2' => ['asic_anna', 'sha_sebi']],
    ];

    public function run(): void
    {
        foreach (self::CLANS as $tag => [$name, $founded, $meetup, $players]) {
            $owner = $this->player($players[0]);
            $foundedAt = Carbon::parse($founded.' 18:00');
            [$meetupName, $meetupCity, $latitude, $longitude] = $meetup ?? [null, null, null, null];

            $clan = Clan::query()->firstOrCreate(['clantag' => $tag], [
                'slug' => Str::slug($name),
                'owner_id' => $owner->id,
                'owner_pubkey' => $owner->pubkey,
                'name' => $name,
                'description' => $meetupCity === null ? null : "The {$meetupCity} meetup clan.",
                'meetup_name' => $meetupName,
                'meetup_city' => $meetupCity,
                'meetup_url' => $meetupCity === null ? null : 'https://portal.einundzwanzig.space/meetups/'.Str::slug($meetupCity),
                'meetup_latitude' => $latitude,
                'meetup_longitude' => $longitude,
            ]);
            $clan->forceFill(['created_at' => $foundedAt])->save();

            foreach ($players as $index => $playerName) {
                $player = $this->player($playerName);

                ClanMember::query()->firstOrCreate(['user_id' => $player->id], [
                    'clan_id' => $clan->id,
                    'role' => $index === 0 || in_array($playerName, self::CAPTAINS, true) ? ClanRole::Captain : ClanRole::Member,
                    'joined_at' => $foundedAt->addMinutes($index),
                ]);
            }

            foreach (self::LINEUPS[$tag] as $mode => $seats) {
                $lineup = Lineup::query()->firstOrCreate(['clan_id' => $clan->id, 'game' => 'rocket-league', 'mode' => $mode]);

                foreach ($seats as $key => $playerName) {
                    $role = match (true) {
                        $key === 'sub' => LineupRole::Substitute,
                        $playerName === $players[0] => LineupRole::Captain,
                        default => LineupRole::Player,
                    };

                    LineupSeat::query()->firstOrCreate(
                        ['lineup_id' => $lineup->id, 'user_id' => $this->player($playerName)->id],
                        ['role' => $role, 'accepted_at' => $foundedAt],
                    );
                }
            }
        }

        $this->pendingInvite();
    }

    /**
     * utxo_ute, invited as Laser Eyes 3v3 sub two hours ago (ledger 1.2).
     */
    private function pendingInvite(): void
    {
        $clan = Clan::query()->where('clantag', 'LSR')->firstOrFail();
        $lineup = Lineup::query()->where(['clan_id' => $clan->id, 'mode' => '3v3'])->firstOrFail();
        $ute = $this->player('utxo_ute');

        LineupSeat::query()->firstOrCreate(['lineup_id' => $lineup->id, 'user_id' => $ute->id], ['role' => LineupRole::Substitute]);

        ClanInvite::query()->firstOrCreate(
            ['lineup_id' => $lineup->id, 'invitee_id' => $ute->id],
            ['clan_id' => $clan->id, 'inviter_id' => $clan->owner_id, 'role' => LineupRole::Substitute, 'status' => InviteStatus::Pending, 'created_at' => now()->subHours(2)],
        );
    }

    private function player(string $name): User
    {
        return User::query()->where('name', $name)->first()
            ?? User::factory()->create([
                'name' => $name,
                'is_member' => in_array($name, self::MEMBERS, true),
                'member_checked_at' => now(),
            ]);
    }
}
