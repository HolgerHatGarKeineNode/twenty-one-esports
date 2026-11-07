<?php

namespace App\Console\Commands;

use App\Support\Caster\CasterFetcher;
use App\Support\Caster\DuetDirector;
use App\Support\Caster\DuetScripts;
use App\Support\Caster\Pcm;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * The voice profiles of TWENTY ONE TV on the workstation (user 2026-10-12:
 * new references for Markus and Sirius are tried one after the other):
 * lists the local VoiceStudio's profiles (id, name, created) and which one
 * each speaker uses (`tv.commentary.voicestudio.profiles`, set by
 * TV_VOICESTUDIO_PROFILE and TV_VOICESTUDIO_PROFILE_SIRIUS, tempo by
 * TV_VOICESTUDIO_SPEED and TV_VOICESTUDIO_SPEED_SIRIUS).
 *
 * `--probe=<id> --speaker=markus|sirius` voices COMPARISON with that
 * profile in the speaker's place, through the fetcher's own path (chunks,
 * trimming, pauses, the speaker's tempo; CasterFetcher::voice(), its
 * history entries deleted), into storage/app/voice-probes/<speaker>-<id>.wav.
 * Only the newest KEEP probes stay; nothing else is written. An id that is
 * not one of VoiceStudio's profiles is refused before any generation.
 */
#[Signature('caster:voices {--probe= : A VoiceStudio profile id to voice the comparison text with} {--speaker=markus : Whose place the probe takes (markus, sirius or genossin)}')]
#[Description('List the VoiceStudio profiles of the TV voices, or voice a comparison probe with one (workstation)')]
class CasterVoicesCommand extends Command
{
    /** The probes kept in storage/app/voice-probes, newest first. */
    public const KEEP = 6;

    /** The same text for every probe, so two references compare by ear. */
    public const COMPARISON = [
        'Heute Abend laufen in der Liga wieder drei Spiele gleichzeitig, und an jedem Brett wird richtig gekämpft.',
        'Wer jetzt noch einsteigen will, der schnappt sich einfach einen freien Platz und spielt eine Runde mit.',
        'Und ganz ehrlich, so spannend war ein Freitagabend vor dem Bildschirm schon lange nicht mehr.',
    ];

    public function handle(CasterFetcher $fetcher): int
    {
        $settings = (array) config('tv.commentary.voicestudio', []);
        $url = rtrim((string) ($settings['url'] ?? ''), '/');

        try {
            $response = Http::timeout(10)->acceptJson()->get($url.'/profiles');
        } catch (ConnectionException) {
            $this->error("VoiceStudio is not reachable at {$url}.");

            return self::FAILURE;
        }

        $profiles = $response->successful() && is_array($response->json()) ? array_values(array_filter($response->json(), fn (mixed $profile): bool => is_array($profile) && is_string($profile['id'] ?? null))) : null;

        if ($profiles === null) {
            $this->error("VoiceStudio answered {$response->status()} for its profiles.");

            return self::FAILURE;
        }

        $uses = self::speakerProfiles();
        $probe = $this->option('probe');

        if (! is_string($probe)) {
            $this->table(['id', 'name', 'created', 'used by'], array_map(fn (array $profile): array => [
                $profile['id'],
                (string) ($profile['name'] ?? ''),
                is_numeric($profile['created_at'] ?? null) ? CarbonImmutable::createFromTimestamp((float) $profile['created_at'], config('app.timezone'))->format('Y-m-d H:i') : '',
                implode(', ', array_map(fn (string $speaker): string => $speaker.' (speed '.self::speed($speaker).')', array_keys($uses, $profile['id'], true))),
            ], $profiles));

            foreach ($uses as $speaker => $id) {
                if (! in_array($id, array_column($profiles, 'id'), true)) {
                    $this->warn("{$speaker} uses {$id}, which VoiceStudio does not have.");
                }
            }

            return self::SUCCESS;
        }

        $speaker = $this->option('speaker');

        if (! is_string($speaker) || ! in_array($speaker, DuetScripts::speakerNames(), true)) {
            $this->error('--speaker is one of: '.implode(', ', DuetScripts::speakerNames()).'.');

            return self::FAILURE;
        }

        if (preg_match('/^[A-Za-z0-9_-]{4,64}$/', $probe) !== 1 || ! in_array($probe, array_column($profiles, 'id'), true)) {
            $this->error('No VoiceStudio profile with that id; `php artisan caster:voices` lists them.');

            return self::FAILURE;
        }

        // The probe takes the speaker's place for this run only: the fetcher reads its profile (and Sirius' tempo) from here.
        config(["tv.commentary.voicestudio.profiles.{$speaker}" => $probe]);
        if ($speaker === DuetDirector::MARKUS) {
            config(['tv.commentary.voicestudio.profile_id' => $probe]);
        }

        $voiced = $fetcher->voice(self::COMPARISON, [], array_fill(0, count(self::COMPARISON), $speaker));

        if ($voiced === null) {
            $this->error('VoiceStudio did not voice the probe.');

            return self::FAILURE;
        }

        $directory = storage_path('app/voice-probes');
        File::ensureDirectoryExists($directory);
        $path = "{$directory}/{$speaker}-{$probe}.wav";
        File::put($path, Pcm::toWav($voiced['pcm'], $voiced['rate']));
        self::prune($directory);

        $this->info(sprintf('%s as %s (speed %s): %.1f s, %s', $probe, $speaker, self::speed($speaker), Pcm::seconds($voiced['pcm'], $voiced['rate']), $path));

        return self::SUCCESS;
    }

    /**
     * The profile each speaker uses now.
     *
     * @return array<string, string>
     */
    public static function speakerProfiles(): array
    {
        $profiles = (array) config('tv.commentary.voicestudio.profiles', []);
        $markus = trim((string) ($profiles[DuetDirector::MARKUS] ?? config('tv.commentary.voicestudio.profile_id', '')));
        $sirius = trim((string) ($profiles[DuetDirector::SIRIUS] ?? ''));

        $others = [];

        // A further voice (the talk show's guest, P16) with its own profile.
        foreach (DuetScripts::speakerNames() as $speaker) {
            if (! in_array($speaker, [DuetDirector::MARKUS, DuetDirector::SIRIUS], true) && trim((string) ($profiles[$speaker] ?? '')) !== '') {
                $others[$speaker] = trim((string) $profiles[$speaker]);
            }
        }

        return [DuetDirector::MARKUS => $markus, DuetDirector::SIRIUS => $sirius === '' ? $markus : $sirius, ...$others];
    }

    /** Only the newest KEEP probe files stay. */
    public static function prune(string $directory): void
    {
        $files = File::glob($directory.'/*.wav');
        usort($files, fn (string $a, string $b): int => [filemtime($b), $b] <=> [filemtime($a), $a]);

        foreach (array_slice($files, self::KEEP) as $file) {
            File::delete($file);
        }
    }

    private static function speed(string $speaker): string
    {
        $speed = $speaker === DuetDirector::MARKUS
            ? config('tv.commentary.voicestudio.speed')
            : config('tv.commentary.voicestudio.speeds.'.$speaker, config('tv.commentary.voicestudio.speed'));

        return (string) (float) $speed;
    }
}
