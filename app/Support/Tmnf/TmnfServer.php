<?php

namespace App\Support\Tmnf;

/**
 * The league's session on its TMNF dedicated server (plan "Trackmania und
 * Restposten", P1): connect, log in at the configured level, switch the
 * callbacks on, and the few calls the league needs. One connection; the
 * listener opens a new one after it broke.
 *
 * Settings: `esports.tmnf.xmlrpc` (host, port, user, password, timeout).
 */
final class TmnfServer
{
    private function __construct(private GbxRemote $remote) {}

    /**
     * Connects, authenticates and enables callbacks.
     *
     * @throws GbxUnavailable when the server cannot be reached or no password is set
     * @throws GbxFault when the login is refused
     * @throws GbxProtocolError
     */
    public static function open(): self
    {
        $config = (array) config('esports.tmnf.xmlrpc', []);
        $password = (string) ($config['password'] ?? '');

        if ($password === '') {
            // Fail closed: no SuperAdmin password, no session (never a call with an empty one).
            throw new GbxUnavailable('TMNF_XMLRPC_PASSWORD is not set.');
        }

        $timeout = (float) ($config['timeout_seconds'] ?? 5);
        $server = new self(GbxRemote::connect((string) ($config['host'] ?? '127.0.0.1'), (int) ($config['port'] ?? 5005), $timeout));
        $server->authenticate((string) ($config['user'] ?? 'SuperAdmin'), $password);
        $server->enableCallbacks();

        return $server;
    }

    /**
     * A session over an existing connection (tests replay recorded frames through it).
     */
    public static function over(GbxRemote $remote): self
    {
        return new self($remote);
    }

    /**
     * @throws GbxFault when the password is wrong ("Password incorrect.")
     */
    public function authenticate(string $user, string $password): void
    {
        if ($this->remote->call('Authenticate', [$user, $password]) !== true) {
            throw new GbxFault('The server did not accept the login.');
        }
    }

    public function enableCallbacks(bool $enabled = true): void
    {
        if ($this->remote->call('EnableCallbacks', [$enabled]) !== true) {
            throw new GbxFault('The server did not switch the callbacks.');
        }
    }

    public function currentChallenge(): TmnfChallenge
    {
        return TmnfChallenge::fromStruct($this->remote->call('GetCurrentChallengeInfo'));
    }

    /**
     * The current round's ranking (SPlayerRanking: Login, NickName, PlayerId,
     * Rank, BestTime in ms, BestCheckpoints, Score, ...), best first.
     *
     * @return list<array<string, mixed>>
     */
    public function currentRanking(int $max = 50, int $offset = 0): array
    {
        $ranking = $this->remote->call('GetCurrentRanking', [$max, $offset]);

        if (! is_array($ranking) || ! array_is_list($ranking)) {
            throw new GbxProtocolError('GetCurrentRanking did not answer with a list.');
        }

        return array_values(array_filter($ranking, is_array(...)));
    }

    /**
     * The tracks in the server's selection (SChallengeInfo each: Name, UId,
     * FileName, Environnement, Author, GoldTime, CopperPrice), from `$start`.
     *
     * @return list<array<string, mixed>>
     */
    public function challengeList(int $max = 100, int $start = 0): array
    {
        $list = $this->remote->call('GetChallengeList', [$max, $start]);

        if (! is_array($list) || ! array_is_list($list)) {
            throw new GbxProtocolError('GetChallengeList did not answer with a list.');
        }

        return array_values(array_filter($list, is_array(...)));
    }

    /**
     * A track file on the server, read without playing it (GetChallengeInfo):
     * `$file` relative to GameData/Tracks, e.g. `Campaigns\Nations\White\A02-Race.Challenge.Gbx`.
     */
    public function challengeInfo(string $file): TmnfChallenge
    {
        return TmnfChallenge::fromStruct($this->remote->call('GetChallengeInfo', [$file]));
    }

