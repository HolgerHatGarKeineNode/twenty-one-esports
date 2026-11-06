<?php

namespace App\Console\Commands;

use App\Support\Caster\Blocks\FormatPurge;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\Programme\Rubrics;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Removes the old stock of a format (plan "TWENTY ONE Radio", P20; user
 * 2026-10-13: "Die Altbestände der Talks dann entfernen"): its scripts and
 * ready clips in the server's spool, its blocks in the stock library, and on
 * the workstation its blocks in the day stock and the replay archive, all
 * written or rendered before `--before` (a time, or a commit whose time
 * counts). Lists only, unless --force (FormatPurge).
 */
#[Signature('caster:purge-format
    {format : The format id, e.g. stammtisch}
    {--before= : Remove what was written or rendered before this time or commit (default: now)}
    {--force : Remove it; without, only list it}')]
#[Description('List or remove what a programme format produced before a cut-off (spool, stock library, day stock, replays)')]
class CasterPurgeFormatCommand extends Command
{
    public function handle(ClipSpool $spool): int
    {
        $format = is_string($this->argument('format')) ? $this->argument('format') : '';

        if (! isset(Rubrics::all()[$format])) {
            $this->error("Unknown format \"{$format}\".");

            return self::FAILURE;
        }

        $before = self::cutOff($this->option('before'));

        if ($before === null) {
            $this->error('--before is neither a time nor a commit of this checkout.');

            return self::FAILURE;
        }

        $purge = FormatPurge::fromConfig($spool);
        $found = $this->option('force') ? $purge->purge($format, $before) : $purge->find($format, $before);

        $this->line(sprintf('%s %s before %s: %d scripts, %d ready clips, %d stock library blocks, %d day stock blocks, %d archived replays.',
            $this->option('force') ? 'Removed' : 'Would remove (dry run, --force removes)', $format, $before->toIso8601String(),
            count($found['scripts']), count($found['clips']), count($found['library']), count($found['day_stock']), count($found['replays'])));

        foreach (['scripts', 'clips', 'library', 'day_stock', 'replays'] as $part) {
            if ($found[$part] !== []) {
                $this->line('  '.$part.': '.implode(', ', array_map('strval', $found[$part])));
            }
        }

        return self::SUCCESS;
    }

    /** The cut-off: now without a value, a commit's time for a hex hash of this checkout, else a parsed time; null when neither. */
    private static function cutOff(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return CarbonImmutable::now();
        }

        if (preg_match('/^[0-9a-f]{7,40}$/i', trim($value)) === 1) {
            $result = Process::path(base_path())->run(['git', 'show', '-s', '--format=%ct', trim($value)]);

            return $result->successful() && is_numeric(trim($result->output())) ? CarbonImmutable::createFromTimestamp((int) trim($result->output())) : null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
