<?php

namespace App\Support\Tmnf;

/**
 * A track ("challenge") as the server describes it (SChallengeInfo of
 * GetCurrentChallengeInfo, BeginChallenge, EndRace): its UID is the course
 * id of a league week, the times are in milliseconds.
 */
final readonly class TmnfChallenge
{
    public function __construct(
        public string $uid,
        public string $name,
        public string $fileName,
        public string $author,
        public string $environment,
        public int $authorTime,
        public int $goldTime,
        public int $checkpoints,
    ) {}

    /**
     * @throws GbxProtocolError when the struct is not a challenge info
     */
    public static function fromStruct(mixed $info): self
    {
        if (! is_array($info) || ! is_string($info['UId'] ?? null) || $info['UId'] === '') {
            throw new GbxProtocolError('Not a challenge info.');
        }

        return new self(
            $info['UId'],
            is_string($info['Name'] ?? null) ? $info['Name'] : '',
            is_string($info['FileName'] ?? null) ? $info['FileName'] : '',
            is_string($info['Author'] ?? null) ? $info['Author'] : '',
            // The server spells it "Environnement".
            is_string($info['Environnement'] ?? null) ? $info['Environnement'] : '',
            is_int($info['AuthorTime'] ?? null) ? $info['AuthorTime'] : 0,
            is_int($info['GoldTime'] ?? null) ? $info['GoldTime'] : 0,
            is_int($info['NbCheckpoints'] ?? null) ? $info['NbCheckpoints'] : 0,
        );
    }
}
