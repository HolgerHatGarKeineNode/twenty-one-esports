<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The broadcast design system (plan "OBS-Broadcast-Overlays", P1): one admin page with the type, colours, curves,
 * slots and tempo of the OBS overlays, and the engine running its demo program live (resources/js/broadcast/).
 * `?stage=1` shows the stage alone on a transparent page, as an OBS browser source would; `?tier=` forces a quality
 * tier, `?sound=1` enables the stinger sounds without a click.
 */
class BroadcastStyleguideController extends Controller
{
    /** The asset pack under public/broadcast/art, by id (tools/broadcast-art/manifest.json). */
    public const array ART = [
        'trophy', 'crown', 'medal-gold', 'medal-silver', 'medal-bronze', 'rank-up',
        'emblem-chess', 'emblem-rocket-league', 'emblem-football', 'emblem-age-of-empires-2', 'emblem-tmnf',
        'emblem-pong', 'emblem-hyper', 'emblem-blockfill', 'emblem-checkers', 'emblem-mill', 'emblem-blockli',
        'energy-wide', 'energy-rays',
    ];

    public function __invoke(Request $request): View
    {
        return view('broadcast.styleguide', [
            'stageOnly' => $request->query('stage') === '1',
            'config' => [
                'art' => collect(self::ART)->mapWithKeys(fn (string $id): array => [$id => '/broadcast/art/'.$id.'.webp'])->all(),
                'demo' => $this->demo(),
                'texts' => $this->texts(),
            ],
        ]);
    }

    /**
     * The demo program's copy: invented players and events, in the viewer's language.
     *
     * @return array<string, mixed>
     */
    private function demo(): array
    {
        return [
            'lower' => [
                ['name' => 'satsjaeger', 'line' => __('Wins the Rocket League final 3 to 1')],
                ['name' => __('Saturday Cup'), 'line' => __('Chess blitz, sign-up open until 6 pm')],
            ],
            'prideRight' => ['name' => 'Hodlbert', 'line' => __('Climbs from 14th to 3rd place'), 'context' => __('Proof of Pong ladder, this week')],
            'prideLeft' => ['name' => 'Satoshis Stack', 'line' => __('Champions of the Autumn Cup'), 'context' => __('Age of Empires II, 8 teams')],
            'tickerLabel' => __('Live'),
            'ticker' => [
                __('Next cup: Saturday at 6 pm, Rocket League'),
                __('Chess blitz arena is open now'),
                __('Prize pot this week: 210,000 sats'),
                __('Join the league for free on the website'),
                __('TrackMania week: the fastest lap wins'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function texts(): array
    {
        return [
            'words' => __('words'),
            'ruleHold' => __('Text holds still after its build-in'),
            'ruleIntro' => __('Build-in'),
            'ruleOutro' => __('Build-out'),
            'rulePride' => __('Pride moment in total'),
            'ruleTicker' => __('Ticker speed at 1080p'),
            'ruleRotation' => __('Elements in one slot change at most every'),
            'typeSample' => [
                'hero' => __('Season final'),
                'headline' => 'Hodlbert',
                'title' => 'satsjaeger',
                'line' => __('Climbs from 14th to 3rd place'),
                'data' => __('Proof of Pong ladder, this week'),
                'crawl' => __('Next cup: Saturday at 6 pm, Rocket League'),
                'tag' => __('Live'),
            ],
            'curves' => [
                'set' => __('A block being placed: fast start, long settle, a slight overshoot.'),
                'reveal' => __('Text uncovering: steady, never wobbles.'),
                'leave' => __('Build-out: accelerates away, shorter than the way in.'),
                'sweep' => __('Light passing over glass and metal.'),
                'drift' => __('Background only: particles and light plates.'),
            ],
            'slots' => [
                'centre' => __('Free for the stream'),
                'cornerLeft' => __('Pride moment, left'),
                'cornerRight' => __('Pride moment, right'),
                'lowerThird' => __('Lower third'),
                'ticker' => __('Ticker'),
            ],
            'slotsLabel' => __('The 1920 by 1080 frame with its title-safe area and slots'),
            'kinds' => ['lowerThird' => __('Lower third'), 'pride' => __('Pride moment'), 'ticker' => __('Ticker'), 'stinger' => __('Stinger')],
            'phases' => ['before' => __('planned'), 'intro' => __('build-in'), 'hold' => __('holding'), 'outro' => __('build-out'), 'after' => __('done')],
        ];
    }
}
