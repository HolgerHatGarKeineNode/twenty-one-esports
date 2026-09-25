<?php

namespace App\Console\Commands;

use App\Support\Nostr\NostrKeys;
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
#[Description('Sign and publish the TWENTY ONE Esports Nostr profile (kind 0) and relay list (kind 10002)')]
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
        $publicRelays = RelayPublisher::relayUrls(config('twentyone.relays.public'));

        $events = [
            $signer->sign($builder->profile($profile)),
            $signer->sign($builder->relayList($publicRelays)),
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
        $allAccepted = true;

        foreach ($events as $event) {
            foreach ($publisher->publish($event, $relays, $timeout) as $result) {
                $allAccepted = $allAccepted && $result->accepted;

                $this->line(sprintf(
                    'kind %d %s %s',
                    $event['kind'],
                    $result->relay,
                    $result->accepted ? 'ok' : 'failed: '.$result->message,
                ));
            }
        }

        return $allAccepted ? self::SUCCESS : self::FAILURE;
    }
}
