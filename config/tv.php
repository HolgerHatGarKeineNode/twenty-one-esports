<?php

use App\Support\Caster\NewsMix;

return [

    /*
    |--------------------------------------------------------------------------
    | TWENTY ONE TV: the spoken commentary (plan "KI-Stimme der Liga", P6)
    |--------------------------------------------------------------------------
    |
    | Markus Turm's voice on the 24/7 stream, generated on his own workstation
    | with VoiceStudio. Every switch here is off by default; go-live waits for
    | the user (P7 tone approval).
    |
    | scripts (App\Support\Caster\ScriptQueue, `caster:script` every minute):
    | scene scripts are written ahead per slide topic, `ahead_seconds` of
    | airtime (estimated at `words_per_second`): at most `ai_calls_per_fill` by the
    | league voice (`ai`, TV_COMMENTARY_AI; never for a topic without data),
    | the rest as `patterns` (resources/data/voice/caster.json, filled only
    | with the topic's facts), so airtime never depends on the model. A
    | script expires unused after `max_age_minutes` or when the facts of its
    | topic changed. Live moments (`live_priority`) and chat reactions
    | (`chat_priority`) jump the queue.
    |
    | fetch: the workstation fetcher (`php artisan caster:fetch`) pulls scripts
    | with `token` as a bearer token (`.env` only, never logged). A fetcher
    | seen within `online_seconds` counts as online; otherwise the stream shows
    | the script as a text lower third instead of sound.
    |
    | spool (App\Support\Caster\ClipSpool, server only): Opus clips on the
    | `disk`, hard caps `max_bytes` and `max_seconds` of unplayed audio (an
    | upload over a cap is refused with 507, the fetcher backs off), a clip is
    | deleted right after it played, unplayed clips expire with their script;
    | `caster:prune` removes expired clips and orphan files as a safety net.
    | The fetcher works towards `target_seconds` of ready audio; below
    | `filler_below_seconds` the stream plays fillers (idents, `filler: true`
    | scripts) instead of eating the last clips.
    |
    | voicestudio (workstation): the local VoiceStudio API, the voice profile
    | of each speaker and its tempo (`php artisan caster:voices` lists them),
    | pauses between chunks (seconds, slightly random), each chunk trimmed to
    | its speech with `pad_ms` of quiet at both ends. Every generation's history entry is deleted
    | right after the audio was read (the fetcher leaves no files behind).
    |
    | mix (App\Support\TwentyOne\Stream\FfmpegCommands): TV levels, voice at
    | -16 LUFS (normalised on the workstation), the music bed `music_gain_db`
    | down (to about -28 LUFS, measure with `caster:music-loudness`), ducked
    | under the voice by a sidechain compressor, limiter at -1 dBFS.
    |
    | scenes (App\Support\Caster\SceneDirector): with commentary on, a scene
    | stands at least `min_scene_seconds` and never ends before its clip ended
    | plus `tail_seconds` (never mid-sentence), at most `max_hold_seconds`.
    |
    | chat (App\Support\Caster\ChatReactions): spoken reactions to the stream
    | chat (kind 1311): a personal one only for trusted pubkeys (TrustRank >=
    | the season's minimum, or web-of-trust level 1/2 of the league's trust
    | anchors); everybody else (`everyone`) gets a generic greeting without
    | name or content, at most one per `everyone_minutes` for all of them.
    | `zaps`: every zap of the stream (kind 9735) is thanked once, with the
    | amount spelled out, the zapper's name only when trusted; zaps within
    | `zap_bundle_seconds` share one thank-you, from `zap_big_sats` on an
    | extra-emotional line. Pattern texts work with `ai` off.
    | `profiles` adds the persona card from public kind 0 + notes (cached in
    | the database, capped, pruned). Both stay off until the association has
    | decided the data protection question (profiling, GDPR).
    |
    | news (App\Support\Caster\NewsFeeds, NewsScripts; `caster:news` every 15
    | minutes): headlines from the public `feeds` (checked 2026-10-11), kept
    | `keep_hours`, at most `max_rows`; a headline about death, disaster,
    | war, terror, violence against people, abuse or suicide is never spoken
    | (keyword filter, then the model's own check). A news segment (intro,
    | the headline word for word with its source, one to three sentences of
    | opinion by the league voice, pattern reactions when it fails) at most
    | every `every_minutes`, one model call per headline.
    |
    | history (App\Support\Caster\HistoryScripts): "Markus erzählt vom
    | Bauernkrieg", a long segment built from resources/data/voice/bauernkrieg.json
    | (facts with sources, no model), every `every_minutes` (random within the
    | range), no episode again within `repeat_hours`. Segments play only on
    | calm slots, never two within `spool.segment_spacing_minutes`.
    |
    */

    'commentary' => [
        'enabled' => (bool) env('TV_COMMENTARY', false),

        // Whether the league voice (Claude) writes scene scripts at all; off, the pattern scripts fill the airtime alone.
        'ai' => (bool) env('TV_COMMENTARY_AI', true),

        'scripts' => [
            'ahead_seconds' => (int) env('TV_COMMENTARY_AHEAD_SECONDS', 960),
            'words_per_second' => 2.4,
            'sentences' => ['min' => (int) env('TV_COMMENTARY_AI_SENTENCES_MIN', 2), 'max' => (int) env('TV_COMMENTARY_AI_SENTENCES_MAX', 8)],
            'live_sentences' => ['min' => 1, 'max' => 3],
            // Short (user 2026-10-25: replies of 60 s were too long).
            'chat_sentences' => ['min' => 1, 'max' => 3, 'ask' => 2],
            // Long, complete sentences (user 2026-10-12: "Wenn die Sätze länger sind, dann hören sich die Audios viel besser
            // an" and "komplette Sätze ab jetzt nur noch"): OmniVoice intones a fragment or a two-word sentence badly, so
            // every spoken sentence of a non-chat script has at least `min_words_per_sentence` words (markers not counted).
            'max_words_per_sentence' => (int) env('TV_COMMENTARY_MAX_WORDS', 40),
            'min_words_per_sentence' => (int) env('TV_COMMENTARY_MIN_WORDS', 7),
            'max_commas' => 2,
            'max_chars' => 900,
            // League-voice scene scripts per fill() run (each with its one retry), AI first (user 2026-10-12: "FREIE
            // KI-generierte Rede!!! Komplette Varianz!"); patterns only where the voice gave nothing. The monthly budget
            // and `daily_cap` bound them as well.
            'ai_calls_per_fill' => (int) env('TV_COMMENTARY_AI_CALLS_PER_FILL', 12),
            // Sampling temperature of every TV call (high: variance and artistic freedom, user 2026-10-12) and room for a long
            // segment's answer (a Bauernkrieg episode of 30 sentences is about 1,000 tokens; the posts' 400 would cut it off).
            'temperature' => (float) env('TV_COMMENTARY_TEMPERATURE', 1.0),
            'max_output_tokens' => (int) env('TV_COMMENTARY_MAX_OUTPUT_TOKENS', 1_500),
            // Scenes are no longer bound to the slide on air; ten minutes expired most of them unvoiced while the workstation lagged (2026-10-12).
            'max_age_minutes' => (int) env('TV_COMMENTARY_SCRIPT_MAX_AGE_MINUTES', 60),
            'supersede' => (bool) env('TV_COMMENTARY_SUPERSEDE', false),
            'live_priority' => 100,
            'chat_priority' => 50,
            'recent' => 10,
            'daily_cap' => (int) env('TV_COMMENTARY_DAILY_CAP', 400),
            // The canon entries a scene script's prompt offers (fitting the phase first): the system prompt (guides,
            // patterns, invitations) stays byte-identical and cached, only the facts and this pick vary.
            'canon' => ['min' => 12, 'max' => 15, 'token_budget' => 2_000],
            // What a scene script is about (user 2026-10-12: "Das mit den Slide-Fakten ist nicht stimmig. Lieber mehr
            // Lebendigkeit!"): one material drawn per call by these weights (CasterVoice::MATERIALS); a material without
            // data (no podcast digest, no world memory, no own note) is left out of the draw. `league` is the only one
            // that shows the slide's facts, so at most about `league` / total of the scenes mention one.
            'materials' => [
                'world' => (int) env('TV_COMMENTARY_MATERIAL_WORLD', 3),
                'podcast' => (int) env('TV_COMMENTARY_MATERIAL_PODCAST', 2),
                'canon' => (int) env('TV_COMMENTARY_MATERIAL_CANON', 3),
                'fiat' => (int) env('TV_COMMENTARY_MATERIAL_FIAT', 2),
                'nostr' => (int) env('TV_COMMENTARY_MATERIAL_NOSTR', 1),
                'chat' => (int) env('TV_COMMENTARY_MATERIAL_CHAT', 2),
                'invite' => (int) env('TV_COMMENTARY_MATERIAL_INVITE', 1),
                'league' => (int) env('TV_COMMENTARY_MATERIAL_LEAGUE', 2),
            ],
        ],

        // The deterministic scene scripts (App\Support\Caster\PatternScripts): at most `per_run` per fill() run,
        // `sentences` each, no line pattern again within the last `recent` pattern scripts.
        // `fact_memory_minutes`: a fact atom is not said again by any script for this long; `name_memory_minutes` the same
        // for a person's name; `flavour_recent`: no flavour line (joke, invitation, pride, chat call, emotion) again
        // within this many pattern scripts (user 2026-10-11: fewer data, no repeats, more jokes and pride).
        'patterns' => [
            // Template text at all (user 2026-10-12: "KEINE SICH WIEDERHOLENDEN FÜLLSÄTZE MEHR: ALLES NUR NOCH MIT KI"): off,
            // nothing pattern-made and no filler is queued or played, and when the model gives nothing the music plays.
            'enabled' => (bool) env('TV_COMMENTARY_PATTERNS', false),
            'per_run' => 40,
            'sentences' => ['min' => (int) env('TV_COMMENTARY_PATTERN_SENTENCES_MIN', 3), 'max' => (int) env('TV_COMMENTARY_PATTERN_SENTENCES_MAX', 6)],
            'recent' => 4,
            'fact_memory_minutes' => 20,
            'name_memory_minutes' => 30,
            // A tournament, track, clan or ladder name: once per script, not again within this long (user 2026-10-11).
            'entity_memory_minutes' => 15,
            'flavour_recent' => 40,
            // No canon line again within this many canon lines of the pattern scripts (the full canon has over 1300).
            'canon_recent' => 200,
        ],

        'fetch' => [
            'token' => env('TV_COMMENTARY_TOKEN'),
            'online_seconds' => 120,
            // A claimed script that came back neither as a clip nor released is free again after this long (a long
            // Bauernkrieg segment is 15 to 30 sentences, about ten VoiceStudio calls).
            // A programme block (P6) of up to six minutes holds its claims while all of it is voiced.
            'claim_seconds' => (int) env('TV_COMMENTARY_CLAIM_SECONDS', 1200),
            // A claimed script the clock places (bulletin, rubric, teaser) is free again after this long instead: a
            // fetcher killed mid-voicing held bulletins 2644 and 2706 past their slots (prod 2026-10-13).
            'slot_claim_seconds' => (int) env('TV_COMMENTARY_SLOT_CLAIM_SECONDS', 120),
            // The claim heartbeat (CasterFetcher::keepClaims()): while voicing, the workstation renews its claims this often,
            // so a long block never lapses mid-voicing while a dead fetcher's claims still free after the two limits above.
            'renew_seconds' => (int) env('TV_COMMENTARY_RENEW_SECONDS', 60),
        ],

        // The voicing budget (App\Support\Caster\VoiceBudget; prod 2026-10-13: at 64 OmniVoice steps the workstation voices
        // about as fast as real time, and of three hours' scripts 27 scenes, 15 news, 15 rubric parts and a bulletin expired
        // unvoiced): the server writes only what the workstation can voice, in strict priority, and leaves `stock_share` of
        // the GPU hour to the stock. The throughput (estimated seconds voiced per wall second) is measured from the uploads
        // of the last `measure_minutes` (claim to upload, overlaps counted once), `default_rtf` until enough was voiced.
        'budget' => [
            // Off for good (user 2026-10-15: "Stundenbudgets gibt es nicht mehr"): the texts come from the local
            // opencode CLI and cost nothing to write, so nothing is throttled by the workstation's voicing speed any
            // more; the spool's own caps (`max_seconds`, `max_bytes`) keep the buffer bounded instead.
            'enabled' => (bool) env('TV_COMMENTARY_BUDGET', false),
            'stock_share' => (float) env('TV_COMMENTARY_BUDGET_STOCK_SHARE', 0.3),
            // The budget's hour: what was written and voiced in it.
            'window_minutes' => 60,
            // The throughput is measured over this long (prod 2026-10-13: the last hour alone held ten busy minutes of short
            // replies and read 1.88, three hours read 0.99); fewer busy seconds than `min_busy_seconds` in it: the last
            // measured value (kept `keep_hours`), else `default_rtf`.
            'measure_minutes' => 180,
            'min_busy_seconds' => 900,
            'keep_hours' => 24,
            'default_rtf' => (float) env('TV_COMMENTARY_BUDGET_DEFAULT_RTF', 0.8),
            // A higher tier's script due to be written within this many minutes is kept free of the budget for it.
            'reserve_minutes' => 30,
            // A script is written only when it is voiced this long before it expires (a bulletin: before `ready_seconds`).
            'margin_seconds' => 60,
            // What one script of a tier is reckoned at before it is written (a show: its window, Shows).
            'expected_seconds' => ['bulletin' => 120, 'news' => 150, 'rubric' => 150, 'chat' => 60, 'segment' => 60, 'scene' => 40],
            'chat_per_hour' => 6,
            // Tight (the tiers above the scenes ask at least `tight_share` of the hour's budget): at most `per_hour` slide scenes an hour.
            // `always` (user 2026-10-13: "Weniger statische selbst ausgedachte Gags"): the cap holds every hour, not only when tight.
            'scene_cap' => ['per_hour' => (int) env('TV_COMMENTARY_SCENES_PER_HOUR', 2), 'tight_share' => 0.6, 'always' => (bool) env('TV_COMMENTARY_SCENE_CAP_ALWAYS', true)],
        ],

        'spool' => [
            'disk' => 'local',
            'dir' => 'caster/spool',
            'max_bytes' => (int) env('TV_COMMENTARY_SPOOL_MAX_BYTES', 50 * 1024 * 1024),
            'max_seconds' => (int) env('TV_COMMENTARY_SPOOL_MAX_SECONDS', 1200),
            // A long Bauernkrieg segment of four minutes is about 1.5 MB at `opus_kbps` 48.
            'max_clip_bytes' => 6 * 1024 * 1024,
            // Long duets and chat duets ran past 90 s; a refused upload went back to the queue and was voiced again and again (2026-10-12).
            'max_clip_seconds' => (int) env('TV_COMMENTARY_MAX_CLIP_SECONDS', 300),
            // A clip of these kinds may be this long (the long variants, tv.commentary.length): a long scene runs 60 to
            // 100 s spoken, a long news segment up to two minutes, a long Bauernkrieg segment two to four minutes.
            // Live clips in a row before a waiting block goes first (ClipSpool::next()).
            'live_streak' => 3,
            'long_clip_seconds' => ['scene' => 360, 'news' => 360, 'history' => 420, 'bulletin' => 600, 'rubric' => 420, 'chat' => 300, 'live' => 300],
            // At most one news or history segment on air within this many minutes.
            'segment_spacing_minutes' => 5,
            // Talk density with the gap (below): a clip of about 20 s plus 20 to 30 s of music, so the spool needs half the
            // airtime it did before the gap (180 and 60), and the queue writes half as much ahead (ahead_seconds).
            'target_seconds' => (int) env('TV_COMMENTARY_TARGET_SECONDS', 600),
            'filler_below_seconds' => 30,
            // Music only between two clips, a random length in this range (user 2026-10-11); a live moment cuts it short.
            // Quiet hours (user 2026-10-12: "zwischen 01:00 und 09:00 nur noch fix alle 10 Minuten ein Audio"): one clip every
            // `every_minutes`, no runs, a small queue so the workstation GPU idles; chat reactions still answer.
            'quiet_hours' => [
                'from' => env('TV_COMMENTARY_QUIET_FROM', '01:00'),
                'to' => env('TV_COMMENTARY_QUIET_TO', '09:00'),
                'timezone' => env('TV_COMMENTARY_QUIET_TZ', 'Europe/Berlin'),
                'every_minutes' => (float) env('TV_COMMENTARY_QUIET_EVERY_MINUTES', 10),
                'ahead_seconds' => (int) env('TV_COMMENTARY_QUIET_AHEAD_SECONDS', 90),
            ],
            'gap_seconds' => ['min' => (float) env('TV_COMMENTARY_GAP_MIN', 6), 'max' => (float) env('TV_COMMENTARY_GAP_MAX', 12)],
        ],

        'voicestudio' => [
            'url' => env('TV_VOICESTUDIO_URL', 'http://127.0.0.1:3900'),
            'profile_id' => env('TV_VOICESTUDIO_PROFILE', 'ee7a2a18'),
            // The voice of each speaker of a duet (DuetDirector); Markus' is `profile_id`. Consent: Sirius agreed (per Markus Turm, 2026-10-12).
            'profiles' => [
                'markus' => env('TV_VOICESTUDIO_PROFILE', 'ee7a2a18'),
                'sirius' => env('TV_VOICESTUDIO_PROFILE_SIRIUS', '446d98e1'),
                // The talk show guest (P16, Programme\TalkShow): VoiceStudio's designed archetype "German Companion"
                // (female, young adult, moderate pitch), no person's clone.
                'genossin' => env('TV_VOICESTUDIO_PROFILE_GENOSSIN', '6575625b'),
            ],
            // Further names a script may lead a line with for a speaker ("Gisela: …" is the guest), lowercase.
            'aliases' => ['gisela' => 'genossin', 'genossin gisela' => 'genossin'],
            // The breath when the speaker changes inside a clip (seconds, random within the range); 0.25 to 0.4 s put
            // Sirius too close to Markus (user 2026-10-12).
            'speaker_pause' => ['min' => 0.45, 'max' => 0.7],
            // Generation settings sent with every call (user 2026-10-13, from the VoiceStudio sliders: "genau so die Einstellungen
            // nun belassen"): steps 64, CFG 2.0, t_shift 0.10, position temperature 10, class temperature 1.0, layer penalty 7.0,
            // denoise and post-processing on, duration automatic.
            // A local copy of every uploaded clip for listening; empty = off.
            'keep_local_dir' => (string) env('TV_VOICESTUDIO_KEEP_LOCAL_DIR', ''),
            'generation' => [
                'num_step' => env('TV_VOICESTUDIO_NUM_STEP', '64'),
                'guidance_scale' => env('TV_VOICESTUDIO_CFG', '2.0'),
                't_shift' => env('TV_VOICESTUDIO_T_SHIFT', '0.1'),
                'position_temperature' => env('TV_VOICESTUDIO_POSITION_TEMPERATURE', '10'),
                'class_temperature' => env('TV_VOICESTUDIO_CLASS_TEMPERATURE', '1.0'),
                'layer_penalty_factor' => env('TV_VOICESTUDIO_LAYER_PENALTY', '7.0'),
                'denoise' => env('TV_VOICESTUDIO_DENOISE', 'true'),
                'postprocess_output' => env('TV_VOICESTUDIO_POSTPROCESS', 'true'),
            ],
            'speed' => (float) env('TV_VOICESTUDIO_SPEED', 1.0),
            // Sirius' clone speaks faster than Markus' at the same speed (user 2026-10-12: "Er redet schneller als Markus").
            'speeds' => ['sirius' => (float) env('TV_VOICESTUDIO_SPEED_SIRIUS', 0.92), 'genossin' => (float) env('TV_VOICESTUDIO_SPEED_GENOSSIN', 1.0)],
            // A speaker's tempo for a heated chunk (its last sentence ends with "!"); 1.0 since P20 (user 2026-10-13: "es geht
            // auch viel zu schnell, die Talkshow").
            'heated_speeds' => ['genossin' => (float) env('TV_VOICESTUDIO_HEATED_SPEED_GENOSSIN', 1.0)],
            // Speech level of every chunk (RMS dBFS) and a per-speaker offset in dB (Pcm::levelled()).
            'speech_dbfs' => (float) env('TV_VOICESTUDIO_SPEECH_DBFS', -20),
            'levels' => ['markus' => (float) env('TV_VOICESTUDIO_LEVEL_MARKUS', 0), 'sirius' => (float) env('TV_VOICESTUDIO_LEVEL_SIRIUS', -1), 'genossin' => (float) env('TV_VOICESTUDIO_LEVEL_GENOSSIN', 0)],
            'language' => 'de',
            'timeout_seconds' => 60,
            'retries' => 3,
            'retry_seconds' => 5,
            // Pauses between chunks only; a chunk is one /generate call of up to `max_sentences` and `max_words`
            // (user 2026-10-11: "Und er spricht zu abgehackt"; one call per sentence put a pause after each).
            // `news`: the news read sentence by sentence with a clear pause (user 2026-10-25: items ran together).
            'pause' => ['sentence' => 0.2, 'accent' => 0.35, 'news' => 0.55, 'scene' => [1.0, 1.5], 'jitter' => 0.08],
            'chunk' => ['max_sentences' => 3, 'max_words' => 25, 'one_sentence_kinds' => ['bulletin', 'news']],
            // Each chunk is trimmed to its speech with at least `pad_ms` of quiet at both ends, the kept tail faded out
            // over `fade_ms` (Pcm::trimEdges()). Measured 2026-10-12: VoiceStudio leaves about 100 ms before the onset
            // and 110 to 160 ms after the last word, its noise floor peaks near 50.
            'pad_ms' => 140,
            'fade_ms' => 100,
            // Below this amplitude (of 32767) a 10 ms window counts as quiet when a chunk is trimmed (400 cut soft endings).
            'silence_threshold' => 150,
            'loudness_lufs' => -16,
            'opus_kbps' => 48,
            'ffmpeg' => env('TV_COMMENTARY_FFMPEG', 'ffmpeg'),
            'server' => env('TV_COMMENTARY_SERVER', 'https://esports.einundzwanzig.space'),
            'idle_seconds' => 10,
            // caster:fetch raises PHP's memory limit to this when lower (a block is rendered in memory: the talk show's
            // ten-minute blocks peaked at 121 MB in the P20 remix, the CLI default is 128M).
            'memory_limit' => env('TV_COMMENTARY_FETCH_MEMORY_LIMIT', '512M'),
            // While the server's spool is full: ask again this soon, so a new chat reaction or zap thank-you is voiced within seconds.
            'full_seconds' => 5,
        ],

        'mix' => [
            'music_gain_db' => (float) env('TV_COMMENTARY_MUSIC_GAIN_DB', -14),
            // The voice lifted in the mix, so it stays on top of a music bed near its own level (radio feeling); the limiter catches the peaks.
            'voice_gain_db' => (float) env('TV_COMMENTARY_VOICE_GAIN_DB', 0),
            'duck' => ['threshold' => 0.02, 'ratio' => 12, 'attack_ms' => 20, 'release_ms' => 600],
            'limit' => 0.891,
            'audio_kbps' => 128,
            'sample_rate' => 24000,
            // The voice is written into the encoder this far ahead of real time, so a slow tick of the daemon never starves it.
            // The voice feed runs this far ahead of the wall clock so a slow daemon tick never starves the mix.
            // 2.5 s (2026-10-15): with the voice running non-stop, a tick that decodes a clip or joins a long
            // block took longer than 1 s, the fifo ran dry and aresample stretched the gap — a siren-like tone
            // wandering up and down on air. Half a second of that costs more than the extra clip-start latency.
            'lead_seconds' => 2.5,
            // News mode (user 2026-10-12: "so viel ducken im News-Modus, dass man die BG-Music nicht mehr hört"): under a
            // bulletin, stinger and bed included, the music fades to this level within the fade down and comes back with
            // the fade up after it, on the voice feed's own timeline (CommentaryTimeline::musicDuck()). 0 switches it off.
            'news_music_db' => (float) env('TV_COMMENTARY_NEWS_MUSIC_DB', -60),
            'news_fade_down_seconds' => (float) env('TV_COMMENTARY_NEWS_FADE_DOWN_SECONDS', 1.0),
            'news_fade_up_seconds' => (float) env('TV_COMMENTARY_NEWS_FADE_UP_SECONDS', 2.0),
        ],

        // The station sound (App\Support\Caster\Idents, plan "TWENTY ONE Radio" P4): own synthesized stingers and a news
        // bed in resources/audio/idents (scripts/make-idents.sh, CC0, LICENSES.md). `kinds` maps a clip kind to the
        // stinger it opens with (never a later part of a long script); `bed_kinds` get the bed under their speech, not
        // in quiet hours unless `bed_at_night`. The `bulletin` kind is the news block of the broadcast clock (P2).
        'idents' => [
            'enabled' => (bool) env('TV_COMMENTARY_IDENTS', true),
            // A slot names one stinger or several that rotate (P11: JingleSynth's sfx-*.opus, 2 to 4 s, -16 LUFS).
            'stingers' => [
                'news' => ['news-stinger.opus', 'sfx-news-pulse.opus', 'sfx-news-rise.opus', 'sfx-news-clock.opus'],
                'ident' => ['station-ident.opus', 'sfx-riser-short.opus', 'sfx-reverse-swell.opus'],
                'breaking' => ['breaking-sting.opus', 'sfx-bass-drop.opus'],
            ],
            'kinds' => ['bulletin' => 'news', 'news' => 'news', 'history' => 'ident', 'followed' => 'ident', 'rubric' => 'ident'],
            'bed' => 'news-bed.opus',
            // News bed variants (P11) rotating with `bed`; at -16 LUFS, so `beds_gain_db` puts them where `bed` sits at
            // `bed_gain_db` (-28 - 6 = -16 - 18). A rubric whose format has a bed gets it under its speech.
            'beds' => ['bed-news-pulse.opus', 'bed-news-drive.opus'],
            'beds_gain_db' => (float) env('TV_COMMENTARY_BEDS_GAIN_DB', -18),
            // Every show, roast and rubric gets the bed of its format under its talk (plan "TWENTY ONE Radio" P17: a format
            // bed under talk, -22 to -26 dB under the voice), at `format_beds_gain_db` from the bed file's own level.
            'format_beds' => [
                'sport_wetter' => 'bed-sport.opus', 'tribunal' => 'bed-courtroom.opus', 'liga_pranger' => 'bed-courtroom.opus', 'schwarzbuch' => 'bed-courtroom.opus',
                'partie' => 'bed-sport.opus', 'liga' => 'bed-sport.opus', 'hotline' => 'bed-late-night.opus', 'inflationsbeichte' => 'bed-late-night.opus',
                'zentralbank' => 'bed-late-night.opus', 'friedhof' => 'bed-late-night.opus', 'geschichte' => 'bed-late-night.opus', 'chat' => 'bed-late-night.opus',
                'jahrestag' => 'bed-weather.opus', 'meetups' => 'bed-weather.opus', 'nostr_fundbuero' => 'bed-tutorial.opus', 'phrasendrescher' => 'bed-tutorial.opus',
                'podcast' => 'bed-tutorial.opus', 'corporate_stacker' => 'bed-news-drive.opus', 'altcoin_hype' => 'bed-news-drive.opus', 'presseschau' => 'bed-news-drive.opus',
                'nachrichten_talk' => 'bed-news-pulse.opus', 'stammtisch' => 'bed-news-pulse.opus',
            ],
            // The bed files sit at -16 LUFS like the voice: -24 dB puts a format bed 24 dB under the speech.
            'format_beds_gain_db' => (float) env('TV_COMMENTARY_FORMAT_BEDS_GAIN_DB', -24),
            // The studio audience (plan "TWENTY ONE Radio" P20; user 2026-10-13: "Was ist mit Klatschgeräuschen … bei Shows? Also
            // auch bei der Talkshow?"): synthesized applause (JingleSynth, own work, CC0) in the pauses of a show rendered on
            // the workstation (BlockRenderer, StudioAudience): after the opening, after a hit and at the close, `gain_db`
            // from the file's -16 LUFS (about 20 dB under the voice), at most `max_per_show`, never two within
            // `min_gap_seconds`, never over a word (the pause grows to hold it); only the formats in `formats` (no news,
            // bulletin, Sport und Wetter, podcast or plain rubric). `files`: the variants per moment, rotating.
            'applause' => [
                'enabled' => (bool) env('TV_COMMENTARY_APPLAUSE', true),
                'gain_db' => (float) env('TV_COMMENTARY_APPLAUSE_GAIN_DB', -20),
                'max_per_show' => (int) env('TV_COMMENTARY_APPLAUSE_MAX_PER_SHOW', 4),
                'min_gap_seconds' => (float) env('TV_COMMENTARY_APPLAUSE_MIN_GAP_SECONDS', 20),
                'formats' => ['stammtisch', 'tribunal', 'friedhof', 'jahrestag', 'meetups', 'partie', 'hotline',
                    'zentralbank', 'schwarzbuch', 'altcoin_hype', 'liga_pranger', 'phrasendrescher', 'nostr_fundbuero', 'corporate_stacker', 'inflationsbeichte'],
                'files' => [
                    'open' => ['applause-big-1.opus', 'applause-big-2.opus', 'applause-big-3.opus'],
                    'hit' => ['applause-short-1.opus', 'applause-short-2.opus', 'applause-short-3.opus', 'applause-short-4.opus'],
                    'close' => ['applause-big-1.opus', 'applause-big-2.opus', 'applause-big-3.opus'],
                ],
            ],
            // The talk show's own theme (P20; user 2026-10-13: "so News-Talkshow-mäßig, aber bitte auf keinen Fall zu laut"):
            // the intro before the host's welcome (his voice starts over its last `overlap_seconds`, the tail ducked), the
            // act-break sting before the fact check and the closing round, the outro after the last line; `gain_db` and
            // `sting_gain_db` against the voice (the files are at -16 LUFS): measured in the P20 probe the loudest
            // 400 ms of the intro (its downbeats) stay about 6 dB under the voice, the whole intro about 10 dB.
            'talk_theme' => [
                'enabled' => (bool) env('TV_COMMENTARY_TALK_THEME', true),
                'formats' => ['stammtisch'],
                'intro' => 'theme-talk-intro.opus',
                'outro' => 'theme-talk-outro.opus',
                'sting' => 'theme-talk-sting.opus',
                'gain_db' => (float) env('TV_COMMENTARY_TALK_THEME_GAIN_DB', -11),
                'sting_gain_db' => (float) env('TV_COMMENTARY_TALK_THEME_STING_GAIN_DB', -12),
                'overlap_seconds' => 1.5,
            ],
            // The bed under the bulletin stays (user 2026-10-12: "Das News-Bett war gut"); the stream music is ducked away under it instead.
            'bed_kinds' => array_values(array_filter(explode(',', (string) env('TV_COMMENTARY_BED_KINDS', 'bulletin')))),
            // Six dB under the file's level (user 2026-10-12: "Das News Bett im Hintergrund muss etwas leiser werden").
            'bed_gain_db' => (float) env('TV_COMMENTARY_BED_GAIN_DB', -6),
            'bed_fade_seconds' => 1.5,
            'bed_at_night' => false,
            'pause_seconds' => 0.3,
        ],

        // Radio imaging on the server (plan "TWENTY ONE Radio", P11; user 2026-10-12: "Mehr typische Radio-Zwischenfüller,
        // wie man sie von einem Radiosender kennt"): the Anchor's jingles, uploaded once by the workstation
        // (caster:imaging-upload, POST api/caster/imaging) into `dir`, play between clips by the rules of
        // App\Support\Caster\Imaging, also while the programme blocks are off. No library uploaded: no imaging.
        'imaging' => [
            'enabled' => (bool) env('TV_IMAGING', true),
            'dir' => env('TV_IMAGING_DIR', storage_path('app/stream/imaging')),
            // A station ID (or a news open, a time-of-day ID) at least this often; in quiet hours the only imaging.
            'station_every_minutes' => (float) env('TV_IMAGING_STATION_EVERY_MINUTES', 15),
            // A long station ID (12 to 20 s, voice, build and hit) when the last long one is this long ago.
            'station_long_every_minutes' => (float) env('TV_IMAGING_STATION_LONG_EVERY_MINUTES', 30),
            // The share of transitions between two talk clips that get a sweeper or liner.
            'sweeper_share' => (float) env('TV_IMAGING_SWEEPER_SHARE', 0.35),
            // The same line never again within this long.
            'repeat_minutes' => 45,
            'pause_seconds' => 0.3,
            // The share of talk clips followed by a music gap that get a power intro over the gap's first seconds (by day).
            'power_intro_share' => (float) env('TV_IMAGING_POWER_INTRO_SHARE', 0.25),
            // A short jingle over a track change of the music while no clip is on air (user 2026-10-17: "ganz kurze
            // Jingles bei einem Musik-Titel-Wechsel, aber nicht immer, nur bei ruhigen Zeitphasen"): this share of the
            // changes, at most one within `music_change_every_minutes`, none just before a bulletin or rubric slot; in
            // the quiet hours (the calm stretch of the day) the short station ID only.
            'music_change_share' => (float) env('TV_IMAGING_MUSIC_CHANGE_SHARE', 0.2),
            'music_change_night_share' => (float) env('TV_IMAGING_MUSIC_CHANGE_NIGHT_SHARE', 0.15),
            'music_change_every_minutes' => (int) env('TV_IMAGING_MUSIC_CHANGE_EVERY_MINUTES', 20),
            // Under this many seconds between a jingle's end and the next clip's start, the next clip opens without one.
            'back_to_back_seconds' => 12,
            // The bed a format's opener and promo are produced over (JingleSynth::LOOP_BEDS), on the workstation; and the
            // bed under a rubric's own speech on the server (Idents, `idents.format_beds`).
            'format_beds' => [
                'tribunal' => 'courtroom', 'liga_pranger' => 'courtroom', 'sport_wetter' => 'sport', 'partie' => 'sport',
                'hotline' => 'late-night', 'inflationsbeichte' => 'late-night', 'zentralbank' => 'late-night', 'schwarzbuch' => 'courtroom',
                'jahrestag' => 'weather', 'meetups' => 'weather', 'nostr_fundbuero' => 'tutorial', 'corporate_stacker' => 'news-drive',
                'altcoin_hype' => 'news-drive', 'phrasendrescher' => 'tutorial', 'friedhof' => 'late-night', 'nachrichten_talk' => 'news-pulse',
            ],
            // Upload bounds: items in a manifest, bytes of one file and of the whole library, seconds of one item.
            'max_items' => 600,
            'max_file_bytes' => 1_500_000,
            'max_bytes' => 120_000_000,
            'max_seconds' => 40,
        ],

        // Programme blocks (plan "TWENTY ONE Radio", P6; user 2026-10-12: "Die Composition soll lokal erfolgen … Der Server
        // bekommt am besten vorgerenderte Programme hochgeladen, trotzdem in Häppchen"). With `enabled` the workstation
        // (App\Support\Caster\Blocks\BlockRenderer) claims consecutive scripts until about `seconds.min` to `seconds.max`
        // of airtime, voices them in one batch per speaker (a profile switch costs VoiceStudio time), and renders one
        // block: the Anchor's jingles (`jingles`, storage/app/jingles, caster:jingles), the speech with short agreeing
        // blips of the other speaker under it (`blips`), crossfades, one loudness pass. A live moment or chat reaction is
        // still voiced alone; a script the clock places (`airs_at`: bulletin, rubric) leads a block of its own.
        // The stream (SceneDirector) plays a block whole, adds no stinger, joins the next block after `join_seconds`,
        // and never lets a reaction cut into it (user 2026-10-15: a rendered programme runs undisturbed; the reaction
        // follows the block's end). Below `starve_seconds` of ready audio a shorter block goes out.
        // Names written the way the voices say them (ScriptQueue::sayAs()), whole words, any case: "written" => "spoken".
        'say_as' => [
            'Noerd' => 'Nörd',
            'TV' => 'T Fau',
        ],
        // Live comments on a running organizer tournament (TournamentLive; user 2026-10-14, Blockli Cup #1): every
        // `every_minutes` a short duet from the live data and the players' Nostr bios, behind the "breaking" jingle.
        // `tournaments`: ids to comment on (empty: the running organizer tournament that started last).
        'tournament_live' => [
            'enabled' => (bool) env('TV_COMMENTARY_TOURNAMENT_LIVE', false),
            'tournaments' => array_values(array_filter(array_map('intval', explode(',', (string) env('TV_COMMENTARY_TOURNAMENT_LIVE_IDS', ''))))),
            'every_minutes' => (int) env('TV_COMMENTARY_TOURNAMENT_LIVE_MINUTES', 8),
            // No daily cap any more (user 2026-10-15: opencode texts cost nothing to write; the interval is the only clock).
            // No expiry either (user 2026-10-18): the next comment is written only once the last one aired.
            // The music after a comment before the next voice item, instead of a whole track (music first).
            'gap_seconds' => (int) env('TV_COMMENTARY_TOURNAMENT_LIVE_GAP', 60),
            // Where the players' bios and newest posts are read, besides the knowledge relays and `esports.relays` (merged,
            // the newest kind 0 per pubkey); none in tests.
            'relays' => array_values(array_filter(array_map('trim', explode(',', env('APP_ENV') === 'testing'
                ? (string) env('TV_COMMENTARY_TOURNAMENT_LIVE_RELAYS', '')
                : ((string) env('TV_COMMENTARY_TOURNAMENT_LIVE_RELAYS') ?: 'wss://nos.lol,wss://relay.ditto.pub,wss://relay.primal.net,wss://purplepag.es,wss://nostr.mom'))))),
            'model' => env('TV_COMMENTARY_TOURNAMENT_LIVE_MODEL'),
        ],
        'blocks' => [
            'enabled' => (bool) env('TV_COMMENTARY_BLOCKS', false),
            'seconds' => ['min' => (float) env('TV_COMMENTARY_HAEPPCHEN_SECONDS_MIN', 180), 'max' => (float) env('TV_COMMENTARY_HAEPPCHEN_SECONDS_MAX', 360)],
            'starve_seconds' => (float) env('TV_COMMENTARY_BLOCKS_STARVE_SECONDS', 150),
            // While a block is voiced, the server is asked this often for a reaction, live moment or bulletin, voiced at once.
            'urgent_poll_seconds' => 15,
            'max_scripts' => 40,
            // Topics of a script that joins the block of the bulletin before it (the news talk of plan P5:
            // nachrichten_talk), so the news block runs jingle, bulletin, back-announce, talk in one piece. Off by default:
            // voicing three to six minutes of talk with the bulletin would make the bulletin miss its slot at
            // VoiceStudio's pace (2026-10-12, P6 demo: rtf in the Phasen-Status); its own block follows the news anyway.
            'news_talk_topics' => array_values(array_filter(explode(',', (string) env('TV_COMMENTARY_BLOCKS_NEWS_TALK', '')))),
            // A thirty-minute talk show (P20) is one block: 1800 s and about 11 MB at 48 kbps.
            'max_clip_seconds' => 2100,
            'max_clip_bytes' => 16 * 1024 * 1024,
            'join_seconds' => ['min' => 0.35, 'max' => 0.7],
            // Unused since 2026-10-15 (reactions no longer cut into a block, SceneDirector); kept for env compatibility.
            'reaction_wait_seconds' => (float) env('TV_COMMENTARY_REACTION_WAIT_SECONDS', 90),
            // A block holds the slide only this long; the slides rotate under a block of several minutes.
            'hold_seconds' => 30,
            // A block cut for a reaction, a takeover or a bulletin resumes from its cut within this long, else it is dropped.
            'resume_within_seconds' => 600,
            // Seconds of quiet between two scripts of a block when no jingle stands between them, and around a jingle.
            'pause_seconds' => ['min' => 0.5, 'max' => 0.9],
            // The tail of a jingle the next speech starts over (the jingle fades out under it).
            'crossfade_seconds' => 0.45,
            'jingles' => [
                // The synth under the Anchor's voice in every jingle, in dB below its original level (user 2026-10-13: "viel viel leiser").
                'synth_gain_db' => (float) env('TV_COMMENTARY_JINGLE_SYNTH_GAIN_DB', -12),
                'dir' => env('TV_COMMENTARY_JINGLES_DIR', storage_path('app/jingles')),
                // Relative to the speech (jingles are mastered at -16 LUFS, the speech sits at speech_dbfs).
                'gain_db' => -3.0,
                // Never the same jingle within this many minutes, never twice in a row.
                'repeat_minutes' => 30,
                // A sweeper between two talk scripts on about this share of the transitions without a jingle of their own.
                'sweeper_share' => 0.5,
            ],
            'blips' => [
                // Share of long-enough duet turns that get one agreeing sound of the other speaker, at least
                // `min_gap_seconds` apart, `gain_db` under the speech, `at` (share of the turn) into a turn of `min_turn_seconds`.
                'share' => (float) env('TV_COMMENTARY_BLIPS_SHARE', 0.35),
                'min_gap_seconds' => 25,
                'gain_db' => -12.0,
                'min_turn_seconds' => 3.5,
                'at' => ['min' => 0.55, 'max' => 0.8],
                // The talk show (P16): under a turn of the other side (the guest under Markus or Sirius, they under her) only
                // a blip saying one of these: a laugh or a doubting sound, never "Stimmt." to the opponent.
                'opponents' => ['genossin'],
                'across' => ['Hm.', '[laughter]', 'Na ja.', 'Ach komm.', 'Pff.'],
            ],
            // The replay archive (user 2026-10-12: a "Best of des Tages" in the quiet hours): the workstation keeps every
            // block it uploaded (`dir`, at most `keep_hours` and `max_bytes`, oldest out first) with its metadata; in the
            // quiet hours, while the server has less than `below_seconds` ready and nothing new to voice, it uploads an
            // evergreen one again (talk, history, rubrics without a date; never a bulletin, teaser, chat reply, wish or a
            // line that names the time), behind the Anchor's "Ihr hört die Wiederholung" jingle (category `replay`; no such
            // jingle, no replay), each block at most once within `repeat_hours`. Without new rendering: no VoiceStudio call.
            // The server takes replays only while this is on and the quiet hours run; they keep the quiet hours' rhythm
            // (one every quiet_hours.every_minutes) unless `continuous`.
            'replay' => [
                'enabled' => (bool) env('TV_COMMENTARY_REPLAY', false),
                'dir' => env('TV_COMMENTARY_REPLAY_DIR', storage_path('app/replay')),
                'keep_hours' => (float) env('TV_COMMENTARY_REPLAY_KEEP_HOURS', 48),
                'max_bytes' => (int) env('TV_COMMENTARY_REPLAY_MAX_BYTES', 3 * 1024 * 1024 * 1024),
                'below_seconds' => 120,
                'repeat_hours' => (float) env('TV_COMMENTARY_REPLAY_REPEAT_HOURS', 24),
                'continuous' => (bool) env('TV_COMMENTARY_REPLAY_CONTINUOUS', false),
                // Never before this many hours after a block first aired, and each block at most once within
                // `repeat_hours` (user 2026-10-12: "auf keinen Fall zu viele Wiederholungen").
                'min_age_hours' => (float) env('TV_COMMENTARY_REPLAY_MIN_AGE_HOURS', 6),
                // The day reserve (user 2026-10-12): by day a replay goes up only when the spool would otherwise run
                // dry (below `below_seconds`, no day stock, nothing fresh to voice), and the server takes it only within
                // `hourly_share` of the Berlin hour (3600 s) and `daily_share` of the day's voice airtime; beyond that,
                // music. A day replay expires `day_expire_minutes` after its upload.
                'day_reserve' => (bool) env('TV_COMMENTARY_REPLAY_DAY_RESERVE', true),
                'hourly_share' => (float) env('TV_COMMENTARY_REPLAY_HOURLY_SHARE', 0.10),
                'daily_share' => (float) env('TV_COMMENTARY_REPLAY_DAILY_SHARE', 0.20),
                'day_expire_minutes' => 20,
            ],
            // The day stock (user 2026-10-12: "Wir brauchen für den Tag über im Buffer vorgerendertes Programm durchweg"):
            // VoiceStudio renders about as fast as it speaks (RTF 1.0 to 1.5 measured), so by day the GPU only keeps pace.
            // In `window` (Berlin; the quiet hours by default, when the air needs one clip per ten minutes) the workstation
            // claims evergreen talk scripts written for the stock (`?only=stock`, StockScripts: no time of day, no date)
            // and renders them into blocks of `seconds` kept in `dir` (at most `target_hours` of audio and `max_bytes`).
            // By day it uploads one whenever the server holds less than `upload_below_seconds` (during a fresh render too,
            // below `low_seconds`); fresh items keep the GPU and play first, a stock block airs once and then moves to the
            // replay archive. `day_share`: a stock render by day while the spool is full (bootstrap before the first
            // night; it grows the stock only by what the GPU has left). `suppress_scenes`: no slide scene scripts by day
            // while at least `suppress_min_seconds` of stock is reported (or fresh talk waits), except `keep_scene_topics`.
            'stock' => [
                'enabled' => (bool) env('TV_COMMENTARY_STOCK', false),
                'dir' => env('TV_COMMENTARY_STOCK_DIR', storage_path('app/day-stock')),
                'target_hours' => (float) env('TV_COMMENTARY_STOCK_TARGET_HOURS', 6),
                'max_bytes' => (int) env('TV_COMMENTARY_STOCK_MAX_BYTES', 2 * 1024 * 1024 * 1024),
                'window' => ['from' => env('TV_COMMENTARY_STOCK_FROM', '01:00'), 'to' => env('TV_COMMENTARY_STOCK_TO', '09:00')],
                'seconds' => ['min' => 180, 'max' => 360],
                'upload_below_seconds' => (float) env('TV_COMMENTARY_STOCK_UPLOAD_BELOW', 900),
                'low_seconds' => (float) env('TV_COMMENTARY_STOCK_LOW', 300),
                'day_share' => (bool) env('TV_COMMENTARY_STOCK_DAY_SHARE', true),
                'suppress_scenes' => (bool) env('TV_COMMENTARY_STOCK_SUPPRESS_SCENES', true),
                'suppress_min_seconds' => 600,
                'keep_scene_topics' => ['live_games', 'live_tournament'],
                // The writer: one model call per script, `turns` turns of up to `sentences_per_turn` sentences.
                'daily_cap' => (int) env('TV_COMMENTARY_STOCK_DAILY_CAP', 220),
                'max_output_tokens' => (int) env('TV_COMMENTARY_STOCK_MAX_OUTPUT_TOKENS', 24000),
                // A whole programme of up to `max_output_tokens` takes minutes to write: far longer than
                // `esports.voice.timeout_seconds` allows a post (the shows and the talk show have their own too).
                'timeout_seconds' => (int) env('TV_COMMENTARY_STOCK_TIMEOUT', 600),
                // The stock's writer (P21): Sonnet like prod's ESPORTS_VOICE_MODEL, whatever the local default is.
                'model' => env('TV_COMMENTARY_STOCK_MODEL', 'claude-sonnet-5-5'),
                // At most this many whole-programme writes an hour (live 2026-10-16): one such write holds the
                // writer and the gateway for minutes at a time, and the day's fresh programme (the shows, the
                // bulletins) must never queue behind them. 0 is not a stop: at least one write an hour goes out.
                'writes_per_hour' => (int) env('TV_COMMENTARY_STOCK_WRITES_PER_HOUR', 2),
                // One talk is one block (P21: no second talk glued on to reach a length - user 2026-10-16:
                // "nicht einfach zusammenhangslos zusammenkleben, der Textgenerator muss kontextuell längere
                // Programmtexte schreiben"), so a talk carries the whole programme's length: one continuous
                // conversation over many acts (programme_minutes, default 20).
                'turns' => ['min' => 40, 'max' => 80],
                'programme_minutes' => (float) env('TV_COMMENTARY_STOCK_PROGRAMME_MINUTES', 20),
                // The coherence gate before a draft is queued (StockCoherenceJudge, P21): premise, through-line, payoff,
                // fit and liveliness 1 to 5; below `min_score` on any, written once more with the judge's hint, then dropped.
                'judge' => [
                    'enabled' => (bool) env('TV_COMMENTARY_STOCK_JUDGE', true),
                    // Sonnet: Haiku flagged plain sentences from the material as nonsense in 10 of 10 real drafts (P21, 2026-10-13).
                    'model' => env('TV_COMMENTARY_STOCK_JUDGE_MODEL', 'claude-sonnet-5-5'),
                    // User 2026-10-13 07:20: "PASST ERSTMAL, WIR VERBESSERN ROLLEND" — airable at 3 on every axis; invented facts stay a hard reject.
                    'min_score' => (int) env('TV_COMMENTARY_STOCK_JUDGE_MIN', 3),
                    // User decision 2026-10-13: sense 3 is airable when nothing is invented (`invented` empty is the hard gate).
                    'sense_min_score' => 3,
                    'max_tokens' => 1500,
                ],
                'sentences_per_turn' => 4,
                // An uploaded stock block keeps `spool_hours` in the server's spool; an unvoiced stock script never expires.
                'spool_hours' => 3,
                // Who talks (VoiceStudio profiles under voicestudio.profiles); a list, so a talk show of three or four
                // needs only their profiles (and their names in DuetScripts::parse()).
                'speakers' => ['markus', 'sirius'],
                // The formats in rotation (user 2026-10-12: "lustige Programme ohne aktuellen Bezug, sondern sinnvolles
                // Besprechen; Programm für Tutorials für Bitcoin-Dinge"), each with its Anchor jingle categories (the
                // talk jingle when none of them exists).
                'formats' => ['talk', 'tutorial', 'geschichte', 'podcast', 'liga', 'schwarzbuch', 'inflationsbeichte', 'stammtisch'],
                'jingles' => [
                    'stammtisch' => ['rubric-stammtisch'],
                    'schwarzbuch' => ['rubric-schwarzbuch'],
                    'inflationsbeichte' => ['rubric-inflationsbeichte'],
                    'talk' => ['stock-talk'],
                    'tutorial' => ['stock-tutorial'],
                    'geschichte' => ['stock-geschichte', 'rubric-geschichte'],
                    'podcast' => ['rubric-podcast'],
                    'liga' => ['rubric-liga'],
                ],
                'tutorials' => [
                    'Die Seed-Phrase sicher aufbewahren: warum Papier oder Metall statt Foto und Cloud',
                    'Eine eigene Bitcoin-Node betreiben: was sie prüft und warum das Vertrauen ersetzt',
                    'Einen Lightning-Kanal öffnen: was Kapazität und Liquidität bedeuten',
                    'Warum eine Hardware-Wallet die Schlüssel vom Internet fernhält',
                    'Den eigenen Nostr-Schlüssel verstehen und sichern: privater und öffentlicher Schlüssel',
                    'Multisig erklärt: mehrere Schlüssel für eine Ausgabe',
                    'Coinjoin-Grundlagen: was Privatsphäre auf einer öffentlichen Blockchain heißt',
                    'Bitcoin und Steuern: die Grundbegriffe, ohne Beratung',
                    'Was der Mempool ist und warum ein neuer Block im Schnitt alle zehn Minuten kommt',
                    'Selbstverwahrung statt Börse: was "not your keys, not your coins" bedeutet',
                    // P18: 60 hours of stock need more subjects than ten per format (the library spaces a subject six hours).
                    'Was eine Bitcoin-Adresse ist und warum man jede nur einmal benutzen sollte',
                    'Transaktionsgebühren verstehen: warum sie schwanken und wovon sie abhängen',
                    'Was eine Bestätigung ist und warum man bei großen Beträgen auf mehrere wartet',
                    'Ein Backup testen: warum man die Wiederherstellung übt, bevor man sie braucht',
                    'Was eine Passphrase zur Seed-Phrase ist und welches Risiko sie mitbringt',
                    'Lightning-Zahlungen empfangen: was eine Rechnung ist und warum sie abläuft',
                    'Was ein Halving ist und warum es ungefähr alle vier Jahre kommt',
                    'Mining in einfachen Worten: was ein Block ist und wer ihn finden darf',
                    'Phishing und Betrug erkennen: warum niemand seriöses nach der Seed-Phrase fragt',
                    'Was eine Wallet eigentlich speichert: Schlüssel statt Münzen',
                    'Nostr-Clients wechseln: warum der eigene Schlüssel überallhin mitkommt',
                    'Was ein Zap auf Nostr ist und wie Lightning dahintersteckt',
                    'Satoshis als Einheit: warum man in Sats statt in ganzen Bitcoin rechnen kann',
                    'Erbe und Notfallplan: wie Angehörige an die Bitcoin kommen, ohne Beratung',
                    'Öffentliches WLAN und Wallets: worauf man unterwegs achten sollte',
                ],
                'questions' => [
                    'Was ist an Fiatgeld eigentlich das Problem?',
                    'Warum ist Bitcoin kein Unternehmen und hat keinen Chef?',
                    'Warum zählen Meetups vor Ort mehr als jede Online-Gruppe?',
                    'Ist HODLn eine Tugend oder einfach nur Faulheit?',
                    'Warum freie Rede ein offenes Protokoll wie Nostr braucht',
                    'Was würde ein Bauer aus dem Jahr fünfzehnhundertfünfundzwanzig zu unseren Steuern sagen?',
                    'Braucht man in einer Bitcoin-Welt noch Banken?',
                    'Warum ist begrenzte Menge so wichtig, einundzwanzig Millionen und keine mehr?',
                    'Was macht eine gute Community aus, online wie offline?',
                    'Warum Spielen mit Bitcoin-Bezug mehr Spaß macht als Zocken um Fiatgeld',
                    'Warum verlieren Ersparnisse auf dem Sparbuch an Kaufkraft?',
                    'Ist Bargeld die letzte Freiheit im Zahlungsverkehr?',
                    'Warum wollen Zentralbanken digitales Geld, und wem nützt es?',
                    'Was macht eine Währung eigentlich zu gutem Geld?',
                    'Warum ist Open Source für Geld so wichtig?',
                    'Kann man Bitcoin verbieten, und was würde dann passieren?',
                    'Warum dauert eine Banküberweisung am Wochenende immer noch Tage?',
                    'Ist Sparen in einer Welt mit Inflation noch vernünftig?',
                    'Warum sind dezentrale Netze schwerer abzuschalten als zentrale?',
                    'Was haben Gold und Bitcoin gemeinsam, und was nicht?',
                    'Warum ist Privatsphäre kein Zeichen dafür, dass man etwas zu verbergen hat?',
                    'Lohnt es sich, in einer Liga um Sats statt um Punkte zu spielen?',
                    'Warum sind kleine Leute bei Inflation immer die Verlierer?',
                    'Was können Gamer von Bitcoinern lernen, und umgekehrt?',
                    'Warum misstrauen so viele dem Staat, wenn es ums Geld geht?',
                ],
                // The server's stock library (plan "TWENTY ONE Radio", P18; user 2026-10-12: "mehrere Tage fest-statische
                // Programme vorrendern, weil dann muss mein Laptop nicht die ganze Zeit an sein"): every stock block the
                // workstation renders goes up at once into `dir` on the server (POST api/caster/stock/library/{sha1}),
                // bounded to `max_bytes` (the oldest-aired block leaves first, a never-aired one never), and the
                // workstation keeps rendering stock whenever the GPU has nothing fresh until the library holds
                // `target_hours` of unique audio (user 2026-10-13: 60 h; at 48 kbps Opus about 1.3 GB, so 4 GB leaves
                // room for the aired blocks that wait for eviction). A block counts toward the target until it aired
                // `retire_after_airings` times. The server feeds its own spool from the library: by day an unaired block
                // whenever less than `feed_below_seconds` is ready (StockFeeder), and everything in the autopilot.
                'library' => [
                    'enabled' => (bool) env('TV_COMMENTARY_STOCK_LIBRARY', true),
                    'dir' => env('TV_COMMENTARY_STOCK_LIBRARY_DIR', storage_path('app/stream/stock')),
                    'max_bytes' => (int) env('TV_COMMENTARY_STOCK_LIBRARY_MAX_BYTES', 4 * 1024 * 1024 * 1024),
                    'target_hours' => (float) env('TV_COMMENTARY_STOCK_LIBRARY_TARGET_HOURS', 60),
                    'retire_after_airings' => (int) env('TV_COMMENTARY_STOCK_LIBRARY_RETIRE_AFTER', 4),
                    'feed_below_seconds' => (float) env('TV_COMMENTARY_STOCK_LIBRARY_FEED_BELOW', 900),
                    // A block airs again at the earliest this long after it last aired; no two blocks on the same
                    // subject (a question, a tutorial, a canon story, a roast case) within `subject_spacing_hours`.
                    'repeat_hours' => (float) env('TV_COMMENTARY_STOCK_LIBRARY_REPEAT_HOURS', 24),
                    'subject_spacing_hours' => (float) env('TV_COMMENTARY_STOCK_LIBRARY_SUBJECT_HOURS', 6),
                ],
            ],
            // The autopilot (P18): while the workstation is gone (no claim for `fetch.online_seconds`) or reports
            // VoiceStudio down, the server keeps a real programme from what it has: the library's blocks (each at
            // most once per `library.repeat_hours`, the least recently aired first, a repeat behind the Anchor's
            // replay jingle), a podcast episode every `podcast_every_hours`, a news-free station ID at :00 (imaging
            // `autopilot-hour`, else a long station ID), music between blocks. No script is written meanwhile (nobody
            // voices it), chat messages wait for the workstation's return. It yields at the next item boundary.
            'autopilot' => [
                'enabled' => (bool) env('TV_COMMENTARY_AUTOPILOT', true),
                // Music between two items (the next block goes into the spool once the air was free this long; the feeder
                // looks every `poll_seconds`), in the quiet hours the night's slower rhythm.
                // A whole song between two items (user 2026-10-13, music first): the tracks run about three to five minutes.
                'music_gap_seconds' => ['min' => (int) env('TV_COMMENTARY_AUTOPILOT_GAP_MIN', 240), 'max' => (int) env('TV_COMMENTARY_AUTOPILOT_GAP_MAX', 330)],
                'night_gap_seconds' => ['min' => 240, 'max' => 420],
                'poll_seconds' => 15,
                'podcast_every_hours' => (float) env('TV_COMMENTARY_AUTOPILOT_PODCAST_HOURS', 4),
                'podcast_repeat_hours' => 24,
                'spool_hours' => 3,
            ],
            // The Anchor (VoiceStudio personality us_news_anchor, a designed voice, no person's clone), made into a profile
            // by caster:jingles; TV_ANCHOR_PROFILE pins an existing one.
            'anchor' => [
                'profile_id' => env('TV_ANCHOR_PROFILE'),
                'name' => 'The Anchor (TWENTY ONE)',
                'personality' => 'us_news_anchor',
                'instruct' => 'male, middle-aged, moderate pitch, american accent',
                'seed' => 2121,
                'speed' => 1.0,
                // Slow and dramatic (user 2026-10-12): VoiceStudio's `duration` per line from these (speed had no
                // audible effect); caster:jingles renders a line again when its spoken text or length changed.
                'seconds_per_word' => (float) env('TV_ANCHOR_SECONDS_PER_WORD', 0.55),
                'sentence_pause_seconds' => 0.6,
            ],
        ],

        'scenes' => [
            'min_scene_seconds' => 20,
            'silent_min_scene_seconds' => 15,
            'tail_seconds' => 1.5,
            'lead_in_seconds' => 0.5,
            'max_hold_seconds' => 100,
            // A clip of these kinds holds its slot until it has been spoken, up to this long (spool.long_clip_seconds
            // plus the lead-in and tail): the scene stays on air through a long scene or story.
            'long_max_hold_seconds' => ['scene' => 135, 'news' => 160, 'history' => 290, 'bulletin' => 430, 'rubric' => 140],
        ],

        'news' => [
            'enabled' => (bool) env('TV_COMMENTARY_NEWS', true),
            'every_minutes' => 10,
            // A headline older than this is not spoken any more; rows older than `keep_hours` are deleted.
            'fresh_hours' => 12,
            'keep_hours' => 48,
            'max_rows' => 600,
            'per_feed' => 20,
            'max_title_chars' => 160,
            // The feed item's teaser text (description) kept with the headline, for the bulletin's context (P5).
            'max_summary_chars' => 500,
            'max_age_minutes' => 30,
            'sentences' => ['min' => (int) env('TV_COMMENTARY_NEWS_SENTENCES_MIN', 1), 'max' => (int) env('TV_COMMENTARY_NEWS_SENTENCES_MAX', 3)],
            'daily_cap' => (int) env('TV_COMMENTARY_NEWS_DAILY_CAP', 150),
            'timeout_seconds' => 8,
            'connect_timeout_seconds' => 4,
            'max_feed_bytes' => 2 * 1024 * 1024,
            // section bitcoin or germany; source as spoken ("laut tagesschau"). German feeds only: the voice is German,
            // and a headline that reads as another language is skipped all the same (NewsFilter::FOREIGN).
            'feeds' => [
                'btc-echo' => ['url' => 'https://www.btc-echo.de/feed/', 'source' => 'BTC-Echo', 'section' => 'bitcoin', 'language' => 'de'],
                // About one article a day (30 items ≈ a month, prod 2026-10-12): eligible for a week unless a bulletin read it (P15).
                'blocktrainer' => ['url' => 'https://www.blocktrainer.de/feed.xml', 'source' => 'Blocktrainer', 'section' => 'bitcoin', 'language' => 'de', 'fresh_hours' => 168],
                'tagesschau-inland' => ['url' => 'https://www.tagesschau.de/inland/index~rss2.xml', 'source' => 'tagesschau', 'section' => 'germany', 'language' => 'de'],
                'tagesschau-wirtschaft' => ['url' => 'https://www.tagesschau.de/wirtschaft/index~rss2.xml', 'source' => 'tagesschau', 'section' => 'germany', 'language' => 'de'],
                'spiegel-deutschland' => ['url' => 'https://www.spiegel.de/politik/deutschland/index.rss', 'source' => 'Spiegel', 'section' => 'germany', 'language' => 'de'],
                'spiegel-wirtschaft' => ['url' => 'https://www.spiegel.de/wirtschaft/index.rss', 'source' => 'Spiegel', 'section' => 'germany', 'language' => 'de'],
                'welt-deutschland' => ['url' => 'https://www.welt.de/feeds/section/politik/deutschland.rss', 'source' => 'Welt', 'section' => 'germany', 'language' => 'de'],
                'welt-wirtschaft' => ['url' => 'https://www.welt.de/feeds/section/wirtschaft.rss', 'source' => 'Welt', 'section' => 'germany', 'language' => 'de'],
                'faz-inland' => ['url' => 'https://www.faz.net/rss/aktuell/politik/inland/', 'source' => 'FAZ', 'section' => 'germany', 'language' => 'de'],
                'faz-wirtschaft' => ['url' => 'https://www.faz.net/rss/aktuell/wirtschaft/', 'source' => 'FAZ', 'section' => 'germany', 'language' => 'de'],
                'handelsblatt-politik' => ['url' => 'https://www.handelsblatt.com/contentexport/feed/politik', 'source' => 'Handelsblatt', 'section' => 'germany', 'language' => 'de'],
                'handelsblatt-finanzen' => ['url' => 'https://www.handelsblatt.com/contentexport/feed/finanzen', 'source' => 'Handelsblatt', 'section' => 'germany', 'language' => 'de'],
                'ntv-politik' => ['url' => 'https://www.n-tv.de/politik/rss', 'source' => 'n-tv', 'section' => 'germany', 'language' => 'de'],
                'ntv-wirtschaft' => ['url' => 'https://www.n-tv.de/wirtschaft/rss', 'source' => 'n-tv', 'section' => 'germany', 'language' => 'de'],
                // Foreign news for the bulletins (plan "TWENTY ONE Radio", P5; RSS with descriptions, reachable from the workstation 2026-10-12).
                'tagesschau-ausland' => ['url' => 'https://www.tagesschau.de/ausland/index~rss2.xml', 'source' => 'tagesschau', 'section' => 'world', 'language' => 'de'],
                'spiegel-ausland' => ['url' => 'https://www.spiegel.de/ausland/index.rss', 'source' => 'Spiegel', 'section' => 'world', 'language' => 'de'],
                // The association's own news (plan "TWENTY ONE Radio", P2; RSS, 50 items, reachable from prod, checked 2026-10-12).
                'einundzwanzig-news' => ['url' => 'https://media.einundzwanzig.space/s/einundzwanzig-news/feed.xml', 'source' => 'EINUNDZWANZIG News', 'section' => 'bitcoin', 'language' => 'de', 'fresh_hours' => 168],
                // Tech news (P15, user 2026-10-12: "heise Technik-News mehr anreichern", "mehr heise Technik"); Atom/RSS, HTTP 200
                // from prod 2026-10-13: heise top 60 entries, heise security 20, golem 40, netzpolitik 25, all with items of the day.
                'heise-top' => ['url' => 'https://www.heise.de/rss/heise-top-atom.xml', 'source' => 'heise online', 'section' => 'tech', 'language' => 'de'],
                'heise-security' => ['url' => 'https://www.heise.de/security/rss/news-atom.xml', 'source' => 'heise Security', 'section' => 'tech', 'language' => 'de'],
                'golem' => ['url' => 'https://www.golem.de/rss.php?feed=ATOM1.0', 'source' => 'Golem', 'section' => 'tech', 'language' => 'de'],
                'netzpolitik' => ['url' => 'https://netzpolitik.org/feed/', 'source' => 'netzpolitik.org', 'section' => 'tech', 'language' => 'de'],
            ],
            // The balance of the bulletins, the Presseschau and the news talk (App\Support\Caster\NewsMix, P15; user
            // 2026-10-12 23:15: "Wir machen zu viel Politik-News", then "etwas zu viel Bitcoin, mehr heise Technik"): about
            // `shares` of each bulletin Bitcoin and tech, the rest money policy before politics, politics at most
            // `politics_max` per slot type; a short tier is filled by the other of Bitcoin and tech first, politics last.
            // `fresh_hours` per section (a feed's own `fresh_hours` wins, else `programme.bulletin.fresh_hours`).
            'quota' => [
                'shares' => [
                    'bitcoin' => (float) env('TV_COMMENTARY_NEWS_SHARE_BITCOIN', 0.4),
                    'tech' => (float) env('TV_COMMENTARY_NEWS_SHARE_TECH', 0.4),
                ],
                'politics_max' => [
                    'news' => (int) env('TV_COMMENTARY_NEWS_POLITICS_MAX', 2),
                    'headlines' => (int) env('TV_COMMENTARY_HEADLINES_POLITICS_MAX', 1),
                ],
                'fresh_hours' => ['bitcoin' => 72, 'tech' => 24],
                // The first outlets within a tier (the association's own news, then Blocktrainer; heise before the other tech feeds).
                'first' => [
                    'bitcoin' => ['EINUNDZWANZIG News', 'Blocktrainer'],
                    'tech' => ['heise online', 'heise Security'],
                ],
                'money_pattern' => NewsMix::MONEY_PATTERN,
                'altcoin_pattern' => NewsMix::ALTCOIN_PATTERN,
                'altcoin_major_pattern' => NewsMix::ALTCOIN_MAJOR_PATTERN,
            ],
        ],

        // The broadcast clock (plan "TWENTY ONE Radio", P1; App\Support\Caster\Programme\BroadcastClock): an hour of
        // fixed slots in `timezone` wall-clock time (DST-safe: a slot is a minute of the local hour), each from its
        // `minute` to the next slot's. `type` news and headlines are the bulletins (NewsBulletin, written
        // `bulletin.write_minutes_ahead` before the slot so the clip is in the spool `bulletin.ready_seconds` ahead);
        // `stinger` asks the stream for a stinger before the slot's clip (P4 plays it, SceneDirector::takeStinger()),
        // the clip starting `stinger_seconds` later; a slot without `quiet` turns into talk in the quiet hours (at night
        // only the full-hour bulletin). `label` is how a teaser names the slot (Rundown, P5).
        'programme' => [
            'enabled' => (bool) env('TV_COMMENTARY_PROGRAMME', true),
            'timezone' => env('TV_COMMENTARY_PROGRAMME_TZ', 'Europe/Berlin'),
            'slots' => [
                ['minute' => 0, 'type' => 'news', 'label' => 'die Nachrichten', 'stinger' => true, 'quiet' => true],
                ['minute' => 3, 'type' => 'talk', 'label' => 'Talk', 'quiet' => true],
                ['minute' => 15, 'type' => 'rubric', 'label' => 'die erste Rubrik der Stunde'],
                ['minute' => 20, 'type' => 'talk', 'label' => 'Talk', 'quiet' => true],
                ['minute' => 30, 'type' => 'headlines', 'label' => 'die Kurznachrichten', 'stinger' => true],
                ['minute' => 33, 'type' => 'talk', 'label' => 'Talk', 'quiet' => true],
                ['minute' => 45, 'type' => 'rubric', 'label' => 'die zweite Rubrik der Stunde'],
                ['minute' => 50, 'type' => 'talk', 'label' => 'Talk', 'quiet' => true],
            ],
            'stinger_seconds' => (float) env('TV_COMMENTARY_STINGER_SECONDS', 0),
            // The bulletin (App\Support\Caster\Programme\NewsBulletin): `items` per type, from at least `min_sources`
            // outlets where the feeds have them, never a headline that was in a bulletin before (`caster_news.bulletin_at`)
            // and at most one league item (world memory) a bulletin, none twice a day; fewer than `min_items` fresh
            // items and the slot passes without one (never filler). The queue writes it from `write_minutes_ahead` down
            // to `last_write_minutes` before the slot (a failed call is tried again the next minute), at `priority`
            // (above chat replies, below live moments); it airs from its slot on, at most `late_seconds` late (the
            // time line names the slot), else it expires. Lead (the plan's estimate, unmeasured): a bulletin of about 100 s
            // spoken is voiced in one to two minutes, so writing seven minutes ahead leaves it spooled about four minutes early.
            'bulletin' => [
                // User 2026-10-12 (live): "Die News war zu kurz": six to eight stories at :00, four or five at :30, each two
                // or three sentences with the feed item's description as context.
                'items' => [
                    'news' => ['min' => (int) env('TV_COMMENTARY_BULLETIN_NEWS_MIN', 6), 'max' => (int) env('TV_COMMENTARY_BULLETIN_NEWS_MAX', 8)],
                    'headlines' => ['min' => (int) env('TV_COMMENTARY_BULLETIN_HEADLINES_MIN', 4), 'max' => (int) env('TV_COMMENTARY_BULLETIN_HEADLINES_MAX', 5)],
                ],
                'min_items' => 2,
                'min_sources' => 3,
                'fresh_hours' => (int) env('TV_COMMENTARY_BULLETIN_FRESH_HOURS', 12),
                'league' => true,
                'league_hours' => 12,
                // Ten minutes ahead (2026-10-13): two bulletins expired unvoiced with seven minutes of lead.
                // Fifteen (live 2026-10-16): a busy writer - a talk show's acts - pushed the 17:00 Berlin bulletin
                // to 2:18 before its slot, and its 162 s of voicing never made ready_seconds before the slot.
                'write_minutes_ahead' => (int) env('TV_COMMENTARY_BULLETIN_WRITE_MINUTES', 15),
                'last_write_minutes' => 2,
                // Not voiced this long before its slot, the bulletin is skipped with its follow-ups (ScriptQueue::expire()).
                'ready_seconds' => 60,
                'late_seconds' => 120,
                'priority' => 75,
                'item_sentences' => ['min' => 2, 'max' => 3],
                'commentary_sentences' => ['min' => 2, 'max' => 3],
                // Chance that Sirius adds one line after Markus' commentary (never in the quiet hours).
                'sirius_chance' => (float) env('TV_COMMENTARY_BULLETIN_SIRIUS', 0.5),
                // Sampled cooler than the talk (scripts.temperature): the news are read, not riffed.
                'temperature' => (float) env('TV_COMMENTARY_BULLETIN_TEMPERATURE', 0.6),
                'daily_cap' => (int) env('TV_COMMENTARY_BULLETIN_DAILY_CAP', 120),
            ],
            // The rubrics (plan "TWENTY ONE Radio", P5; App\Support\Caster\Programme\Rubrics): at every `rubric` slot of the
            // clock one rubric of `rotation`, in turn over the day (the hour and the slot's place in it pick the start), one
            // without material skipped; the choice for a slot is kept once made, so a teaser and the rubric agree. Written
            // like the bulletin from `write_minutes_ahead` down to `last_write_minutes` before the slot (AI only, two tries,
            // `daily_cap`), queued at `priority` in parts and played from its slot on, at most `late_seconds` late. It opens
            // with the ident (idents.kinds.rubric) and a line that names it, then `seconds` of content (a draft below `min`
            // is tried again, one above `max` loses its last sentences); `sirius_chance` of them as a duet.
            'rubrics' => [
                'enabled' => (bool) env('TV_COMMENTARY_RUBRICS', true),
                'rotation' => array_values(array_filter(array_map('trim', explode(',', (string) env('TV_COMMENTARY_RUBRIC_ROTATION', 'liga,zentralbank,presseschau,schwarzbuch,podcast,altcoin_hype,geschichte,liga_pranger,chat,phrasendrescher,liga,nostr_fundbuero,presseschau,corporate_stacker,podcast,inflationsbeichte'))))),
                'write_minutes_ahead' => 8,
                'last_write_minutes' => 2,
                'late_seconds' => 180,
                'priority' => 70,
                'seconds' => ['min' => 60, 'max' => 150],
                'sentences' => ['min' => 10, 'max' => 16],
                'sirius_chance' => (float) env('TV_COMMENTARY_RUBRIC_SIRIUS', 0.5),
                'daily_cap' => (int) env('TV_COMMENTARY_RUBRIC_DAILY_CAP', 100),
                // Liga-Rubrik: league events (WorldMemory) of the last `league_hours`; Chat-Stunde: questions of the last `chat_hours`.
                'league_hours' => 24,
                'chat_hours' => 3,
                // The same material of a rubric does not air again within this many hours (the podcast digest stays a week).
                'repeat_hours' => ['default' => 2, 'podcast' => 12],
            ],
            // The roast formats (P10; App\Support\Caster\Programme\Roasts, RoastMaterial, RoastScripts; user 2026-10-12: "Alles aus
            // echten Daten, alles roasten"): they take rubric slots from `rubrics.rotation` like any rubric, each only within its
            // `hours` (Berlin; the fresh ones 09:00 to midnight, the evergreen Schwarzbuch and Inflationsbeichte any time) and
            // never right after another roast format. Every source is fetched server-side (cached `cache_minutes`, a failure
            // `retry_seconds`); a source that is down skips its format. `seconds` of content by `speakers` (a list: another voice
            // needs only its VoiceStudio profile and its name in DuetScripts::parse()). Reachable from prod, checked 2026-10-12.
            'roasts' => [
                'enabled' => (bool) env('TV_COMMENTARY_ROASTS', true),
                'speakers' => ['markus', 'sirius'],
                'seconds' => ['min' => 120, 'max' => 240],
                'sentences' => ['min' => 16, 'max' => 30],
                'max_output_tokens' => 4_000,
                'daily_cap' => (int) env('TV_COMMENTARY_ROAST_DAILY_CAP', 60),
                'cache_minutes' => 30,
                'retry_seconds' => 120,
                'timeout_seconds' => 8,
                'formats' => [
                    'zentralbank' => ['hours' => ['from' => '09:00', 'to' => '00:00']],
                    'schwarzbuch' => [],
                    'altcoin_hype' => ['hours' => ['from' => '09:00', 'to' => '00:00']],
                    'liga_pranger' => ['hours' => ['from' => '09:00', 'to' => '00:00']],
                    'phrasendrescher' => ['hours' => ['from' => '09:00', 'to' => '00:00']],
                    'nostr_fundbuero' => ['hours' => ['from' => '09:00', 'to' => '00:00']],
                    'corporate_stacker' => ['hours' => ['from' => '09:00', 'to' => '00:00']],
                    'inflationsbeichte' => [],
                ],
                'sources' => [
                    // Official RSS of both banks; the decision itself first (`prefer`), its release page read for the first sentences.
                    'central_banks' => [
                        'max_days' => 45,
                        'sentences' => 4,
                        'feeds' => [
                            ['name' => 'Die Europäische Zentralbank (EZB)', 'short' => 'EZB', 'url' => 'https://www.ecb.europa.eu/rss/press.html', 'only' => '/press/pr/', 'prefer' => '/monetary policy decisions/i', 'start' => '<main'],
                            ['name' => 'Die US-Notenbank Federal Reserve (Fed)', 'short' => 'Fed', 'url' => 'https://www.federalreserve.gov/feeds/press_monetary.xml', 'prefer' => '/FOMC statement/i', 'start' => 'id="article"'],
                        ],
                    ],
                    'altcoins' => [
                        'max_hours' => 48,
                        'items' => 4,
                        'feeds' => [
                            ['url' => 'https://cointelegraph.com/rss', 'source' => 'Cointelegraph'],
                            ['url' => 'https://decrypt.co/feed', 'source' => 'Decrypt'],
                            ['url' => 'https://www.coindesk.com/arc/outboundfeeds/rss/', 'source' => 'CoinDesk'],
                        ],
                    ],
                    'league' => ['days' => 7, 'min_games' => 3],
                    // Quoted headlines of the week from the stored news (NewsFeeds, CasterNews).
                    'phrases' => ['outlets' => ['tagesschau', 'Spiegel'], 'days' => 7, 'items' => 3, 'min_items' => 2],
                    'nostr' => [
                        'relays' => array_values(array_filter(array_map('trim', explode(',', (string) env('TV_COMMENTARY_ROAST_NOSTR_RELAYS', 'wss://nostr.einundzwanzig.space'))))),
                        // Where the authors' kind 0 names are read (nos.lol reachable from prod 2026-10-12, damus answers 403 there).
                        'profile_relays' => ['wss://nos.lol'],
                        'hours' => 72,
                        'items' => 3,
                        'min_words' => 8,
                        'max_words' => 70,
                        'seconds' => 12,
                    ],
                    'treasuries' => ['url' => 'https://api.coingecko.com/api/v3/companies/public_treasury/bitcoin', 'top' => 5],
                    'inflation' => ['url' => 'https://www.destatis.de/DE/Themen/Wirtschaft/Preise/Verbraucherpreisindex/_inhalt.html'],
                ],
            ],
            // The long formats (plan "TWENTY ONE Radio", P9; App\Support\Caster\Programme\Shows; user 2026-10-12: "Mehr Programm …
            // damit es nicht langweilig wird, müssen wir roasten!"): each owns a rubric slot of the clock at its Berlin time
            // (`schedule`, "HH:MM" => format); without material the slot falls back to the rubric rotation, never filler.
            // Real data only (the guard rejects a number or a name that is not in it). Per format: `seconds` and `sentences`
            // of content, `speakers` (Markus leads; a further voice needs only its voicestudio.profiles entry and
            // its name here), `write_minutes_ahead` (the hotline looks things up first) and how long
            // its material does not come again (`repeat_days`).
            'shows' => [
                'enabled' => (bool) env('TV_COMMENTARY_SHOWS', true),
                // P21 (user 2026-10-13): a long show starts at :03 right after the news and owns its window up to thirty
                // minutes (`slot_minutes`, BroadcastClock: no rubric or headlines inside it); one long show an hour.
                'schedule' => [
                    '10:03' => 'jahrestag',
                    '11:03' => 'meetups',
                    '12:03' => 'friedhof',
                    '15:03' => 'partie',
                    '17:03' => 'partie',
                    '20:03' => 'tribunal',
                    '21:03' => 'hotline',
                    '18:15' => 'stammtisch',
                    '22:15' => 'stammtisch',
                ],
                'max_output_tokens' => 6_000,
                // A five-minute show is about a thousand words: longer to write than `esports.voice.timeout_seconds` allows a post.
                // 240 s, not 90 (live 2026-10-16): the gateway answered a segment's prompt in 100 to 240 s, and the
                // friedhof's segments died at the old default until the slot passed - 7 attempts, 1 accepted, no show.
                'timeout_seconds' => (int) env('TV_COMMENTARY_SHOWS_TIMEOUT', 240),
                // A long show is written in segments (ShowScripts::inSegments()): six to twelve calls a show.
                'daily_cap' => (int) env('TV_COMMENTARY_SHOWS_DAILY_CAP', 120),
                'formats' => [
                    // P21: a long show fills `slot_minutes` (the window on air) segment by segment, each segment of the format's
                    // `seconds`/`sentences` (ShowScripts::inSegments(); a segment is short only under `segment_seconds_min`, default 90:
                    // the model stops after two to three minutes on its own), to `slot_margin_seconds` short of the window's end, at
                    // most `max_segments`; under `min_share` of the window it does not air. Its material grows with it
                    // (`max_chatters`, `graves`, `max_questions`, `max_events`). Voicing thirty minutes takes VoiceStudio about
                    // as long: written `write_minutes_ahead` before the slot.
                    'tribunal' => ['seconds' => ['min' => 240, 'max' => 360], 'sentences' => ['min' => 30, 'max' => 48], 'speakers' => ['markus', 'sirius'], 'chat_hours' => 24, 'max_chatters' => 8, 'notes_per_chatter' => 3, 'repeat_days' => 1,
                        'slot_minutes' => 30, 'slot_margin_seconds' => 120, 'max_segments' => 10, 'min_share' => 0.75, 'write_minutes_ahead' => 120],
                    'friedhof' => ['seconds' => ['min' => 180, 'max' => 240], 'sentences' => ['min' => 22, 'max' => 34], 'speakers' => ['markus', 'sirius'], 'repeat_days' => 30,
                        'graves' => 5, 'slot_minutes' => 25, 'slot_margin_seconds' => 120, 'max_segments' => 10, 'min_share' => 0.75, 'write_minutes_ahead' => 120],
                    'jahrestag' => ['seconds' => ['min' => 120, 'max' => 180], 'sentences' => ['min' => 15, 'max' => 26], 'speakers' => ['markus', 'sirius'], 'repeat_days' => 2, 'wikipedia' => (bool) env('TV_COMMENTARY_SHOWS_WIKIPEDIA', true),
                        'slot_minutes' => 20, 'slot_margin_seconds' => 120, 'max_segments' => 10, 'min_share' => 0.75, 'write_minutes_ahead' => 90],
                    'meetups' => ['seconds' => ['min' => 120, 'max' => 180], 'sentences' => ['min' => 15, 'max' => 26], 'speakers' => ['markus', 'sirius'], 'days' => 7, 'max_events' => 20, 'repeat_days' => 1,
                        'slot_minutes' => 20, 'slot_margin_seconds' => 120, 'max_segments' => 10, 'min_share' => 0.75, 'write_minutes_ahead' => 90],
                    'partie' => ['seconds' => ['min' => 180, 'max' => 300], 'sentences' => ['min' => 22, 'max' => 40], 'speakers' => ['markus', 'sirius'], 'hours' => 24, 'repeat_days' => 2,
                        'slot_minutes' => 20, 'slot_margin_seconds' => 120, 'max_segments' => 8, 'min_share' => 0.75, 'write_minutes_ahead' => 90],
                    'hotline' => ['seconds' => ['min' => 240, 'max' => 330], 'sentences' => ['min' => 28, 'max' => 44], 'speakers' => ['markus', 'sirius'], 'chat_hours' => 24, 'max_questions' => 8, 'searches_per_question' => 2, 'repeat_days' => 1,
                        'slot_minutes' => 25, 'slot_margin_seconds' => 120, 'max_segments' => 10, 'min_share' => 0.75, 'write_minutes_ahead' => 120],
                    // Turm und Genossin, der Talk (P16, Programme\TalkShow, TalkShowScripts; user 2026-10-13: like a real TV talk
                    // show): Sirius hosts as the parody of an interrupting talk show host, Markus and the guest Genossin Gisela (MMT,
                    // resources/data/voice/mmt-positions.json) fight in short turns of at most `sentences_per_turn` (one-liners
                    // from `min_words` words), each of the three with at least `min_turns` turns; between two turns only
                    // `turn_gap_ms` of breath (the duets take voicestudio.speaker_pause; P20, user 2026-10-13: "es geht auch viel
                    // zu schnell": 450 to 800 ms like a TV talk, `interrupt_gap_ms` for at most `max_interruptions` cut-ins,
                    // `long_gap_ms` before the fact check, the closing round and the host's reaction to a hit, TalkShow::gapFor());
                    // the host gets `host_moves` of his
                    // phrases per episode (resources/data/voice/talkshow-host-moves.json). Live at its slots on a real money
                    // headline of the last `headline_hours` (none within `repeat_days`), else the slot falls back to the
                    // rotation; the evergreen version (a timeless topic) goes into the night stock at most `stock_per_day`
                    // times a day. Its own usage channel and `daily_cap`, `timeout_seconds` for the long write.
                    'stammtisch' => ['seconds' => ['min' => 360, 'max' => 600], 'sentences' => ['min' => 55, 'max' => 110], 'speakers' => ['markus', 'sirius', 'genossin'],
                        'sentences_per_turn' => 3, 'min_words' => 3, 'host_moves' => 14, 'host_question_share' => 0.6, 'min_turns' => 6, 'turn_gap_ms' => ['min' => 450, 'max' => 800], 'interrupt_gap_ms' => 250, 'max_interruptions' => 2, 'long_gap_ms' => ['min' => 1000, 'max' => 1400], 'headline_hours' => 24, 'repeat_days' => 2, 'write_minutes_ahead' => 120, 'stock_per_day' => 2,
                        'daily_cap' => (int) env('TV_COMMENTARY_TALKSHOW_DAILY_CAP', 60), 'timeout_seconds' => 240, 'max_output_tokens' => 9_000,
                        // P20 (user 2026-10-13: "wenn im Programm steht TalkShow von 19:00 bis 19:30, dann muss die TalkShow auch 30
                        // min gehen!"): the talk owns a window of `slot_minutes` (BroadcastClock: no headlines inside it) and fills it
                        // to `slot_margin_seconds` short of its end on air (TalkShow::airSeconds(): `air_words_per_second`, the turn
                        // gaps, `studio_seconds` of theme and applause). Written in `acts`, each in parts of about `part_seconds`
                        // (one call each, at most `max_parts_per_act`; the voice model writes 21 to 28 lines per call on its own,
                        // measured 2026-10-13); short before the closing round: up to `max_extra_acts` parts of `extra_act`. Voicing thirty minutes takes VoiceStudio about as long,
                        // `air_words_per_second` 2.9: the P20 probe's talk (4791 words, 194 lines) aired 1801.9 s rendered as one show
                        // (remix 2026-10-13), TalkShow::airSeconds() gave 1749.7 s at 3.0; VoiceStudio speaks 3.18 words a second
                        // between its pauses, but every chunk carries its pads. `slot_margin_seconds` 60: 29 to 30 minutes on air.
                        // An act's `evergreen_brief` replaces its `brief` in an evergreen episode (no headline to read out).
                        // so the live talk is written `write_minutes_ahead` before its slot.
                        'model' => (string) env('TV_COMMENTARY_TALKSHOW_MODEL', 'claude-sonnet-5-5'), 'slot_minutes' => 30, 'slot_margin_seconds' => 60, 'air_words_per_second' => 2.9, 'studio_seconds' => 35, 'max_extra_acts' => 2, 'act_short_share' => 0.5, 'part_seconds' => 150, 'max_parts_per_act' => 4,
                        'acts' => [
                            ['id' => 'opening', 'brief' => 'Akt eins: der kalte Einstieg, die Genossin mit ihrer steilsten These, Markus fährt ihr sofort in die Parade; dann begrüßt Sirius die Hörer, stellt beide Gäste mit Namen und Haltung vor und stellt das Thema als zugespitzte Frage, ausgehend von der Schlagzeile mit ihrer Quelle; danach die erste Runde Kreuzfeuer.', 'evergreen_brief' => 'Akt eins: der kalte Einstieg, die Genossin mit ihrer steilsten These, Markus fährt ihr sofort in die Parade; dann begrüßt Sirius die Hörer, stellt beide Gäste mit Namen und Haltung vor und stellt das Thema als zugespitzte Frage; danach die erste Runde Kreuzfeuer.', 'min_turns' => 4],
                            ['id' => 'headline', 'brief' => 'Akt zwei: die Schlagzeile im Kreuzfeuer. Sirius liest sie noch einmal vor und konfrontiert beide abwechselnd mit dem Argument des anderen, hakt bei jeder ausweichenden Antwort nach; die Genossin landet einen Treffer, den Markus nicht sofort kontern kann, und es eskaliert.', 'evergreen_brief' => 'Akt zwei: das Thema im Kreuzfeuer. Sirius spitzt die Frage des Abends noch einmal zu und konfrontiert beide abwechselnd mit dem Argument des anderen, hakt bei jeder ausweichenden Antwort nach; die Genossin landet einen Treffer, den Markus nicht sofort kontern kann, und es eskaliert.', 'min_turns' => 4],
                            ['id' => 'everyday', 'brief' => 'Akt drei: Was heißt das für die Leute? Sirius stellt hypothetische Fragen („Mal angenommen, …, was dann?“), fragt nach der Rentnerin, dem Sparer und dem Einkauf im Supermarkt, stellt Entweder-oder-Fragen; die Running Gags kommen zurück.', 'min_turns' => 4],
                            ['id' => 'factcheck', 'brief' => 'Akt vier: Sirius ruft den Faktencheck aus, mit genau diesem Wort, und prüft eine strittige Behauptung nur mit den Tatsachen unten im Material, ausgesprochen als eigenes Wissen (nie „laut Liste“ oder „im Material“); danach liest er eine Zuschauerfrage vor, ohne einen Namen zu erfinden („Ein Zuschauer fragt …“), und zwingt beide zu einer Antwort.', 'factcheck' => true, 'min_turns' => 4],
                            ['id' => 'closing', 'brief' => 'Letzter Akt: ein letztes Aufbäumen, dann die Schlussrunde: Sirius verlangt von jedem genau einen Satz („Ein Satz, Herr Turm, ein Satz!“), die Genossin zuerst, dann Markus; Sirius spricht zuletzt, mit einer bissigen Zusammenfassung als Pointe: kein Dank, kein Gruß, kein „bleiben Sie dran“ (eine Verabschiedung wird gestrichen, dann spräche nicht er zuletzt).', 'min_turns' => 3],
                        ],
                        'extra_act' => ['id' => 'extra', 'brief' => 'Zusatzrunde: noch eine Runde Kreuzfeuer zu einem Aspekt aus dem Material, der noch nicht dran war; Sirius fragt nach, die beiden Gäste roasten sich, keiner gibt nach.', 'min_turns' => 4]],
                ],
                // The EINUNDZWANZIG portal's public meetup dates (GET, about five megabytes, every date ever): read at most
                // every `cache_minutes`, a failed read tried again after `retry_minutes`.
                'meetup_events_url' => env('TV_COMMENTARY_MEETUP_EVENTS_URL', 'https://portal.einundzwanzig.space/api/meetup-events'),
                'meetup_cache_minutes' => 180,
                'meetup_retry_minutes' => 15,
                'meetup_timeout_seconds' => 20,
                // Wikipedia's "on this day" feed (German), only its bitcoin events are used.
                'on_this_day_url' => 'https://api.wikimedia.org/feed/v1/wikipedia/de/onthisday/events',
                'on_this_day_timeout_seconds' => 8,
            ],
            // The news talk (P5; App\Support\Caster\Programme\NewsTalk, user 2026-10-12: "Die Programme sollen aufregend und lang
            // werden"): right after the :00 bulletin Markus and Sirius discuss its two or three most interesting stories
            // (`stories`), `seconds` long (a shorter draft is tried again, a longer one loses its last sentences), with
            // questions from the chat of the last `chat_hours`, ending with a teaser. Written once the bulletin is, in parts,
            // airing when the bulletin ends (its estimated length after the slot), at most `late_seconds` late.
            'news_talk' => [
                'enabled' => (bool) env('TV_COMMENTARY_NEWS_TALK', true),
                'seconds' => ['min' => (int) env('TV_COMMENTARY_NEWS_TALK_MIN_SECONDS', 180), 'max' => (int) env('TV_COMMENTARY_NEWS_TALK_MAX_SECONDS', 360)],
                'sentences' => ['min' => 24, 'max' => 44],
                'stories' => 3,
                'chat_hours' => 2,
                'priority' => 70,
                'late_seconds' => 240,
                'max_output_tokens' => 5_000,
                'daily_cap' => (int) env('TV_COMMENTARY_NEWS_TALK_DAILY_CAP', 60),
            ],
            // Sport und Wetter (App\Support\Caster\Programme\SportWeather; user 2026-10-12: "Wetter und Sport im Radio Programm aus
            // echten Daten … sehr sarkastisch und toxisch darüber sprechen. Sport und Wetter immer im Anschluss der News zur vollen
            // Stunde."): right after the :00 bulletin and before the news talk, `seconds` long, Markus and Sirius (`duet`) or
            // Markus alone, from real data only: Open-Meteo for `weather.cities` (WeatherReport, cached `cache_minutes`) and
            // OpenLigaDB for `sport.leagues` plus the league's own results of the last `esports_minutes` (SportResults). A source
            // that is down leaves its half out, both down leave the segment out. In the quiet hours `quiet` decides: off, weather
            // (the weather alone, `quiet_seconds`, Markus alone) or full (both halves, short, Markus alone).
            'sport_weather' => [
                'enabled' => (bool) env('TV_COMMENTARY_SPORT_WEATHER', true),
                'duet' => (bool) env('TV_COMMENTARY_SPORT_WEATHER_DUET', true),
                'quiet' => (string) env('TV_COMMENTARY_SPORT_WEATHER_QUIET', 'weather'),
                'seconds' => ['min' => 60, 'max' => 120],
                'sentences' => ['min' => 8, 'max' => 14],
                'quiet_seconds' => ['min' => 20, 'max' => 50],
                'quiet_sentences' => ['min' => 2, 'max' => 5],
                'priority' => 70,
                'late_seconds' => 240,
                'daily_cap' => (int) env('TV_COMMENTARY_SPORT_WEATHER_DAILY_CAP', 60),
                'weather' => [
                    'enabled' => (bool) env('TV_COMMENTARY_WEATHER', true),
                    'url' => 'https://api.open-meteo.com/v1/forecast',
                    'cache_minutes' => 30,
                    'retry_seconds' => 60,
                    'timeout_seconds' => 8,
                    'cities' => [
                        ['name' => 'Berlin', 'lat' => 52.52, 'lon' => 13.41],
                        ['name' => 'Hamburg', 'lat' => 53.55, 'lon' => 9.99],
                        ['name' => 'München', 'lat' => 48.14, 'lon' => 11.58],
                        ['name' => 'Kempten', 'lat' => 47.73, 'lon' => 10.31],
                        ['name' => 'Wien', 'lat' => 48.21, 'lon' => 16.37],
                        ['name' => 'Zürich', 'lat' => 47.37, 'lon' => 8.54],
                    ],
                ],
                'sport' => [
                    'enabled' => (bool) env('TV_COMMENTARY_SPORT', true),
                    'url' => 'https://api.openligadb.de',
                    'cache_minutes' => 15,
                    'retry_seconds' => 60,
                    'timeout_seconds' => 8,
                    'leagues' => [
                        ['shortcut' => 'bl1', 'name' => 'Bundesliga'],
                        ['shortcut' => 'bl2', 'name' => 'Zweite Bundesliga'],
                        ['shortcut' => 'dfb', 'name' => 'DFB-Pokal', 'round' => 'Runde', 'table' => false],
                    ],
                    'results' => 9,
                    // A finished match older than this is old news (a cup round from August, the summer break): left out.
                    'results_max_days' => 8,
                    'fixtures' => 4,
                    'esports_minutes' => 60,
                ],
            ],
            // The Programmvorschau (P14; App\Support\Caster\Programme\ProgrammePreview; user 2026-10-12: "Was ist mit einer Vorlesung
            // des Programms, was in den nächsten 12 Stunden zu erwarten ist?"): Markus and Sirius read the highlights of the next
            // `hours` from the real timetable (long formats with a source, fixed rubric plans, the podcast replay, news, Sport und
            // Wetter, the quiet hours), at each of `slots` (Berlin "HH:MM" => day, or night for the short "heute Nacht und morgen
            // früh" version) after that hour's news block, written from `write_minutes_ahead` before to `late_minutes` after it,
            // at most `tries` writes a slot; never while a podcast replay is on, never replayed. `generic_formats` rotation formats
            // are named without a time.
            'preview' => [
                'enabled' => (bool) env('TV_COMMENTARY_PREVIEW', true),
                'slots' => ['09:03' => 'day', '13:03' => 'day', '17:03' => 'day', '21:03' => 'day', '00:35' => 'night'],
                'hours' => 12,
                'write_minutes_ahead' => 15,
                'late_minutes' => 10,
                'tries' => 2,
                'seconds' => ['min' => 60, 'max' => 120],
                'sentences' => ['min' => 8, 'max' => 14],
                'night_seconds' => ['min' => 30, 'max' => 60],
                'night_sentences' => ['min' => 4, 'max' => 8],
                'generic_formats' => 3,
                'priority' => 68,
                'late_seconds' => 240,
                'daily_cap' => (int) env('TV_COMMENTARY_PREVIEW_DAILY_CAP', 20),
            ],
            // Programme announcements (P5; App\Support\Caster\Programme\Announcements): written by the model from the rundown as
            // data, and a line goes on air only when it names a bulletin or rubric that really follows, at the time it
            // says. A teaser for the next slot plays at the first clip boundary within `before_minutes` of it (written a
            // minute or two before that, at `priority`); a bulletin ends with a preview of the hour (`after_bulletin`).
            'teasers' => [
                'enabled' => (bool) env('TV_COMMENTARY_TEASERS', true),
                'before_minutes' => 5,
                'write_minutes_ahead' => 7,
                'after_bulletin' => true,
                'priority' => 72,
                'daily_cap' => (int) env('TV_COMMENTARY_TEASER_DAILY_CAP', 120),
            ],
            // Music announcements (user 2026-10-17: "Im Radio werden doch auch die Musik-Titel angekündigt, die als
            // nächstes gespielt werden … aber nicht jedes Stück, sondern ab und an, wenn es passt"): the track the
            // music timeline really plays next, by the artist's collected data (Nostr identity, Wavlake, YouTube,
            // lightning address, the last kind-1 note when there is an npub; App\Support\Caster\Programme\MusicTeasers).
            // Written only around the handover to the music — the voice ahead below `ahead_below_seconds` of
            // airtime or gone, no clock slot or stock block within `guard_minutes` — at most one per
            // `min_gap_minutes`, at `chance` per opportunity, capped per day; the script expires after
            // `max_age_minutes`, so a late one never announces a track that has played. The live-only mode
            // does not hold it back: the announcement belongs to the music (user 2026-10-17).
            'music_teasers' => [
                'enabled' => (bool) env('TV_COMMENTARY_MUSIC_TEASERS', true),
                'chance' => (float) env('TV_COMMENTARY_MUSIC_TEASER_CHANCE', 0.35),
                'min_gap_minutes' => (int) env('TV_COMMENTARY_MUSIC_TEASER_MIN_GAP_MINUTES', 25),
                'guard_minutes' => 4,
                'ahead_below_seconds' => (int) env('TV_COMMENTARY_MUSIC_TEASER_AHEAD_BELOW_SECONDS', 240),
                // The track named starts at least this long after the write: the voicing needs two to four
                // minutes, and a named track must not be playing already when the announcement airs
                // (live 2026-10-17: 45 s named the previous title).
                'lead_seconds' => 200,
                // The lead grows by the voice ahead plus this much voicing latency.
                'render_seconds' => 150,
                'max_age_minutes' => 8,
                'priority' => 70,
                'daily_cap' => (int) env('TV_COMMENTARY_MUSIC_TEASER_DAILY_CAP', 16),
            ],
        ],

        // Followed profiles (user 2026-10-11: "Für mehr Varianz kann er noch auf die Nostr-Posts von nprofile1… reagieren,
        // aber pro Post nur einmal"): npub, nprofile or hex, comma-separated. `caster:followed` reads their new kind 1
        // notes every five minutes from the nprofile's relay hints and the stream's relays (App\Support\Caster\FollowedNotes);
        // each note gets at most one segment ever (App\Support\Caster\FollowedScripts).
        'followed_profiles' => array_values(array_filter(array_map('trim', explode(',', (string) env('TV_COMMENTARY_FOLLOWED_PROFILES', 'nprofile1qqs0ys979d5ylpwvs9tx7gyp8p40s8t5yl4gvfgv300xk759qrrkrws3c57u3'))))),

        'followed' => [
            'enabled' => (bool) env('TV_COMMENTARY_FOLLOWED', true),
            // At most one followed-profile segment within this many minutes.
            'every_minutes' => (int) env('TV_COMMENTARY_FOLLOWED_EVERY_MINUTES', 10),
            // A note older than this is not spoken (nor read); 0: no limit, the whole history (user 2026-10-12: "Die Kommentare
            // für die Nostr-Posts des npubs dürfen bis an den Anfang aller je geposteten kind-1-Events gehen. Also ALL! … aber
            // sortiert nach Zeit."). With a limit, rows older than `keep_days` are deleted, beyond `max_rows` the oldest; without
            // one, nothing is ever deleted (a deleted row would be read back by the backfill and spoken twice).
            'fresh_hours' => (int) env('TV_COMMENTARY_FOLLOWED_FRESH_HOURS', 0),
            'keep_days' => 7,
            'max_rows' => 500,
            // Notes asked per REQ while paging back through a profile's notes (relay `until` pagination).
            'page_limit' => 200,
            // Notes read per profile and run at most, and seconds per run (checked between pages; a page started reads every
            // relay, each within `esports.relay_fetch_budget_seconds`): the backfill resumes at the oldest note next run.
            'per_run' => 1000,
            'run_seconds' => 120,
            // Unused notes are spoken oldest first, from the very first one on; a note younger than this jumps ahead.
            'jump_ahead_minutes' => 60,
            // A note older than this is announced with its month and year ("Im März zweitausenddreiundzwanzig …").
            'dated_after_hours' => 48,
            // A note with fewer words (links, references and markup left out) is not spoken.
            'min_words' => 5,
            // Replies (NIP-10 `e` tags other than a mention) are left out unless this is on.
            'replies' => false,
            // Markus' retelling of the note (one or two sentences) plus his reaction (two to five).
            'sentences' => ['min' => 3, 'max' => 7],
            // The quote the pattern stand-in reads out, in words.
            'quote_words' => 20,
            'name_hours' => 24,
            'daily_cap' => (int) env('TV_COMMENTARY_FOLLOWED_DAILY_CAP', 60),
        ],

        'history' => [
            'enabled' => (bool) env('TV_COMMENTARY_HISTORY', true),
            'every_minutes' => ['min' => 45, 'max' => 90],
            'repeat_hours' => 24,
            'sentences' => ['min' => 6, 'max' => 12],
        ],

        // The length mix (user 2026-10-11: "Die Audios dürfen ruhig auch mal ganz viel länger werden … dann öfters auch
        // mal viel längere Texte"): today's short scripts stay the base, and this share of each kind is a long variant
        // (App\Support\Caster\LengthMix; 0 turns it off, 1 makes every one long). Sentences per script: a long scene is a
        // pattern script of `scene_long` (a fact, canon lines, pride, an invitation, no model); a long news segment is the
        // intro, the headline and a comment of `news_long` by the league voice (one call per headline, as before), so at
        // most 10; a long Bauernkrieg segment chains `history_long` of the episode's facts, asides, twist and outro.
        // No sentence again within this many hours, from any source (App\Support\Caster\SpokenMemory; user 2026-10-12:
        // "ALLE WIEDERHOLUNGEN RAUS!!!"): the queue keeps a day of scripts, so more than 24 has no effect. 0 turns it off.
        'repeat_hours' => (int) env('TV_COMMENTARY_REPEAT_HOURS', 24),
        // A worn phrase (four words, two with content, said twice) counts within this many hours only (plan "TWENTY ONE
        // Radio" P8): over a whole day, every fact phrase ("der Spieler mit Weiß", "mit Nostr einloggen", "die Welt
        // berichtet, dass") wore out and took the script with it; 10.6 % of fresh eval sentences at 24 h, 5.5 % at 6 h.
        'repeat_phrase_hours' => (int) env('TV_COMMENTARY_REPEAT_PHRASE_HOURS', 6),

        'length' => [
            'shares' => [
                'scene' => (float) env('TV_COMMENTARY_LONG_SCENES', 0.25),
                'news' => (float) env('TV_COMMENTARY_LONG_NEWS', 0.5),
                'history' => (float) env('TV_COMMENTARY_LONG_HISTORY', 0.5),
            ],
            'scene_long' => ['min' => (int) env('TV_COMMENTARY_SCENE_LONG_MIN', 6), 'max' => (int) env('TV_COMMENTARY_SCENE_LONG_MAX', 10)],
            'news_long' => ['min' => (int) env('TV_COMMENTARY_NEWS_LONG_MIN', 3), 'max' => (int) env('TV_COMMENTARY_NEWS_LONG_MAX', 6)],
            'history_long' => ['min' => 15, 'max' => 30],
            // The fact guard's character cap for a whole long script (scripts.max_chars holds the short ones).
            'max_chars' => 1_800,
        ],

        // Long scripts in parts (live 2026-10-11: a wished Bauernkrieg episode of 27 lines left 2.5 minutes of silence
        // while the workstation voiced it as one clip): a scene, segment or wished segment of more than `sentences`
        // lines is queued as parts of at most that many (4 to 5 each), split between sentences only
        // (App\Support\Caster\ScriptQueue::parts(); 0 turns it off). The workstation voices them in order, so the first
        // part is on the spool within seconds; SceneDirector plays the next part after `breath_seconds` (plus the
        // clip's lead-in of about a second) instead of the music gap, before any other scene, ignoring
        // spool.segment_spacing_minutes. A part not ready `wait_seconds` after the one before it ends the group.
        'parts' => [
            'sentences' => (int) env('TV_COMMENTARY_PART_SENTENCES', 5),
            'breath_seconds' => 0.0,
            // Voicing a part with a profile switch takes 30–60 s on the workstation; 20 s ended stories mid-way (2026-10-12).
            'wait_seconds' => (int) env('TV_COMMENTARY_PART_WAIT_SECONDS', 120),
        ],

        // Duets (App\Support\Caster\DuetDirector, user 2026-10-12: "Sirius ist so ein Side-Kommentator, der jeweils immer
        // das zuletzt Gesagte von Markus kommentiert … mal hin und her, dann mal einmal Markus mit zweimal Sirius"): about
        // `share` of the league voice's scene scripts are an exchange of Markus (M) and Sirius (S), rolling over the last
        // `window_segments` scene scripts (never in the quiet hours); the rest stays Markus alone. A duet's turn sequence
        // walks the weighted `patterns` turn by turn: where the turns so far form a whole pattern, it ends with the rising
        // `stop` chance of that turn count, else the next speaker is drawn by the weight of the patterns that continue.
        // Patterns breaking `max_run` or `turns` are ignored; Sirius never opens two duets in a row. His share of the
        // duets' spoken seconds over the last `sirius_share.window_minutes` steers the next draw: above `max` only the
        // patterns Markus opens with one Sirius turn are left (MS, MSM, MMS), below `min` a pattern's weight is divided
        // by `cooldown` for each Sirius turn beyond the first.
        // One model call writes the whole exchange (App\Support\Caster\DuetScripts, `daily_cap` calls a day).
        'duet' => [
            'share' => (float) env('TV_COMMENTARY_DUET_SHARE', 0.35),
            'tolerance' => 0.05,
            'window_segments' => 20,
            // Banter, not two monologues (plan "TWENTY ONE Radio" P17, user 2026-10-12: "wie echte Programme aus dem Radio"):
            // live 2026-10-12 most duets were one Markus turn and one Sirius turn of three to six sentences; now mostly four to
            // six short turns of one to three sentences, about as many sentences in all.
            'turns' => ['min' => 2, 'max' => (int) env('TV_COMMENTARY_DUET_TURNS_MAX', 100)],
            'max_run' => 2,
            'stop' => [2 => 0.2, 3 => 0.3, 4 => 0.5, 5 => 0.7, 6 => 1.0],
            'patterns' => [
                'MS' => 6, 'MSM' => 12, 'MSS' => 4, 'MSMS' => 22, 'MMS' => 4, 'MSSM' => 4, 'MSMSM' => 18, 'MSMSMS' => 10,
                'SM' => 4, 'SMS' => 8, 'SMSM' => 8,
            ],
            'sirius_share' => ['min' => (float) env('TV_COMMENTARY_SIRIUS_SHARE_MIN', 0.25), 'max' => (float) env('TV_COMMENTARY_SIRIUS_SHARE_MAX', 0.35), 'window_minutes' => 60],
            'cooldown' => 0.35,
            // The previous duet's last turns a follow-up may pick up, if it ran within this many minutes.
            'follow_up_minutes' => 30,
            'sentences' => ['markus' => ['min' => 1, 'max' => 3], 'sirius' => ['min' => 1, 'max' => 4]],
            'daily_cap' => (int) env('TV_COMMENTARY_DUET_DAILY_CAP', 1000),
            // Share of duets in which Sirius may pick his beer topic (the running gag, not every time).
            'beer_percent' => (int) env('TV_COMMENTARY_DUET_BEER_PERCENT', 15),
        ],

        // Commentary runs (user 2026-10-11: "Durchaus auch öfter mal mehrere Audios hintereinander, damit es an diesen Stellen
        // zu Dauerkommentaren kommt"): every `every_minutes` (random within the range) `clips` clips back to back, each with
        // its own scene and only a breath of `breath_seconds` between them instead of spool.gap_seconds
        // (App\Support\Caster\SceneDirector).
        'runs' => [
            'enabled' => (bool) env('TV_COMMENTARY_RUNS', false),
            'every_minutes' => ['min' => 10, 'max' => 15],
            'clips' => ['min' => 2, 'max' => 4],
            'breath_seconds' => ['min' => 1.0, 'max' => 3.0],
        ],

        'chat' => [
            'reactions' => (bool) env('TV_COMMENTARY_CHAT', false),
            'profiles' => (bool) env('TV_COMMENTARY_CHAT_PROFILES', false),
            // The live relay subscription (caster:chat-live): one round lasts this long in its own lane, the
            // schedule starts the next five minutes sharp, and the REQ asks since the chat cursor, so a socket's
            // gap closes with the next round (the polled reads stay on as the safety net). User 2026-10-17:
            // "Realtime rein, keine Verzögerung mehr" - a message is in the reaction chain the moment the relay
            // pushes it, not up to a poll interval later.
            'live' => (bool) env('TV_COMMENTARY_CHAT_LIVE', true),
            'live_seconds' => (int) env('TV_COMMENTARY_CHAT_LIVE_SECONDS', 270),
            // Whether a chat reply's draft is checked at all (user 2026-10-18: "Ich will es mal ohne Gate versuchen"; on
            // prod a draft that failed both tries left the message without any reaction, about one in two, because the
            // pattern fallback is off). Off: every draft that has words airs; the violations are only counted
            // (voice_rejections, channel caster_chat_ungated).
            'gate' => (bool) env('TV_COMMENTARY_CHAT_GATE', false),
            'per_person_minutes' => (int) env('TV_COMMENTARY_CHAT_PERSON_MINUTES', 2),
            'per_hour' => (int) env('TV_COMMENTARY_CHAT_PER_HOUR', 20),
            // A reaction (a chat reply, a wish, a zap thank-you) waits at most this long after it is ready (user
            // 2026-10-12: "Maximal 10 Minuten, dann dürfen wieder Reactions reinkommen"): then it cuts in at the next
            // sentence end of whatever is on air, never inside a bulletin's news, and the programme resumes from there
            // (SceneDirector). Its script never expires before this plus `reaction_voicing_minutes`.
            // Zero (user 2026-10-15: reactions go on air at once): every waiting reaction counts as overdue and
            // cuts into what is on air at its next sentence end, except the news, the weather and sport talk and
            // a rendered programme (SceneDirector::protectedFromReactions()).
            'max_hold_seconds' => (int) env('TV_COMMENTARY_CHAT_MAX_HOLD_SECONDS', 0),
            // A reaction (and a live moment) plays as soon as no clip is on air, mid-track, unless a story or a podcast runs
            // (SceneDirector::liveAnytime(); user 2026-10-14). false: it waits for the end of the gap's track (music first).
            'live_anytime' => (bool) env('TV_COMMENTARY_LIVE_ANYTIME', true),
            'reaction_voicing_minutes' => 20,
            // The share of trusted chat replies that are a short duet: Markus answers, Sirius adds his take (user 2026-10-12).
            'duet_share' => (float) env('TV_COMMENTARY_CHAT_DUET_SHARE', 0.5),
            // Sirius' one turn in a chat duet (user 2026-10-25: shorter).
            'duet_sirius_sentences' => ['min' => 1, 'max' => 2],
            'notes_per_person' => 50,
            // Ninety days, not thirty (prod 2026-10-12): two of the six chatters who post at all had one note in thirty days.
            'notes_days' => 90,
            'notes_total' => 20_000,
            'profiles_total' => 2_000,
            'persona_chars' => 600,
            'persona_refresh_hours' => 6,
            // Where the chatter's kind 0, newest notes and NIP-65 relay list are read (App\Support\Caster\ChatterCache::refresh),
            // measured from prod 2026-10-12 for the eleven chatters: purplepag.es carries kind 0 and 10002 (eight of nine
            // lists), primal and oxtr the most notes (six and five chatters), nostr.mom many but it failed about half the reads;
            // nostr.wine held one chatter's notes and nothing else, damus refuses prod, nos.lol failed half the reads.
            // None answering: `esports.relays`. Read one after the other; `knowledge_budget_seconds` stops starting the next.
            'knowledge_relays' => array_values(array_filter(array_map('trim', explode(',', env('APP_ENV') === 'testing'
                ? (string) env('TV_COMMENTARY_KNOWLEDGE_RELAYS', '')
                : ((string) env('TV_COMMENTARY_KNOWLEDGE_RELAYS') ?: 'wss://purplepag.es,wss://relay.primal.net,wss://relay.ditto.pub,wss://nostr.mom'))))),
            'knowledge_budget_seconds' => 8,
            // Then the chatter's own write relays from that 10002 (kind 3's legacy map without one), NIP-65 outbox: at most
            // `outbox_relays`, untrusted (RelayReader::readGuarded()), within `outbox_budget_seconds`; one that fails is
            // skipped for `outbox_down_minutes`. `outbox_skip`: relays measured refusing prod.
            'outbox_relays' => 4,
            'outbox_budget_seconds' => 6,
            'outbox_down_minutes' => 60,
            'outbox_skip' => ['wss://relay.damus.io', 'wss://relay.nostr.band', 'wss://nostr.oxtr.dev', 'wss://inbox.nostr.wine'],
            // The newest own notes (no replies) a refresh keeps, and how many of them a reply prompt carries, each cut.
            'knowledge_notes' => 15,
            'prompt_notes' => 3,
            'prompt_note_chars' => 160,
            'exchanges_per_person' => 5,
            'inactive_days' => 30,
            'wot_level_two_min_followers' => 2,
            'everyone' => (bool) env('TV_COMMENTARY_CHAT_EVERYONE', true),
            'everyone_minutes' => (int) env('TV_COMMENTARY_CHAT_EVERYONE_MINUTES', 3),
            'zaps' => (bool) env('TV_COMMENTARY_CHAT_ZAPS', true),
            'zap_bundle_seconds' => 60,
            'zap_big_sats' => (int) env('TV_COMMENTARY_ZAP_BIG_SATS', 10_000),
            // Wishes of trusted chatters (App\Support\Caster\ChatWishes): a Bauernkrieg episode, news, jokes, the game,
            // EINUNDZWANZIG lore or "erzähl mehr", queued right behind the reply. Web of trust level 1 always, up to
            // `wishes_min_level`, or a trust rank of at least the season minimum. 0 lifts a limit (no cooldown, no
            // hourly cap, no Bauernkrieg repeat window).
            'wishes' => (bool) env('TV_COMMENTARY_CHAT_WISHES', true),
            'wishes_min_level' => (int) env('TV_COMMENTARY_WISHES_MIN_LEVEL', 2),
            'wishes_person_minutes' => (int) env('TV_COMMENTARY_WISH_PERSON_MINUTES', 5),
            'wishes_per_hour' => (int) env('TV_COMMENTARY_WISHES_PER_HOUR', 6),
            'wishes_history_repeat_minutes' => (int) env('TV_COMMENTARY_HISTORY_REPEAT_MINUTES', 60),
            // `caster:chat` runs every minute and reads the chat and zaps this often within it (one read of the eight relays
            // takes about 3.5 s, measured 2026-10-14). The reactions are written on the `caster-chat` queue
            // (ReactToChatMessage), so the reads never wait for one; `background` false writes them inline.
            'poll_seconds' => (int) env('TV_COMMENTARY_CHAT_POLL_SECONDS', 5),
            'background' => (bool) env('TV_COMMENTARY_CHAT_BACKGROUND', true),
            // The latest EINUNDZWANZIG podcast episodes as data for every chat reply (App\Support\Caster\PodcastFeed):
            // fetched at most once per `podcast_minutes`, the last good copy kept when the feed fails.
            'podcast_feed' => env('TV_COMMENTARY_PODCAST_FEED', 'https://anchor.fm/s/d8d3c38/podcast/rss'),
            // The podcasts whose episodes are transcribed for the timeless programmes (user 2026-10-16: "echte
            // Transkripte aus allen Podcasts von EINUNDZWANZIG oder Re:set Blocktrainer oder Münzweg"): Re:set
            // airs inside the Blocktrainer feed (GM.MarkusTurm and DerPhiL's series), so three feeds cover all four.
            'transcript_feeds' => array_values(array_filter(array_map('trim', explode(',', (string) env('TV_COMMENTARY_TRANSCRIPT_FEEDS',
                'https://anchor.fm/s/d8d3c38/podcast/rss,https://serve.podhome.fm/rss/9b654631-a45c-58e4-a237-2e8aa65262b8,https://serve.podhome.fm/rss/537e4d76-e51c-5a01-a88d-83450d7cf7e8'))))),
            // Real tutorial articles for the timeless tutorial programme (user 2026-10-16: "echte Tutorial-Artikel
            // aus discover.einundzwanzig.space/feed.xml - keine erfundenen Dinge mehr").
            'discover_feed' => (string) env('TV_COMMENTARY_DISCOVER_FEED', 'https://discover.einundzwanzig.space/feed.xml'),
            'podcast_episodes' => 3,
            'podcast_chars' => 600,
            'podcast_minutes' => 60,
            'podcast_timeout_seconds' => 8,
            'podcast_max_bytes' => 12 * 1024 * 1024,
            // A question in the chat is first looked up on the web (WebLookupAgent with Anthropic's server-side web
            // search, at most `max_searches` searches), the finding goes into the reply prompt as data.
            'web_search' => (bool) env('TV_COMMENTARY_CHAT_WEB_SEARCH', true),
            'max_searches' => (int) env('TV_COMMENTARY_CHAT_MAX_SEARCHES', 2),
            // The rolling chat memory (App\Support\Caster\ChatMemory): every trusted message the caster saw and its
            // reply, kept `memory_hours`; a reply prompt sees the asker's thread and the others' latest lines, each
            // block at most `memory_tokens` (characters / 4).
            'memory_hours' => (int) env('TV_COMMENTARY_CHAT_MEMORY_HOURS', 24),
            'memory_tokens' => 1_500,
            // The chat cadence (App\Support\Caster\ChatCadence, user 2026-10-13: "Die müssen alle 10 Minuten kommen, außer
            // es läuft gerade eine fixe Show/Programm"): when no chat item aired for `every_minutes` less `lead_minutes`
            // (the time a script needs to be voiced and reach the air) and none is on its way, `caster:chat` queues one:
            // a YouTube summary of whatever is new there (one line is enough), else a short call-out to a quiet chat.
            // Never while the news or a show window is on air, now or at the expected airing, nor in the autopilot.
            'cadence' => [
                'enabled' => (bool) env('TV_COMMENTARY_CHAT_CADENCE', true),
                // Sixty since music first (user 2026-10-13 15:20): the call-out is a rare fallback for a chat that stayed silent.
                'every_minutes' => (int) env('TV_COMMENTARY_CHAT_CADENCE_MINUTES', 60),
                'lead_minutes' => (int) env('TV_COMMENTARY_CHAT_CADENCE_LEAD_MINUTES', 3),
            ],
        ],

        // Music breaks (App\Support\Caster\SceneDirector, user 2026-10-13: "Ab und an soll der Radiosender auch mal ein
        // komplettes Musikstück spielen lassen, sonst wird nur ständig geredet"): `per_hour` times an hour the voice pauses
        // for one complete vocal track of the stream music (MusicTimeline), from its first second to its last, at the music's
        // gap level. Planned at a clip boundary for a vocal track starting within `wait_seconds`; before it starts only a
        // single clip that ends in time may still play. Never in the quiet hours, never across the news, a rubric's slot,
        // a show window or a story told in parts.
        'music_breaks' => [
            'enabled' => (bool) env('TV_COMMENTARY_MUSIC_BREAKS', true),
            'per_hour' => (float) env('TV_COMMENTARY_MUSIC_BREAKS_PER_HOUR', 2),
            'wait_seconds' => (int) env('TV_COMMENTARY_MUSIC_BREAK_WAIT_SECONDS', 300),
        ],

        // Music first (user 2026-10-13: "Was ist mit den Pausen, damit Musikstücke durchlaufen können! … Maximal ein Gag und
        // dann nicht ständig welche hintereinander!"): between two voice items at least one complete track of the stream
        // music (MusicTimeline); the voice resumes at that track's end, a chat reaction or live moment first, at most
        // `max_wait_seconds` after the last clip (else spool.gap_seconds). No commentary runs, no reaction cutting into a
        // block, no block joining the next unless it is the same story or a show window; the bulletin never waits.
        // `one_item_per_block`: the workstation renders one programme item per block (BlockFetcher), the parts of one story
        // and a bulletin's news talk still together. Quiet hours keep their own rhythm.
        'music_first' => [
            'enabled' => (bool) env('TV_COMMENTARY_MUSIC_FIRST', true),
            'max_wait_seconds' => (float) env('TV_COMMENTARY_MUSIC_FIRST_MAX_WAIT_SECONDS', 540),
            'one_item_per_block' => (bool) env('TV_COMMENTARY_BLOCK_ONE_ITEM', true),
            // LiveOnly (user 2026-10-13 15:20): while the workstation is online by day, no generic voice item and no stock
            // block; only the news with one comment after it, the shows in their windows and the live chat reactions.
            'live_only' => (bool) env('TV_COMMENTARY_LIVE_ONLY', true),
        ],

        // The rolling world memory (App\Support\Caster\WorldMemory, user 2026-10-12: "zu monoton und nicht reaktiv genug auf
        // echte Geschehnisse"): what really happened in the last `hours` (aired headlines, results, tournaments, new players
        // and clans, games on now, chat lines, zaps, wishes, Nostr notes aired), newest first, at most `tokens`
        // (characters / 4), as data in every TV prompt; built at most every `cache_seconds`. A "Rückblick-Spitze" (a
        // sarcastic callback on one or two of its items) is written every `callback_every_minutes` outside the quiet hours
        // (0: never); an item is not called back again within `callback_repeat_hours`.
        'world' => [
            'hours' => (int) env('TV_COMMENTARY_WORLD_HOURS', 24),
            'tokens' => (int) env('TV_COMMENTARY_WORLD_TOKENS', 2_000),
            'cache_seconds' => 120,
            'callback_every_minutes' => (int) env('TV_COMMENTARY_CALLBACK_EVERY_MINUTES', 12),
            'callback_repeat_hours' => 6,
            'callback_sentences' => ['min' => 2, 'max' => 4],
        ],

        // Anthropic's prompt cache for the caster's model calls (a prefix match: tools, system, messages; one changed byte
        // re-bills everything behind it). The system prompt (caster guide, tone, personas) is the static prefix, cached for
        // `static_ttl` ("1h" or "5m"; measured 2026-10-12: 43 gaps of 5 to 60 minutes between scripts at night re-wrote
        // the 5-minute entry each time). The world memory's items older than the start of the current `world_epoch_minutes`
        // epoch follow as a second cached system block, frozen for the epoch (WorldMemory::epochDigest()); the items since
        // then and the games on now stay in the uncached user message. 0: the whole world block in the user message.
        'cache' => [
            'static_ttl' => (string) env('TV_COMMENTARY_CACHE_STATIC_TTL', '1h'),
            'world_epoch_minutes' => (int) env('TV_COMMENTARY_CACHE_WORLD_EPOCH_MINUTES', 10),
        ],

        // The latest EINUNDZWANZIG podcast episode as a transcript (App\Support\Caster\PodcastTranscripts): the workstation
        // (`caster:podcast-transcript`, started by `caster:fetch` at most every `check_minutes` outside the quiet hours)
        // downloads the newest episode, transcribes it with the local VoiceStudio Whisper in `chunk_seconds` pieces, deletes
        // the audio and uploads the text (at most `max_bytes`). The server keeps the latest episode only; its digest (one
        // Haiku call per episode, at most `digest_tokens`) goes into the cached system prompt of every TV call.
        'podcast' => [
            'transcribe' => (bool) env('TV_PODCAST_TRANSCRIBE', true),
            'transcribe_url' => env('TV_WHISPER_URL', 'http://127.0.0.1:3900/v1/audio/transcriptions'),
            'transcribe_timeout_seconds' => 1_800,
            'chunk_seconds' => 600,
            'max_audio_bytes' => 400 * 1024 * 1024,
            'max_bytes' => 400 * 1024,
            'digest_tokens' => 2_500,
            'digest_retry_minutes' => 30,
            'check_minutes' => 60,
        ],

        // Podcast replay (plan "TWENTY ONE Radio", P12; user 2026-10-12: "lass jeweils die letzten Podcast-Folgen von
        // EINUNDZWANZIG und Blocktrainer Re:Sat nutzen und pro Tag abspielen … Dabei soll die BG-Musik natürlich voll
        // geduckt sein"). The server (`caster:podcasts`, App\Support\Caster\PodcastLibrary) reads each enabled source's feed
        // at most every `check_minutes`, downloads the newest episode (title matching `title_pattern`, when set), levels it
        // to the voice's loudness under `nice` and keeps it as Opus chunks of about `chunk_seconds`, cut at pauses, in `dir`
        // on the spool disk: the newest episode per source, at most `max_bytes` in all. Once a day per source it airs from
        // `starts_at` (Berlin) on (App\Support\Caster\PodcastReplay): the music in news mode the whole time, the bulletins
        // on time (the episode stops at the pause nearest the slot within `pause_window_seconds` and resumes after
        // bulletin, sport and weather), chat replies in the bulletin break or after max_hold_seconds at a pause, only the
        // Anchor's podcast opener before and back-announce after. Meanwhile the queue writes no slide scenes, teasers or
        // talk and the workstation's GPU renders day stock (`gpu` stock) or idles (`gpu` idle).
        'podcast_replay' => [
            'enabled' => (bool) env('TV_PODCAST_REPLAY', true),
            // Podcasts start only in this window (Berlin; user 2026-10-15: "Die Podcast Wiederholungen sollen nur zwischen
            // 24 Uhr und 09 Uhr morgens laufen"): a day slot (`starts_at`) outside it never airs and gets no promo, the
            // autopilot and the night loop start no episode outside it (PodcastLibrary::airsAt()). The same from and to: any time.
            'window' => ['from' => env('TV_PODCAST_REPLAY_WINDOW_FROM', '00:00'), 'to' => env('TV_PODCAST_REPLAY_WINDOW_TO', '09:00')],
            'dir' => 'caster/podcasts',
            'sources' => [
                // The user hosts it (EINUNDZWANZIG main feed: all its shows, the newest episode).
                'einundzwanzig' => [
                    'enabled' => (bool) env('TV_PODCAST_REPLAY_EINUNDZWANZIG', true),
                    'name' => 'EINUNDZWANZIG Podcast',
                    // How Markus and Sirius name it (PodcastScripts).
                    'spoken' => 'EINUNDZWANZIG Podcast',
                    'feed' => 'https://anchor.fm/s/d8d3c38/podcast/rss',
                    'title_pattern' => null,
                    'starts_at' => env('TV_PODCAST_REPLAY_EINUNDZWANZIG_AT', '14:03'),
                ],
                // Third-party content: only with Blocktrainer's permission. The user named the show "Re:Sat"; in the
                // Blocktrainer feed it is titled "Re:set #19 …" (no "Re:sat" title among 202 items, measured 2026-10-12),
                // so the pattern takes both spellings and leaves out the Wochenrückblick and the interviews.
                'resat' => [
                    'enabled' => (bool) env('TV_PODCAST_REPLAY_RESAT', false),
                    'name' => 'Blocktrainer Re:set',
                    'spoken' => 'Blocktrainer-Podcast Reset',
                    'feed' => 'https://serve.podhome.fm/rss/9b654631-a45c-58e4-a237-2e8aa65262b8',
                    'title_pattern' => '/^Re:s[ae]t\b/i',
                    // Only Markus Turm and DerPhiL (user 2026-10-13: "nur Folgen mit MarkusTurm und DerPhiL und keine anderen"):
                    // both named in the notes, and none with other voices (the event special, "Stimmen" from a conference,
                    // "Bonusmaterial", guests, interviews; measured on the feed 2026-10-13: #19, #15, #14 leave).
                    'require_pattern' => '/(?=.*\bMarkus)(?=.*PhiL\b)/isu',
                    'exclude_pattern' => '/Special|Stimmen|Bonusmaterial|\bzu Gast\b|\bGäste\b|\bGast:|Interview/iu',
                    'starts_at' => env('TV_PODCAST_REPLAY_RESAT_AT', '16:03'),
                ],
            ],
            'check_minutes' => 60,
            'feed_max_bytes' => 12 * 1024 * 1024,
            // Episodes run one to two and a half hours: about 100 to 140 MB of MP3 (measured 2026-10-12).
            'max_download_bytes' => 400 * 1024 * 1024,
            'download_timeout_seconds' => 900,
            'transcode_timeout_seconds' => 1_800,
            'opus_kbps' => 32,
            // A pause: at least `pause_seconds` below `pause_db` (ffmpeg silencedetect on the levelled episode). Measured on
            // episode #335 (2026-10-12): -35 dB for 0.35 s left 6.2 % of the slot seconds without a pause within 20 s and gaps
            // of up to 63 s in speech; -32 dB for 0.3 s leaves only its 233 s music outro (5.0 %), speech gaps at most 29 s.
            'pause_db' => -32,
            'pause_seconds' => 0.3,
            'chunk_seconds' => 300,
            'max_bytes' => (int) env('TV_PODCAST_REPLAY_MAX_BYTES', 1024 * 1024 * 1024),
            // The airing goes into the spool this long before `starts_at`, and not later than `late_minutes` after it.
            'ahead_minutes' => 15,
            'late_minutes' => 60,
            // A bulletin stops the episode at the pause nearest its slot, at most this far before or after it.
            'pause_window_seconds' => 20,
            // The rest of a chunk cut for the news or a reply resumes within this long (bulletin, sport and weather, replies).
            'resume_within_seconds' => 1_800,
            // What the workstation's GPU does meanwhile: `stock` renders day stock, `idle` waits.
            'gpu' => env('TV_PODCAST_REPLAY_GPU', 'stock'),
            // Markus and Sirius promote it (user 2026-10-12: "müssen auch regelmäßig angekündigt werden von KI-Markus und
            // Sirius und bevor sie gespielt werden auch explizit"): a promo duet (a teaser) from `from` (Berlin) on at most
            // every `every_minutes` and once more in the hour before the slot, only while today's airing really follows
            // (enabled source, stored episode, not aired yet), up to `until_minutes_before` the slot; each airs within
            // `expire_minutes`. Right before the episode their announcement duet, after it Markus' back-announce.
            'promos' => [
                'enabled' => (bool) env('TV_PODCAST_REPLAY_PROMOS', true),
                'from' => '09:00',
                'every_minutes' => (int) env('TV_PODCAST_REPLAY_PROMO_EVERY_MINUTES', 120),
                'until_minutes_before' => 10,
                'expire_minutes' => 30,
                'priority' => 72,
            ],
            'scripts' => [
                'daily_cap' => 30,
                'timeout_seconds' => 60,
                // Above the bulletin's writing, so the announcement is voiced before the episode starts.
                'priority' => 76,
                'tries' => 3,
            ],
            // The night loop (PodcastNight; user 2026-10-12 23:30: "warum nicht heute Nacht ausnahmsweise mehrere
            // Podcastfolgen im Loop … weil heute Nacht dazu Zeit ist"): from `from` to `to` (Berlin) the `episodes` newest
            // episodes of each of `sources` back to back, newest first, each once a night, every one between the podcast
            // opener and the back-announce (no announcement duet at night). The next episode goes into the spool once the
            // one on air has at most `queue_ahead_seconds` left; none starts that would end more than `overrun_minutes`
            // after `to`. Only until `until` (Berlin, empty: every night), so "just tonight" needs no switch back.
            // `skip_daytime_episode` leaves out the episode the day's slot airs. The library keeps `episodes + 1` per
            // source (within `max_bytes`) and stores up to `prefetch_per_run` missing ones per feed read.
            'night' => [
                'enabled' => (bool) env('TV_PODCAST_NIGHT', true),
                'from' => env('TV_PODCAST_NIGHT_FROM', '00:00'),
                'to' => env('TV_PODCAST_NIGHT_TO', '09:00'),
                'until' => env('TV_PODCAST_NIGHT_UNTIL', '2026-10-13 09:00'),
                'episodes' => (int) env('TV_PODCAST_NIGHT_EPISODES', 6),
                'sources' => array_values(array_filter(array_map('trim', explode(',', (string) env('TV_PODCAST_NIGHT_SOURCES', 'einundzwanzig'))))),
                'skip_daytime_episode' => (bool) env('TV_PODCAST_NIGHT_SKIP_DAYTIME', false),
                'queue_ahead_seconds' => 900,
                // Music between two episodes for this many minutes (0: back to back).
                'break_minutes' => (int) env('TV_PODCAST_NIGHT_BREAK_MINUTES', 0),
                // No episode that would run past `to` (user 2026-10-15: podcasts only until 09:00).
                'overrun_minutes' => 0,
                'prefetch_per_run' => 6,
            ],
        ],
    ],

];
