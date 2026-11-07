<?php

namespace App\Console\Commands;

use App\Support\Voice\CanonLanguageAgent;
use App\Support\Voice\VoiceUsage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Marks the meme canon for the English channels (plan "KI-Stimme der Liga",
 * P2): every entry without `en_ok` goes to Claude Haiku in batches
 * (CanonLanguageAgent), and its answer is written back into
 * resources/data/voice/meme-canon.json. An entry the model leaves out stays
 * unmarked, which VoiceCanon reads as German only (the English channels
 * never get it). Costs money: a few Haiku calls for the whole canon; the
 * tokens are counted in `voice:usage` (channel `canon_language`).
 */
#[Signature('voice:mark-english
    {--all : Ask again for entries that already carry en_ok}
    {--batch=100 : Entries per request}
    {--path= : The canon file (default resources/data/voice/meme-canon.json)}')]
#[Description('Mark which meme canon entries make sense in English posts (en_ok), with one Haiku batch run')]
class VoiceMarkEnglishCommand extends Command
{
    public function handle(VoiceUsage $usage): int
    {
        $given = $this->option('path');
        $path = is_string($given) && $given ? $given : resource_path('data/voice/meme-canon.json');
        $raw = (string) file_get_contents($path);
        $canon = json_decode($raw, true);

        if (! is_array($canon)) {
            $this->error("Unreadable canon file [{$path}].");

            return self::FAILURE;
        }

        $open = [];
        foreach ($canon as $index => $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null) && ($this->option('all') || ! is_bool($entry['en_ok'] ?? null))) {
                $open[$entry['id']] = $index;
            }
        }

        $this->line(count($open).' of '.count($canon).' entries to mark.');
        $decided = 0;

        foreach (array_chunk(array_keys($open), max(1, (int) $this->option('batch'))) as $batch) {
            $lines = array_map(fn (string $id): string => $id.' | '.$canon[$open[$id]]['wording'].' | '.($canon[$open[$id]]['meaning'] ?? ''), $batch);

            try {
                $response = (new CanonLanguageAgent)->prompt("Entries (id | wording | meaning):\n\n".implode("\n", $lines));
            } catch (Throwable $exception) {
                $this->warn('A batch failed ('.$exception::class.'); its entries stay unmarked.');

                continue;
            }

            $usage->recordAttempt('canon_language');
            $usage->recordTokens('canon_language', $response->usage->inputTokens, $response->usage->outputTokens, (int) $response->usage->cacheReadInputTokens, (int) $response->usage->cacheWriteInputTokens);

            $answer = $response->toArray();

            foreach ((array) ($answer['decisions'] ?? []) as $decision) {
                $id = is_array($decision) ? ($decision['id'] ?? null) : null;

                if (is_string($id) && in_array($id, $batch, true) && is_bool($decision['en_ok'] ?? null)) {
                    $canon[$open[$id]]['en_ok'] = $decision['en_ok'];
                    $decided++;
                }
            }

            $this->line(sprintf('batch of %d: %d decided so far, input %d, output %d tokens', count($batch), $decided, $response->usage->inputTokens, $response->usage->outputTokens));
        }

        $json = json_encode($canon, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // The file is indented by two spaces (json_encode indents by four) and keeps its own last line break or none.
        $json = (string) preg_replace_callback('/^( {4})+/m', fn (array $match): string => str_repeat('  ', intdiv(strlen($match[0]), 4)), $json);
        file_put_contents($path, $json.(str_ends_with($raw, "\n") ? "\n" : ''));

        $marked = array_filter($canon, fn (mixed $entry): bool => is_array($entry) && is_bool($entry['en_ok'] ?? null));
        $english = array_filter($marked, fn (array $entry): bool => $entry['en_ok'] === true);
        $this->info(sprintf('%d decided now; %d of %d entries marked, %d of them en_ok.', $decided, count($marked), count($canon), count($english)));

        return self::SUCCESS;
    }
}
