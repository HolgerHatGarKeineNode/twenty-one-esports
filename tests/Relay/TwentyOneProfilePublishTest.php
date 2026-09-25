<?php

use App\Support\TwentyOne\EventBuilder;
use App\Support\TwentyOne\RelayPublisher;
use App\Support\TwentyOne\TwentyOneSigner;
use swentel\nostr\Key\Key;
use Tests\Support\TestSigner;
use WebSocket\Client;
use WebSocket\Message\Text;

test('a published TWENTY ONE profile can be read back from the local relay', function () {
    $key = new TestSigner;
    $signer = TwentyOneSigner::fromNsec((new Key)->convertPrivateKeyToBech32($key->secret));
    $event = $signer->sign((new EventBuilder)->profile(['name' => 'relay test '.bin2hex(random_bytes(4))]));

    $results = (new RelayPublisher)->publish($event, ['ws://127.0.0.1:7777'], 3);

    expect($results['ws://127.0.0.1:7777']->accepted)->toBeTrue();

    $client = (new Client('ws://127.0.0.1:7777'))->setTimeout(3);
    $client->text(json_encode(['REQ', 'readback', ['ids' => [$event['id']]]]));
    $received = null;

    while (($message = $client->receive()) instanceof Text) {
        $answer = json_decode($message->getContent(), true);

        if ($answer[0] === 'EVENT') {
            $received = $answer[2];
        }

        if ($answer[0] === 'EOSE') {
            break;
        }
    }

    $client->disconnect();

    expect($received)->toEqual($event);
});