    /**
     * Puts a track file into the selection right after the current track (InsertChallenge).
     *
     * @throws GbxFault when the server refuses it (no such file, already in the selection)
     */
    public function insertChallenge(string $file): void
    {
        $this->expectTrue('InsertChallenge', [$file]);
    }

    /**
     * Makes a track of the selection the next one (ChooseNextChallenge).
     */
    public function chooseNextChallenge(string $file): void
    {
        $this->expectTrue('ChooseNextChallenge', [$file]);
    }

    /**
     * Ends the current round and loads the next track now (NextChallenge); BeginChallenge follows.
     */
    public function nextChallenge(): void
    {
        $this->expectTrue('NextChallenge');
    }

    /**
     * Plays the current track again from the start (RestartChallenge): a new time limit applies then.
     */
    public function restartChallenge(): void
    {
        $this->expectTrue('RestartChallenge');
    }

    /**
     * Takes a track out of the selection (RemoveChallenge); never the one being played.
     */
    public function removeChallenge(string $file): void
    {
        $this->expectTrue('RemoveChallenge', [$file]);
    }

    /**
     * The time attack limit of a round in ms (GetTimeAttackLimit, the
     * `CurrentValue` of {CurrentValue, NextValue}).
     */
    public function timeAttackLimit(): int
    {
        $limit = $this->remote->call('GetTimeAttackLimit');

        if (! is_array($limit) || ! is_int($limit['CurrentValue'] ?? null)) {
            throw new GbxProtocolError('GetTimeAttackLimit did not answer with {CurrentValue, NextValue}.');
        }

        return $limit['CurrentValue'];
    }

    /**
     * The time attack limit of the next round in ms (SetTimeAttackLimit: it applies from the next track on).
     */
    public function setTimeAttackLimit(int $milliseconds): void
    {
        $this->expectTrue('SetTimeAttackLimit', [$milliseconds]);
    }

    /**
     * @param  list<mixed>  $params
     *
     * @throws GbxFault when the server answers with anything but true
     */
    private function expectTrue(string $method, array $params = []): void
    {
        if ($this->remote->call($method, $params) !== true) {
            throw new GbxFault("The server did not accept {$method}.");
        }
    }

    /**
     * A line in the server chat, from the server.
     */
    public function chat(string $message): void
    {
        $this->remote->call('ChatSendServerMessage', [mb_substr($message, 0, 200)]);
    }

    /**
     * A line in the chat of one player only.
     */
    public function chatTo(string $login, string $message): void
    {
        $this->remote->call('ChatSendServerMessageToLogin', [mb_substr($message, 0, 200), $login]);
    }

    /**
     * Shows a page of manialinks (TmnfManialinks) to everyone, or to one login.
     * Timeout 0: it stays until a manialink with the same id replaces or
     * removes it; false: a click does not hide it.
     *
     * @throws GbxFault e.g. for a login that is not on the server
     */
    public function showPage(string $xml, ?string $login = null): void
    {
        $answer = $login === null
            ? $this->remote->call('SendDisplayManialinkPage', [$xml, 0, false])
            : $this->remote->call('SendDisplayManialinkPageToLogin', [$login, $xml, 0, false]);

        if ($answer !== true) {
            throw new GbxFault('The server did not show the manialink page.');
        }
    }

    /**
     * The logins of the players on the server (GetPlayerList, Forever structs without the server itself).
     *
     * @return list<string>
     */
    public function players(int $max = 255): array
    {
        $players = $this->remote->call('GetPlayerList', [$max, 0, 1]);

        if (! is_array($players) || ! array_is_list($players)) {
            throw new GbxProtocolError('GetPlayerList did not answer with a list.');
        }

        return array_values(array_filter(array_map(fn (mixed $player): ?string => is_array($player) && is_string($player['Login'] ?? null) && $player['Login'] !== '' ? $player['Login'] : null, $players)));
    }

    /**
     * @return list<TmnfCallback>
     */
    public function callbacks(float $wait = 1.0): array
    {
        return $this->remote->callbacks($wait);
    }

    public function close(): void
    {
        $this->remote->close();
    }
}
