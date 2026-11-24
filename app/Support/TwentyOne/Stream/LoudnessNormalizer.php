<?php

namespace App\Support\TwentyOne\Stream;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Brings a song to the library's loudness (plan "Musik-Katalog": −15 LUFS, true peak ≤ −1.5 dBTP, 44.1 kHz stereo
 * AAC): two-pass loudnorm (I=-15:TP=-1.5:LRA=11, linear) with a lookahead limiter at −1.5 dB behind it, encoded as
 * AAC 128k .m4a, then measured with ebur128. A result whose true peak is above −1.0 dBTP is made again with the
 * ceiling lowered by the measured excess and half a dB more, at most TRIES encodes in all; still above, it is refused.
 * The ceiling is lowered from the song's own peak where that lies below it, since a ceiling above the peak limits
 * nothing. The AAC encoder overshoots the limiter, by more than a fixed dB on some songs: "Journalists Are
 * Cypherpunks - Richard Greaser" (peak −3.1 dB after loudnorm) kept +0.2 dBTP at ceilings of −1.5, −3.2 and −4.5 dB
 * and came to −1.1 at −5.2 (measured 2026-10-16).
 *
 * The limiter runs without latency compensation: with it (`latency=true`) the same song measured +4.2 dBTP at a
 * −4.9 dB ceiling, against −2.3 without it; the delay it saves is the 5 ms lookahead at the start of the song.
 *
 * The 68 songs measured above −1 dBTP on 2026-10-16 (up to +2.3) are re-made this way from their originals
 * (twentyone:music-renormalize); new songs come in through twentyone:music-add.
 */
final class LoudnessNormalizer
{
    public const TARGET = 'I=-15:TP=-1.5:LRA=11';

    /** The highest true peak a result may keep, in dBTP. */
    public const MAX_TRUE_PEAK = -1.0;

    /** The limiter's first ceiling, and loudnorm's own true-peak target, in dB. */
    private const LIMIT = -1.5;

    /** loudnorm's integrated loudness target, in LUFS. */
    private const LOUDNESS = -15.0;

    /** Encodes at most, each with a lower ceiling than the one before. */
    public const TRIES = 3;

    /** Headroom added below the measured excess when the ceiling is lowered, in dB. */
    private const MARGIN = 0.5;

    public function __construct(private string $ffmpeg = 'ffmpeg') {}

