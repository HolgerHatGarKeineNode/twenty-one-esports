<?php

namespace App\Console\Commands;

use App\Support\TwentyOne\EventBuilder;
use App\Support\TwentyOne\RelayPublisher;
use App\Support\TwentyOne\Stream\StreamSession;
use App\Support\TwentyOne\Stream\StreamTexts;
use App\Support\TwentyOne\TwentyOneSigner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The permanent stop of the 24/7 stream: publishes the kind-30311 event once
 * as `ended` (same builder, relays and budget the daemon uses) and clears the
 * session, so the next start begins a new one. A deploy does not need this:
 * the daemon publishes nothing on SIGTERM and continues its session.
 *
 * Stop the daemon first: a running one republishes `live` over this.
 * The session is kept when no relay accepted the event, so the command can
 * be run again.
 */
#[Signature('twentyone:stream:end
    {--relays= : Comma-separated relay URLs, instead of twentyone.stream.relays}')]
#[Description('Announce the 24/7 stream as ended (NIP-53) and start a new session next time')]
class TwentyOneStreamEndCommand extends Command
{
    public function handle(EventBuilder $builder, RelayPublisher $publisher): int
    {
        try {
            $signer = TwentyOneSigner::fromConfig();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $relays = RelayPublisher::relayUrls($this->option('relays') ?? config('twentyone.stream.relays'));
        $invalid = array_filter($relays, fn (string $relay): bool => ! EventBuilder::isRelayUrl($relay));

        if ($relays === [] || $invalid !== []) {
            $this->error($relays === [] ? 'No relays to publish to.' : 'Not a ws:// or wss:// relay URL: '.implode(', ', $invalid));

            return self::FAILURE;
        }

        $session = StreamSession::fromConfig();
        $stored = $session->read($problem);

        if ($problem !== null) {
            $this->warn('Session file '.$session->path().' '.$problem.'; ending without its start time.');
        }

        // A replaceable event only replaces an older one: after the last `live`.
        $endedAt = max(time(), ($stored['lastLiveAt'] ?? 0) + 1);
        /** @var array{d: string, title: string, summary: string, image: string, t?: list<string>} $stream */
        $stream = [...config('twentyone.stream.event'), ...StreamTexts::for(null)];
        $event = $signer->sign($builder->liveActivity(
            $stream,
            (string) config('twentyone.stream.public_url'),
            $signer->pubkey,
            'ended',
            $stored['starts'] ?? $endedAt,
            $endedAt,
        )->setCreatedAt($endedAt));

        $results = $publisher->publish($event, $relays, (float) config('twentyone.stream.shutdown_publish_seconds', 8));
        $accepted = collect($results)->where('accepted', true)->count();

        $this->line(sprintf(
            'published kind 30311 status=ended starts=%d id=%s created_at=%d to %d/%d relays (%s)',
            $stored['starts'] ?? $endedAt,
            $event['id'],
            $event['created_at'],
            $accepted,
            count($results),
            collect($results)->map(fn ($result): string => $result->relay.' '.($result->accepted ? 'ok' : 'failed: '.$result->message))->implode('; '),
        ));

        if ($accepted === 0) {
            $this->error('No relay accepted the ended event; the session is kept, run this again.');

            return self::FAILURE;
        }

        $session->clear();
        $this->info('Session cleared; the next start begins a new one.');

        return self::SUCCESS;
    }
}
