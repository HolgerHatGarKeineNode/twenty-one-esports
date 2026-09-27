<?php

namespace Tests\Integration\Support;

use App\Support\Nostr\SignedEvent;
use Illuminate\Support\Facades\Process;

/**
 * What P15 calls "am Ende sind alle erwarteten Events auf den lokalen
 * Relays nachprüfbar (Kinds, Tags, Signaturen, `prev`-Kette)": read the
 * `nak serve` Stack::instance() started with `nak req` (same tool
 * tests/Browser/ShareTest.php reads its relay with), never the app's own
 * archive (nostr_events) — that only proves the server thinks it published
 * something, not that a relay actually holds it.
 */
final class RelayCheck
{
    public function __construct(private readonly string $relayUrl) {}

    /**
     * Raw filtered query (stdin closed: `nak req` answers nothing without
     * it), decoded per line, NOT signature-checked (see events()).
     *
     * @return list<array<string, mixed>>
     */
    public function raw(string $args): array
    {
        $out = Process::timeout(15)->run('nak req '.$args.' '.escapeshellarg($this->relayUrl).' </dev/null')->output();

        return array_values(array_filter(array_map(
            fn (string $line): ?array => is_array($decoded = json_decode($line, true)) ? $decoded : null,
            explode("\n", trim($out)),
        )));
    }

    /**
     * Same, decoded into SignedEvent and kept only when id and signature
     * actually check out — a relay could in principle be asked to hold a
     * tampered event; the suite must not take its word for it.
     *
     * @return list<SignedEvent>
     */
    public function events(string $args): array
    {
        return array_values(array_filter(array_map(
            fn (array $raw): ?SignedEvent => ($event = SignedEvent::fromInput($raw)) !== null && $event->hasValidSignature() ? $event : null,
            $this->raw($args),
        )));
    }

    /** @return list<SignedEvent> */
    public function byKind(int $kind): array
    {
        return $this->events('-k '.$kind);
    }

    /** The one event of this kind and `d` (a replaceable/parameterized kind), or null. */
    public function byD(int $kind, string $d): ?SignedEvent
    {
        $events = $this->events('-k '.$kind.' -t d='.escapeshellarg($d));

        return $events[0] ?? null;
    }

    public function byId(string $id): ?SignedEvent
    {
        return $this->events('-i '.escapeshellarg($id))[0] ?? null;
    }

    /**
     * Walks a `prev`-chained kind (League Attestation `2154`) back from
     * $event to its genesis: every link found on the relay by id, every
     * link's signature valid, none skipped.
     *
     * @return list<SignedEvent> genesis first
     */
    public function chain(SignedEvent $event): array
    {
        $chain = [$event];

        while (($prev = $chain[0]->tag('prev')) !== null) {
            $earlier = $this->byId($prev) ?? throw new \RuntimeException("prev chain broken: {$chain[0]->id} points at {$prev}, not found on the relay.");
            array_unshift($chain, $earlier);
        }

        return $chain;
    }
}
