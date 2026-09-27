<?php

use App\Support\TwentyOne\Stream\PublishSchedule;
use App\Support\TwentyOne\Stream\ViewerCounter;

/**
 * One datagram as nginx sends it with `nohostname,tag=hls` and the
 * `$remote_addr|$http_user_agent|$status` format.
 */
function hlsLine(string $address, string $agent = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/131.0', string $status = '200'): string
{
    return "<190>Sep 27 12:00:00 hls: {$address}|{$agent}|{$status}";
}

test('a viewer counts for the window after its last playlist request, once per address and agent', function () {
    $counter = new ViewerCounter(20);

    $counter->record(hlsLine('203.0.113.1'), 100);
    $counter->record(hlsLine('203.0.113.1'), 101);
    $counter->record(hlsLine('203.0.113.1', 'VLC/3.0.20 LibVLC/3.0.20'), 102);
    $counter->record(hlsLine('203.0.113.2'), 110);

    expect([$counter->count(110), $counter->count(120), $counter->count(121), $counter->count(122), $counter->count(129), $counter->count(130)])
        ->toBe([3, 3, 2, 1, 1, 0])
        ->and($counter->size())->toBe(0);

    // A new request refreshes the sighting.
    $counter->record(hlsLine('203.0.113.3'), 200);
    $counter->record(hlsLine('203.0.113.3'), 215);

    expect($counter->count(234))->toBe(1)
        ->and($counter->count(235))->toBe(0);

    // A refreshed viewer moves behind the others, so it cannot keep an expired one alive.
    $counter->record(hlsLine('203.0.113.4'), 300);
    $counter->record(hlsLine('203.0.113.5'), 305);
    $counter->record(hlsLine('203.0.113.4'), 315);

    expect($counter->count(326))->toBe(1);
});

test('only answers that delivered the playlist count', function () {
    $counter = new ViewerCounter(20);
    $counted = [];

    foreach (['200', '206', '304', '404', '403', '500', '301', '2000', '20', ''] as $index => $status) {
        $counted[$status] = $counter->record(hlsLine('198.51.100.'.($index + 1), status: $status), 0);
    }

    expect(array_keys(array_filter($counted)))->toBe([200, 206, 304])
        ->and($counter->count(0))->toBe(3);
});

test('our own probes, crawlers and monitors never count, and the list is configurable', function () {
    $agents = [
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/131.0.0.0 Safari/537.36',
        'Playwright/1.48',
        'curl/8.10.1',
        'Wget/1.24.5',
        'python-requests/2.32.3',
        'Go-http-client/2.0',
        'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)',
        'Baiduspider',
        'Better Uptime Monitor',
    ];
    $default = new ViewerCounter(20);

    foreach ($agents as $index => $agent) {
        $default->record(hlsLine('192.0.2.'.($index + 1), $agent), 0);
    }

    $default->record(hlsLine('192.0.2.200', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148'), 0);

    $custom = new ViewerCounter(20, '/Firefox/');
    $custom->record(hlsLine('192.0.2.1', 'curl/8.10.1'), 0);
    $custom->record(hlsLine('192.0.2.2'), 0);

    $none = new ViewerCounter(20, null);
    $none->record(hlsLine('192.0.2.1', 'curl/8.10.1'), 0);

    expect($default->count(0))->toBe(1)
        ->and($custom->count(0))->toBe(1)
        ->and($none->count(0))->toBe(1);
});

test('the syslog frame is read with a padded day, a host, IPv6, a pipe in the agent and a trailing newline', function () {
    $counter = new ViewerCounter(20);

    $lines = [
        '<190>Sep  7 09:05:03 hls: 203.0.113.9|Mozilla/5.0 Firefox/131.0|200',
        '<14>Jan 31 23:59:59 web-1 hls: 203.0.113.10|Mozilla/5.0 Firefox/131.0|206',
        '<190>Sep 27 12:00:00 nginx: 2001:db8::1|Mozilla/5.0 Firefox/131.0|304',
        '<190>Sep 27 12:00:00 hls: 203.0.113.11|Odd|Agent|With|Pipes|200',
        "<190>Sep 27 12:00:00 hls: 203.0.113.12|Mozilla/5.0 Firefox/131.0|200\n",
        '<190>Sep 27 12:00:00 hls: 203.0.113.13||200',
    ];

    $counted = array_map(fn (string $line): bool => $counter->record($line, 0), $lines);

    expect($counted)->toBe([true, true, true, true, true, true])
        ->and($counter->count(0))->toBe(6);
});

test('garbage is ignored instead of counted', function () {
    $counter = new ViewerCounter(20, null);

    $garbage = [
        '',
        'hello',
        '203.0.113.1|Mozilla/5.0|200',
        '<190>Sep 27 12:00:00 hls: ',
        '<190>Sep 27 12:00:00 hls: 203.0.113.1|200',
        '<190>Sep 27 12:00:00 hls: not-an-address|Mozilla/5.0|200',
        '<190>Sep 27 12:00:00 hls: 999.1.1.1|Mozilla/5.0|200',
        '<190>Sep 27 12:00:00 hls: 203.0.113.1|Mozilla/5.0|200 extra',
        '<190>27 Sep 12:00:00 hls: 203.0.113.1|Mozilla/5.0|200',
        '<1900>Sep 27 12:00:00 hls: 203.0.113.1|Mozilla/5.0|200',
        "\x00\xff\xfe<190>".random_bytes(64),
        str_repeat('|', 5000),
    ];

    $counted = array_filter(array_map(fn (string $line): bool => $counter->record($line, 0), $garbage));

    expect($counted)->toBe([])
        ->and($counter->count(0))->toBe(0);
});

test('a flood of new addresses cannot grow the map beyond its cap: the oldest sighting goes first', function () {
    $counter = new ViewerCounter(20, null, 100);

    foreach (range(0, 99) as $index) {
        $counter->record(hlsLine('10.0.0.'.$index), 0);
    }

    // 10.0.0.0 is seen again, so 10.0.0.1 is now the oldest and makes room for the new one.
    $counter->record(hlsLine('10.0.0.0'), 1);
    $counter->record(hlsLine('10.0.1.0'), 1);

    expect($counter->size())->toBe(100)
        // The rest (seen at 0) is out of the window at 20; the two seen at 1 are not.
        ->and($counter->count(20))->toBe(2);

    // RSS: 50 000 distinct addresses leave 100 entries, not 50 000.
    foreach (range(0, 49_999) as $index) {
        $counter->record(hlsLine(long2ip(0x0B000000 + $index)), 30);
    }

    expect($counter->size())->toBe(100)
        ->and($counter->count(30))->toBe(100);
});

test('a changed viewer count is republished at most once a minute', function () {
    $schedule = new PublishSchedule(1200, 60);
    $three = ['title' => 'loop', 'summary' => 'loop', 'viewers' => 3];
    $four = [...$three, 'viewers' => 4];

    $schedule->published($three, 0);

    expect([$schedule->due($three, 59), $schedule->due($four, 59), $schedule->due($four, 60)])->toBe([false, false, true]);
});