    /**
     * Writes the normalised `$output` from `$input` (through a temp file beside it, renamed at the end) and returns
     * what ebur128 measured on it.
     *
     * @return array{lufs: float|null, true_peak: float|null, limit: float}
     */
    public function normalize(string $input, string $output): array
    {
        $measured = $this->firstPass($input);
        // Hidden and without the .m4a ending: neither the stream's glob nor the catalog's scan takes it for a song.
        $temp = dirname($output).'/.'.basename($output).'.normalize-'.getmypid().'.part';

        // The song's peak after loudnorm: its own raised by the linear gain, at most loudnorm's target.
        $peak = min((float) $measured['input_tp'] + self::LOUDNESS - (float) $measured['input_i'], self::LIMIT);

        [$pre, $given] = self::withinLoudnormRange($measured);

        try {
            $limit = self::LIMIT;

            for ($try = 0; $try < self::TRIES; $try++) {
                $chain = $pre.sprintf('loudnorm=%s:measured_I=%s:measured_TP=%s:measured_LRA=%s:measured_thresh=%s:offset=%s:linear=true,alimiter=limit=%.4f:level=false:latency=false',
                    self::TARGET, $given['input_i'], $given['input_tp'], $given['input_lra'], $given['input_thresh'], $given['target_offset'], 10 ** ($limit / 20));

                $encode = Process::timeout(1800)->run(['nice', '-n', '19', $this->ffmpeg, '-y', '-hide_banner', '-nostats', '-i', $input, '-map', '0:a:0',
                    '-filter:a', $chain, '-ar', '44100', '-ac', '2', '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', '-f', 'mp4', $temp]);

                if ($encode->failed()) {
                    throw new RuntimeException('ffmpeg could not encode '.basename($input).': '.self::tail($encode->errorOutput()));
                }

                $result = AudioProbe::loudness(Process::timeout(1800)->run(['nice', '-n', '19', $this->ffmpeg, '-hide_banner', '-nostats', '-i', $temp,
                    '-map', '0:a:0', '-filter:a', 'ebur128=peak=true', '-f', 'null', '-'])->errorOutput());

                if ($result['true_peak'] !== null && $result['true_peak'] <= self::MAX_TRUE_PEAK) {
                    if (! rename($temp, $output)) {
                        throw new RuntimeException('Could not write '.$output);
                    }

                    return [...$result, 'limit' => $limit];
                }

                // A peak ebur128 could not read counts as one dB too high.
                $limit = min($limit, $peak) - ($result['true_peak'] === null ? 1.0 : $result['true_peak'] - self::MAX_TRUE_PEAK) - self::MARGIN;
            }

            throw new RuntimeException(sprintf('%s: true peak %s dBTP after the limiter, above %.1f', basename($input), $result['true_peak'] ?? 'unknown', self::MAX_TRUE_PEAK));
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * loudnorm's measurement of the input (its JSON block on stderr).
     *
     * @return array{input_i: string, input_tp: string, input_lra: string, input_thresh: string, target_offset: string}
     */
    private function firstPass(string $input): array
    {
        $run = Process::timeout(1800)->run(['nice', '-n', '19', $this->ffmpeg, '-hide_banner', '-nostats', '-i', $input, '-map', '0:a:0',
            '-filter:a', 'loudnorm='.self::TARGET.':print_format=json', '-f', 'null', '-']);
        $stderr = $run->errorOutput();
        // The block is followed by ffmpeg's closing lines.
        $start = strrpos($stderr, '{');
        $end = $start === false ? false : strpos($stderr, '}', $start);
        $data = $run->successful() && $start !== false && $end !== false ? json_decode(substr($stderr, $start, $end - $start + 1), true) : null;
        $keys = ['input_i', 'input_tp', 'input_lra', 'input_thresh', 'target_offset'];

        if (! is_array($data) || array_diff($keys, array_keys($data)) !== [] || collect($keys)->contains(fn (string $key): bool => ! is_numeric($data[$key]))) {
            throw new RuntimeException('loudnorm could not measure '.basename($input).': '.self::tail($stderr));
        }

        /** @var array{input_i: string, input_tp: string, input_lra: string, input_thresh: string, target_offset: string} */
        return array_map('strval', array_intersect_key($data, array_flip($keys)));
    }

    /**
     * loudnorm refuses `measured_I` above 0 LUFS ("Numerical result out of range", the encode never starts), and a
     * source can measure above it: a clipped or float master whose peaks pass full scale (ffmpeg n9.1 keeps a +7 dBFS
     * AAC fixture at +1.8 LUFS). Such a song is brought to the target by a gain in front of loudnorm, and the
     * measurement is handed over shifted by the same gain (to the target, which is in range), so loudnorm's linear
     * pass has nothing left but the song's own correction. Songs within range pass unchanged.
     *
     * @param  array{input_i: string, input_tp: string, input_lra: string, input_thresh: string, target_offset: string}  $measured
     * @return array{0: string, 1: array{input_i: string, input_tp: string, input_lra: string, input_thresh: string, target_offset: string}} the filter prefix and the measurement loudnorm is given
     */
    private static function withinLoudnormRange(array $measured): array
    {
        if ((float) $measured['input_i'] <= 0.0) {
            return ['', $measured];
        }

        $gain = self::LOUDNESS - (float) $measured['input_i'];

        return [sprintf('volume=%.4FdB,', $gain), [
            ...$measured,
            'input_i' => sprintf('%.2F', self::LOUDNESS),
            'input_tp' => sprintf('%.2F', (float) $measured['input_tp'] + $gain),
            'input_thresh' => sprintf('%.2F', (float) $measured['input_thresh'] + $gain),
        ]];
    }

    private static function tail(string $output): string
    {
        return trim(substr(trim($output), -300));
    }
}
