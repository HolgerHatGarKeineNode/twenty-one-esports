<?php

namespace App\Console\Commands;

use App\Support\Nostr\NostrKeys;
use App\Support\TwentyOne\EventBuilder;
use App\Support\TwentyOne\RelayPublisher;
use App\Support\TwentyOne\TwentyOneSigner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

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
        $signer = $this->signer();

        if ($signer === null) {
            return self::FAILURE;
        }

        $this->line('Signing as '.NostrKeys::hexToNpub($signer->pubkey));

        /** @var array<string, mixed> $profile */
        $profile = (array) config('twentyone.profile');
        $publicRelays = $this->relayList(config('twentyone.relays.public'));

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
            ? $this->relayList(explode(',', (string) $this->option('relays')))
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

    /**
     * The configured signer, or null after printing why there is none. The
     * messages name the variable, never its value.
     */
    private function signer(): ?TwentyOneSigner
    {
        $nsec = config('twentyone.nostr.nsec');

        if (! is_string($nsec) || trim($nsec) === '') {
            $this->error('TWENTYONE_NOSTR_NSEC is not set.');

            return null;
        }

        $signer = TwentyOneSigner::fromNsec($nsec);

        if ($signer === null) {
            $this->error('TWENTYONE_NOSTR_NSEC is not a valid nsec.');

            return null;
        }

        $npub = config('twentyone.nostr.npub');

        if (is_string($npub) && trim($npub) !== '' && NostrKeys::npubToHex($npub) !== $signer->pubkey) {
            $this->error('TWENTYONE_NOSTR_NSEC does not belong to TWENTYONE_NOSTR_NPUB.');

            return null;
        }

        return $signer;
    }

    /**
     * @return list<string>
     */
    private function relayList(mixed $relays): array
    {
        if (! is_array($relays)) {
            return [];
        }

        $relays = array_map(fn (mixed $relay): string => is_string($relay) ? trim($relay) : '', $relays);

        return array_values(array_unique(array_filter($relays, fn (string $relay): bool => $relay !== '')));
    }
}
