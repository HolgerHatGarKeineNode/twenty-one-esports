<?php

namespace App\Console\Commands;

use App\Support\Nostr\NostrKeys;
use App\Support\Prizes\PoolInvoices;
use App\Support\SeasonChain\LeagueKey;
use App\Support\TwentyOne\EventBuilder;
use App\Support\TwentyOne\RelayPublisher;
use App\Support\TwentyOne\TwentyOneSigner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('twentyone:profile
    {--relays= : Comma-separated relay URLs to publish to, instead of twentyone.relays.public}
    {--dry-run : Print the signed events and send nothing}')]
#[Description('Sign and publish the TWENTY ONE Esports Nostr profile (kind 0), relay list (kind 10002) and DM inbox relays (kind 10050)')]
class TwentyOneProfileCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(EventBuilder $builder, RelayPublisher $publisher): int
    {
        try {
            $signer = TwentyOneSigner::fromConfig();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Signing as '.NostrKeys::hexToNpub($signer->pubkey));

        /** @var array<string, mixed> $profile */
        $profile = (array) config('twentyone.profile');

        // The league's pool takes only zaps whose `p` is the pool key: a profile of another key would have every zap refused.
        if (is_string($profile['lud16'] ?? null) && strcasecmp(trim($profile['lud16']), PoolInvoices::address()) === 0 && LeagueKey::poolPubkey() !== $signer->pubkey) {
            $this->error('The lud16 is the league pool ('.PoolInvoices::address().'), which takes zaps for ESPORTS_POOL_NPUB only, and that is not this key. Set ESPORTS_POOL_NPUB to '.NostrKeys::hexToNpub($signer->pubkey).' first.');

            return self::FAILURE;
        }
        $publicRelays = RelayPublisher::relayUrls(config('twentyone.relays.public'));

        $events = [
            $signer->sign($builder->profile($profile)),
            $signer->sign($builder->relayList($publicRelays)),
            // NIP-17: without a 10050 nobody can reach this profile with a gift-wrapped DM (user, 2026-10-04).
            $signer->sign($builder->dmRelayList(RelayPublisher::relayUrls(config('twentyone.relays.dm')))),
        ];

        if ($this->option('dry-run')) {
            foreach ($events as $event) {
                $this->line((string) json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            $this->info('Dry run: nothing was sent.');

            return self::SUCCESS;
        }

        $relays = $this->option('relays') !== null
            ? RelayPublisher::relayUrls($this->option('relays'))
            : $publicRelays;

        if ($relays === []) {
            $this->error('No relays to publish to.');

            return self::FAILURE;
        }

        $timeout = (float) config('twentyone.nostr.publish_timeout_seconds', 5);
        // Each event needs one relay that took it: the prod host is refused by
        // some public relays (damus 403, nos.lol unreachable), which the relay
        // list still names for readers elsewhere.
        $everyEventTaken = true;

        foreach ($events as $event) {
            $taken = false;

            foreach ($publisher->publish($event, $relays, $timeout) as $result) {
                $taken = $taken || $result->accepted;

                $this->line(sprintf(
                    'kind %d %s %s',
                    $event['kind'],
                    $result->relay,
                    $result->accepted ? 'ok' : 'failed: '.$result->message,
                ));
            }

            $everyEventTaken = $everyEventTaken && $taken;
        }

        return $everyEventTaken ? self::SUCCESS : self::FAILURE;
    }
}
