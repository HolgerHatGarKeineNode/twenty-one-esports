NIP-XX
======

Esports Ladder: Clans, Lineups, Challenges and League Attestations
------------------------------------------------------------------

`draft` `optional`

**Status of this document:** project draft for EINUNDZWANZIG Esports V1, first written 2026-09-25,
revised the same day (round 2: goals per game optional, who played, seasons as rating epochs,
tournament reference and draw, clan tag, no-show; chess as the first game, rated per player,
with NIP-64 game records and correspondence moves; round 3: a trust gate against rating farming,
league-wide seasons, a global score; round 4, **revision 4**: the league match number, queue
pairings, mix-team sides, tournaments as NIP-52 calendar events with a solo-pool draw, the inputs for
clan hashrate, a public anchor list, league discovery, the league relay policy, NIP-17 chat and
notifications, prize-pool zaps; round 5, **revision 5**: the season chain, pots and zap targets,
clan ownership, 21 rank tiers, rank badges, bounties; round 6, **revision 6** (2026-09-25): clan
invitations into the roster only, the membership as consent to lineups, the wording of consensus
rule 7; **revision 7** (2026-09-26): running tournaments, with the sign-up consent `22150`, director
results, tournaments before Block 0, and no blocks from tournaments; **revision 7.1** (2026-09-26):
director forfeits unrated, disinterested directors, Rocket League 1v1 as a player ladder;
**revision 8** (2026-09-26): rank badges from rated ladders only, the badge artwork URL, the profile
badge list written by the app, share posts). Not
submitted to
`nostr-protocol/nips`. Kind
numbers are checked against the official NIP index and other registries (see
[Kind numbers and collision check](#kind-numbers-and-collision-check)); every example in this
document is a real signed event that was published to and read back from local relays
(`docs/plans/2026-09-25T1212-esports-v1-ladder/p1-relay-proof.md`, rounds 1 to 6). Revision 7 adds
no example yet, and neither does revision 8 (see [Open points](#open-points)).

**Revisions.** A ladder that carries `hashrate` is a **revision-4 ladder**, and every event that
references it follows revision 4 (the rules marked "rev. 4" below). Ladders without `hashrate`
follow revision 3. The examples of rounds 1 to 3 are revision-3 events and stay as they were signed;
the round-4 examples open season 4 and are revision 4 throughout. A league adopts revision 4 with a
new season, because `hashrate` is a frozen season parameter. A revision-4 ladder that also names a
Season Genesis (`2156`) by `e` is a **revision-5 ladder**, and its season is a chain season (the rules
marked "rev. 5"). Rules marked "rev. 5" that do not depend on a ladder (clan ownership, lineup
signer, rank tiers, badges, bounties, pots) apply from the day a league adopts revision 5. The rules
marked "rev. 6" (clans and lineups only; no ladder depends on them) apply from the day a league adopts
revision 6. The rules marked "rev. 7" concern tournaments only; they apply to every tournament whose
first `31923` version the league signs after it adopts revision 7. The rules marked "rev. 7.1" on
director results apply with revision 7; the one on Rocket League 1v1 applies to every
`rocket-league/1v1` ladder whose first version is signed after the league adopts revision 7.1,
because `rates` is a frozen ladder parameter. The rules marked "rev. 8" concern badges, the
profile badge list and share posts; no ladder depends on them, and they apply from the day a league
adopts revision 8.

### Changelog of revision 8 (2026-09-26)

- **Rank badges come from the rated ladder only** ([Rank badges](#rank-badges-rev-5)): the tier of a
  badge is the entity's tier on the rated ladder of the live season; a casual rating has no tier and
  never signs a badge. A version is signed only when tier or season change; a rating change inside
  the same tier signs nothing. The `d` value stays `rank/<game>/<mode>/<player pubkey>` of revision 5
  (the plan's earlier `rank/<game>-<mode>/…` is not used), and the signer stays the badge key.
- **Exactly one award, stated as an invariant**: a league MUST NOT sign a second `8` with the same
  `a` and `p`; a new badge key is a new definition address and gets one award of its own.
- **Badge artwork URL**: `<site>/badges/rank/<game>/<tier>-v<artwork>.png` (`image`, 1024 × 1024) and
  `…-v<artwork>-256.png` (`thumb`, 256 × 256), a function of game, tier and artwork version only.
- **Profile badge list** ([Profile](#rank-badges-rev-5)): read only after a write relay's `EOSE`,
  events verified before they are compared, the newest valid list wins, a `30008` merged only when
  it is the newer list, relays first and the league second; new validation rule 36.
- **Share posts** (new section [Share posts (rev. 8)](#share-posts-rev-8)): a kind `1` note the player
  signs, with a share card of the league (NIP-92 `imeta`); new validation rule 37. Rule 35 states the
  badge rules above.

### Changelog of revision 7.1 (2026-09-26)

- **Director forfeits are unrated** ([Director results](#director-results-rev-7)): a no-show or forfeit
  entered by a tournament director is attested with `resolution` `forfeit` and **no `elo`**; it moves
  no rating, counts for no Block Height and no clan hashrate. Rating step 4 and validation rule 16
  amended.
- **Only a disinterested director enters a rated result**: whoever plays in the match, belongs to a
  clan in it, or was appointed along the chain of appointments by someone who does, may not enter or
  correct it; an admin only for their own interest.
- **Rocket League 1v1 is a player ladder** ([Rocket League 1v1](#rocket-league-1v1-rev-71)): `rates`
  `player`, one entity per player (the pubkey), whether the player plays for a clan's 1v1 lineup or
  from a tournament's solo entry. A side without a lineup is a `p` side, as in a solo chess game. The
  open point "Rocket League 1v1" is settled.
- Validation rule 16: an `elo` "before" value follows the entity's last attestation **with an `elo`
  row for it**, which also covers the unrated attestations that already existed.

### Changelog of revision 7 (2026-09-26)

- **Tournament Consent** (new ephemeral kind `22150`, [Tournament Consent](#tournament-consent-22150)):
  a sign-up or withdrawal is signed by the player (solo) or an acting captain (lineup), checked and
  stored by the league and **never published**. It replaces the NIP-98 event (`27235`) that an early
  implementation used: NIP-98 authorises one HTTP request, and signers show and pre-approve it as
  "HTTP Authentication", not as a consent.
- **Director results** ([Director results](#director-results-rev-7)): in a tournament whose results are
  entered by its directors, the attestation carries `entered-by` (the director's pubkey) and the
  tournament `a`, and has no challenge, answer, report or game record behind it; `resolution` is
  `admin` or `forfeit`. Validation rule 16 amended.
- **Tournaments before Block 0** ([Tournaments](#tournaments)): the ladder `a` of a `31923` and of a
  `2155` is optional. It is frozen with the tournament's first version: a tournament published while
  no ladder of its game and mode is open is **unrated** for its whole run. Validation rules 17 and 21
  amended.
- **Tournaments never mine** ([Blocks](#blocks-block-in-2154)): an attestation with a tournament `a`
  carries no `block` tag and is not a candidate; a tournament challenge carries no match-fee `zap`.
  The chain belongs to the season (decision of 2026-09-26). Validation rules 11 and 27 amended.
- **`zap` in a tournament is optional** until the league runs the tournament's prize pool; without it
  the tournament has no pool and the league's LNURL endpoint refuses zaps to it.
- **Draw**: a `2155` needs at least one full team (rule 17 already said so); a solo pool smaller than
  a team is not drawn, which the section now states.
- New tags `action` (`22150`) and `entered-by` (`2154`); validation rule 34; collision check for
  `22150`; no new example yet.

### Changelog of revision 6 (2026-09-25)

- **Invitations are into the clan only** ([Clan](#clan-32150)): listing a pubkey in `32150` invites it
  into the clan's roster, never into a lineup, a mode or a role.
- **The membership is the consent to lineups** ([Lineup](#lineup-32151),
  [Clan Membership](#clan-membership-12150)): an active clan member is an active lineup player of every
  lineup of the clan that lists them, without naming the lineup anywhere. A `12150` carries the clan
  `a` only; lineup `a` tags of earlier revisions are still valid (rule 9) and are ignored. Leaving the
  clan leaves every lineup at once, so a lineup can fall below its size without a new `32151`.
- **Lineups list active members only** (validation rule 8): the owner places members; nobody else
  signs anything for a lineup.
- **Consensus rule 7 compares the two gatekeepers** ([Consensus rules](#consensus-rules-season-chain-v1)),
  as rule 1 does, not every winning and losing player of a series. This corrects the wording of
  revision 5: the revision-5 examples, the sample ledger and the reference engine were computed this
  way, so no revision-5 block changes.
- Round-6 examples ([Revision 6](#revision-6-a-roster-invitation-and-a-lineup-from-members)) and relay
  proof (R6).
- **Invite links and join requests** (added 2026-09-26, no new kind, no change to any event): open
  game links and clan join links are league data ([Invite links](#invite-links)); a join request and a
  captain's yes never become an event, and the owner's clan event stays the only listing
  ([Clan](#clan-32150), "Join requests").

### Changelog of revision 5 (2026-09-25)

- **Season chain** ([Season chain](#season-chain-rev-5)): new regular kind `2156` **Season Genesis**
  (Block 0) with the chain parameters `supply`, `subsidy`, `weight` (per winning player),
  `share`, `daily`, `pairlimit`, `subtree`, `moves`, `halving` (eras by date), `ends` (fixed season
  end), `claim` and `consensus` `season-chain-v1`; released by **one** admin of the league's **admin
  list** (the board, a NIP-51 `30000` signed by the league key) with a NIP-32 label (`1985`,
  `release-block-0`) that carries the parameter digest (`x`); announced as a NIP-52 `31923` with `d` =
  `season/<season>`. The first chain season is the **Pre-Season** (`pre-season`); later seasons are
  never announced before admins schedule them. Supply, subsidy, eras, end and claim window are fixed
  for the season; the consensus parameters can change (`2158`).
- **Blocks**: every rated result with a winner in a chain season carries `block` in its attestation:
  height and link to the previous block, or an empty height and the tip it was checked against.
  Consensus rules 0 to 9, including same anchor subtree (7, threshold `subtree`), blocks per pairing
  and day and per season (4 and 8, `pairlimit`) and a share cap per game and era (9). Rewards, eras and
  reasons are derived, never signed.
- **Parameter changes during a season**: new regular kind `2158` **Parameter Change** (weights, share
  caps, daily caps, pairing limits, rule-7 threshold, minimum moves) with `effective` and the chain
  `tip`, signed by the league key for a listed admin; never retroactive.
- **Fees** on a live match target its challenge; the app adds the league's `zap` tag to every
  challenge of a chain season. Fees go to the winners of the match's valid blocks, otherwise to the
  reserve.
- **Review and payout**: corrections at season end as league-signed NIP-32 labels (`void-block`)
  before settlement; new regular kind `2157` **Payout**, one per player and season, with `bolt11` and
  `preimage` and `e`/`a` references to what it pays. No season payouts during a season; **tournament
  prizes** are settled at the tournament's end after an admin's check, one `2157` per player and
  tournament.
- **Rest before Block 0 and between seasons**: only rated play and mining rest; how clients and
  relays see it ([Rest](#rest-before-block-0-and-between-seasons)).
- **Pots and zap targets** ([Pots and zap targets](#pots-and-zap-targets-rev-5)): one zap target per
  pot; the league reserve is a NIP-75 zap goal (`9041`); one wallet with a receive-only and a paying
  NWC connection; reconciliation from relay data. Late receipts of any pot go to the reserve.
- **Trust**: `30382` carries `anchor` (the anchor subtree); `gate` rows name a player's opponent list
  whenever it lists the direct opponent, also in league pairings.
- **Ladders**: a revision-5 ladder names its genesis by `e` (frozen), `starts` is Block 0, `ends` the
  genesis' `ends`.
- **Clan ownership** ([Ownership](#ownership-rev-5)): not transferable; the owner leaves only with a
  captain remaining, the clan is frozen without its owner, the owner may return, the last departure
  ends the clan for good. Validation rules 7 and 9 amended.
- **Lineups are signed by the owner only** (`32151`, validation rule 8), so each clan has one lineup
  address per game and mode.
- **Rank tiers** ([Rank tiers](#rank-tiers-rev-5)): 21 levels `bronze-1` ... `grand-champion-3`
  with the Season 1 thresholds; tier names validated (rule 10); every ladder of the examples re-signed
  with 21 tiers.
- **Rank badges** ([Rank badges](#rank-badges-rev-5)): one NIP-58 definition per player, game and mode
  (`d` = `rank/<game>/<mode>/<pubkey>`), replaced on every rank change, one award, listed by the player
  in `10008`; one image URL per tier; a new single-purpose **badge key**.
- **Bounties** ([Bounties](#bounties-rev-5)): NIP-52 `31923` with `d` = `bounty/<slug>` and a `target`;
  claim rule `bounty-v1`; no new kind.
- Validation rules 24 to 33 and amendments to 10, 11 and the `gate` rows; tag table, kinds tables,
  identifiers, queries, keys, relay policy, reused NIPs, what is not on Nostr, collision check
  (`2156`, `2157`, `2158` free), open points; round-5 examples (the Pre-Season) and relay proof.

## Abstract

This NIP describes a competitive ladder for team and solo games. Players form **clans**, clans field
**lineups** per game and mode, captains or players **challenge** each other, both sides sign the
**result** including who played, and a **league** key attests the outcome together with the rating
change and publishes the current **ladder**. A ladder rates either lineups or single players.

The first game is **chess**, rated per player: every game is a rated solo game, including each
**board** of a clan team match over two or three boards. Each game is a
[NIP-64](https://github.com/nostr-protocol/nips/blob/master/64.md) game record signed by one player
and confirmed by the other, and correspondence games put every move on the relay. A clan's chess
strength is derived from its players' ratings and needs no events of its own. The second game is
Rocket League, a team game played in series and rated per lineup. Ratings live in **seasons**: each season is a rating
epoch whose parameters are published with the ladder, so every rating can be recomputed from the
public events. Tournament matches are rated like ordinary matches; a tournament can optionally publish a
**draw** that anyone can re-run, and its results can be entered by its **directors**. Tournaments
never mine: the season chain belongs to the season.

Seasons are league-wide: one parameter set for all games, with per-game overrides. Because anyone can
join, a game is only rated if the two players list each other as opponents and both have a **trust
rank** that flows from the association's paid members; lists, ranks and reports reuse NIP-51,
NIP-85 and NIP-56. A **global score** (Block Height and Global Rating) compares players across games
and is computed from the public events alone.

Every action a player takes is an event signed by that player, so the history can be checked by
anyone who can read it, without trusting the league for anything except the handling of disputes
and no-shows, which it signs openly.

From revision 5 on, a season is also a **chain**: a supply of sats is fixed at Block 0, a win that
passes the consensus rules is a block and earns a reward, blocks are linked in the order the league
attests them, and the rewards are paid once at season end after a public review. Ranks follow Rocket
League's 21 tiers and appear on the player's profile as a NIP-58 badge; bounties are NIP-52 events.

Around the match flow the league reuses standard NIPs only: tournaments are NIP-52 calendar events
that anyone can zap into a prize pool (NIP-57), players chat privately with NIP-17, the league sends
notifications with NIP-17 from a key of its own, and the league key announces itself with a kind `0`
and a NIP-65 relay list.

The design is game-agnostic. Game and mode are identifiers (`chess`, `blitz`; `rocket-league`,
`2v2`); what counts as a valid result is defined by the league's [game registry](#game-registry), not
by this NIP.

## Terminology

- **League**: the service that validates events, keeps the rating state and signs attestations. It
  is identified by its **league key** (a pubkey used for nothing else).
- **Clan**: a group of players with an **owner** (the author of the clan event, for the clan's whole
  life; see [Ownership](#ownership-rev-5)) and **captains**.
- **Lineup**: the team a clan fields for one game and mode.
- **Season**: a rating epoch of the whole league; all games share it. Rating parameters (start
  rating, K-factor, tier thresholds, series until a tier is shown) are fixed for the whole season,
  one set for all games with per-game overrides; a new season may change them and carries ratings
  over by a published rule.
- **Ladder**: the ranking for one game, one mode and one season, published by the league. A ladder
  rates **lineups** or **players**; the rated thing is the ladder's **entity**, referenced by lineup
  address or by pubkey.
- **Challenger / challenged**: the two sides of a match, fixed by the challenge event: two lineups
  (series games, chess team matches) or two players (solo games).
- **Board** (chess): one game of a clan team match; board 1 is the first. A team match over `n`
  boards is `n` games played at the same time, each a rated solo game.
- **Game record** (chess): a NIP-64 note (kind `64`) whose content is the PGN of a game.
- **Clan rating, clan hashrate**: values the league derives for display (chess: average of the top
  three player ratings of a clan; activity points per clan). Neither is an event. The clan rating is a
  view of the ladders; the clan hashrate is recomputable from the attestations of a revision-4 season
  (see [Clan hashrate](#clan-hashrate)).
- **Match number**: the league's running number of matches over all games and modes, the "block
  height of the league" (tag `match`). The league assigns it before a challenge is signed; casual
  games take numbers too, so the numbers on Nostr have gaps.
- **Pairing**: how the two sides of a challenge met: one side chose the other (no tag), the rated
  queue paired them (`queue`, see [Queue pairings](#queue-pairings)), or a tournament bracket did
  (`tournament`).
- **Mix team**: a team the league draws from a tournament's solo pool (kind `2155`). It has no lineup
  and no rating; in a challenge it is a **roster side**, one `p` per team member.
- **League publisher**: the key the league's server authenticates with (NIP-42) on the league relay
  to publish accepted events. It signs no stored event.
- **Notification key, pool key, LNURL server key, sponsor desk key, badge key** (rev. 5): further single-purpose league
  keys, see [League relay and keys](#league-relay-and-keys).
- **Anchor list**: the trust key's public NIP-51 list of the anchors.
- **Acting captain** of a lineup: a pubkey that is currently an active member of the lineup's clan
  and is either the lineup's author (the clan owner) or listed in the lineup with role `captain`.
- **Roster** of a match: the players who actually played in the series, per side.
- **Tournament**: a set of matches that the league pairs from a bracket. Revision 4 publishes it as a
  NIP-52 time-based calendar event (`31923`) in the league's calendar (`31924`); registration and
  bracket state stay league data. See [Tournaments](#tournaments).
- **Tournament consent** (rev. 7): the signed sign-up or withdrawal of a tournament entry (`22150`),
  kept by the league and never published. See [Tournament Consent](#tournament-consent-22150).
- **Tournament director** (rev. 7): a person the league lets enter the results of a tournament (its
  organiser and the directors named for it). Who is a director is league data; a director's entry is
  public only as the `entered-by` of the attestation. See [Director results](#director-results-rev-7).
- **Trust key**: a second league pubkey, used only to sign trust ranks (NIP-85 assertions).
- **Anchor**: a pubkey trust flows from: a paid member of the association or a league admin.
- **Opponent list**: a player's NIP-51 follow set for this league; listing someone means "I play
  rated games against this person".
- **Trust rank**: an integer 0-100 the trust key publishes per player; see [Trust](#trust).
- **Block Height, Global Rating**: a player's global score across games, derived from public events;
  see [Global score](#global-score).
- **Season chain, Block 0, block, block reward** (rev. 5): a season's reward chain; its first event, the
  Season Genesis signed after an admin released it; an attestation of a win that passes the
  consensus rules; the sats such a win earns, paid at season end. See [Season chain](#season-chain-rev-5).
- **League reserve, pot** (rev. 5): the sats that fund seasons, and each separately counted balance
  (reserve, season supply, tournament pool, bounty, match fees); see
  [Pots and zap targets](#pots-and-zap-targets-rev-5).
- **Rank tier, rank badge** (rev. 5): one of 21 levels from Bronze I to Grand Champion III; its NIP-58
  badge on the player's profile.

## Kinds

| kind | class | name | signed by |
|---|---|---|---|
| `2150` | regular | Challenge | acting captain of the challenger lineup, or the challenging player (solo game) |
| `2151` | regular | Challenge Answer | the challenged side (`accepted`, `declined`) or the challenger side (`withdrawn`): an acting captain, or the player in a solo game |
| `2152` | regular | Result Report | acting captain of either lineup |
| `2153` | regular | Result Response | acting captain of the lineup that did **not** author the report; in chess the other player of the game |
| `2154` | regular | League Attestation | league key |
| `2155` | regular | Tournament Draw (optional) | league key |
| `64` | regular | Game Record, **reused from NIP-64** (chess) | a player of the game |
| `12150` | replaceable | Clan Membership | the player |
| `32150` | addressable | Clan | clan owner |
| `32151` | addressable | Lineup | clan owner (rev. 5; before: the owner or a clan captain) |
| `32152` | addressable | Ladder | league key |
| `2156` | regular | Season Genesis, Block 0 (rev. 5) | league key |
| `2157` | regular | Payout (rev. 5) | league key |
| `2158` | regular | Parameter Change (rev. 5) | league key |
| `22150` | ephemeral | Tournament Consent (rev. 7), **never published** | the entering player (solo), an acting captain of the entered lineup |

The numbers form one family: `2150+n` for the regular match flow and the season chain, `12150` for the one-per-player
membership, `22150` for the one signed statement that never goes to a relay, `32150+n` for the
addressable definitions. Classes follow NIP-01 (`1000 <= n < 10000` regular, `10000 <= n < 20000`
replaceable, `20000 <= n < 30000` ephemeral, `30000 <= n < 40000` addressable).

Why these classes:

- **Match-flow events, the genesis and payouts are regular.** They are history. A report that could be replaced would let
  its author rewrite a result after the opponent confirmed it. The draw is regular for the same
  reason: a draw that could be replaced is no commitment.
- **Membership is replaceable.** One event per pubkey is exactly the rule "a player belongs to at
  most one clan", enforced by the relay's replace semantics instead of by convention.
- **Clan, lineup and ladder are addressable.** They are current state with a stable address that
  the history can point at with `a` tags.
- **The tournament consent is ephemeral** (rev. 7). It is evidence for the league, not history for
  readers, and it is never published. The ephemeral class is the safety net: a copy that reaches a
  relay by mistake is "not expected to be stored by relays" (NIP-01).

A league that does not publish draws implements every other kind and never emits `2155`.

Kinds of other NIPs that the league reads or writes with a meaning defined here (rounds 3 and 4 add
no kind of their own; round 5 adds `2156` to `2158`):

| kind | NIP | used as | signed by |
|---|---|---|---|
| `0`, `10002` | 01, 65 | league profile and relay list, so clients find the league | league key |
| `31923` | 52 | tournament (time-based calendar event); rev. 5 also a bounty (`d` = `bounty/<slug>`) | league key |
| `31924` | 52 | the league's tournament calendar | league key |
| `30000` | 51 | opponent list, `d` = `esports/<league key>` | the player |
| `30000` | 51 | anchor list, `d` = `esports/<league key>/anchors` | trust key |
| `14` in `13` in `1059` | 17, 59 | private chat between players; notifications | a player; the notification key |
| `10050` | 17 | a player's DM relays | the player |
| `9734`, `9735` | 57 | zaps to a pot: tournament, bounty, match fees, reserve (rev. 5, see [Pots and zap targets](#pots-and-zap-targets-rev-5)) | the zapper; the league's LNURL server key |
| `9041` | 75 | rev. 5: the league reserve as a zap goal | league key |
| `30000` | 51 | rev. 5: the admin list, `d` = `esports/<league key>/admins` | league key |
| `1985` | 32 | rev. 5: release of Block 0 (`release-block-0`); correction of the season review (`void-block`) | a listed admin; the league key |
| `30382` | 85 | trust rank of a player; rev. 5 also the anchor subtree (`anchor`) | trust key |
| `0` | 01, 85 | description of the trust algorithm | trust key |
| `1984` | 56, 32 | report with the league's label namespace | any player |
| `3` | 02 | read only: opponent suggestions, mutual contacts | the player |
| `30009`, `8` | 58 | rev. 5: rank badge definition per player, game and mode; its single award | badge key |
| `10008` | 58, 51 | rev. 5: the player's profile badges, with the rank badges the player chose to show | the player |
| `1` | 01, 92 | rev. 8: share post, a note with a share card of the league (rank up, block, tournament win, season) | the player |

## Identifiers

| thing | `d` value | example |
|---|---|---|
| clan | slug, `^[a-z0-9][a-z0-9-]{1,47}$` | `satoshis-strikers` |
| lineup | `<clan-d>/<game>/<mode>` | `satoshis-strikers/rocket-league/2v2` |
| ladder | `<game>/<mode>/<season>` | `chess/blitz/season-1`, `rocket-league/2v2/season-1` |
| opponent list (`30000`) | `esports/<league key>` | `esports/8a0f19d2…4753` (full hex key) |
| trust assertion (`30382`) | the player's pubkey (NIP-85) | |
| report label namespace (`L`) | `space.einundzwanzig.esports` | reverse-DNS of the planned domain |
| tournament (`31923`) | tournament slug, same pattern as a clan slug | `rl-2v2-cup-1` |
| tournament calendar (`31924`) | `tournaments` | |
| anchor list (`30000`, trust key) | `esports/<league key>/anchors` | |
| admin list (`30000`, league key, rev. 5) | `esports/<league key>/admins` | |
| season announcement (`31923`, rev. 5) | `season/<season>` | `season/season-5` |
| bounty (`31923`, rev. 5) | `bounty/<slug>` | `bounty/alice-blitz-s5` |
| rank badge definition (`30009`, rev. 5) | `rank/<game>/<mode>/<player pubkey>` | `rank/chess/blitz/e221ff8c…413e` (full hex key) |
| quest badge definition (`30009`, rev. 5) | `quest/<slug>` | `quest/first-block` |

The separator inside a `d` value is `/`, not `:`. An `a` value is `<kind>:<pubkey>:<d>`, and several
client libraries split it on every `:`; a `d` value containing `:` then loses its tail.

`game` and `mode` are lower-case slugs from the league's game registry. `season` is a slug of the
same pattern as a clan slug; this league numbers its seasons `season-1`, `season-2`, and so on. From
revision 5 on, the first chain season is the **Pre-Season** (`pre-season`), with looser defaults to
build a base of players and clans; later seasons are `season-1`, `season-2`, ... if and when they come.
A tournament slug (the `d` of its `31923`; in revision 3 the value of the tag `tournament`) uses the
same pattern and is unique within the league.

**The ladder address is the league scope.** Every match-flow event carries the ladder's `a` tag,
which contains the league key, the game, the mode and the season. An event signed for one league or
one season is therefore not valid in another one, and all events of a season can be fetched with a
single `#a` filter.

## Tags

Only single-letter tags are used for querying. Multi-letter tags carry data and are never used
in filters (measured: strfry rejects a `#status` filter with `unindexed tag filter`, rnostr ignores
it and returns non-matching events; see the relay proof).

| tag | format | used in | meaning |
|---|---|---|---|
| `d` | `<identifier>` | 32150, 32151, 32152 | see [Identifiers](#identifiers) |
| `a` | `<kind>:<pubkey>:<d>`, `<relay>`, `[role]` | all except 32150 | reference to clan, lineup, ladder or tournament. In 2150 and 2154 the two lineup references carry the role `challenger` / `challenged` in position 3; the tournament `31923` (rev. 4, 2150-2155 of a tournament; rev. 7 also every 2154 of a tournament match and 22150) and the ladder carry no role; in a revision-3 2155 each entrant lineup carries `entrant`; rev. 7: in 22150 the entered lineup carries `entrant`; in a ladder that continues a previous season, see `reset` |
| `e` | `<id>`, `<relay>`, `<author pubkey>` | 2150 (optional), 2151-2158, 64, 32152 | reference to the event this one answers (NIP-01 form, no NIP-10 markers). Which reference is which follows from the referenced event's kind. In 2150 an `e` can only point at a draw (2155); in a correspondence move (64) the second `e` points at the previous move; in a revision-5 ladder one `e` names the genesis (2156, frozen); in 2156, 2157 and 2158 see [Season chain](#season-chain-rev-5); rev. 7: in 22150 the `31923` version signed up for, or the sign-up consent withdrawn |
| `p` | `<pubkey>`, `<relay>`, `[role]`, `[lineup role]` | 32150, 32151, 32152, 2150-2158, 64 | in 32150 role `captain` or `member`; in 32151 role `captain`, `player` or `substitute`; in a solo 2150 the two players with side `challenger` / `challenged`; in a 2150 with a **roster side** (rev. 4, a mix team) one `p` per team member with that side; in 2152 and 2154 a **roster entry** has the side `challenger` or `challenged` in position 3 and the player's lineup role (`captain`, `player`, `substitute`; `player` on a roster side) in position 4; in 2155 role `entrant` (solo entrant); rev. 7: in 22150 role `entrant`, one per player entered; in 64 role `white` or `black`; in a player ladder (32152) one plain `p` per ranked player; rev. 5: in 2156 the admin with role `release`, in 2158 the admin with role `change`, in 2157 the paid player. A `p` without position 3 only notifies (e.g. the other captain) |
| `name` | `<text>` | 32150, 2155 | display name of the clan or tournament, at most 64 characters |
| `clantag` | `<text>` | 32150 (optional) | short clan tag shown next to names, `^[A-Z0-9]{2,4}$` |
| `picture` | `<url>` | 32150 | logo |
| `r` | `<url>` | 32150 | external link, e.g. a meetup page (NIP-24 style web reference) |
| `game` | `<slug>` | 32151, 32152 | game identifier |
| `mode` | `<slug>` | 32151, 32152 | mode identifier |
| `bo` | `<odd integer>` | 2150 | best-of length of the series (series games) |
| `boards` | `<integer>` | 2150 | number of boards of a chess team match |
| `color` | `white` \| `black` | 2150 | the challenger's color in a solo chess game |
| `board` | `<no>` | 64 | the board this game record belongs to (team match) |
| `board` | `<no>`, `<white pubkey>`, `<black pubkey>`, `<result>` | 2154 | one chess game: board number (1 in a solo game), players, PGN result `1-0`, `0-1` or `1/2-1/2` |
| `start` | `<unix seconds>` | 2150 (1-3), 2151 (1, if accepted) | proposed start times; the chosen one |
| `respond_by` | `<unix seconds>` | 2150 | the challenge is open until this time. Deliberately **not** NIP-40 `expiration`, see [Relay behaviour](#relay-behaviour) |
| `tournament` | `<slug>` | 2150 (optional), 2155 | revision 3 only: the tournament a challenge belongs to. Revision 4 references the tournament's `31923` with `a` instead |
| `match` | `<positive integer>` | 2150, 2154 (rev. 4); `14` rumors | the league match number, assigned by the league before the challenge is signed and copied into every attestation of the challenge; in a chat or notification rumor the match it is about |
| `pairing` | `queue` \| `tournament` | 2150 (rev. 4, optional) | the league paired the two sides: the rated queue or a tournament bracket. Absent: one side chose the other. Changes the trust gate, see [Trust gate](#trust-gate) |
| `clan` | `<pubkey>`, `<clan address>` | 2154 (rev. 4) | the clan a rated player was an active member of at the accept; one row per rated player with a clan. Input of [Clan hashrate](#clan-hashrate) |
| `hashrate` | `<win>`, `<draw>`, `<loss>`, `<team win bonus>` | 32152 (rev. 4) | activity points of the season, see [Clan hashrate](#clan-hashrate); its presence makes the ladder a revision-4 ladder |
| `teams` | `<team size>` | 2155 (rev. 4) | the solo pool is drawn into teams of this size |
| `teamname` | `<text>` | 2155 (rev. 4) | team names in order: team `n` of the draw gets the `n`-th name, at most 64 characters |
| `status` | `accepted` \| `declined` \| `withdrawn` | 2151 | answer to a challenge |
| `status` | `confirmed` \| `disputed` | 2153 | answer to a report |
| `score` | `<game no>`, `<winner>`, `[challenger points]`, `[challenged points]`, `[flag...]` | 2152, 2154 | one tag per game played, numbered from 1. `<winner>` is `challenger` or `challenged` (or `draw` if the game registry allows draws). The two point values are both present, or both absent or empty: absent means **points unknown**, the game counts for the series with its winner only. Optional flags after the points are defined by the game registry (e.g. `ot` for overtime) |
| `resolution` | `confirmed` \| `admin` \| `forfeit` \| `void` | 2154 | how the result was reached |
| `winner` | `challenger` \| `challenged` \| `draw` \| `none` | 2154 | winner of the match; `draw` only where the game registry allows draws; `none` only with `resolution` `void` |
| `elo` | `<entity>`, `<before>`, `<after>` | 2154 | rating of each entity (lineup address or pubkey) before and after this match |
| `prev` | `<attestation id>` | 2154 | the previous attestation of the same ladder; absent only for the first one of a season |
| `season` | `<slug>` | 32152, 2156 | season identifier |
| `starts`, `ends` | `<unix seconds>` | 32152 | season window, inclusive. `ends` is absent while the season is open and set when it closes. Rev. 5: `starts` is the genesis' `created_at` (Block 0), and `ends` equals the genesis' `ends` |
| `ends` | `<unix seconds>` | 2156 (rev. 5) | the fixed end of the chain season |
| `supply`, `subsidy` | `<sats>` | 2156 (rev. 5) | the season's supply; the base reward per winning player, see [Season Genesis](#season-genesis-2156) |
| `weight` | `<game>/<mode>`, `<factor>` | 2156 (rev. 5) | reward factor per winning player |
| `share`, `daily` | `<game>`, `<percent>` / `<blocks>` | 2156 (rev. 5) | share cap per era; block weight limit per player and UTC day |
| `halving`, `claim` | `<seconds>` | 2156 (rev. 5) | length of an era; claim window for unpaid players |
| `consensus` | `season-chain-v1` | 2156 (rev. 5) | the rule set of [Consensus rules](#consensus-rules-season-chain-v1) |
| `block` | `<height>` or empty, `<previous block id or tip id>` | 2154 (rev. 5) | the block this attestation mines, and the block before it; an empty height: the win does not mine, and the id is the tip it was checked against |
| `anchor` | `<anchor pubkey>`, `<percent>` | 30382 (rev. 5) | the anchor with the largest share in the player's trust, and that share |
| `x` | `<sha256 hex>` | 1985 (rev. 5) | the parameter digest a release label releases |
| `pairlimit` | `<per UTC day>`, `<per season>` | 2156, 2158 (rev. 5) | blocks per pairing (rules 4 and 8) |
| `subtree` | `<percent>` | 2156, 2158 (rev. 5) | threshold of rule 7 |
| `moves` | `<full moves>` | 2156, 2158 (rev. 5) | minimum length of a chess game that mines (rule 2) |
| `effective` | `<unix seconds>` | 2158 (rev. 5) | from when a parameter change is in force |
| `tip` | `<block id>` | 2158 (rev. 5) | the newest block when the change was signed |
| `zap` | `<pool key>`, `<relay>`, `1` | 2150 (rev. 5, never in a tournament challenge), 31923 (rev. 7: optional in a tournament, see [Tournaments](#tournaments)), 9041 | NIP-57 appendix G: zaps to this event go to the league's LNURL endpoint |
| `action` | `signup` \| `withdraw` | 22150 (rev. 7) | what the tournament consent does |
| `entered-by` | `<director pubkey>` | 2154 (rev. 7) | the tournament director whose entry decided this result, lower-case hex; see [Director results](#director-results-rev-7) |
| `bolt11`, `preimage` | `<invoice>`; `<hex>` | 2157 (rev. 5) | the paid invoice and its preimage |
| `rates` | `lineup` \| `player` | 32152 | what the ladder rates; absent means `lineup` |
| `time_control` | `<PGN TimeControl>` | 32152 | e.g. `300+3` (5 minutes plus 3 seconds per move), `1/86400` (one move per day) |
| `variant` | `<slug>` | 32152 | game variant, e.g. `standard` |
| `rating` | `elo`, `<start rating>`, `<k-factor>`, `<scale>` | 32152 | rating system and parameters of this season, see [Rating](#rating) |
| `tier` | `<name>`, `<minimum rating>` | 32152 | one row per rank tier, lowest first. Rev. 5: the 21 levels `bronze-1` ... `grand-champion-3`, see [Rank tiers](#rank-tiers-rev-5); earlier ladders carry five (`bronze` ... `diamond`) |
| `provisional` | `<results>`, `[provisional k-factor]` | 32152 | an entity is shown without a tier until it has this many rated results in the season; while it has fewer, its rating moves with the provisional k-factor if one is given |
| `reset` | `<previous ladder address>`, `<last attestation id>`, `<factor>` | 32152 | this season continues the previous ladder: ratings are carried over from the state after that attestation, see [Season transition](#season-transition) |
| `seed` | `<entity>`, `<rating>` | 32152 | start rating of an entity carried over by `reset` |
| `standing` | `<rank>`, `<entity>`, `<rating>`, `<wins>`, `<losses>`, `<tier>`, `[draws]` | 32152 | one row per ranked entity; `<tier>` is a tier name or `provisional`; `draws` absent means 0 |
| `format` | `<slug>` | 2155 | tournament format, e.g. `single-elimination`, `double-elimination`, `groups-knockout` |
| `draw` | `<block height>`, `<algorithm>` | 2155 | the Bitcoin block whose hash seeds the draw; algorithm `sha256-v1`, see [Tournament Draw](#tournament-draw-2155-optional) |
| `trust` | `<trust key>`, `<minimum rank>` | 32152 (optional), 2154 | the ladder has a trust gate; in 2154 the values in force at the accept, see [Trust gate](#trust-gate) |
| `gate` | `<pubkey>`, `<rank>`, `<assertion id>`, `<opponent list id>` | 2154 | one row per gatekeeper and rated player: the rank at the accept, the `30382` it came from, and for gatekeepers the `30000` version that listed the other one (empty otherwise). Rev. 5: the list id is set whenever the player's list names their direct opponent (the other gatekeeper, the other player, the board opponent), also in league pairings |
| `override` | `<tag name>`, ... | 32152 (optional) | the season parameters in which this ladder deviates from the league's season, see [League-wide seasons](#league-wide-seasons) |
| `alt` | `<text>` | all | NIP-31 fallback text, required |

## Game registry

The league defines its games in code. This NIP needs the following fields per mode; the ones with a
tag name in the "ladder" column are also published in every ladder of the mode, so that the ladder
describes itself.

| field | ladder | chess | Rocket League |
|---|---|---|---|
| game, mode | `game`, `mode` | `chess`: `blitz`, `correspondence` | `rocket-league`: `1v1`, `2v2`, `3v3` |
| rated entity | `rates` | `player`; there is no team rating | `lineup` for `2v2` and `3v3`; `player` for `1v1` (rev. 7.1, see [Rocket League 1v1](#rocket-league-1v1-rev-71)) |
| time control | `time_control` | `300+3` (blitz 5+3), `1/86400` (correspondence, one move per day, in the PGN period form "moves/seconds") | none |
| variant | `variant` | `standard` | none |
| rated | only rated modes have ladders | yes; casual games stay off the ladder and at most become a plain NIP-64 note | yes |
| match size | | a solo game, or a clan team match over `boards` 2 or 3 whose boards are rated solo games | `bo` 3 or 5 |
| team size | | a chess lineup (`<clan>/chess/<mode>`) needs at least `boards` active players | 1, 2, 3 |
| clan standing | | derived: clan rating off Nostr; clan hashrate recomputable (rev. 4) | `2v2`, `3v3`: the lineup's own Elo; `1v1` (rev. 7.1): the player's Elo; clan hashrate recomputable (rev. 4) |
| draws | | yes, a game can end `1/2-1/2` | no |
| result per game | | a game record (kind `64`), see [Game Record](#game-record-64-reused-from-nip-64) | `score` tags in the report (2152) |
| moves on Nostr | | correspondence only; blitz moves stay on the league server | none |

A chess lineup exists only to field team matches; it has no rating of its own. Casual (unrated)
games never produce match-flow events; a player may still publish the finished game as a plain
NIP-64 note.

### Rocket League 1v1 (rev. 7.1)

A Rocket League 1v1 ladder is a **player ladder**: `d` = `rocket-league/1v1/<season>` (unchanged),
`rates` `player`, and the rated entity is always the **player's pubkey**, whether the player plays for
a clan or alone. Revision 6 had it as a lineup ladder of one-player lineups; a tournament's solo
entries have no lineup, and a lineup address for a single player would split one person's rating over
every clan they ever played 1v1 for. Chess solved the same problem the same way: the player is rated,
the clan appears as context.

A 1v1 **side** is one of two things:

- **a lineup side**: a clan's `1v1` lineup, as before. The challenge and the attestation carry the
  lineup `a` with role `challenger` or `challenged` (context, like the lineups of a chess team match),
  and the roster `p` with side and lineup role; the `elo` entity is the roster player's pubkey;
- **a player side**: a player without a lineup (a tournament's solo entry). The challenge carries
  `["p", "<pubkey>", "<relay>", "<side>"]` and no lineup `a` for that side, exactly like a solo chess
  game; report and attestation carry the player as a roster entry
  `["p", "<pubkey>", "<relay>", "<side>", "player"]`; the `elo` entity is that pubkey.

The two kinds of side can meet: a clan's 1v1 lineup against a solo entry is one lineup `a` and one
`p` side. Every 1v1 side has exactly one roster player. `clan` rows follow the players, as always:
a player side gets one if its player was an active clan member at the accept (the pairing, in a
tournament). A 1v1 **team win** (clan hashrate step 2) needs a lineup side that won.

A `rocket-league/1v1` ladder whose first version was signed with `rates` `lineup` keeps it for its
season (frozen parameter). On such a ladder a player side is never rated: its matches are casual.

## Events

### Clan (`32150`)

Signed by the clan owner. `content` is an optional plain-text description. Required tags: `d`,
`name`, `alt`. Optional: `clantag`, `picture`, `r`. The owner is always a captain; further captains
and all other members are listed as `p` with role `captain` or `member`.

A pubkey is an **active clan member** when the clan lists it (or it is the owner) **and** that
pubkey's own Clan Membership (`12150`) points at the clan. Listing alone is an invitation; the
membership event is the acceptance. Rev. 6: the invitation is into the clan's roster only; it names no
lineup, mode or role. Only the listed player can accept it, since only their key signs their
membership.

**Join requests (league data).** A player may also ask to join, through a clan's join link
([Invite links](#invite-links)). The request and a captain's yes stay on the league server: no event
carries them, and a captain's approval does not list anybody, because only the owner signs `32150`
(see [Ownership](#ownership-rev-5)). The flow is: request → a captain approves → the **owner** lists
the player in a new `32150` → the player accepts with their own `12150`. The last two steps are the
invitation and acceptance above, unchanged, so a relay reader sees an ordinary invitation and cannot
tell a request from an invitation the owner started. An approved request waits for the owner; while
the clan is frozen (owner gone), it waits for good. The league shows the requester each step as it is
(waiting for a captain, waiting for the owner, invited).

The clan tag is a display label, not an identifier: the `d` value identifies a clan. The league
keeps clan tags unique within the league and refuses a clan event whose `clantag` another clan
already uses.

#### Ownership (rev. 5)

**Ownership cannot be transferred.** The clan's address `32150:<owner>:<d>` contains the owner's key,
so a clan event with the same `d` signed by another key is a different clan. A group that wants a
new owner founds a new clan: new address, new lineups, ratings from the start rating. The old clan's
history stays with its addresses. The rules for V1:

1. **The owner leaves like any member**, with a `12150` without the clan (or with another clan: a
   switch). The league accepts it only if at least one other active member of the clan is listed
   with role `captain`, or if the owner is the last active member. An owner who wants to leave a clan
   without a captain names one first.
2. **Without its owner the clan is frozen.** Only the owner signs `32150` and `32151`, and the league
   refuses versions of both from an owner who is not an active member. So nobody can invite, promote,
   remove or change a lineup. Listed members can still accept (`12150`) or leave, and acting captains
   keep playing with the lineups as they stand.
3. **The owner can come back** with a `12150` that points at the clan again (the owner needs no
   listing), as long as the clan has not ended. That ends the freeze.
4. **The last departure ends the clan.** When the clan has no active member left, it has ended: the
   league refuses every later `32150`, `32151` and `12150` that points at it, its lineups cannot
   challenge or be challenged, and its clan tag is free for another clan. Ending is final in V1.

Accepting an invitation of another clan is a switch: the player's new `12150` names the new clan
and thereby leaves the old one (a player belongs to at most one clan). For an owner, rule 1 applies
to the switch as well.

"Frozen" and "ended" are league state derived from memberships. Relays keep only the newest `12150`
of each player, which shows who is active now; whether a clan ended earlier and was then joined
again needs the league's archive of membership versions.

### Lineup (`32151`)

Signed by the clan owner (rev. 5; revision 4 allowed any clan captain). `content` is empty. Required
tags: `d`, `a` (clan), `game`, `mode`, `alt`, and one `p` per player with role `captain`, `player` or
`substitute`.

**Why only the owner.** A lineup's rating belongs to its address `32151:<author>:<clan>/<game>/<mode>`.
With several signers, two captains would create two addresses for the same clan, game and mode, and
a lineup edited by another captain would start over at the start rating. With the owner as the only
signer, each clan has exactly one lineup address per game and mode, and it stays the same for the
clan's whole life. Captains who want a change ask the owner in the app; while the clan is frozen
(see [Ownership](#ownership-rev-5)), lineups cannot change.

A player is an **active lineup player** when the lineup lists them and they are an active clan member
(rev. 6). **Membership is consent:** by joining the clan a player agrees to be placed in any of its
lineups, so the owner builds lineups from the active members and no player signs anything for it; a
player who does not want to play leaves the clan. Revisions 4 and 5 also required the player's Clan
Membership to list the lineup's address, and a listed player who had not done that was only invited.
Substitutes are active lineup players like the others; the role only says who is expected to play by
default.

The owner lists only active members (rule 8). A member who leaves the clan stops being an active
player of all its lineups at once, without a new lineup version; a lineup whose active captains and
players fall below the mode's team size cannot challenge or be challenged until the owner fills it
again.

### Clan Membership (`12150`)

Signed by the player. `content` is empty. Tags: at most one `a` pointing at a Clan (`32150`), and
`alt`. An event with no clan `a` means "no clan". Leaving a clan and switching clans are done by
publishing a new version of this event; being a member is the consent to be placed in the clan's
lineups (rev. 6). Revisions 4 and 5 also listed each accepted lineup with an `a` to it; such tags stay
valid if they point at lineups of the named clan, and are ignored.

### Ladder (`32152`)

Signed by the league key. `content` is empty or a human-readable note. Required tags: `d`, `game`,
`mode`, `season`, `starts`, `rating`, `provisional`, `alt`, and at least one `tier`; `rates`,
`time_control` and `variant` where the game registry defines them; `trust` if the ladder has a trust
gate; `hashrate` in revision 4; `override` if it deviates from the season's parameters. A ladder that
continues a previous season also carries `reset` and one `seed` per carried-over lineup. After the
first match the ladder carries an `e` tag pointing at the last attestation it includes. Every lineup
that has a `standing` or a `seed` also appears as a plain `a` tag, every player in a player ladder as
a plain `p` tag, so that `#a` and `#p` find every ladder an entity appears in.

The league publishes a first version with no standings when the season opens, so that the ladder
address the challenges point at exists from the start. The standings are a convenience view:
anyone can recompute them by replaying the season's attestations in `prev` order.

**The season parameters are frozen.** Every version of a ladder carries the same `game`, `mode`,
`season`, `starts`, `rates`, `time_control`, `variant`, `rating`, `tier`, `provisional`, `hashrate`,
`override`, `reset` and `seed` tags as the first one. Only `ends`, `e`, the plain `a` and `p` tags, `standing`,
the values of `trust`, `content` and `alt` change between versions; whether `trust` is present does
not. Different parameters mean a new season, and a season is league-wide (see
[League-wide seasons](#league-wide-seasons)).

Relays keep only the newest version of an addressable event, so a reader cannot see earlier
versions. The rule is checkable anyway for everything that affects ratings: the attestations are
regular events and keep their `elo` values forever, and replaying them with the published `rating`
and `seed` values either reproduces every one of them or shows where the parameters were changed.
Tier thresholds and `provisional` do not enter any attestation, so a change to them could not be
detected this way; they are presentation, and the rule is still binding on the league.

### Challenge (`2150`)

Signed by an acting captain of the challenger lineup, or in a solo game by the challenging player.
`content` is an optional public message. Required tags: the two sides, `a` ladder, the match size
where the game has one, one to three `start`, `respond_by`, `alt`.

- **Sides.** Lineups: `a` challenger lineup (role `challenger`) and `a` challenged lineup (role
  `challenged`); `p` for the challenged lineup's captain(s) is recommended so their clients can
  subscribe with `#p`. Solo game: `p` of the author with side `challenger` and `p` of the opponent
  with side `challenged`, and no lineup `a`. **Roster side** (rev. 4): on a lineup ladder, a mix team
  of a tournament plays as one `p` per team member with its side and no lineup `a`; the team must be
  one team of the tournament's draw. On a player ladder a `p` side is always a single player.
  **Player side** (rev. 7.1): on the Rocket League 1v1 player ladder a side is either a clan's 1v1
  lineup (lineup `a` with its role) or a single player (`p` with its side, no lineup `a`), and the
  two can meet; see [Rocket League 1v1](#rocket-league-1v1-rev-71). A player side is not a roster
  side and is rated.
- **Match number** (rev. 4). `match` with the number the league reserved for this challenge. The
  client asks the league for a number, puts it into the challenge and signs; the league refuses a
  challenge whose number it did not reserve for this author or that another challenge already uses.
  Numbers of challenges that are never accepted, and of casual games, leave gaps in the numbers that
  appear on Nostr; a gap is not a missing event.
- **Pairing** (rev. 4). `pairing` `queue` or `tournament` when the league paired the sides; absent
  when one side chose the other.
- **Match size.** `bo` for series games (Rocket League), `boards` for a chess team match, nothing
  for a solo chess game. A chess team match references the player ladder of its mode, like a solo
  game, because its boards are rated there.
- **Colors (chess).** A solo game carries `color`, the challenger's color. In a team match the
  challenger side has White on odd boards and Black on even boards; which player sits on which board
  is agreed by the captains before the start and becomes public with the game records.

**Tournament matches are ordinary challenges** on the same ladder and are rated like any other,
except that a match with a mix team is unrated (see [League Attestation](#league-attestation-2154)).
In revision 4 a challenge of a tournament carries `pairing` `tournament`, an `a` to the tournament's
`31923`, and, if the tournament has a solo-pool draw, an `e` to that draw, so that
`{"kinds":[2150],"#a":["31923:<league>:<slug>"]}` returns every challenge of the tournament. The
bracket, the registration and the seeding live with the league. A tournament pairing that is never
accepted (declined or expired) produces no attestation and no rating change; how the bracket
continues is tournament policy. (Revision 3: optional `tournament` slug and `e` to a slot-order draw.)

Revision 7 adds three limits. A match of a tournament that names no ladder (see
[Tournaments](#tournaments)) is casual and produces no match-flow event. A tournament challenge never
carries the match-fee `zap` tag: tournament matches mine no blocks, so there would be no block for a
fee to go to ([Fees](#fees)); the tournament's pot is its `31923`. And in a tournament whose directors
enter the results, no challenge is signed at all ([Director results](#director-results-rev-7)).

**Trust gate.** On a ladder with `trust`, the league SHOULD refuse a challenge whose
[trust gate](#trust-gate) would fail when it is submitted. The binding check happens at the accept.

### Challenge Answer (`2151`)

Required tags: `e` challenge, `status`, `alt`, and copies of the `a` references of the challenge
(three with lineups, one in a solo game; plus the tournament `a` if the challenge has one). With
`status` `accepted` exactly one `start` is required and it must equal one of the
challenge's proposals. `content` is an optional public message. For a roster side any roster player
of that side answers. In a queue pairing the app signs the answer without a prompt (see
[Queue pairings](#queue-pairings)). On a ladder with `trust` the league
accepts an `accepted` answer only if the [trust gate](#trust-gate) passes at that moment; otherwise it
refuses the answer and the challenge stays open. The answer itself carries no trust evidence.

### Result Report (`2152`)

Required tags: `e` challenge, one `score` per game played in order, the **roster** (one `p` per
player who played in the series, with side and lineup role), `alt`, and the three `a` references.
A `p` without side for the other lineup's captain is recommended if that captain is not on the
roster.

- **Points per game.** Each `score` names the winner of the game. The points are the team goals of
  that game as shown on the end screen. If they are unknown, both point values are left out and the
  game counts with its winner only (`["score","2","challenged"]`). Statistics that use points skip
  such games; they never read them as `0`.
- **Who played.** A player is on the roster if they played at least one game of the series. The
  lineup role in position 4 is copied from the lineup as it stands when the report is signed, so a
  substitute stays recognisable after the lineup changes. Per-game line-ups are not recorded.
- **Roster side** (rev. 4). A report may be signed by any roster player of a roster side. Its roster
  entries carry lineup role `player`, and each must be a member of that side in the challenge.

The roster and the scores are one statement: the confirming response covers both, and a wrong
roster is a reason to dispute.

### Result Response (`2153`)

Required tags: `e` report (or chess game record), `e` challenge, `status`, `alt`, the `a` references
of the challenge. For a roster side, a roster player of that side responds. With
`disputed`, `content` should give a short public reason. Evidence never goes into the event.

### Game Record (`64`, reused from NIP-64)

Chess results are not reported by captains. Each game is a NIP-64 note (kind `64`, `content` = PGN
in export format) signed by **one of its two players**, and the other player answers it with a
Result Response (`2153`). The two signatures together carry the same weight as report and
confirmation in a series game. The PGN comes from the league server, which validated every move;
the player signs that text, so a doctored record is visible to the opponent before they confirm.

Tags added to the NIP-64 note (NIP-64 allows additional tags): `e` challenge, the `a` references of
the challenge (both lineups and the ladder in a team match, the ladder in a solo game), `p` White
with role `white` and `p` Black with role `black`, `board` in a team match, `alt`. The PGN headers
`White` and `Black` carry display names; identities are the `p` tags. `Result` and the movetext
terminator agree.

- **Final record.** A record whose result is `1-0`, `0-1` or `1/2-1/2` ends the game. Per game
  exactly one final record counts: the first valid one the league accepts. Either player may sign
  it; the league's client offers it to both at the end of the game.
- **Blitz.** Moves, clocks and chat run over the league server in real time and are not events. At
  the end the final record contains the complete game; `{[%clk h:mm:ss]}` comments may carry the
  clock times.
- **Correspondence.** Every move is a kind `64` note by the player who moves, containing the whole
  game so far with result `*`, and a second `e` pointing at the previous move (none for the first
  move). The move that ends the game (mate, or a resignation or draw claim entered as the final
  record) carries the result. Each note is a complete NIP-64 game, so any NIP-64 client can show the
  current position; the chain of `e` references is the order of the moves. A game of 40 moves is 80
  notes of 0.3 to 1 KB each.

**Ordering and replay protection for moves.** A move is valid only if its previous-move `e` points
at the current last move of the game, its author is the player to move, its PGN equals the previous
PGN plus exactly one legal move with identical headers, and its `created_at` is not earlier than the
previous move's. A replayed old move does not point at the current last move and changes nothing.
Two different moves signed by the same player on the same predecessor are an equivocation: the
first the league accepted counts, and the second is signed evidence against its author. A move that
arrives after the time control allows (`1/86400`: more than 86400 seconds after the previous move)
loses on time; the league decides that with `resolution` `forfeit`.

### League Attestation (`2154`)

Signed by the league key. Required tags: `e` for every event of the chain it decides on
(challenge, accepting answer, and every report or game record and response, if there are any), `a`
ladder, `a` challenger and challenged lineup with roles whenever the challenge has lineup sides,
`resolution`, `winner`, `elo` for both rated entities, `prev` (except for the first attestation of a
season), `alt`.

Scores and roster (series games):

- `confirmed`: the attestation copies the `score` tags and the roster `p` tags of the confirmed
  report verbatim.
- `admin`: `score` and roster are the league's decision and are required; they may differ from
  every report (for example after checking the end-screen screenshots).
- `forfeit`, `void`: without any report there are neither; after a report they are copied from the
  latest report.

Plain `p` tags without side may notify the captains. With `resolution` `admin`, `forfeit` or
`void`, `content` states the public reason for the decision.

Revision 4 adds three things to every attestation of a revision-4 ladder:

- `match`, copied from the challenge; every attestation of a chess team match carries the challenge's
  number, the boards are told apart by `board`;
- `clan`: for every rated player who was an active member of a clan at the accept (their `12150` then
  pointed at the clan and the clan listed them), one row `["clan", "<pubkey>", "<clan address>"]`. The
  league knows the membership at the accept from its archive of `12150` versions; relays keep only the
  newest one, which is why the attestation freezes it;
- the tournament `a` if the challenge has one. Revision 7: every attestation of a tournament match
  carries the tournament `a`, also a [director result](#director-results-rev-7), which has no
  challenge. It is the marker that keeps the attestation out of the season chain
  ([Blocks](#blocks-block-in-2154)).

**Mix teams are unrated** (rev. 4). An attestation of a challenge with a roster side (a mix team;
rev. 7.1: not a single-player side on the 1v1 player ladder) carries `score`,
the roster, `resolution`, `winner` and `match`, but no `elo`, no `trust`, no `gate` and no `clan`. The
other side's rating does not move, and the match counts for no Block Height and no clan hashrate. The
result is still backed by signatures: the report and the confirmation are signed by roster players or
acting captains of the two sides.

On a ladder with `trust`, every attestation also carries the `trust` tag in force at the accept and
one `gate` row per gatekeeper and rated player (see [Trust gate](#trust-gate)). An attestation without
any report or game record (a whole-team no-show) carries the gate rows of the accept as well.

**One attestation per ladder and pairing.** An attestation rates exactly one pairing of two entities
on one ladder: the two lineups of a series, or two players.

**Chess.** Every chess game is attested on the player ladder of its mode with one `board` row, copied
from the confirmed game record, and the two players as roster entries by side. A **team match over
`n` boards has no attestation of its own**: it produces `n` board attestations, each also carrying
the two lineup `a` references with their roles and the players' lineup roles, chained by `prev` like
any other. The team result is derived from them: the sum of board points (win 1, draw ½, loss 0) of
the attestations that reference the challenge; the side with more points wins the match, equal
points are a draw. The team result moves no rating. If not a single board was played (a whole team
did not show up), the league attests the challenge once with the two lineups, `resolution`
`forfeit` or `void`, `winner`, and neither `board` nor `elo`; that attestation is unrated. A board
attestation's `challenger` and `challenged` are the players of that board by side, not by color.
Each attestation references the challenge, the accepting answer and the counted game record with its
response; for correspondence the final move is the record, and its `e` chain leads to every other
move.

**No-show.** When a lineup does not show up for an accepted match, nobody signs a report. The league
decides the match with `resolution` `forfeit` and `winner` set to the lineup that showed up,
referencing only the challenge and the accepting answer. It is rated like any other series result
(see [Rating](#rating)).

**What an attestation proves and what it does not.** With `resolution` `confirmed` the result and
the roster are backed by two signatures the league cannot produce: the report of one captain and
the confirming response of the other. The league only adds the rating arithmetic, which anyone can
recompute from the ladder's `rating` and the `elo` chain. With `admin`, `forfeit` or `void` the
league decides; the resolution says so openly, so a reader can weigh admin decisions differently
from confirmed results.

#### Director results (rev. 7)

A tournament can be run by **directors** instead of by its players: the organiser and the directors
named for it enter each result, for example for games played over the board at a meetup. The players
then sign nothing for the match: no challenge, no answer, no report, no game record, no response.
The only event is the attestation, and it says so:

- `["entered-by", "<director pubkey>"]`: exactly one, lower-case hex (never an `npub`), naming the
  director whose entry stands when the league signs, that is, the last one who entered or corrected
  the result. The earlier entries and corrections stay in the league's log.
- the tournament `a` (required with `entered-by`, and `entered-by` appears in no other attestation);
- `resolution` `admin` for a played result, `forfeit` for a no-show; never `confirmed` (nobody
  confirmed anything) and never `void` (a director does not void; an admin does, as in any match);
- no `e` is required, because nothing exists to reference; `content` states that the result was
  entered by a tournament director;
- **series**: `score` and roster as for `admin` ("the league's decision"): the roster comes from the
  director's entry; where the entry does not say who played, it is each side's regular players
  (lineup roles `captain` and `player`, no `substitute`) as pinned at the pairing, and for a player
  side its one player (rev. 7.1). A no-show `forfeit` has neither, as in
  [League Attestation](#league-attestation-2154);
- **chess**: one `board` row with the director's result, the two players as roster entries by side
  (White `challenger`), and `winner` following from the `board` row;
- everything else as for any attestation of the ladder: `elo` (except for a forfeit, below), `prev`,
  `match`, `trust` and `gate` rows on a ladder with `trust` (a league pairing needs no opponent list),
  `clan` rows for every rated player with a clan at the pairing.

**What is rated (rev. 7.1).** A director's word is the only evidence behind a director result, so two
limits apply:

1. **A director forfeit is never rated.** A no-show or other forfeit that a director entered is
   attested with `resolution` `forfeit` and **no `elo` row**; it moves no rating and is no
   [rated result](#rating) (step 5), so it counts for no Block Height and no clan hashrate. The bracket
   still advances. On a ladder with `trust` it keeps the `trust` tag and the `gate` rows pinned at the
   pairing, for the gatekeepers and the players on its roster, and it has `clan` rows only for players
   on its roster (a chess forfeit names both players; a
   series forfeit has no roster, so none). (A forfeit that the league decides outside director mode, after a no-show in a
   match the players run, stays rated as before.)
2. **A played result is rated only if a disinterested director entered it.** A person has an
   **interest** in a match if they
   - play in it (belong to either entry),
   - belong to a clan in it, as member, captain or owner: the clan of an entered lineup, or the clan of
     any player of either entry, entered or not, or
   - were appointed director, directly or along the chain of appointments (who added whom), by someone
     with an interest; a director with no recorded appointer counts as appointed by the organiser.

   An **admin** of the league counts only with a personal interest (the first two); the appointment
   chain does not apply to them. An interested person never enters or corrects the result, and a
   league that let one do so must not rate it: that result carries no `elo`. Interest is judged at each
   entry and correction; `entered-by` names the last one, and every earlier one had to pass as well.

A played director result that passes both limits is rated like any `admin` result
([Rating](#rating), step 4) and counts for [Block Height](#block-height) and
[Clan hashrate](#clan-hashrate) by the same rules. No director result mines; no tournament match does
([Blocks](#blocks-block-in-2154)). Who is a director, who appointed whom, and clan memberships at entry
time are league data: a reader cannot check the interest rule, only the league's claim of it (see
"What it proves" below).

The **pairing** is the tournament's: it happens when the league puts the two sides into a match of
the director's open round. Everything "at the accept" (trust gate, `clan` rows, the ladder being open)
is read at that moment.

**What it proves.** `entered-by` is the league's statement about its own process: the director's key
signs nothing, so the tag cannot prove that this director entered this result, only that the league
names them for it. It makes a result accountable to a person, not verifiable. A reader weighs a
director result like any `admin` decision. (A director who signs a Result Report of their own would
close the gap; see [Open points](#open-points).)

**Why a tag and not a `p`.** A `p` in an attestation is a roster entry (side in position 3) or a
notification. A director is neither: the query "matches a player played in" (`#p`) and
[Block Height](#block-height) must not return the results a director entered. `entered-by` carries
data and is never filtered on, like every multi-letter tag here.

### Tournament Draw (`2155`, optional)

Signed by the league key. It freezes a tournament's **solo pool** and commits to a future Bitcoin
block whose hash sorts the pool into **mix teams**, so that nobody, the league included, can choose
who plays with whom. Clan lineups are not drawn: the tournament rules seed them by their rating at
registration close, and the mix teams follow them in draw order (see [Tournaments](#tournaments)).

Required tags (rev. 4): `a` ladder (the one the tournament's matches are played on), `a` tournament
(`31923`), one `p` per solo entrant with role `entrant`, `draw`, `teams`, at least as many `teamname`
as there are teams, `alt`. Optional: `name`, `format`, and an `e` pointing at an earlier draw of the
same tournament that this one replaces (the reason goes into `content`).

Revision 7: the ladder `a` is the tournament's. A draw carries it exactly when the tournament's
`31923` names a ladder, and then with the same value; a draw of an unrated tournament (published
before Block 0 or between seasons, see [Tournaments](#tournaments)) carries no ladder `a`. The draw
never looks up which ladder is open when it is signed. A draw needs **at least one full team**: a
solo pool with fewer players than the team size is not drawn, and its players are reserves.

**Algorithm `sha256-v1`.** Let `H` be the block hash at the height given in `draw`, as lower-case
hex. For every entrant compute `SHA-256(UTF-8("<H>:<pubkey>"))` with the lower-case hex pubkey. The
draw order is the list of entrants sorted by that digest, ascending (compared as lower-case hex).
With team size `s` from `teams`, team `n` (from 1) is entrants `(n-1)*s+1` to `n*s` of the draw order
and gets the `n`-th `teamname`; the entrants after the last full team are reserves, in draw order. A
mix team's challenges carry its members as a roster side, which the league accepts only if they are
exactly one team of the draw.

Revision 3 used the same kind for the slot order of a whole bracket (lineup entrants with `a`, slot
`n` = entrant `n`, the tag `tournament`). That meaning is replaced: seeding lineups from a public
rating is already verifiable, and only the grouping of solo players needed a commitment.

**Why a kind at all, and why this one is optional.** A signature alone proves only that the league
published a pairing, not that it did not pick it. With a committed block hash, the order is fixed
by something outside the league's control, and anyone can re-run it with a block explorer. The
commitment holds only if the draw was public before block `H` was mined. `created_at` is chosen by
the league and proves nothing about that. A NIP-03 OpenTimestamps attestation (kind `1040`) of the
draw event proves it, provided the timestamp is confirmed in a block below `H`; choose `H` far
enough ahead (for example six blocks) to leave room for that. A league that seeds tournaments by
hand simply does not publish a draw; nothing else in this NIP depends on it.

**Limits.** A replacement draw (`e` to the earlier one) commits to a new, later block and is as
public as the first; a league that replaces a draw after its block was mined has re-rolled it, and
everyone can see that it did. Short of a miner withholding a found block, nobody can steer the hash.

Registration is deliberately not a published event. Sign-ups and withdrawals change often until the
draw and matter to nobody outside the tournament; the draw freezes the final solo pool publicly, and
an entrant who is missing can object before block `H`. Each sign-up and withdrawal is still signed by
the person who makes it, as a [Tournament Consent](#tournament-consent-22150) that only the league
keeps (rev. 7).

### Tournament Consent (`22150`)

Rev. 7. The signed statement of a player or captain who enters a tournament or pulls an entry out. The
league checks it, stores it with the entry, and **never publishes it**: not on the league relay, not
anywhere else. It is ephemeral (NIP-01 `20000 <= n < 30000`), so a copy that reaches a relay by
mistake is not stored there.

| tag | `signup` | `withdraw` | meaning |
|---|---|---|---|
| `a` | required | required | `31923:<league>:<slug>`, the tournament, exactly one without role |
| `action` | `signup` | `withdraw` | what this consent does |
| `e` | the id of the tournament's `31923` version shown when signing | the id of the sign-up consent it withdraws | exactly one; binds a sign-up to the rules it accepted, a withdrawal to the entry it ends |
| `a` with role `entrant` | lineup entry only | lineup entry only | `["a", "<lineup address>", "", "entrant"]`, the lineup entered |
| `p` with role `entrant` | required | required, copied from the sign-up | `["p", "<pubkey>", "", "entrant"]`, one per player entered: the author for a solo entry; the players the captain enters for a lineup |
| `alt` | required | required | NIP-31 text, e.g. `Tournament sign-up: <name>` |

`content` is the sentence the player agrees to, in words a person reads in a signer's prompt (for
example "I enter <tournament> and accept its rules."); it holds nothing private (rule 6). No
`expiration`, no `u`, no `method`.

**Who signs.** A solo entry and its withdrawal: the entering player, who is the only `p`. A lineup
entry and its withdrawal: an acting captain of that lineup. The captain enters players of the lineup
without their signature: under revision 6 an active clan member is an active player of every lineup of
the clan that lists them, and that membership is their consent to play for it.

**What the league checks** before it acts on a consent, and stores:

1. `id` and `sig` valid, kind `22150`, author equal to the logged-in account that submits it;
   `created_at` within the window of rule 4; the id not seen before (rule 5), so every consent is used
   once and a player who re-enters signs again;
2. the tournament `a` names a `31923` of the league key whose sign-up is open; for `signup` the `e`
   is the id of its current version; for `withdraw` the `e` is the stored sign-up consent of an active
   entry, and the `p` and lineup `a` are that consent's;
3. the author may make this entry (rules above); each `p` is an active player of the entered lineup,
   or the author for a solo entry; the league's entry rules hold (one entry per person, lineup size,
   capacity): those are tournament policy and are not part of this NIP;
4. `alt` present, no `expiration`, the tags and `content` as the league prepared them for signing.

The league stores the **complete signed event** (id, pubkey, created_at, kind, tags, content, sig)
with the entry, and the withdrawal next to it, at least until the tournament's prizes are settled
([Payout](#payout-2157)). That is the evidence if an entrant later says they never entered, or never
pulled out. The draw (`2155`) is the only public trace of the entries.

**Why not NIP-98 (`27235`).** NIP-98 "defines an ephemeral event used to authorize requests to HTTP
servers": its `u` "MUST be exactly the same as the absolute request URL" and its `content` "SHOULD be
empty". A consent authorises no request (the sign-up is not a request to the URL it would name) and its
content is the text agreed to. It also matters in the signer. nostr-mill, the league app's signer,
names a known kind by its table before it looks at `alt`, so a `27235` prompt reads "HTTP
Authentication", and it keeps grants per kind, so an "always" or "this session" that a player gave for
the login (the same kind) signs a sign-up without asking. An unknown kind is named by its `alt` and
needs a grant of its own; only a category-wide pre-approval chosen at setup ("other") covers both kinds
alike. (A remote signer that was granted `sign_event` for every kind at connect,
which is what nostr-mill's NIP-46 client requests, asks for nothing either way; there the app's own
confirmation is the consent.)

## Tournaments

A tournament is a [NIP-52](https://github.com/nostr-protocol/nips/blob/master/52.md) time-based
calendar event (`31923`) signed by the league key, listed in the league's calendar (`31924`, `d` =
`tournaments`). Calendar clients show it, `naddr` links to it work in any client, and it is the zap
target of the prize pool (see [Prize pool funding](#prize-pool-funding)).

| tag | content |
|---|---|
| `d` | the tournament slug |
| `title`, `summary`, `image` | as in NIP-52 |
| `start`, `end`, `D`, `start_tzid` | NIP-52 time fields (`D` is required by NIP-52, which adds "Multiple tags SHOULD be included to cover the event's timeframe": one `D` per UTC day from `start` to `end`); the prize pool closes at `end` |
| `location` | the tournament page |
| `r` | the rules page |
| `a` | the league calendar (`31924:<league>:tournaments`), and the ladder the matches are rated on; rev. 7: the ladder only in a rated tournament, see below |
| `zap` | the pool key, weight `1` (NIP-57 appendix G): zaps go to the pool, not to the league key; rev. 7: only while the tournament has a prize pool, see below |
| `t` | hashtags, e.g. `esports` and the game |
| `alt` | NIP-31 text |

`content` states format, match size, seeding, how results are reached (players or directors), whether
the matches are rated, and the prize split in words. **What a tournament event never
contains:** the bracket, registrations, the pool amount or results. They change during the tournament;
an addressable event keeps only its newest version, and a stale copy would be a second truth next to
the attestations. A change of time or rules is a new version of the same address.

**Rated or unrated (rev. 7).** Before Block 0 of the first chain season, and between seasons, no ladder
is open ([Rest](#rest-before-block-0-and-between-seasons)), yet tournaments go on. The ladder `a` of a
tournament is therefore optional, and it is **frozen with the first version**:

- the league adds the ladder `a` exactly when, at the signing of the tournament's first `31923`
  version, a ladder of the tournament's game and mode is open (its season has started, rev. 5: its
  genesis exists, and it has no `ends`). Later versions carry the same ladder `a`, or none if the
  first had none; a ladder that opens later is never added;
- a tournament **without** a ladder `a` is **unrated** for its whole run: its matches are casual,
  produce no match-flow event and no attestation, even if a ladder of its game and mode opens while it
  runs. Its `content` says so;
- in a tournament **with** a ladder `a`, a match is rated on that ladder only if the ladder is still
  open (no `ends`) at the match's pairing and the [trust gate](#trust-gate) passes on rank; otherwise
  the match is casual. A season that ends during a tournament ends its rated matches. A match is
  never rated on any other ladder.

Why frozen: whether a tournament counts is then decided and public before anyone signs up, the consent
(`22150`) names the version that says so, and one bracket never mixes rated and unrated rounds because
Block 0 happened to fall between them.

**No blocks (rev. 7).** Tournament matches never mine: the season chain belongs to the season, and a
tournament has its own prize pool. An attestation that carries a tournament `a` is not a block
candidate and carries no `block` tag ([Blocks](#blocks-block-in-2154)); a tournament challenge carries
no match-fee `zap`. Rating, Block Height and clan hashrate count tournament matches like any other.

**Prize pool and `zap` (rev. 7).** A tournament version without `zap` has no zappable pool. A client
that zaps it anyway follows NIP-57 and pays the author's lightning address, the league key's; the
league's LNURL endpoint refuses a zap request whose `a` names a tournament without `zap` in its current
version, so no receipt arises and nothing is counted. The league adds `zap` with a new version when the
pool opens; receipts count from then on, under the rules of [Counting the pool](#prize-pool-funding).

**Seeding.** Clan lineups are seeded by their rating on the tournament's ladder at registration
close, mix teams follow in the order of the draw. The rating is public (`32152`), so the seeding can
be checked; the tie-break is league policy (see [Open points](#open-points)). In an unrated tournament
(rev. 7) the seeding ratings are the league's casual ratings, which are not on Nostr: that seeding
cannot be checked from relays.

**Everything of a tournament** references its address: `{"#a":["31923:<league>:<slug>"]}` returns the
challenges, answers, reports, responses, attestations, the draw and the zap receipts.

**Why the league key.** Draws and attestations are signed by the league key and reference the
tournament, and a tournament signed by another key could be replaced by someone the attestations do
not name. The cost is a wider key scope for the league key (`31923`, `31924`); see
[League relay and keys](#league-relay-and-keys).

## Queue pairings

Rated blitz is played from a **queue**: players who are online and searching are paired by the league.
The match flow is the same as for a challenge, with the league choosing the sides.

1. **Consent.** Joining the rated queue of a ladder is the player's consent to one rated game against
   whoever the league pairs them with. The app says so on the button. After a pairing the two apps
   sign `2150` and `2151` without a further prompt.
2. **Pairing.** The league pairs two players, checks conditions 2 and 3 of the
   [trust gate](#trust-gate) (rank) and its pairing limit, reserves a match number, decides the
   colors, and names the challenger (the player who has waited longer).
3. **Challenge.** The challenger's app signs `2150` with `pairing` `queue`, `match`, `color`, one
   `start` a few seconds ahead and `respond_by` shortly after it.
4. **Answer.** The other app signs `2151` `accepted`. If it does not answer before `respond_by` (tab
   closed, signer offline) the challenge expires unrated; its number stays unused.
5. From here on it is a solo game: the final game record (`64`), the response (`2153`) and the
   attestation (`2154`) with four `e` references, `gate` rows without opponent lists, `clan` rows and
   the match number.

Rematches and "invite a friend who is online" are ordinary challenges without `pairing`, and the full
trust gate applies. A login through nostr-mill with Google signs through a remote signer (NIP-46),
so each automatic signature is a network round trip; its latency was not measured (see
[Open points](#open-points)).

## Rating

The `rating` tag of the ladder fixes the arithmetic for the whole season.
`["rating","elo","<start>","<k>","<scale>"]` means:

1. An entity without an earlier attestation in this ladder starts at its `seed` if the ladder has
   one for it, otherwise at `<start>`.
2. One rated result per attested pairing: per series (Rocket League), per game or board (chess).
   For the challenger with rating `Rc` against the challenged side with rating `Rd`:
   `E = 1 / (1 + 10^((Rd - Rc) / scale))`, `S = 1` if the challenger won, `1/2` for a `draw`, `0` if
   it lost.
3. Each side has its own k-factor: the provisional k-factor of `provisional` while that side has
   fewer rated results in this ladder than `provisional` requires (counted before this result),
   otherwise the `rating` k-factor. `delta_c = round(k_c * (S - E))` and
   `delta_d = round(k_d * (S - E))`, rounded to the nearest integer, halves away from zero. The
   challenger's rating after is `Rc + delta_c`, the challenged side's is `Rd - delta_d`. Without a
   provisional k-factor both are the `rating` k-factor and the result is zero-sum; with one, a pairing
   of a provisional and an established entity is not zero-sum, by design.
4. `confirmed`, `admin` and `forfeit` are rated. `void` is not: its `elo` values have `before` equal
   to `after`. Rev. 7.1: a forfeit entered by a tournament director is not rated and carries no `elo`,
   nor is a director result entered by an interested person
   ([Director results](#director-results-rev-7)).
5. A **rated result** is an attestation with `confirmed`, `admin` or `forfeit` that carries `elo`.
   An entity's `wins`, `losses` and `draws` in `standing` count its rated results; while their sum is
   below `provisional`, its tier is shown as `provisional`, afterwards it is the highest `tier` whose
   minimum does not exceed its rating.

Rounding half away from zero is symmetric (`round(-x) = -round(x)`), so it does not matter from
whose side a delta is computed, and a board's challenger may have either color.

Ratings are integers. Implementations compute `E` in IEEE 754 double precision. The rounding rule
is not what most standard functions do: JavaScript's `Math.round(-0.5)` is `-0` and
`Math.round(-15.5)` is `-15`, Python's `round()` rounds halves to even; PHP's `round()` rounds halves
away from zero as required. A client that verifies ratings in JavaScript needs
`Math.sign(x) * Math.round(Math.abs(x))`. Halves are rare in step 3 but common in
[Season transition](#season-transition) with a factor of `0.5`; the example below hits both
directions.

### Rank tiers (rev. 5)

The `tier` tag carries any number of tiers; the league uses Rocket League's scheme, seven ranks with
three levels each, **Grand Champion III** the highest. Tier names are tokens, not display text:
`<rank>-<level>`, which a client shows as the rank with the level in Roman numerals
(`diamond-2` is "Diamond II"). A client that does not know a token shows it as it is. Season 1
thresholds, published in every example ladder since revision 5:

| rank | level I | level II | level III |
|---|---|---|---|
| Bronze | `bronze-1` 0 | `bronze-2` 900 | `bronze-3` 925 |
| Silver | `silver-1` 950 | `silver-2` 975 | `silver-3` 1000 |
| Gold | `gold-1` 1025 | `gold-2` 1050 | `gold-3` 1075 |
| Platinum | `platinum-1` 1100 | `platinum-2` 1125 | `platinum-3` 1150 |
| Diamond | `diamond-1` 1175 | `diamond-2` 1200 | `diamond-3` 1225 |
| Champion | `champion-1` 1250 | `champion-2` 1275 | `champion-3` 1300 |
| Grand Champion | `grand-champion-1` 1325 | `grand-champion-2` 1375 | `grand-champion-3` 1425 |

Levels are 25 rating points apart, Grand Champion 50. The start rating 1000 is Silver III, and the
thresholds of the five earlier tiers (950, 1025, 1100, 1175) stay where they were. The 21 `tier` rows
appear in the ladder in this order, lowest first. Until an entity has `provisional` rated results its
`standing` says `provisional`, as before; afterwards it names one of the 21 tokens (step 5 above).

## League-wide seasons

A season is one rating epoch for the **whole league**, not for one game. All games open and close
their seasons together, and season slugs are numbered league-wide: a game whose first ladder comes
later starts in the season that is current then. In the examples, Rocket League was in `season-2`
and chess in `season-1` when the league moved to league-wide seasons; both then continued in
`season-3`.

**One parameter set, copied into every ladder.** The league keeps one set of season parameters
(`rating`, `tier`, `provisional`, the `reset` factor, `trust`, and in revision 4 `hashrate`) and
writes it into every ladder of the season. A game may override single parameters (for example fewer provisional results for
correspondence chess); such a ladder names the overridden tags in `override`. Every ladder still
carries its complete parameter set, so a ladder can be verified on its own, exactly as before.

Rules for two ladders of the same league with the same `season`:

1. `starts` is equal, and `ends` is equal once both carry it. The window is never overridden; it
   is what makes the season league-wide.
2. `rating`, `tier`, `provisional` and `trust` are equal, unless one of the two ladders names the
   tag in `override`. `hashrate` is always equal and cannot be overridden: clan hashrate adds points
   across games.
3. If both carry `reset`, the factor (position 3) is equal unless one of them names `reset` in
   `override`. The other positions of `reset` and all `seed` values are per ladder by nature.

Game-registry values (`game`, `mode`, `rates`, `time_control`, `variant`) are not season
parameters and differ freely.

**No season event.** A league-level season event (a new kind, or a `32152` with a `d` of its own
shape) was considered and rejected. Its only gain would be that ladders could leave out inherited
parameters. The cost: verifying a ladder would need a second addressable event, which relays let
the league replace, so the frozen-parameter rule would have to hold across two events instead of
one. Finding all ladders of a season needs no extra event either: `{"kinds":[32152],"authors":["<league>"]}`
returns every ladder of the league (a handful per season), and the reader filters on `season`.

**Revision 5 adds a season event, for the chain only.** The rating parameters stay in the ladders as
above. The chain parameters (supply, rewards, caps) span all games and decide money, so they go into a
**regular** Season Genesis (`2156`), which relays cannot replace and which block 1 pins by id: the
objection above was to an addressable event. A chain season's ladders name the genesis by `e`, which
also finds them all: `{"kinds":[32152],"authors":["<league>"],"#e":["<genesis id>"]}`. See
[Season chain](#season-chain-rev-5).

## Season transition

Seasons follow each other league-wide: a season change closes every open ladder and opens the next
season for every game at once. Per game and mode, to go from season `A` to season `B` the league:

1. Stops creating new challenges on `A` and resolves every challenge of `A` that is open, accepted,
   reported or disputed (answer deadline, attestation, `void`).
2. Publishes a final version of the `A` ladder with `ends` set. From then on, no match-flow event
   that references `A` is accepted.
3. Publishes the first version of the `B` ladder with its own parameters, a `reset` tag and one
   `seed` per entity that has at least one rated result in `A`. `reset` names the `A` ladder, the id
   of the last attestation of `A`, and the carry-over factor `f` between `0` (hard reset) and `1`
   (full carry-over).

For each entity, `seed = start_B + round((final_A - start_A) * f)`, with the rounding of step 3 of
[Rating](#rating), where `final_A` is the entity's rating after the pinned attestation and
`start_A`, `start_B` are the start ratings of the two seasons. Entities without a seed start at
`start_B`.

In a chain season (rev. 5) the new ladders open right after the genesis, with `starts` equal to Block
0, and between the end of `A` and Block 0 of `B` rated play rests (see
[Rest](#rest-before-block-0-and-between-seasons)). The reward chain does not carry over: each season
has its own genesis, and what `A` did not pay goes to the league reserve.

**What a reader checks:** replay `A` up to the pinned attestation (it must be the last one of `A`;
an `A` attestation after it is a league error), apply the formula, compare with the `seed` rows,
then replay `B` from those seeds. Every rating in every season is then derived from signed, immutable
events and the parameters in the ladders.

## Trust

Anyone can join: a login with a Google account creates a key in seconds. Without a further check, a
player could create opponents that lose to him on purpose and farm rating. The league therefore
rates a game only if, **at the moment it is accepted**, the two players who agreed to it list each
other as opponents and every player whose rating it moves has a **trust rank** at or above the
ladder's minimum. Casual games are unaffected: they produce no match-flow events and need neither.

Trust is built entirely from existing NIPs. This NIP adds no kind for it, only the tags `trust` and
`gate`.

| what | reused from | kind | signed by |
|---|---|---|---|
| opponent list | [NIP-51](https://github.com/nostr-protocol/nips/blob/master/51.md) follow set | `30000`, `d` = `esports/<league key>` | the player |
| trust rank | [NIP-85](https://github.com/nostr-protocol/nips/blob/master/85.md) trusted assertion | `30382` | the trust key |
| description of the trust algorithm | NIP-85, appendix 1 | `0` | the trust key |
| report | [NIP-56](https://github.com/nostr-protocol/nips/blob/master/56.md), qualified with [NIP-32](https://github.com/nostr-protocol/nips/blob/master/32.md) labels | `1984` | any player |
| Nostr follow list | [NIP-02](https://github.com/nostr-protocol/nips/blob/master/02.md) | `3` | the player; **read only, never written, never counted** |

The mutual listing alone does not stop sock puppets: one person controlling two accounts lists both
in a second. It makes a rated game a deliberate pairing of two people who know each other. The
defence against sock puppets is the rank, which only flows from anchors (see the algorithm below).

### Opponent list (`30000`, reused from NIP-51)

A player's opponent list is a NIP-51 follow set with `d` = `esports/<league key>` (the league key
in hex, so lists for different leagues never mix), a `title`, one `p` per listed player, and `alt`.
"Add as opponent" in the app appends a `p`; accepting a request adds the requester. Two players
**list each other** when the newest opponent list of each contains the other.

Why a follow set and not the contact list: kind `3` is replaceable and holds the user's whole
social graph. An app that writes it from a stale copy deletes follows, because every new version
overwrites the previous one (NIP-02). The league never writes kind `3`. A follow set with its own
`d` is replaceable too, but it holds only what this league put there, and its meaning is narrower:
a Nostr follow often means interest in someone's notes, an entry here means "I play rated games
against this person".

- **Newest version.** The league reads the newest version it knows: every version the app writes is
  submitted through the league, and versions made in other clients are read from the author's
  NIP-65 write relays. Newest is the highest `created_at`, ties broken by the lowest id (NIP-01). A
  version not newer than the stored one is refused, as for memberships (rule 9).
- **Public only.** `content` stays empty. NIP-51 private items are ignored: they are encrypted to
  the author, so the league cannot read them.
- **Kind `3` is a hint, not an input.** The app reads the Nostr follow list to suggest opponents
  ("people you follow who play here") and to show mutual contacts before an accept. It never enters
  the gate or the rank: a follow list of hundreds of entries would say nothing about this league,
  and it is written by other apps under other rules.

### Trust rank (`30382`, reused from NIP-85)

The league computes trust with a dedicated **trust key** and publishes one NIP-85 user assertion per
player: `d` = the player's pubkey, `p` = the same pubkey (NIP-85 allows it; it makes the assertion
findable with `#p`), `rank` = integer 0-100, `alt`. The trust key is not the league key: NIP-85
requires a key per algorithm, and the league key keeps signing nothing but league events. The trust
key's kind `0` names the algorithm and its parameters (NIP-85 appendix 1). A new algorithm version
gets a new key, and the ladders name the key in their `trust` tag.

- A pubkey that never had a rank above 0 gets no assertion, and a missing assertion means rank 0.
  When a published rank drops to 0, the trust key publishes a new version with `rank` 0, so that
  the old value does not stay on the relays.
- The league recomputes periodically and republishes only the ranks that changed (NIP-85: "only if
  the contents of each event actually change").
- **The gate only uses published assertions**, so every gate decision can name the assertion it
  used.
- Other clients can show these ranks: a user of a NIP-85 client adds
  `["30382:rank", "<trust key>", "<relay>"]` to their kind `10040`.
- **Anchor reference** (rev. 4). Each assertion carries `["e", "<anchor list id>", "<relay>"]`, the
  version of the anchor list the rank was computed from.
- **Anchor subtree** (rev. 5). Each assertion also carries `["anchor", "<anchor pubkey>", "<percent>"]`,
  the anchor with the largest share in the player's trust; consensus rule 7 of the
  [season chain](#consensus-rules-season-chain-v1) uses it. It is an additional output of the same
  algorithm; the rank does not change, so the trust key stays the same.

### Anchor list (`30000`, rev. 4)

The association decided that paid membership is public; the site shows a member badge. The trust key
therefore publishes the anchors as a NIP-51 follow set: `d` = `esports/<league key>/anchors`,
`title`, `description`, one `p` per anchor, `alt`. It publishes a new version whenever the anchor set
changes, before the trust run that uses it, and every assertion of that run references the version by
`e`. With the anchor list, the players' opponent lists and the counted reports, anyone can recompute
every rank (relay proof, round 4).

The list is addressable, so relays keep only its newest version, while older assertions still point at
the version they were computed from. The league archives every version and serves it by id, like the
other gate evidence.

### Trust gate

The gate applies on a ladder that carries `["trust", "<trust key>", "<minimum rank>"]`. The presence
of `trust` is frozen for the season; its values may change between versions of the ladder (for
example an admin raising the minimum). A ladder without `trust`, such as every ladder of the round
1 and 2 examples, has no gate.

- **Gatekeepers** are the two pubkeys that agreed to the pairing: in a solo game the two players; with
  lineups the author of the challenge and the author of the accepting answer (acting captains).
- **Rated players** are the players the attestation rates or lists on its roster: the two players of
  a solo game or board, the roster players of a series.

When the league receives an accepting answer (`2151` `accepted`) on a gated ladder, it requires:

1. each gatekeeper's newest opponent list lists the other gatekeeper;
2. each gatekeeper has a published assertion with rank at least the minimum;
3. every active player of both lineups whose rank is below the minimum is marked ineligible for this
   match: the match room does not let them play, and a report whose roster lists one is invalid.

If 1 or 2 fails, the league refuses the answer and the challenge stays open. The league SHOULD also
refuse a challenge (`2150`) whose gate would fail at that moment; the app offers a casual game
instead. Nothing after the accept undoes the gate: removing the opponent from the list or a lower
rank after the accept leaves the match rated, so nobody can unrate a game they are losing.

**League pairings** skip condition 1; conditions 2 and 3 still apply, checked when the league makes
the pairing. In revision 4 these are the challenges with `pairing` `queue` or `tournament`; in
revision 3, a challenge with `tournament` that the league's bracket created. Farming needs choosing
one's opponent. A bracket pairing cannot be chosen; a queue pairing can be steered by two players
who join the queue at the same moment when few are online, which is why the queue has a pairing
limit (league policy) and the rank still applies. A match with a mix team is unrated and has no gate.

**Evidence.** The attestation (`2154`) of a gated ladder copies the `trust` tag that was in force at
the accept, and carries one `gate` row per gatekeeper and per rated player:
`["gate", "<pubkey>", "<rank>", "<assertion id>", "<opponent list id>"]`. The opponent list id is set
for the two gatekeepers and empty for the other rated players and for league pairings. Revision 5
sets it for every player whose list names their direct opponent (the other gatekeeper, the other
player, the board opponent), league pairings included; the season chain needs it (rule 1). Challenge
and answer carry no evidence: their signatures are the players' consent, and the gate is an admission
rule the league enforces and states in its own attestation, a regular event that keeps the values
for good.

**Checking a gate** needs the referenced events. Relays keep only the newest version of an opponent
list and of an assertion, so the version a gate used disappears from the relays as soon as the
player edits the list or the rank changes. In the examples, alice's list used for the Rocket League
series was replaced one minute later and is gone from all three relays. The league therefore
archives every version it referenced and serves it by id (in the app's proof details). A gate whose
evidence cannot be found is unverifiable, not failed.

**What a gate proves, and what it does not.** It proves that both players signed lists naming each
other before the accept, that the trust key signed the ranks before the accept, and that the ranks
met the minimum. In revision 4 each assertion names the anchor list it was computed from, so the
ranks can be recomputed too (see [Anchor list](#anchor-list-30000-rev-4)).

### Algorithm `anchored-trust-v1`

Inputs: the **anchors** (the paid members of the association for the current or the previous year,
from the association's API, and the league admins; published as the [anchor list](#anchor-list-30000-rev-4)),
the newest opponent list of every pubkey, the counted reports and the admin exclusions (see
[Reports](#reports-1984-reused-from-nip-56)).

1. Remove excluded pubkeys.
2. **Layers.** Anchors are layer 0. A pubkey listed by a pubkey of layer `h` that is in no earlier
   layer is in layer `h+1`. Layers stop at 3.
3. **Raw trust.** For an anchor, `raw = penalty`. For layers 1, 2, 3 in order:
   `raw(v) = penalty(v) * min(1, sum over u in layer(v)-1 listing v of 0.5 * raw(u) / |L(u)|)`,
   where `|L(u)|` is the number of distinct pubkeys in `u`'s list other than `u`. `penalty` is 1,
   halved per counted report (at most two count), 0 if excluded.
4. **Rank.** `rank = 0` if `raw = 0`, else
   `rank = clamp(round(100 + 25 * log2(8 * raw)), 0, 100)`, rounded as in [Rating](#rating).

The scale reads: 100 means `raw >= 1/8`; every 25 points are a factor of 2; 50 is `raw = 1/32`; 0
is `raw <= 1/128` or unreachable. The gate minimum defaults to 50.

Why this shape:

- **Trust only flows forward**, from one layer to the next. Edges inside a layer or back to an
  earlier one carry nothing, so a ring of accounts cannot feed itself. In PageRank, where rank also
  circulates in cycles, a closed ring multiplies what flows into it.
- **A voucher splits its trust over the whole list.** Listing more people makes each entry weaker.
  One anchor alone lifts at most 16 players to rank 50.
- **Three hops** end every chain.
- **Anchors are not singled out.** An anchor has rank 100, as has everyone with `raw >= 1/8`, for
  example a player listed by an anchor with at most four entries.

Measured with this algorithm on constructed graphs (relay proof, round 3):

| situation | result |
|---|---|
| newcomer listed by one anchor whose list has 1-4 / 8 / 12 / 16 / 17 / 32 entries | rank 100 / 75 / 60 / 50 / 48 / 25 |
| newcomer listed by 1 / 2 / 3 / 4 players, each listed by an anchor, each with 5 entries | rank 17 / 42 / 57 / 67 |
| ring of 100 accounts listing each other and five anchors, listed by nobody outside | 0 reached, rank 0 for all |
| the same ring, one anchor fooled into listing one ring account (list of 10) | that account 67; the other 99: rank 0 |
| the same ring, one player at rank 100 lists one ring account (list of 5) | highest ring rank 17, none at the gate |
| a colluding anchor builds a tree of fresh accounts (search over the list size per layer, trees only) | best found: 16 at minimum 50 (32 at 25, 8 at 75); a chain of single entries reaches 3 accounts |
| one / two counted reports against a player at rank 100 (anchor list of 4) | 75 / 50 |

The algorithm is personalized PageRank with the anchors as the only seed (as in TrustRank,
Gyöngyi, Garcia-Molina and Pedersen, "Combating Web Spam with TrustRank", VLDB 2004), restricted to
forward edges and three hops. Existing services compute related scores (see
[Prior art](#prior-art)); the league does not use them for the gate, because their seed is the whole
Nostr graph or a single user rather than the association's members, and their inputs can be farmed
outside the league. A client MAY show them as additional information.

### Reports (`1984`, reused from NIP-56)

A player reports another with a NIP-56 report: `p` with the target and a NIP-56 report type
(usually `other`), qualified with the NIP-32 namespace `L` = `space.einundzwanzig.esports` and one
`l` of `multi-account`, `result-fixing`, `cheating`, `abuse`. `content` is a public reason; evidence
never goes into the event.

A report **counts** for a run if it carries the league namespace, its author had rank at least 50 in
the previous run, it is at most 365 days old and no admin has dismissed it; one report per author and
target. Each counted report halves the target's raw trust, at most two before an admin decides. An
admin either dismisses the report (it stops counting) or excludes the target (raw 0; the target is
removed from the graph, so its own entries stop vouching for anyone). In V1 these decisions stay on
the league server; their effect is public in the assertion.

The cap is deliberate: a player who lost must not be able to eject the winner with a report. Two
independent trusted reports push an account near the minimum below it until an admin looks.
Reports without the league namespace (general Nostr moderation) do not count.

### New players

A new player has rank 0 and plays casual games with anyone, without any listing. The ways to rated
play:

1. **A member adds them as opponent.** One anchor with a short list is enough (rank 100 up to 4
   entries, 50 at 16).
2. **Several vouched players add them.** Three players who are each listed by an anchor (with a
   list of four) and have five entries in their own lists lift a newcomer to 57.
3. **Clan.** When a captain invites a player, the app suggests that both add each other. The clan
   itself is no input: clans are founded freely.
4. **Tournaments.** League-made pairings skip the mutual listing, not the rank. A tournament is where
   newcomers meet members who can add them.
5. **Membership.** A paid member of the association is an anchor from the next run on.

Admins can change the minimum per season: at 25 (`raw >= 1/64`) one anchor vouches for up to 32
players; at 0 only the mutual listing remains.

### Privacy

- **Opponent lists are public.** Anyone who reads the relay sees who listed whom. Private list items
  are not usable, because the league cannot read them. The app says so next to the button, since its
  users do not see that it runs on Nostr.
- **Reports are public**, with the reporter's pubkey. A player who does not want to be seen reporting
  uses the league's support channel; an admin handles such a report, and it is not counted
  automatically.
- **Ranks are public** for every player who ever had a rank above 0; newcomers nobody listed have no
  assertion.
- **Membership is public** (decision of the association for this league). The anchor list names the
  paid members and admins, and the site shows the member badge. Anyone can recompute every rank from
  relay data.

## Global score

Two numbers per player across all games. Both are computed from public league events alone; the
league signs neither, for the same reason as the clan rating: a signed copy would only be a second,
possibly diverging statement of the same numbers.

### Block Height

The number of **rated results** a player took part in, over all ladders and all seasons of the league.
An attestation counts once for a player if its `resolution` is `confirmed`, `admin` or `forfeit`, it
carries `elo`, and the player appears in it as an `elo` entity (player ladders) or as a roster `p`
with a side (lineup ladders). Attestations are regular events and there are no corrections in V1, so
the number only grows.

Query: `{"kinds":[2154],"authors":["<league>"],"#p":["<player>"]}`, then the filter above; `#p` also
returns plain notification `p` tags, for example in a no-show forfeit.

What counts: each board of a chess team match for the two players of that board; a Rocket League
series once per roster player, however many of its games they played; a chess time-out forfeit for
both players; a no-show forfeit without a report for nobody; a match with a mix team (unrated, no
`elo`) for nobody; a director forfeit (rev. 7.1, no `elo`) for nobody; a Rocket League 1v1 series
(rev. 7.1) once for each of its two players.

### Global Rating

For the **current season** (the season of the league's open ladders):

1. For every ladder of the season that has at least one `standing`, and every entity the player
   played for on it (in a player ladder the player; in a lineup ladder each lineup whose roster listed
   the player), take the weight `w`: in a player ladder the player's `wins + losses + draws` from the
   standing; in a lineup ladder the number of rated attestations, up to the ladder's `e`, that list
   the player on that lineup's roster.
2. The entity's percentile in that ladder, counted from the ratings in the standings:
   `p = (entities rated lower + 0.5 * entities rated equal, itself included) / N`, with `N` the number
   of `standing` rows. `p` is never 0 or 1.
3. `W` is the sum of the weights. With `W < 5` the player has no Global Rating yet.
4. `pbar = sum(w * p) / W`, and `Global Rating = round(1000 + 200 * invnorm(pbar))`, rounded as in
   [Rating](#rating), with `invnorm` the inverse of the standard normal distribution function (AS 241,
   as in Python's `statistics.NormalDist().inv_cdf`), in IEEE 754 double precision.

A player at the median of every ladder they play has 1000; the 84th percentile gives 1200, the
97.7th 1400. Percentiles make games comparable where Elo values are not: each ladder has its own
k-factor, its own pool of players and its own size.

Limits: in a small ladder a percentile is coarse (with two entities it is 0.25 or 0.75, i.e. 865 or
1135); a new season starts without Global Ratings until players have five rated results in it; a
player's value moves when others play, because percentiles do.

Example (relay proof, round 3, computed from strfry): alice has four blitz results in
`chess/blitz/season-3`, where her 1028 is the highest of five (`p = 4.5 / 5 = 0.9`), and one series
in `rocket-league/2v2/season-3` for Satoshi's Strikers, the higher of two lineups (`p = 0.75`).
`pbar = (4 * 0.9 + 1 * 0.75) / 5 = 0.87`, Global Rating `1000 + 200 * 1.1264 = 1225`. Everyone else
has fewer than five rated results in season 3 and no Global Rating. Block Heights: alice 8, bob 6,
carol 5, erin 4, dave 2, frank 1.

## Clan hashrate

Clan hashrate is the activity score of a clan, the visible boost of the early seasons. It is not
signed as a number: in revision 4 every input is in the events, so anyone computes it.

For a season, over all attestations of its revision-4 ladders that are **rated results** (as in
[Block Height](#block-height)):

1. every rated player with a `clan` row earns for that clan the ladder's `hashrate` points for their
   own result: `<win>` if their side won, `<draw>` for a draw, `<loss>` if it lost. A Rocket League
   series gives each roster player the series result; a chess game or board gives its two players the
   game result;
2. a **team win** adds `<team win bonus>` once to the clan of the winning lineup: a Rocket League series
   won by a lineup, or a chess team match whose board points (from its board attestations) are higher
   for one lineup;
3. unrated matches (mix teams, `void`, the unrated team forfeit) and players without a `clan` row
   earn nothing.

"Last 7 days" and "this season" are windows over the attestations' `created_at`. The example season 4
gives Satoshi's Strikers 3 points (alice's win in match #421) and Nakamoto Rockets 1 (erin's loss);
the unrated semi-final #422 gives nothing (relay proof, round 4).

## Season chain (rev. 5)

From revision 5 on, every season is its own **chain** with a fixed **supply** of sats, and winning is
the proof of work. A win that passes the [consensus rules](#consensus-rules-season-chain-v1) is a
**block** and earns a **block reward**. Blocks count in the order the league signs its attestations,
and each attestation names the block before it, as in Bitcoin. Rewards stay pending during the season
and are paid once, at season end, after a public review. The league signs the order and its
decisions; every number that follows from them is derived and never signed (the principle of
[Global score](#global-score) and [Clan hashrate](#clan-hashrate)). An admin can adjust the consensus
parameters during a season; a change applies only to blocks attested after it.

The first chain season is the **Pre-Season** (`pre-season`): looser defaults (for example a higher
pairing limit and a relaxed rule 7) to build a base of players and clans, still adjustable. Later
seasons are `season-1`, `season-2`, ... if and when admins schedule them; no event and no text of the
league announces a season before that.

| event | kind | signed by | role |
|---|---|---|---|
| league reserve | `9041` (NIP-75 zap goal) | league key | zap target of the reserve, which funds the seasons (see [Pots and zap targets](#pots-and-zap-targets-rev-5)) |
| season announcement | `31923` (NIP-52), `d` = `season/<season>` | league key | planned Block 0 and planned end: the countdown |
| admin list | `30000` (NIP-51), `d` = `esports/<league key>/admins` | league key | the board: who may release Block 0 and change parameters |
| release | `1985` (NIP-32), `l` = `release-block-0` | one listed admin | the release, bound to the parameters by their digest |
| **Season Genesis**, Block 0 | **`2156`** | league key | the chain parameters of the season, frozen |
| block | `2154` with `block` | league key | the attestation of a winning result is the block |
| **Parameter change** | **`2158`** | league key, for a listed admin | a change of consensus parameters during the season, in force from its `effective` time |
| correction | `1985`, `l` = `void-block` | league key | result of the season-end review |
| **Payout** | **`2157`** | league key | one settlement per player and season, with the Lightning proof |

A season with a genesis is a **chain season**, its ladders are **revision-5 ladders**. A revision-5
ladder is a revision-4 ladder (it carries `hashrate`) that also carries an `e` to the genesis.

### Season Genesis (`2156`)

Signed by the league key when an admin has released Block 0. `content` is the **genesis message**.
Its `created_at` is the time of Block 0 (`T0`), and its id is the hash that block 1 points at.

| tag | format | meaning |
|---|---|---|
| `season` | `<season slug>` | the season, as in the ladders |
| `supply` | `<sats>` | the season's supply, drawn from the league reserve |
| `subsidy` | `<sats>` | the base reward per winning player in era 1 at weight 1 |
| `weight` | `<game>/<mode>`, `<factor>` | one row per game and mode that mines: the factor **per winning player**, a decimal with at most three decimal places. A game and mode without a row does not mine |
| `share` | `<game>`, `<percent>` | share cap of the game per era (rule 9), 1 to 100; absent means 100 |
| `daily` | `<game>`, `<blocks>` | block weight limit: blocks per winning player, game and UTC day (rule 5); absent means no limit |
| `halving` | `<seconds>` | length of an era; era `n` starts at `T0 + (n - 1) * halving` |
| `ends` | `<unix seconds>` | the fixed end of the season; every ladder of the season closes with this `ends` |
| `claim` | `<seconds>` | claim window for players who cannot be paid at settlement |
| `consensus` | `season-chain-v1` | the rule set of this NIP by which blocks are valid |
| `a` | the season's announcement `31923` | |
| `pairlimit` | `<per UTC day>`, `<per season>` | blocks per pairing (rules 4 and 8); absent means `1`, `3` |
| `subtree` | `<percent>` | rule 7: two players are in the same anchor subtree if both shares of the same anchor are at least this; absent means `51`; `101` switches rule 7 off |
| `moves` | `<full moves>` | rule 2: the minimum length of a chess game; absent means `20` |
| `e` | the reserve (`9041`); the release label (`1985`); the admin list version (`30000`) | which reference is which follows from the kind |
| `p` | `<admin>`, `<relay>`, `release` | the admin who released Block 0 |
| `alt` | NIP-31 text | |

**Why a regular event, and why a kind of its own.** Revision 3 rejected a season event because a
second *addressable* event lets the league replace the parameters without trace (see
[League-wide seasons](#league-wide-seasons)). The genesis is *regular*: relays cannot replace it, and
block 1 names its id, so the whole chain is pinned to exactly these parameters. Rating parameters stay
in the ladders, where they were; the genesis holds only what spans all games: one supply, one
reward schedule, one set of caps.

**Parameter digest.** `P` is the list of the genesis tags whose name is one of `season`, `supply`,
`subsidy`, `weight`, `share`, `daily`, `pairlimit`, `subtree`, `moves`, `halving`, `ends`, `claim`,
`consensus`, in event order. The
digest is `SHA-256` of the UTF-8 JSON serialization of `[<content>, P]`, without whitespace and with
the escaping rules of NIP-01's event serialization.

**Admins.** The admins are the association's board. The league key publishes them as a NIP-51
follow set (`30000`, `d` = `esports/<league key>/admins`, `title`, one `p` per board npub, `alt`) and
publishes a new version when the board changes. Relays keep only the newest version; the league
archives the older ones, as it does for gate evidence.

**Release.** The league's app keeps a season as a draft until an admin schedules it; then the league
publishes the announcement (`31923`, `start` = planned Block 0, `end` = planned end). **One** admin
releases Block 0: the release screen shows the estimator's summary and asks the admin to retype the
supply, then the admin signs a NIP-32 label (`1985`): `L` = `space.einundzwanzig.esports`, `l` =
`release-block-0`, `a` = the announcement, `x` = the parameter digest. The league then signs the
genesis with that label and the version of the admin list as `e`, and the admin as `p` with role
`release`. A genesis is valid only with exactly one such label, signed by the `p` with role `release`,
who is listed in the referenced admin list, with its `x` equal to the genesis' digest and a
`created_at` not later than the genesis. (A first draft of revision 5 required two labels by two
admins; the test bed's season 5 was released that way.)

### Eras and rewards

For an attestation with `created_at` `t`: era `n = floor((t - T0) / halving) + 1`, and the reward per
winning player is `floor(subsidy * w / 2^(n - 1))`, with the weight `w` of the ladder's game and mode,
computed in integers (`w` in thousandths: `subsidy * w_milli / (1000 * 2^(n - 1))`, rounded down).
The era and the reward are fixed when the league signs the attestation; within an era every valid win
of a game and mode pays the same, and a halving never reverses. Before a game the app can only say
"about" the reward: the attestation may fall into the next era.

The **era budget** `B_n = floor(supply / 2^n)` (half the supply in era 1, a quarter in era 2, ...)
exists only for the share cap (rule 9). Mining stops when a block no longer fits the remaining supply;
ratings go on until `ends`.

### Blocks (`block` in `2154`)

Every attestation of a chain season that is a rated result with a winner (it has `elo`, its
`resolution` is `confirmed`, `admin` or `forfeit`, its `winner` is `challenger` or `challenged`; for
a chess board the board has a winner) and that is not a tournament match (rev. 7, below) carries
exactly one `block` tag:

- `["block", "<height>", "<previous block id>"]` if it mines; block 1 names the genesis;
- `["block", "", "<tip id>"]` if it does not: the id of the newest block (or the genesis) at the time
  of the attestation, the state its rules were checked against.

Draws, `void` and unrated attestations carry none. Each board of a chess team match is its own
candidate: one block per board won. The heights form one chain across all ladders and games of the
season: exactly one block names each previous block, and a reader walks it from the genesis. The link,
not `created_at`, is the order: timestamps tie within a second, and a link makes a dropped or reordered
block visible, as `prev` does within a ladder.

**Tournament matches are not candidates** (rev. 7). An attestation that carries a tournament `a`
(`31923`) carries no `block` tag, not even an empty one, whatever its result: it is not checked
against the consensus rules, it does not count for rules 4, 5, 8 and 9, and it pays no reward. It
stays in its ladder's `prev` chain and moves ratings as usual. This holds for every rated result of a
tournament, including [director results](#director-results-rev-7), also while a chain season runs.

### Consensus rules (`season-chain-v1`)

A candidate mines if, checked against the blocks up to the id in its `block` tag and with the
parameters in force at its `created_at` (the genesis, changed by every [parameter change](#parameter-changes-2158)
whose `effective` is not later), it passes all of the following; the **reason** a win does not mine is
the first rule it fails, in this order.

0. **Season.** Its ladder names the genesis, its `created_at` lies between `T0` and `ends`, the
   ladder's game and mode have a `weight`, and the block reward (reward per winning player times the
   winning players) still fits the supply minus everything mined before.
1. **Trusted and connected.** The trust gate passed with every rated player at or above the minimum,
   and the two players (a solo game; the two players of a board) or the two gatekeepers (a series) list
   each other, shown by the opponent-list ids in their `gate` rows. This applies to queue pairings as
   well: revision-5 `gate` rows name a player's opponent list whenever it lists the direct opponent,
   also where the trust gate does not need it.
2. **A real game.** `resolution` `confirmed` or `admin`; a forfeit never mines. Chess: the counted
   game record has at least `moves` full moves (default 20: the last move number is 20 or more), which
   also excludes a resignation before move 10. Rocket League: a complete series, which the report rules guarantee.
3. **Not the same clan**: no winning and losing player share a `clan` row value.
4. **Blocks per pairing and day.** Fewer earlier blocks of the same pairing on the same UTC day of
   `created_at` than the first value of `pairlimit` (default 1). The pairing is the two rated entities: two players, or two lineups in a series.
5. **Block weight limit.** No winning player has `daily` blocks of this game on that UTC day already.
6. **Team wins.** A series or board is one block; each winning roster player earns the reward per
   winning player, so the block pays that reward times the winners.
7. **Not the same anchor subtree.** The two players (a solo game; the two players of a board) or the
   two gatekeepers (a series), as in rule 1, do not both have pinned assertions (`30382`, referenced by
   their `gate` rows) that name the same `anchor` with a share of at least `subtree` percent (default
   51; `101` switches the rule off). The other roster players of a series are not compared. Revision 5
   worded this rule for every winning and losing player; revision 6 corrects the wording to what the
   revision-5 examples and the sample ledger compute.
8. **Blocks per pairing and season.** Fewer earlier blocks of the same pairing in the season than the
   second value of `pairlimit` (default 3).
9. **Share cap per game and era.** The rewards of the game's blocks in this era, this one included,
   stay within `floor(B_n * share / 100)`.

Rules 4, 5, 8, 9 and the supply part of 0 depend on earlier blocks; that is why the tip is pinned.
Blocks that the review voids later keep their place for all counters: a correction never changes a
later block or its reward.

### Parameter changes (`2158`)

An admin can change the consensus parameters during a season in the admin view; the estimator
re-forecasts from the live data. Every change is a regular event signed by the league key:

| tag | meaning |
|---|---|
| `e` | the genesis of the season; the version of the admin list |
| `effective` | `<unix seconds>`: the change is in force for every attestation with a `created_at` from here on; not earlier than the change's own `created_at` |
| `tip` | the newest block of the chain when the change was signed |
| `weight`, `share`, `daily`, `pairlimit`, `subtree`, `moves` | the changed parameters, in the formats of the genesis. A `weight`, `share` or `daily` row replaces the row for its game (and mode); weight `0` stops a game from mining |
| `p` | `<admin>`, `<relay>`, `change`: who made the change, a key of the admin list |
| `alt` | NIP-31 text |

`content` states why. Supply, subsidy, eras, `ends`, `claim` and the rule set cannot change during a
season. Changes apply in the order of `effective` (then `created_at`, then id); the last one that sets
a parameter wins.

**Never retroactive.** A change applies only to attestations signed at or after `effective`, and the
league signs no attestation at or after `effective` whose `block` tag names a block before `tip`.
Blocks attested before are evaluated, and paid, with the parameters that were in force for them.
Within an era every valid win of a game pays the same **between two changes**; the app says "about"
before a game for this reason too.

**What a reader can check.** The parameters for every candidate follow from the genesis and the
changes on the relays (`{"kinds":[2158],"authors":["<league>"],"#e":["<genesis id>"]}`). A change
backdated behind a block contradicts that block's `block` tag, because the block would then be judged
by the new parameters; the relay proof shows it (round 5, R5.13). A backdated change that would not
have altered any outcome cannot be told from an honest one, and changes nothing.

**Anchor subtree (`anchor` in `30382`).** The trust key adds one output to `anchored-trust-v1`: the
**anchor share** of each player. An anchor has share 1 of itself. Every voucher passes its trust on as
in the algorithm (`0.5 * raw / |L|` per listed player of the next layer) and splits it by its own
anchor shares; a player's shares are the sums, normalised to 1. The assertion carries
`["anchor", "<anchor with the largest share>", "<floor(100 * share)>"]` (ties: the lower pubkey).
NIP-85 says that "Calculation results are saved in pre-defined tags whose syntax and semantics are
agreed upon by providers and clients"; this is such a tag. Because the `gate` row pins the assertion's
id, the attestation freezes the subtree that was current at the accept. In the test bed (anchors alice
and carol) 6 of the 15 pairs of the six players share a subtree; 4 of them are also clan mates, and
alice–frank and bob–frank are excluded by rule 7 alone (relay proof, round 5). In a league with few
anchors most pairings fall into one subtree; see [Open points](#open-points).

### Fees

Zaps on a live match are **fees** for its winner. The target is the challenge (`2150`): the app adds
`["zap", "<pool key>", "<relay>", "1"]` to every challenge of a chain season except a tournament's
(rev. 7: a tournament match mines no block, so its fees could only go to the reserve; zaps for a
tournament go to its `31923`), so that any client that
follows NIP-57 appendix G sends the zap to the league's LNURL endpoint instead of the challenger. The
zap request carries `e` = the challenge, `k` = `2150`, `p` = the pool key. A receipt counts for the
match if it passes the checks of [Counting the pool](#prize-pool-funding) and its `created_at` lies
between the accepting answer and the last attestation of the challenge.

The fees of a challenge go to the blocks of that challenge won by the side that won the match (a
solo game or a series: its one block; a chess team match: the blocks of the boards the winning lineup
won), in equal parts per block and within a block per winning player, rounded down. They go to the
league reserve if there is no such block: a draw, a `void`, a win that does not mine, a voided block,
a tournament match, and the remainders of the division. A receipt before the accept or after the last
attestation also goes to the reserve.

### Review and corrections

During the season every block is a **pending reward**. After `ends` the league reviews the season for
anomalies (for example reciprocal results within a pairing, shared anchors, move and timing patterns;
the analysis and its evidence stay off Nostr). Every correction is a NIP-32 label signed by the league
key: `L` = `space.einundzwanzig.esports`, `l` = `void-block`, one `e` per voided block, and the public
reason in `content`. A label counts only if it was published before the season's first payout. A voided
block keeps its height and its place in every counter; its reward and its fees go to the reserve.
Ratings do not change.

### Payout (`2157`)

Signed by the league key after the payment, through the league's paying NWC connection to the
player's Lightning address. Two occasions:

- **Season settlement:** after the season's review, **one payout per player and season**, for their
  blocks, fees and bounties. There are no payouts during a season.
- **Tournament settlement:** after a tournament's `end` and an admin's check of that tournament, **one
  payout per player and tournament** for the prize: `a` = the tournament, no genesis. Tournament
  prizes do not wait for the season end.

| tag | meaning |
|---|---|
| `e` | season settlement: the genesis (the season's supply); one `e` per block paid; one `e` per challenge whose fees it pays; for a bounty, the claiming attestation if it is not one of the blocks |
| `a` | season settlement: each bounty (`31923`) whose prize it pays; tournament settlement: the tournament |
| `p` | the player |
| `bolt11` | the invoice the player's Lightning address issued |
| `preimage` | its preimage |
| `alt` | NIP-31 text |

The amount is the invoice's; the event names no amount of its own. It must equal the sum a reader
derives for the referenced items: the rewards of the player's blocks that were not voided, their share
of the referenced fees, and the referenced prizes. A player without a valid Lightning address at
settlement has the `claim` window from the season's first payout; afterwards the amount returns to the
reserve. A payout that failed is not published.

**How a player checks it.** `{"kinds":[2157],"authors":["<league>"],"#p":["<me>"]}`, then: the
preimage hashes to the invoice's payment hash; that payment hash is the incoming payment in my
wallet; the invoice's description hash is the hash of my Lightning address's metadata (LUD-06; its
`text/identifier` is my address); the amount equals what I derive from my blocks, fees and prizes.
If my wallet supports NIP-57 the league pays with a zap (its request's `e` is the genesis), and my
LNURL server's receipt is a second, independent record.

**What it proves.** The preimage proves that the invoice was paid, and the invoice's signature names
the node that issued it; the description hash ties it to the address. That the node is the player's
is what the player checks in their own wallet.

### Season end

At `ends` the ladders close and mining is over (it may have stopped earlier, when the supply ran out).
Then the review, the corrections and the payouts. The unmined supply, voided rewards, fees without a
block, the remainders of divisions and unclaimed payouts go back to the **league reserve**. Nothing
rolls into a named next season: whether and when another season starts is open, and a new genesis
draws its supply from the reserve. Tournament prizes are not part of this: they are settled when their
tournament ends.

### Rest: before Block 0 and between seasons

Only rated play and mining rest. Casual games, casual correspondence chess, clans, lineups,
memberships, opponent lists, tournament sign-ups and zaps to the reserve go on. On Nostr:

- **No open ladder.** Before the first season no ladder exists; between seasons every ladder of the
  league carries `ends`. A challenge needs an open ladder (rule 11), so there is nothing to accept,
  report or attest.
- **The countdown.** The next season's `31923` announcement exists and no `2156` names it; its `start`
  is the planned Block 0. Clients show the countdown from it and switch when the genesis appears
  (`{"kinds":[2156],"authors":["<league>"]}`).
- **Events outside the window are never part of a season.** A `2150`, `2151`, `2152`, `2153` or game
  record whose `created_at` lies before the ladder's `starts` (Block 0) or after its `ends` belongs to
  no season and is never attested; an attestation of such a challenge is a league error. The league
  relay does not store them, because only the league publisher writes there and the league refuses
  them. Other relays may store anything; readers ignore them.
- **Casual correspondence games** may be published as plain NIP-64 notes without an `e` to a challenge
  and without a ladder `a`; they never become part of a chain.
- **Tournaments** (rev. 7) go on as well. One published during rest has a `31923` and, with a solo
  pool, a `2155`, both without a ladder `a`; it stays unrated to its end, also if Block 0 falls into it
  ([Tournaments](#tournaments), "Rated or unrated"). Its sign-up consents (`22150`) are never published
  in any case.

### Signed and derived

| signed by the league | derived by everyone |
|---|---|
| the parameters (genesis), released by a listed admin's label; their changes (`2158`) | eras, rewards, the era budgets; the parameters in force for each block |
| the order of blocks (`block`: height and link, or the tip) | whether a win mines, and why not |
| the corrections of the review (`void-block` labels) | mined, remaining and voided supply; share per game |
| the payouts (`2157`) with invoice and preimage | the amount each player is owed; fee shares; the reserve balance |

## Rank badges (rev. 5)

A player's rank shows up on their Nostr profile as a [NIP-58](https://github.com/nostr-protocol/nips/blob/master/58.md)
badge. NIP-58 says that "Badge definitions can be updated" and that "Awarded badges are immutable".
This league uses both properties: **one definition per player, game and mode**, which the league
replaces on every rank change, and **one award**, which never changes. The profile holds one entry per
game and mode, however often the rank changes.

| event | kind | `d` / content | signed by |
|---|---|---|---|
| badge definition | `30009` | `d` = `rank/<game>/<mode>/<player pubkey>` (lower-case hex), e.g. `rank/chess/blitz/e221ff8c…413e` | badge key |
| badge award | `8` | `a` = that definition, `p` = the player; exactly once per definition | badge key |
| profile badges | `10008` | the player's NIP-51 list; the pair `a` (definition), `e` (award) | the player |

**Definition.** Tags: `d`, `name` (game, mode and rank, e.g. "Chess blitz · Gold II"), `description`
(rank, season and league in words), `image` with `1024x1024`, `thumb` with `256x256`, `p` = the player
(so that `#p` finds a player's badges), `a` = the ladder the rank comes from, `alt`. The rank is the
entity's tier in the `standing` of that ladder: the player's own in a player ladder, the tier of the
lineup the player plays for in a lineup ladder. Anyone can compare the badge with the ladder.

- **First tier.** The league publishes the definition and the award when the player gets a tier in
  that game and mode for the first time, the "rank reveal" after the provisional results. There is no
  badge for `provisional`.
- **Rank change.** Every time the tier changes, the league publishes a new version of the same
  definition (same `d`, newer `created_at`). The award and the player's `10008` entry keep pointing at
  it.
- **New season.** While the player is provisional in a new season, the definition keeps the last tier
  and its `description` names the season it is from. The first tier of the new season replaces it.
- **Never re-awarded.** A second award for the same definition would add a second entry to clients that
  list awards; a rank change is a new version of the definition, never a new award. Rev. 8: a league
  MUST NOT sign a second `8` with the same `a` and `p`. A new badge key makes a new definition
  address (`30009:<new key>:<d>`), which gets one award of its own.
- **Rated only** (rev. 8). The tier is read from the rated ladder of the live season. A casual rating
  has no tier and never signs a badge; outside a live season nothing is signed. A new version is
  signed only when the tier or the season changes; a rating change within a tier signs nothing. Its
  `created_at` is later than the previous version's, also within the same second.

**Image URL per rank.** The `image` and `thumb` URLs are a function of game, tier and artwork version
only, for example `https://example.org/badges/rank/chess/gold-2-v1.png`. Clients cache images by URL;
a URL per player whose picture changes with the rank would keep showing the old rank. All players of
a tier share one URL, and new artwork gets a new version in the URL. Rev. 8 fixes the form:
`<site>/badges/rank/<game>/<tier>-v<artwork>.png` for `image` (1024 × 1024) and
`<site>/badges/rank/<game>/<tier>-v<artwork>-256.png` for `thumb` (256 × 256). Only the current artwork version is served; any other version is not found, so no cache keeps a
picture under a URL meant for future artwork.

**Profile.** "Show on my Nostr profile" in the app adds the pair (`a` definition, `e` award) to the
player's kind `10008` after a confirmation, once per definition. Like the opponent list, the app first
reads the newest `10008` from the player's NIP-65 write relays and the league relay, appends at the
end, and keeps every other entry and its order; it never writes from a stale copy. A deprecated
`30008` with `d` = `profile_badges` (NIP-58: "Clients should treat these events as equivalent to kind
`10008` and migrate") is merged into the new `10008`. Rev. 8: the new version keeps the newest
list's tags in order and its `content` verbatim (it may hold private NIP-51 items), appends the new
pair, and adds an `alt` only if there is none. The pairs of a `30008` are merged in only when there
is no `10008` or the `30008` is the newer list: a pair the player removed in a newer `10008` never
comes back from an older `30008`.

- **Read before writing** (rev. 8). A relay counts as read only after its `EOSE`; a relay that is
  down, closes the subscription or times out is not read, whatever it sent before. At least one of
  the player's NIP-65 write relays (the relays the app reads from, when the player has no relay
  list) MUST have been read, or the app MUST NOT write; it never falls back to a copy it archived
  earlier. When the read returns no list although the app knows one, it MUST NOT write either.
- **Verify before choosing.** Every event is signature-checked before it counts, and ids are not
  deduplicated before that check: a relay that serves a forged copy of the right id first must not
  hide the real list. Of the valid lists the newest wins (highest `created_at`, then lowest id).
- **Relays first.** The app sends the signed list to the player's write relays first and shows the
  badge as on the profile only when at least one answered `OK` true; only then does the league
  archive it and send it to its relays. The list is dated now, never ahead; if the newest list is
  from this second or later, the change waits.

**Why a badge key.** Every rank change of every player means a signature, automatically on the server.
A leaked badge key can forge badges, which are cosmetic and checkable against the ladder; it cannot
forge a result or a rating. The league key stays in its remote signer with its short allowlist.

**Limits.** NIP-58 has no cache invalidation: a client that cached an older version of a definition
shows the old rank until it fetches again. The award says nothing about the rank, so it can never
contradict the current one. Quest badges ("Mine your first block") are ordinary definitions with a `d`
of their own (`quest/<slug>`) and one award per player; this NIP does not specify them further.

## Share posts (rev. 8)

A player shares a moment of the league as an ordinary note (kind `1`), signed by the player. The
league draws a share card (a PNG on its own site, 1200 × 630 for link previews and 1080 × 1920 for
stories) and prepares the note; the player signs it in the app.

| moment | card URL (`<site>/cards/<locale>/…`) | drawn from |
|---|---|---|
| rank up | `rank-up/<version>-<format>.png` | one signed version of the player's rank badge definition that is the first tier or a higher tier than the version before |
| block mined | `block/<attestation>/<npub>-<format>.png` | a `2154` whose `block` tag has a height and names the player among its winners |
| tournament win | `tournament/<tournament>-<format>.png` | a finished tournament; its winner is read from the bracket |
| season wrapped | `wrapped/<season>/<npub>-<format>.png` | the player's blocks, sats, rated wins, tournament wins and best tier of the season; only for a player with rated results in that season (their own or their lineup's) |

`<format>` is `wide` or `story`. A card URL carries `?v=<fingerprint>`, a hash of everything the card
shows: a new name or picture is a new URL, so clients that cache by URL show the new card. A card is
drawn from one version or one attestation, so a posted card keeps showing what happened.

```json
{
  "kind": 1,
  "content": "Mined block 84 on the TWENTY ONE Esports season chain: +5 000 sats.\n\n<site>/cards/en/block/217/npub1…-wide.png?v=3f2a…\n<site>/players/npub1…",
  "tags": [
    ["imeta", "url <site>/cards/en/block/217/npub1…-wide.png?v=3f2a…", "m image/png", "dim 1200x630", "alt Mined block 84 …"],
    ["r", "<site>/players/npub1…"],
    ["alt", "Share post: block in TWENTY ONE Esports"]
  ]
}
```

- **No references.** A share post has no `e`, `p`, `q` or `a` tag: it is not a reply, mentions no one
  and quotes nothing. What it claims can be checked on the card's page and against the league's own
  events (the badge, the attestation, the tournament).
- **Relays.** The app sends the note to the player's NIP-65 write relays (or the relays it read from
  when the player has none); the league archives it and sends it to its relays. A league limits share
  posts per player and hour, counting every attempt before it checks it.
- **Not league state.** A share post changes nothing in the league: no rating, no block, no badge.

## State machine

```
                     2151 declined / withdrawn
          +-----------------------------------------> declined | withdrawn   (final)
          |
 2150 --> OPEN --- respond_by passes, no answer ----> expired                (final)
          |
          | 2151 accepted
          v
       ACCEPTED --- 2152 report ---> REPORTED --- 2153 confirmed ---> CONFIRMED --- 2154 ---> ATTESTED (final)
          |                             |
          |                             | 2153 disputed
          |                             v
          |                          DISPUTED --- 2152 new report ---> REPORTED
          |                             |
          |                             +--- 2154 resolution admin|forfeit|void ---> ATTESTED (final)
          |
          +--- no-show, no report: 2154 resolution forfeit|void --------------> ATTESTED (final)
```

| from | event | author | to | preconditions |
|---|---|---|---|---|
| none | 2150 | acting captain, challenger lineup | open | both lineups valid, different clans, same game and mode as the ladder, season open, no other open or running challenge between the two lineups, league anti-farming policy (tournament challenges may be exempt) |
| open | 2151 `accepted` | acting captain, challenged lineup | accepted | `created_at <= respond_by` and received by the league before `respond_by`; `start` is one of the proposals; both lineups still have enough active players; on a ladder with `trust` the [trust gate](#trust-gate) passes |
| open | 2151 `declined` | acting captain, challenged lineup | declined | as above, without `start` |
| open | 2151 `withdrawn` | acting captain, challenger lineup | withdrawn | none |
| open | time | league | expired | `respond_by` has passed; no event is signed for this |
| accepted | 2152 | acting captain, either lineup | reported | `created_at >= ` chosen `start`; scores valid for the game and `bo`; roster valid |
| accepted | 64 final record (chess, per game or board) | a player of that game | reported (that game) | `created_at >= ` chosen `start`; PGN valid and legal; for correspondence the last move of a valid chain |
| reported (game) | 2153 | the other player of that game | confirmed / disputed (that game) | `e` points at the counted final record; each board is attested on its own |
| reported | time | league | attested (`admin`) | the league's confirmation window passed without a response; the league decides from its own record |
| reported | 2153 `confirmed` | acting captain of the other lineup | confirmed | `e` points at the latest report of this challenge |
| reported | 2153 `disputed` | acting captain of the other lineup | disputed | as above |
| disputed | 2152 | acting captain, either lineup | reported | the new report replaces the disputed one; the league may cap the number of rounds |
| confirmed | 2154 `confirmed` | league | attested | exactly one attestation per challenge, or per board of a chess team match |
| disputed | 2154 `admin`, `forfeit`, `void` | league | attested | decided by a league admin; reason in `content` |
| accepted | 2154 `forfeit`, `void` | league | attested | no report exists; `created_at` is at least the chosen `start` plus the league's grace period; reason in `content` |
| none | 2154 `admin`, `forfeit` with `entered-by` | league | attested | rev. 7: a [director result](#director-results-rev-7) of a rated tournament match; no 2150 to 2153 or game record exists; the director's round is closed |

For a **roster side** (rev. 4), "acting captain" of that side reads "a roster player of that side".
In a **queue pairing** the league makes the pairing before `2150` exists; the apps sign `2150` and
`2151` right after it (see [Queue pairings](#queue-pairings)).

## Validation rules

A league MUST check all of the following before it accepts an event, and a client that verifies a
season SHOULD check them when it replays the history. Parts marked "rev. 4" apply to events that
reference a revision-4 ladder (one with `hashrate`).

General:

1. `id` and `sig` are valid (NIP-01); `kind` is one of this NIP's kinds.
2. `alt` is present (NIP-31).
3. The event has no `expiration` tag (see [Relay behaviour](#relay-behaviour)).
4. At submission, `created_at` is no more than 5 minutes in the future and no more than 10 minutes
   in the past of the league's clock. This applies to live submission only, not to replaying history.
5. The event id has not been seen before. A second submission of the same id is a no-op.
6. `content` is public. It MUST NOT contain lobby names, passwords or any other private data. Clients
   MUST NOT offer a way to put such data there.

Per kind:

7. **32150**: `d` matches the slug pattern; `name` is present and at most 64 characters; `clantag`,
   if present, matches `^[A-Z0-9]{2,4}$` and is not used by another clan of the league; every `p`
   has role `captain` or `member`; no pubkey is listed twice. Rev. 5: except for the first version,
   the author is an active member of the clan, and the clan has not ended
   ([Ownership](#ownership-rev-5)).
8. **32151**: `d` equals `<clan d>/<game>/<mode>` of the referenced clan and the `game`/`mode`
   tags; the author is the owner of that clan and an active member of it (revision 4: the owner or a
   captain); the clan has not ended; `game` and `mode` exist in the league's
   game registry; the number of `player` and `captain` entries is at least the mode's team size;
   every listed player is listed in the clan (rev. 6: is an active member of the clan when the lineup is
   signed). The league treats at most one lineup per (clan, game, mode) as active: the newest valid one.
9. **12150**: at most one clan reference; every lineup reference belongs to that clan (rev. 6: lineup
   references are not needed and do not count); a newer version is only accepted if its `created_at`
   is greater than the stored one. Rev. 5: the clan it
   names has not ended; a version by a clan owner that leaves the clan (no clan, or another clan) is
   accepted only if another active member of the clan is listed as `captain` or no other active
   member remains ([Ownership](#ownership-rev-5)).
10. **32152**: signed by the league key; `d` equals `<game>/<mode>/<season>` of its own tags;
    `rating` is `elo` with a positive integer start, a positive k-factor and a positive scale;
    the first `tier` has minimum `0`, minimums strictly increase, tier names are distinct, match
    `^[a-z][a-z0-9-]{0,31}$` and none is `provisional`;
    `provisional` is a non-negative integer, optionally followed by a positive provisional
    k-factor; `rates`, if present, is `lineup` or `player`; `reset` and `seed` appear together or not at all,
    `reset` names a ladder of the same league key, game and mode that has `ends` set, its last
    attestation, and a factor between `0` and `1`; every `seed` value follows the formula in
    [Season transition](#season-transition); every `standing` and `seed` lineup also appears as a
    plain `a` tag; the tier of every `standing` follows step 5 of [Rating](#rating); the frozen tags
    equal those of the first version; `ends`, once set, does not change. Rev. 5: an `e` to a genesis
    (`2156`) of the league makes a revision-5 ladder; that `e` is frozen, `season` equals the genesis',
    `starts` equals the genesis' `created_at`, and `ends`, once set, equals the genesis' `ends`. `trust`, if present, names a
    key other than the league key and a minimum between 0 and 100; `override` names only `rating`,
    `tier`, `provisional`, `reset` or `trust`; together with the league's other ladders of the same
    `season` it follows the rules in [League-wide seasons](#league-wide-seasons). `hashrate` (rev. 4)
    has four non-negative integers and is never named in `override`.
11. **2150**: the author is an acting captain of the `challenger` lineup (solo game: is the
    `challenger` `p`); there is exactly one `challenger`, one `challenged` (lineup `a` or player `p`,
    never both) and one ladder reference; lineups belong to the ladder's game and mode; a chess team
    match carries `boards` allowed by the registry and no `bo`, a solo chess game carries `color` and
    neither; the ladder belongs to this league and
    `created_at` lies in its season window (at submission: the ladder has no `ends` yet); `bo` is allowed for the game; one to three
    `start` values, each later than `created_at`; `respond_by` is later than `created_at` and within
    the league's maximum (7 days suggested). Revision 3: if an `e` is present, it points at a draw
    (2155) of this league on the same ladder that lists both lineups as entrants, and `tournament` is
    present and equals the draw's. Revision 4: `match` is present, a positive integer the league
    reserved for this author and used by no other challenge; `pairing`, if present, is `queue` or
    `tournament`; with `tournament` the challenge carries an `a` to a `31923` of the league key whose
    `a` names this ladder; a **roster side** is allowed only with `pairing` `tournament` on a lineup
    ladder, needs an `e` to a draw of that tournament, and its `p` set equals one team of that draw;
    an author on a roster side is a member of it; a solo game on a player ladder never has a roster
    side. Revision 5: on a revision-5 ladder the challenge carries a `zap` tag naming the pool key
    (the app adds it; see [Fees](#fees)), except (rev. 7) a challenge with `pairing` `tournament`,
    which carries no `zap`; a challenge created before the ladder's `starts` or after
    its `ends` is refused and never attested ([Rest](#rest-before-block-0-and-between-seasons)).
12. **2151**: see the state machine for author and timing (a roster player for a roster side); `a`
    references equal the challenge's; on a ladder with `trust`, an `accepted` answer passes the
    [trust gate](#trust-gate), except for a challenge with a roster side, which is unrated.
13. **2152**: `score` numbers run from 1 without gaps; each `score` has a winner (`draw` only if the
    game registry allows it); the point values are both absent or empty, or both non-negative
    integers, and then the winner has more points; the series ends in the game in which one side
    reaches `ceil(bo / 2)` wins, and no `score` follows that game. Roster: every `p` with a side has
    side `challenger` or `challenged` and a lineup role in position 4; each side lists at least the
    mode's team size; every roster player of a lineup side is an active lineup player of that side's
    lineup when the report is signed, with the role that lineup gives them; every roster player of a
    roster side (rev. 4) is a member of that side in the challenge, with role `player`; the roster
    entry of a 1v1 player side (rev. 7.1) is the side's `p` of the challenge, with role `player`; no
    pubkey appears twice in the event.
14. **2153**: the author is an acting captain of the lineup (or a roster player of the roster side)
    that did not author the report; the report is the latest one for the challenge. For a chess game record: the author is the other
    player of that game, and the record is the counted final record of the game.
15. **64** (in a challenge): `e` challenge accepted; the `a` references equal the challenge's; one
    `p` `white` and one `p` `black`, and the author is one of them; in a team match `board` is between
    1 and `boards`, White and Black are active lineup players of the sides the board parity gives
    them, and no player sits on two boards; in a solo game the colors follow `color`; the PGN parses,
    all moves are legal for the ladder's `variant`, `Result` equals the terminator, `TimeControl`
    equals the ladder's `time_control`. Correspondence moves also follow the ordering rules in
    [Game Record](#game-record-64-reused-from-nip-64).
16. **2154**: signed by the key in the ladder address; `prev` equals the id of the latest
    attestation of this ladder; the `elo` entities match the ladder's `rates` (lineup addresses or
    pubkeys); each `elo` "before" value equals the entity's "after" value in its previous
    attestation of this ladder that has an `elo` row for it (rev. 7.1: attestations without one, such
    as a mix-team match or a director forfeit, are skipped), or else its `seed`, or else the ladder's
    start rating; each
    "after" value follows [Rating](#rating); `score` and roster `p` tags follow
    [League Attestation](#league-attestation-2154) for the given `resolution` and pass the checks of
    rule 13; a `board` row equals the counted game record, and `winner` follows from it; without
    any report or game record, `resolution` is `forfeit` or `void`; the only attestations without
    `elo` are the unrated whole-team forfeit or void of a chess team match, (rev. 4) an
    attestation of a challenge with a roster side of several players, and (rev. 7.1) a director
    result with `resolution` `forfeit`, which never has `elo`. Rev. 7.1: on a player ladder, each `elo`
    entity is the pubkey of a roster player; a 1v1 side's entity is its one roster player, with or
    without a lineup `a`. On a ladder with `trust`:
    the attestation (except one with a roster side) carries `trust` and a `gate` row for both
    gatekeepers and every rated player; each
    row's assertion is a `30382` by the key in `trust` with `d` equal to the row's pubkey and `rank`
    equal to the row's rank, at least the minimum; each gatekeeper's opponent list is a `30000` by that
    gatekeeper with `d` = `esports/<league key>` that lists the other gatekeeper (not required for a
    tournament pairing); every referenced event is signed and has a `created_at` not later than the
    accepting answer's. On a ladder without `trust`, neither `trust` nor `gate` appears. Revision 4:
    `match` equals the challenge's; one `clan` row per rated player who was an active clan member at
    the accept, naming that clan, and none for anyone else; the tournament `a` equals the challenge's;
    an attestation of a challenge with a roster side (a mix team) has no `elo`, `trust`, `gate` or
    `clan`. Revision 7: every attestation of a tournament match carries the tournament `a`, a `31923` of the
    league key whose ladder `a` is this ladder (an unrated tournament has no attestations). With
    `entered-by` (a [director result](#director-results-rev-7)): exactly one, a 64-character lower-case
    hex pubkey; the tournament `a` is present; `resolution` is `admin` or `forfeit`; there is no
    challenge, so no `e` is required, and "without any report or game record, `resolution` is
    `forfeit` or `void`" does not apply; with `admin`, a series carries `score` and roster, a chess
    game one `board` row; "at the accept" reads "at the pairing". Without a tournament `a`, no
    `entered-by`.
17. **2155**: signed by the league key; `a` ladder of this league whose season window contains
    `created_at` (at submission: the ladder has no `ends` yet), rev. 7: present exactly when the
    tournament's `31923` names a ladder, and then equal to it; the height in `draw` is above the
    chain tip at publication; algorithm `sha256-v1`. Revision 3: at least two entrants, none listed
    twice, every entrant lineup valid for the ladder's game and mode; an `e`, if present, points at
    an earlier draw with the same `tournament`. Revision 4: an `a` to a `31923` of the league key; only
    `p` entrants, none twice, at least one full team; `teams` a positive integer; at least as many
    distinct `teamname` values (each at most 64 characters) as there are full teams; an `e`, if
    present, points at an earlier draw with the same tournament `a`.
18. **30000** (opponent list): `d` is `esports/<league key>`; only `p` entries count, deduplicated,
    the author's own pubkey ignored; encrypted private items are ignored; a version not newer than the
    stored one is refused.
19. **30382** (trust rank): signed by the key in the ladder's `trust`; `d` is a pubkey; `rank` an
    integer from 0 to 100.
20. **1984** (report): counts only as described in [Reports](#reports-1984-reused-from-nip-56).
21. **31923** (tournament, rev. 4): signed by the league key; NIP-52 required tags (`d`, `title`,
    `start`, `D`) and `end` later than `start`; `d` matches the slug pattern; an `a` to the league's
    calendar and one to a ladder of this league; `zap` names the pool key; `alt`. Revision 7: the
    ladder `a` is optional and frozen: present in every version exactly when it is present in the
    first, with the same value, and present in the first exactly when a ladder of this league with the
    tournament's game and mode was open at its `created_at`; `zap` is optional, and if present names
    the pool key.
22. **31924** (calendar): signed by the league key; `d` = `tournaments`; every `a` is a `31923` of the
    league key.
23. **30000** (anchor list, rev. 4): signed by the trust key; `d` = `esports/<league key>/anchors`;
    every `30382` of the trust key in revision 4 carries an `e` to the anchor list version it was
    computed from.
24. **31923** (bounty, rev. 5): signed by the league key; NIP-52 required tags, `end` later than
    `start`, a `D` for every day from `start` to `end`; `d` is `bounty/<slug>` with a slug as for a
    clan; exactly one `p` with role `target`; one `a` to a ladder of this league whose season window
    contains `start`; `zap` names the pool key; `alt`. The claim follows `bounty-v1`
    ([Bounties](#bounties-rev-5)).
25. **30009** (rank badge, rev. 5): signed by the badge key; `d` = `rank/<game>/<mode>/<pubkey>`, and
    `p` equals that pubkey; `a` names a ladder of this league with that game and mode, in whose
    `standing` the player (or the lineup the player plays for) has the tier the `name` shows, or, while
    provisional in a newer season, had it at the end of the season the `description` names; `image`
    and `thumb` are the URLs of that game and tier. **8**: signed by the badge key; one `a` to a rank
    badge definition and one `p`, the player of its `d`; at most one award per definition.
26. **2156** (rev. 5): signed by the league key; `season`, `supply`, `subsidy`, at least one `weight`,
    `halving`, `ends`, `claim`, `consensus` `season-chain-v1` present; integers positive, weights
    positive decimals with at most three decimal places, `share` between 1 and 100, `ends` later than
    `created_at`; one `a` to the season's `31923` announcement; one `e` to the league's reserve goal
    (`9041`); one `e` to the release label, one `e` to a version of the admin list and one `p` with
    role `release` as in [Release](#season-genesis-2156); `pairlimit` two positive integers, `subtree`
    51 to 101, `moves` a positive integer; at most one genesis per season; the previous chain season has
    ended (its `ends` is not later than this `created_at`); `supply` does not exceed the reserve
    balance at `created_at` ([Pots and zap targets](#pots-and-zap-targets-rev-5)).
27. **2154** (rev. 5, on a ladder that names a genesis): exactly one `block` tag on every rated result
    with a winner, none on others; a non-empty height is the previous block's height plus 1 (1 after
    the genesis), and no other block names the same previous block; an empty height names the newest
    block at signing; a block passes, and a non-mining candidate fails, the
    [consensus rules](#consensus-rules-season-chain-v1) checked against the blocks up to the named id.
    The `gate` rows follow the revision-5 rule for opponent-list ids. Rev. 7: an attestation with a
    tournament `a` has no `block` tag; it is not a rated result "with a winner" for this rule, and no
    block counts it.
28. **2157** (rev. 5): signed by the league key; either one `e` to a genesis whose season has ended
    (season settlement: blocks, fees, bounties), or no genesis and one `a` to a tournament whose `end`
    has passed (tournament settlement); one `p`;
    `bolt11` and `preimage` present, and `SHA-256(preimage)` is the invoice's payment hash; every
    referenced block names that player as a winner and is not voided by a counted `void-block` label;
    every referenced challenge had fees for such a block; every `a` is a bounty or tournament the
    player won; the invoice amount equals the derived sum; at most one payout per player and season,
    and per player and tournament; no referenced item is paid twice.
29. **1985** (rev. 5): with `l` `release-block-0`, signed by a key of the league's admin list, `a` a
    season announcement of the league, `x` a 64-character hex digest; with `l` `void-block`, signed by the league key, every `e`
    a block of a chain season, `content` the reason, and `created_at` before the season's first payout.
30. **9041** (rev. 5): signed by the league key; NIP-75 `amount` and `relays`; `zap` names the pool key.
32. **2158** (rev. 5): signed by the league key; one `e` to a genesis whose season has not ended; one
    `e` to a version of the admin list and one `p` with role `change` listed in it; `effective` not
    earlier than `created_at` and not later than the genesis' `ends`; `tip` is the newest block of the
    chain at `created_at`, and no attestation with a `created_at` at or after `effective` names an
    earlier block in its `block` tag; only `weight`, `share`, `daily`, `pairlimit`, `subtree` and
    `moves` rows, with the formats of the genesis; `content` the reason.
33. **30000** (admin list, rev. 5): signed by the league key; `d` = `esports/<league key>/admins`.
31. **30382** (rev. 5): the `anchor` row names an anchor of the anchor list the assertion references,
    with an integer share from 0 to 100, as computed in [Anchor subtree](#consensus-rules-season-chain-v1).
34. **22150** (tournament consent, rev. 7): checked by the league only, never published; see
    [Tournament Consent](#tournament-consent-22150) for the checks. A league relay refuses the kind like
    any event not from the league publisher. A reader that meets a `22150` anywhere gives it no meaning:
    registrations are not public data.
35. **30009**, **8** (rank badges, rev. 8): signed by the league's badge key; a definition's `d` is
    `rank/<game>/<mode>/<pubkey of its p>` and its `a` a ladder of that game and mode; its name names
    the entity's tier in the `standing` of that ladder at `created_at` (never `provisional`); at most
    one `8` per definition address and `p`, with `p` the definition's `p`.
36. **10008** (profile badges written by the app, rev. 8): signed by the player; at least one `a`/`e`
    pair; every `a` a `30009` address, every `e` an event id; every pair of the newest valid list the
    league knew is kept, in order, and its `content` is unchanged; written only after a write relay of
    the player delivered `EOSE` (see [Rank badges](#rank-badges-rev-5), Profile).
37. **1** (share post, rev. 8): signed by the player; exactly one `imeta` whose `url` is a share card on
    the league's site (`<site>/cards/…`) and appears in `content`; no `e`, `p`, `q` or `a`.

## Replay protection

- **Transition binding.** Every match-flow event references its predecessor by `e` and is valid only
  while the predecessor is in the expected state. Once a challenge is accepted, a replayed accept
  or a replayed confirm finds the state already moved and changes nothing. An answer cannot be
  signed before the challenge exists, because it has to contain the challenge id.
- **League and season binding.** The ladder `a` tag names the league key and the season. An event
  replayed to another league or into a later season fails rule 11.
- **Unique ids.** The league stores every accepted event id with a unique index (rule 5).
- **Unique match numbers** (rev. 4). A number is reserved for one author and used by one challenge; a
  replayed challenge has the same id (rule 5), and a new challenge with a used number is refused.
- **Replaceable state is monotonic.** For `12150`, `32150`, `32151` and `32152` the league rejects
  versions whose `created_at` is not newer than the stored one (rule 9). rnostr and strfry do the same
  on their own and answer `replaced: have newer event`; khatru answers `OK true` and keeps the newer
  version, so a publish result of `true` does not mean the event is the current one.
- **Gate evidence is pinned by id.** A `gate` row names the exact versions of the opponent list and
  the assertion that were current at the accept. A replayed older list (for example one that still
  listed a removed opponent) is refused by the league as not newer (rule 18), and a later list or
  rank does not change an attestation that is already signed.
- **Attestation chain.** `prev` links the attestations of a ladder into one sequence. Rating is
  path-dependent, so the order matters; a league that drops, reorders or rewrites an attestation
  breaks the chain visibly. `reset` links the chains of consecutive seasons.
- **Block chain** (rev. 5). `block` links the blocks of a chain season across all ladders, from the
  genesis (a regular event) on; a dropped, reordered or forked block is visible to anyone who walks it.
  A non-mining win names the tip it was checked against, so its exclusion can be rechecked. The
  parameters are pinned twice: by the genesis id in block 1 and by the digest in the admins' labels.
- **Deletion requests do not roll back state.** A NIP-09 deletion of a challenge or report does not
  change the league's state. A league-operated relay SHOULD refuse kind `5` events that target
  events of an attested chain.

## Relay behaviour

Measured on 2026-09-25 against rnostr 0.4.9, strfry 1.1.2-148 and khatru (ndak test bed); details in
the relay proof.

- **NIP-40 `expiration` is unsuitable for challenges.** A challenge is the root of the whole match
  chain. With `expiration`, rnostr no longer returned it after the expiry, and NIP-40 tells clients
  to ignore expired events even where the relay (strfry, khatru here) still serves them. An accepted
  and played match would lose its root. The deadline is therefore the data tag `respond_by`, and the
  league moves the challenge to `expired` itself.
- **Multi-letter tag filters are not portable.** strfry refuses them, rnostr ignores them and returns
  non-matching events, khatru applies them. All query paths of this NIP use `#a`, `#e`, `#p`, `#d`.
  This is why a tournament is found through the draw's `e`, not through `#tournament`.
- **Replaced versions.** rnostr and strfry refuse an older version of a replaceable or addressable
  event with `replaced: have newer event`; khatru answers `OK true` and does not store it.
- **Old versions of lists and assertions disappear.** An opponent list (`30000`) or trust assertion
  (`30382`) that a gate referenced is gone from all three relays once a newer version exists; a replay
  of the old list is refused by rnostr and strfry and answered `OK true` by khatru, which keeps the
  newer one. Gate evidence therefore needs the league's archive (round 3 of the relay proof).
- **NIP-70 protected events are enforced by all three.** An event carrying `["-"]` is refused unless
  the publishing connection is authenticated (NIP-42) as the event's author. See
  [What is not on Nostr](#what-is-not-on-nostr) for what that means for publishing through the league.

Measured in round 4 (rnostr 0.4.9, strfry 1.1.2-148, khatru; zooid `d985857` built locally; the
[league relay](#league-relay-and-keys) as a khatru reference):

- **Who can read gift wraps (`1059`).** rnostr and khatru serve them to anyone, without AUTH. strfry
  with `restrictedReadKinds = "1059"` and `restrictReadToInvolvedPubkey = true` serves them only to the
  authenticated `p` recipient, also by id; in the ndak configuration `serviceUrl` is empty, so AUTH
  fails ("relay needs serviceUrl to be configured before AUTH can work") and nobody can read them.
  zooid serves every gift wrap to every authenticated reader, members and, with `public_read`,
  non-members.
- **A live subscription for gift wraps must not use `since: now`.** The wrap's `created_at` is up to
  two days in the past (NIP-17), so a filter with `since` equal to the subscription time receives
  none of them live (0 of 40 on each relay, while the same subscription with `since` two days back
  received all 40); `since` must be at least two days back.
- **Latency is not the problem.** 40 gift wraps to an open subscription of the recipient arrived
  after a median of 0.6 ms (league relay), 1.4 ms (zooid), 2.3 ms (khatru), 47 ms (rnostr) and 102 ms
  (strfry); the client-side sealing and wrapping took about 95 ms per message with `nak` (a process
  per message, an upper bound).
- **The ephemeral gift wrap (`21059`, NIP-59) is not portable.** strfry refuses one with a
  `created_at` in the past ("invalid: ephemeral event expired"), rnostr stores it, zooid refuses it
  with and without AUTH (the wrapper key is never the authenticated key); khatru delivers it to open
  subscriptions and does not store it.
- **A lone seal (`13`)** is accepted by rnostr, strfry and zooid; the ndak khatru refuses it by its
  own policy.
- **Zap receipts are not validated by relays.** A receipt signed by the zapper instead of the LNURL
  server was accepted by rnostr, strfry and khatru; only the league relay refuses it.

## League relay and keys

### Relay policy

The league runs one relay. It is the source of truth for clients, and the players' DM inbox.

- **Writes** are accepted in exactly three cases:
  1. any event on a connection **authenticated (NIP-42) as the league publisher**, whoever signed it.
     The league's server publishes every player event it accepted (`2150`-`2153`, `64`, `12150`,
     `30000`, `1984`, and `10050` and `10008` written through the app) and its own events (`2154`,
     `2155`, `32152`, `31923`, `31924`, `0`, `10002`, rev. 5 `2156`, `2157`, `2158`, `9041`, `1985` and the admin list, the
     admins' `1985` release confirmations, the trust key's `30382` and anchor list, the badge key's
     `30009` and `8`) over this connection;
  2. a gift wrap (`1059`) on any authenticated connection whose single `p` is a registered player
     (spam protection as in NIP-59: the wrap's own key is random);
  3. a zap receipt (`9735`) signed by the league's LNURL server key.

  Everything else is refused (`restricted:`). "Only the league writes" therefore means the
  connection, not the author: player-signed events reach the relay through the league.
- **Reads** are public, except gift wraps: a `1059` is served only to its authenticated `p` recipient,
  in stored queries (also by id) and in live subscriptions.
- **NIP-70.** A protected event can only be published by its authenticated author, not by the league
  publisher. The league therefore does not ask players to mark events with `["-"]`.

Implementations, measured in round 4:

| relay | writes: publisher publishes player events | reads: `1059` only for the recipient |
|---|---|---|
| khatru, about 100 lines (`OnEvent` with `GetAuthed`, filtered `QueryStored` and `PreventBroadcast`; source in the relay proof) | yes | yes |
| strfry 1.1.2-148 with a write-policy plugin | **no**: the plugin receives the authenticated pubkey only for NIP-70 events (`authed` is set in `RelayIngester.cpp` only in the protected-event branch), so rule 1 cannot be expressed; a source-IP rule would be the fallback | yes (`restrictReadToInvolvedPubkey`) |
| zooid `d985857` | **no**: "restricted: you cannot publish events on behalf of others", also for a manager | **no**: every authenticated reader gets every gift wrap |

zooid also accepts gift wraps and zap receipts whose `p` is a member without any AUTH, and a member
may publish any kind. A zooid tenant as the league relay needs changes upstream.

### Keys

| key | signs | where it lives |
|---|---|---|
| league key | `0`, `10002`, `2154`, `2155`, `32152`, `31923`, `31924`, `9734` (payout zaps); rev. 5 `2156`, `2157`, `2158`, `9041`, `1985` (`void-block`), `30000` (admin list) | remote signer (NIP-46) with exactly this allowlist |
| admin keys (rev. 5) | `1985` `release-block-0` | each admin's own signer; the board npubs, published in the league's admin list |
| trust key | `0`, `30382`, `30000` (anchor list) | trust service |
| league publisher | nothing stored; only NIP-42 `22242` | league server |
| notification key | `0`; seals (`13`) of notifications | league server |
| pool key | `0` (with the pool's `lud16`) | offline after setup |
| LNURL server key | `9735` | the league's LNURL endpoint |
| sponsor desk key | `9734` for sponsor invoices | league server |
| badge key (rev. 5) | `0`, `30009` rank badge definitions, `8` awards | league server |

A compromise of a server-side key can forge notifications, receipts, sponsor requests or badges, but
no result, rating or tournament: those need the league key.

### Discovery

The league key publishes a kind `0` (name, about, website, and the pool's `lud16`, so that clients
that ignore `zap` tags still pay into the pool) and a NIP-65 relay list (`10002`) with the league
relay. A client that knows only the league npub reads the `10002` (on the relays it already uses or an
indexer relay) and finds every ladder there with `{"kinds":[32152],"authors":["<league>"]}`
(relay proof, round 4).

## Chat

Players chat privately with [NIP-17](https://github.com/nostr-protocol/nips/blob/master/17.md): in the
match room, about a date, after a game. There is no moderation by the league; each player can mute
others for themselves. The league cannot read the messages and stores none.

**One message** from alice to erin:

1. **Rumor.** alice's client builds an unsigned kind `14` with `p` erin (relay hint: erin's DM relay)
   and `match` with the match number, optionally `e` to the message it answers. The rumor keeps a
   real `created_at` and its `id`.
2. **Seal.** The rumor is encrypted with NIP-44 from alice to erin and placed in a kind `13` signed by
   alice, with no tags.
3. **Gift wrap.** The seal is encrypted with NIP-44 from a fresh random key to erin and placed in a kind
   `1059` signed by that random key, with `p` erin and a `created_at` up to two days in the past.
4. **Publish** to the relays of erin's DM relay list (`10050`). A second rumor copy is sealed and
   wrapped the same way to alice herself and published to alice's DM relays, so her other devices see
   what she wrote.

**Receiving.** erin's client authenticates (NIP-42) to her DM relays, subscribes to
`{"kinds":[1059],"#p":["<erin>"],"since":<now minus 2 days and a margin>}`, decrypts the wrap and then
the seal, and **checks that the seal's pubkey equals the rumor's pubkey** (NIP-17). Without this
check anyone can write as anyone: in round 4 a rumor that claimed to be alice, sealed by bob, was
caught by the check; `nak gift unwrap` returned it with the author replaced by bob and no error.

**DM relays.** A player's `10050` names the relays they receive DMs on. NIP-17: "If such a list is not
found that indicates the user is not ready to receive messages and clients shouldn't try." The app
therefore:

- for a new identity without a `10050` (every Google login: nostr-mill publishes none), publishes one
  with the league relay;
- for an identity with a `10050`, sends to those relays, and offers to add the league relay. Adding it
  replaces the list, so the app first reads the newest version from the player's relays, as for the
  opponent list.

**Live games.** Delivery on the relays takes milliseconds (see [Relay behaviour](#relay-behaviour)),
so a blitz chat uses the same kind `1059`. The ephemeral gift wrap `21059` is not used: three of the
four relays tested refuse or store it.

**Google logins.** A Google identity from nostr-mill is a NIP-46 bunker (pomegranate): every seal is
a remote `sign_event`, every encryption and decryption a remote `nip44_encrypt` / `nip44_decrypt`.
The coordinating server (`central`) computes the NIP-44 conversation key from partial ECDH results
of the operators and decrypts on its side (`central/encryption.go`), so **for Google logins the
central server can read the messages**. Receiving one message costs two decryptions: the wrap (a new
random key each time, so a new ECDH round through the operators) and the seal (the conversation key
with the sender, cached). The latency of this path was not measured.

**Mute.** Muting is client-side: the app hides messages from pubkeys on the player's NIP-51 mute list
(kind `10000`, private entries encrypted to oneself). As with the opponent list, the app writes a
mute list only after reading the newest version, and never from a stale copy.

**What is stored where.** The app's database stores nothing about chats. The league relay stores the
gift wraps: ciphertext, recipient, a random time. Clients may cache decrypted messages on the device.

## Notifications

The league notifies players by Nostr DM from a dedicated **notification key**, besides browser push.

- **NIP-17, not NIP-04.** NIP-04 is marked `unrecommended` and "deprecated in favor of NIP-17"
  (`04.md`); its events show sender, recipient and time to everyone. A notification is a kind `14`
  rumor sealed by the notification key and wrapped to the player only; there is no copy to the sender.
- **Where to.** To the relays of the player's `10050`, and to none if the player has none.
- **No replies.** The notification key publishes a kind `0` with `bot: true` (NIP-24) and the note
  that it reads no replies, and **no** `10050`: NIP-17 clients then do not send replies at all.
- **Content.** Plain text with a link into the app and `match` for the match it is about; never lobby
  data. Each type is opt-in in the settings.
- **Why its own key.** It signs on the server, automatically, all the time. A leak lets someone send
  fake notifications, not fake results.

## Prize pool funding

Anyone can zap a tournament; the pool grows live and anyone can recount it.

**Zap target.** The tournament's `31923`. A zap request (NIP-57 `9734`) carries `a` =
`31923:<league>:<slug>`, `k` `31923`, `p` = the pool key (from the event's `zap` tag), `amount`,
`lnurl` and `relays` = the league relay. The pool key's `lud16` (and the league key's) point at the
league's own LNURL endpoint, for example `pool@example.org`.

**LNURL endpoint.** The league runs the LNURL-pay endpoint itself on top of its NWC wallet
(NIP-47), instead of relying on a wallet provider's lightning address:

1. `/.well-known/lnurlp/pool` answers with `allowsNostr: true` and `nostrPubkey` = the LNURL server key;
2. the callback validates the zap request (NIP-57 appendix D) and asks the wallet for an invoice with
   `make_invoice` and `description_hash` = SHA-256 of the zap request;
3. when `lookup_invoice` reports `settled`, it signs the receipt (`9735`: `p`, `P`, `a`, `k`, `bolt11`,
   `description`, `preimage`, `created_at` = the settle time) and publishes it to the relays of the
   request.

A wallet provider's lightning address works too, but only if it supports NIP-57 and publishes to the
league relay; which one the league's wallet would be is open.

**Who pays how.**

- A player zaps with their own key (a Google login signs the zap request through its bunker).
- A visitor without Nostr pays an invoice whose zap request the page signed with a throwaway key.
- The **admin top-up** is a zap from the admin's npub with the comment "League top-up", not a direct
  deposit into the wallet: a deposit outside the endpoint leaves no receipt and could not be counted.
- A **sponsor** pays a Lightning invoice that belongs to a zap request signed by the league's sponsor
  desk key with the comment "Sponsor: <name>" (or by the sponsor's own npub). When the receipt
  appears, the logo appears. The league keeps sponsor, zap request id, payment hash and logo in its
  database; logos are not published on Nostr.

**Counting the pool.** The pool is the sum over zap receipts `{"kinds":[9735],"#a":["31923:<league>:<slug>"]}`
that pass all of:

1. signed by the `nostrPubkey` of the league's LNURL endpoint;
2. `description` is a validly signed `9734` whose `a` is the tournament;
3. SHA-256 of `description` equals the invoice's description hash;
4. the invoice amount equals the zap request's `amount`;
5. `created_at` is before the tournament's `end`;
6. each payment hash counts once.

Receipts after `end` go to the league reserve (revision 4: to the league's next pool). In round 4 the example pool counts
3 receipts, 171 000 sats; a receipt signed by the zapper, one whose invoice is smaller than its
request, one whose description does not match the invoice and one after `end` are rejected; all four
are on rnostr, strfry and khatru, which store receipts unchecked.

**What this proves.** NIP-57: "The `zap receipt` is not a proof of payment". Every receipt of the pool
comes from the league's own endpoint, so the pool is the league's claim, made checkable: every sat is
tied to a signed request and to an invoice signed by the league's Lightning node, and the payouts to
the winners' `lud16` are the other half (receipts only where the winners' LNURL servers support
NIP-57). A NIP-75 zap goal (`9041`) linked from the tournament with `goal` would add a progress bar in
NIP-75 clients; it needs a target amount and is not used in V1.

## Pots and zap targets (rev. 5)

The league has **one wallet** with two NWC connections (NIP-47): a receive-only one, which the LNURL
endpoint uses for `make_invoice` and `lookup_invoice`, and one that can pay, with a budget limit, for
payouts. The sats in it belong to **pots**, kept as a double-entry ledger in the league's database.
Every pot has **its own zap target**, and every zap request names exactly one pot: the LNURL endpoint
refuses a request that names two, and attributes each payment by its payment hash to the pot of its
request.

| pot | zap target | the zap request carries | counted receipts | paid out by | what is left goes to |
|---|---|---|---|---|---|
| **league reserve** | the league's zap goal (`9041`, NIP-75) | `e` = the goal, `k` = `9041` | `{"kinds":[9735],"#e":["<goal id>"]}` | a season's supply: the genesis names the goal by `e` and draws `supply` | stays |
| **season supply** | none; funded from the reserve at Block 0 | | | block rewards: `2157` with `e` genesis and `e` blocks | reserve, at settlement |
| **tournament pool** | the tournament's `31923` | `a` = the tournament, `k` = `31923` | `#a` = the tournament, until `end` | prizes at the tournament's end, after an admin's check: `2157` with `a` the tournament | reserve |
| **bounty** | the bounty's `31923` | `a` = the bounty, `k` = `31923` | `#a` = the bounty, until `end` | the claimer: `2157` with `a` the bounty | reserve |
| **match fees** | the challenge (`2150`, with the league's `zap` tag) | `e` = the challenge, `k` = `2150` | `#e` = the challenge, from the accept to the last attestation | the winners of the match's blocks: `2157` with `e` the challenge | reserve |

In every request `p` is the pool key (named by the target's `zap` tag), whose `lud16` is the league's
LNURL endpoint. **Everything else is the reserve's**: zaps to the profile of the pool key or of the
league key (no `e`, no `a`), zaps to any other league event, and the late receipts of every pot.

**Why a zap goal for the reserve.** The reserve is permanent and must not name a season ("the next
season is never promised"). A NIP-75 zap goal is the event made for "zap into this pot": NIP-75 clients
show a progress bar, its id never changes (it is a regular event), and its `zap` tag routes zaps to the
pool key. NIP-75 requires a target `amount`; the league sets one that promises nothing (21 000 000 sats
in the examples). A calendar event would have needed a start date, and a profile zap carries no pot id.

**Reconciling from relay data.** A third party can check every pot without the league's database:

1. **Find the pots:** the goal (`{"kinds":[9041],"authors":["<league>"]}`), the tournaments and
   bounties (`{"kinds":[31923],"authors":["<league>"]}`), the challenges of the chain seasons
   (`{"kinds":[2150],"#a":[<the season's ladders>]}`) and the geneses (`{"kinds":[2156],"authors":["<league>"]}`).
2. **Credits:** per pot, its receipts by `#e` or `#a`, each passing the six checks of
   [Counting the pool](#prize-pool-funding) against the endpoint's `nostrPubkey`; each payment hash
   counts once over all pots.
3. **Debits:** the payouts (`2157`), each amount split over the pots it references by the rules of the
   pot (block rewards, fee shares, prizes); and for the reserve, the `supply` of each genesis.
4. **Leftovers** go to the reserve by the rules in the table. The reserve balance before a genesis must
   cover its `supply`.

**What this proves, and what not.** Payouts carry preimages: those are proofs. Credits rest on the
receipts, which the league's own LNURL key signs (NIP-57: "The `zap receipt` is not a proof of
payment"): the league could leave a paid zap without a receipt, which only its payer would notice, or
sign a receipt for a payment that never happened, which inflates a pot that the league must then pay
out. Each receipt's invoice is signed by the league's node and names the amount, so a payer can
always match their own payment.

## Bounties (rev. 5)

A bounty is a prize on one player: whoever beats them first in a rated game within a time window
takes it. **Decision: no new kind.** A bounty is a NIP-52 time-based calendar event (`31923`) signed by
the league key, funded by zaps exactly like a tournament's prize pool, and the claim is derived from
the attestations. Every part a new kind would carry already has a home:

| part | where |
|---|---|
| target, ladder, window, rules | the `31923`: `p` with role `target`, `a` ladder, `start` / `end`, rules in `content` |
| funding | zaps to the `31923` (its `zap` tag names the pool key), counted as in [Prize pool funding](#prize-pool-funding) with `end` as the cut-off |
| claim | derived from the ladder's attestations; nothing is signed for it |
| payout | with the season's settlement: a `2157` that names the bounty by `a` (see [Payout](#payout-2157)) |

A new kind would only have added a second place for the same data, and calendar clients show the
bounty with its window for free.

| tag | content |
|---|---|
| `d` | `bounty/<slug>`, slug as for a clan; the `/` keeps it apart from tournament slugs |
| `title`, `summary`, `image` | as in NIP-52 |
| `start`, `end`, `D` | the window; one `D` per day it spans (NIP-52: "Multiple tags SHOULD be included to cover the event's timeframe") |
| `p` | the target, role `target` (NIP-52 participant role) |
| `a` | the ladder the claim must be played on |
| `zap` | the pool key, weight `1` |
| `t` | `esports`, the game, `bounty` |
| `location`, `alt` | the bounty page; NIP-31 text |

**Claim rule `bounty-v1`.** The bounty goes to the winner of the **first** attestation, in the ladder's
`prev` order, that

1. has a `created_at` between `start` and `end`, and belongs to a challenge created in that window;
2. is a rated result with `resolution` `confirmed` or `admin` (a forfeit never claims: a target could
   simply not show up against a friend);
3. has the target on the losing side (as an `elo` entity, or on the losing roster);
4. is between players who list each other: the `gate` rows of the two players (the gatekeepers in a
   series) name opponent lists that list the other one; a league pairing (queue, tournament) of players
   who do not list each other does not claim;
5. has no `clan` row that puts a winner in the target's clan;
6. was a real game: in chess the counted game record has at least 20 moves; a Rocket League series is
   complete by construction.

With a lineup ladder the winning roster shares the bounty equally. The bounty is paid with the season's
settlement (a `2157` that names the bounty by `a`); without a claim before `end` it goes to the league
reserve. Several bounties on one target are independent.
Query: `{"kinds":[31923],"authors":["<league>"],"#p":["<target>"]}` returns the bounties on a player;
`{"kinds":[9735],"#a":["31923:<league>:bounty/<slug>"]}` its funding.

## Queries

| question | filter |
|---|---|
| everything of one match | `{"kinds":[2151,2152,2153,2154],"#e":["<challenge id>"]}` |
| everything of one season | `{"kinds":[2150,2151,2152,2153,2154],"#a":["32152:<league>:<game>/<mode>/<season>"]}` |
| the current ladder | `{"kinds":[32152],"authors":["<league>"],"#d":["<game>/<mode>/<season>"]}` |
| all seasons of the league | `{"kinds":[32152],"authors":["<league>"]}` |
| challenges and answers addressed to me | `{"kinds":[2150,2151,2152,2153],"#p":["<my pubkey>"]}` |
| matches a player played in | `{"kinds":[2154],"#p":["<player pubkey>"]}` (roster entries; plain notification `p` in forfeits also match, check position 3) |
| a lineup's matches | `{"kinds":[2150,2154],"#a":["32151:<author>:<clan>/<game>/<mode>"]}` |
| the challenges of a tournament (rev. 3) | `{"kinds":[2150],"#e":["<draw id>"]}` |
| a tournament and everything that references it (rev. 4) | `{"#a":["31923:<league>:<slug>"]}` |
| the league's tournaments | `{"kinds":[31924],"authors":["<league>"],"#d":["tournaments"]}`, then its `a` references |
| the prize pool of a tournament | `{"kinds":[9735],"#a":["31923:<league>:<slug>"]}`, then [Counting the pool](#prize-pool-funding) |
| where the league publishes | `{"kinds":[10002],"authors":["<league>"]}` |
| the anchor list | `{"kinds":[30000],"authors":["<trust key>"],"#d":["esports/<league>/anchors"]}` |
| my private messages and notifications | `{"kinds":[1059],"#p":["<me>"],"since":<now - 2 days - margin>}` on my `10050` relays, after NIP-42 AUTH |
| a match by number | not filterable (`match` is a multi-letter tag); the league's API resolves a number to the challenge id |
| the games of a chess match (all boards; all moves of a correspondence game) | `{"kinds":[64],"#e":["<challenge id>"]}` |
| the next move of a correspondence game | `{"kinds":[64],"#e":["<id of the current last move>"]}` |
| the board results of a chess team match | `{"kinds":[2154],"#e":["<challenge id>"]}` |
| a player's ladders | `{"kinds":[32152],"authors":["<league>"],"#p":["<player pubkey>"]}` |
| a player's clan | `{"kinds":[12150],"authors":["<player>"]}` |
| who listed me as opponent (open requests and mutual ones) | `{"kinds":[30000],"#d":["esports/<league>"],"#p":["<my pubkey>"]}` |
| a player's opponent list | `{"kinds":[30000],"authors":["<player>"],"#d":["esports/<league>"]}` |
| trust ranks of the two players before an accept | `{"kinds":[30382],"authors":["<trust key>"],"#d":["<pubkey 1>","<pubkey 2>"]}` |
| the league's reports, or those about one player | `{"kinds":[1984],"#L":["space.einundzwanzig.esports"]}`, plus `"#p":["<player>"]` |
| all ladders of a season | `{"kinds":[32152],"authors":["<league>"]}`, then filter on `season` |
| a player's Block Height | `{"kinds":[2154],"authors":["<league>"],"#p":["<player>"]}`, then filter as in [Block Height](#block-height) |
| the seasons with a chain, Block 0 (rev. 5) | `{"kinds":[2156],"authors":["<league>"]}` |
| the ladders of a chain season | `{"kinds":[32152],"authors":["<league>"],"#e":["<genesis id>"]}` |
| the release confirmations of a genesis | its `e` tags, or `{"kinds":[1985],"#a":["31923:<league>:season/<season>"]}` |
| the blocks of a season | `{"kinds":[2154],"#a":[<the season's ladders>]}`, then walk `block` from the genesis |
| the review of a season | `{"kinds":[1985],"authors":["<league>"],"#e":[<block ids>]}` |
| the payouts of a season, or mine | `{"kinds":[2157],"authors":["<league>"],"#e":["<genesis id>"]}`, plus `"#p":["<me>"]` |
| the fees of a match | `{"kinds":[9735],"#e":["<challenge id>"]}` |
| the league reserve | `{"kinds":[9041],"authors":["<league>"]}`; its zaps `{"kinds":[9735],"#e":["<goal id>"]}` |
| the admins | `{"kinds":[30000],"authors":["<league>"],"#d":["esports/<league>/admins"]}` |
| the parameter changes of a season | `{"kinds":[2158],"authors":["<league>"],"#e":["<genesis id>"]}` |
| a player's rank badges (rev. 5) | `{"kinds":[30009],"authors":["<badge key>"],"#p":["<player>"]}`; the awards: `{"kinds":[8],"authors":["<badge key>"],"#p":["<player>"]}` |
| the bounties on a player (rev. 5) | `{"kinds":[31923],"authors":["<league>"],"#p":["<target>"]}`; the funding of one: `{"kinds":[9735],"#a":["31923:<league>:bounty/<slug>"]}` |

## Invite links

A shareable link `https://<league>/i/<code>` invites anyone who opens it, logged in or not. It is
league data only: **no invite link, no acceptance of one and no referral is ever an event.** What a
link leads to is ordinary protocol data once it happens.

- **The code** is 22 characters of base62 from a cryptographic random source (about 131 bits), not
  derived from any id. It is the only secret of the link; the league rate-limits the landing page.
- **Game links are open.** A chess link (blitz or daily) or a Rocket League link does not name the
  opponent: whoever opens it and accepts plays. A link is for one taker or for several (one game per
  taker); it expires. The game starts at acceptance, and a series takes its league match number then
  (the `match` tag, [Terminology](#terminology)), not when the link is made, so an unused link leaves
  no gap in the numbers.
- **Casual only.** Every game started from a link is casual. A rated Rocket League challenge (`2150`)
  names the challenged captains in `p` and is signed by the challenger before anyone could accept, so
  an open link cannot carry one; casual games produce no match-flow events at all
  ([Game registry](#game-registry)). A chess game from a link produces the same game notes (`64`)
  as any casual chess game.
- **Clan links send a join request**, never a membership (see [Clan](#clan-32150), "Join
  requests"). Named invitations (the owner lists one player) stay direct.
- **Referrals** (who invited whom, and whether the account is new) are kept by the league for
  cosmetic perks later. They never count toward ratings, trust, blocks, rewards or any attested
  number: a link is the easiest thing to farm.
- **Previews.** The landing page carries a title, a description and a preview image in plain HTML for
  messengers and Nostr clients, and asks search engines not to index it. The image is drawn by the
  league from its own assets; it fetches no picture from a URL a player chose.

## What is not on Nostr

These stay on the league server, on purpose:

| data | why |
|---|---|
| lobby name and password | a secret for the two lineups only; anything on a relay is readable by every reader and cannot be recalled |
| dispute evidence (screenshots of the end screen or the in-game match history) | may show third parties and other personal data; evidence is judged by admins, the verdict is public via `resolution admin` |
| plaintext of chats and notifications | only on the players' devices; the relay holds encrypted gift wraps (see [Chat](#chat)) |
| live per-game entries during a series | shown as provisional in the match room; only the final report is signed |
| blitz chess moves, clocks, draw offers | real-time traffic over the league server; the final game record contains the moves |
| clan rating (chess: average of the top three player ratings of the clan's members) | a view of public data (the player ladders and the current memberships); a league-signed copy would only be a second, possibly diverging statement of the same numbers |
| clan hashrate as a number | a game metric, not a rating; recomputable from `clan` rows and `hashrate` (rev. 4), so the league signs no number (a signed copy would be a second truth). Revision-3 seasons lack the inputs |
| no-show claims | a button in the match room; the league's decision is public as `resolution forfeit` |
| tournaments: registration, bracket state, seeding snapshot, per-player prize shares | league data that changes during the tournament; the tournament itself is a `31923`, the mix teams follow from the draw, the prize split is stated in the tournament's `content` |
| tournament consents (`22150`, rev. 7) | signed by the entrant or captain and kept by the league as evidence; registrations matter to nobody outside the tournament, and the draw is their public trace ([Tournament Consent](#tournament-consent-22150)) |
| tournament directors, their entries and corrections (rev. 7) | who may enter results is league data; the append-only log of entries and corrections stays with the league; the final entry is public as the attestation with `entered-by` |
| casual ratings (before Block 0, between seasons, unrated tournaments) | never attested; an unrated tournament's seeding rests on them and cannot be checked from relays |
| pool balance of the wallet, sponsor contracts, sponsor logos, NWC secret | operational; the pool is counted from receipts (see [Prize pool funding](#prize-pool-funding)) |
| Google e-mail address of a login | known to nostr-mill's servers (pomegranate), not to the league |
| sessions, presence, online counters, live ticker | ephemeral, high-frequency, worthless as history |
| rating computation internals, idempotency keys, payout status, NWC secret | operational state; only the rating result is attested |
| every earlier version of a membership (`12150`) | relays keep only the newest; a membership history needs the league's own archive |
| earlier versions of opponent lists (`30000`) and trust assertions (`30382`) referenced by a `gate` | relays keep only the newest; the league archives them and serves them by id as gate evidence |
| the ranks of all active lineup players at each accept | the eligibility snapshot behind condition 3 of the trust gate; only the rated players' rows go into the attestation |
| report reviews (dismissed, excluded) and their reasons | admin decisions in V1; their effect is public in the assertion |
| Nostr contact lists (kind `3`) | read and cached for suggestions and mutual contacts; never written |
| Block Height, Global Rating | views computed from public ladders and attestations, like the clan rating |
| rewards, eras, pending sums, mined and remaining supply, reserve balance (rev. 5) | derived from the genesis, the `block` tags, the labels and the receipts; a signed copy would be a second truth |
| the anomaly review: analysis, evidence, admin deliberation | may show private data and game patterns; the result is public as `void-block` labels with reasons |
| the pot ledger (double entry), NWC secrets, the players' Lightning addresses | operational; credits and debits are checkable from receipts and payouts ([Pots and zap targets](#pots-and-zap-targets-rev-5)) |
| season drafts before they are scheduled; estimator inputs | admin working state; the scheduled season is the announcement, the released one the genesis |
| invite links, their codes and uses, referrals | a link is a secret to share, not a statement; what it leads to (a game note, an owner's listing, a membership) is public when it happens ([Invite links](#invite-links)) |
| clan join requests and a captain's approval | only the owner's key lists players; the approval is an instruction to the owner, not a listing ([Clan](#clan-32150)) |

**Implication for publishing.** In the planned flow the client signs, the league validates and then
publishes. That works for events without `["-"]`. An event with `["-"]` (NIP-70) can only be
published by its author over an authenticated connection, so the league could not publish it for the
player. The league relay accepts writes only from the league publisher (see
[League relay and keys](#league-relay-and-keys)), so player events are not protected; a relay that
wanted protected player events would have to accept them from their authenticated authors as well.

## Reused NIPs

| NIP | use |
|---|---|
| 01 | event model, kind classes, `e`/`p`/`a`/`d` tags, filters |
| 02 | kind `3` read only: opponent suggestions and mutual contacts; never written, never counted |
| 03 | optional: OpenTimestamps proof (kind `1040`) that a tournament draw existed before its block |
| 07, 46, 55 | signing on the client (browser extension, remote signer, Android signer); a Google login through nostr-mill is a NIP-46 bunker (pomegranate) |
| 09 | deletion requests are accepted by relays but do not change league state |
| 19, 21 | `naddr` for clans, lineups, ladders; `nevent` for matches and draws; `nostr:` links |
| 04 | deliberately **not** used: `unrecommended`, deprecated in favor of NIP-17 |
| 17, 44, 59 | private chat and notifications: kind `14` rumors, NIP-44 sealed (`13`), gift-wrapped (`1059`); DM relay list `10050`; not the ephemeral `21059` |
| 22 | public discussion of a match: kind `1111` comments with the challenge as root, instead of a new chat kind |
| 24 | `bot` in the notification key's kind `0` |
| 31 | `alt` on every event |
| 32 | `L`/`l` labels that mark a report as a league report and give its reason; rev. 5: kind `1985` labels for the admins' release of Block 0 (`release-block-0`) and the corrections of the season review (`void-block`) |
| 40 | deliberately **not** used on challenges (see [Relay behaviour](#relay-behaviour)) |
| 42 | authentication on a league-operated relay |
| 47 | Nostr Wallet Connect, server side only: rev. 5 one wallet with a receive-only connection (LNURL invoices) and a paying connection with a budget (payouts) |
| 51 | opponent list and anchor list as follow sets (`30000`); mute list (`10000`) read by the client |
| 56 | reports (`1984`) that can lower a trust rank |
| 52 | tournaments as time-based calendar events (`31923`) in the league calendar (`31924`); rev. 5 also bounties (`d` = `bounty/<slug>`) and season announcements (`d` = `season/<season>`); no calendar event per match (the answer's `start` already is the schedule) |
| 64 | chess game records and correspondence moves (kind `64`, PGN) |
| 57 | zaps into a tournament's prize pool (receipts from the league's own LNURL endpoint, see [Prize pool funding](#prize-pool-funding)); optional for prize payouts. A tournament prize is split among the players who played and paid to each player's own `lud16`. Only if that player's LNURL server supports NIP-57 (`allowsNostr`) can the payment be a zap: the league key signs the zap request (`9734`), and the zap receipt (`9735`) is signed by the recipient's LNURL server, not by the league. Otherwise the payout is a plain Lightning payment. Revision 5: every payout, zap or not, is also a Payout (`2157`) with invoice and preimage; zaps go to every pot (see [Pots and zap targets](#pots-and-zap-targets-rev-5)), and the fees of a match target its challenge. A payout without a zap receipt is a normal case, not an error |
| 58 | rank badges (rev. 5): one `30009` definition per player, game and mode, replaced on every rank change, one `8` award, listed by the player in `10008`; signed by the badge key, see [Rank badges](#rank-badges-rev-5) |
| 65 | the league key publishes a relay list (`10002`) so clients find the ladder |
| 75 | rev. 5: the league reserve is a zap goal (`9041`), the zap target of the reserve pot; tournaments still use no goal |
| 70 | optional: protected user events (see above) |
| 98 | the league's login only (`27235` with `u`, `method` and a one-time `challenge`); rev. 7: deliberately **not** used for the tournament consent, see [Tournament Consent](#tournament-consent-22150) |
| 85 | trust ranks as user assertions (`30382`) and the trust key's kind `0`, signed by a trust key separate from the league key (one key per algorithm); not used for rating attestations (see [Prior art](#prior-art)) |

## Kind numbers and collision check

Checked on 2026-09-25, `2155` again in round 2 the same day, `2156` to `2158` in round 5. None of
`2150`-`2158`, `12150`, `32150`-`32152` is taken in any of the sources below.

| source | what was checked | result |
|---|---|---|
| official NIP index, `nostr-protocol/nips` README at commit `62d5fed` (2026-09-24; still the head in round 2) | event kind table | free; nearest registered kinds: `2022` and `4550` around `2150`-`2154`, `10312` and `13194` around `12150`, `31990` and `32267` around `32150`-`32152` |
| `nostr-protocol/registry-of-kinds` `schema.yaml` (commit `5cf2b84`, 2026-09-22; byte-identical in round 2) | 258 registered kinds | free |
| open and closed PRs and issues in `nostr-protocol/nips` | search for game, gaming, esports, tournament, match, leaderboard, ladder, score, bracket, chess, sport, bet, elo, clan | no PR uses these numbers (see [Prior art](#prior-art)) |
| GitHub code search, exact phrases `"kind: N"` and `"kinds: [N"` | each of the nine kinds | 0 hits each; controls `32100` (6 and 4 hits) and `30023` (934 and 506 hits) show the search finds real uses |
| GitHub code search, round 2 | `2155`: `"kind: 2155"`, `"kinds: [2155"`, `"kind === 2155"`, `"kind == 2155"`, `"kind\":2155"` | 0 hits each; controls `"kind: 30023"` 934, `"kind === 30023"` 351 |
| EINUNDZWANZIG repositories (`einundzwanzig-verein`, `-portal`, `-group`, `-autobot`) | kind constants | free; round 2: no file contains `2155` as a number, control `32121` finds 3 files in `einundzwanzig-verein` |
| nostrhub.io custom NIPs (kind `30817` events) | | **unconfirmed**: the site renders client-side and returned only its header over HTTP; public relays were not reachable over WebSocket from the sandbox this was written in |

**Round 7** adds `22150` (Tournament Consent, ephemeral), checked on 2026-09-26: free in the kind table
of the `nostr-protocol/nips` README at `b82211e` (still the head; the registered ephemeral kinds are
`21059`, `22242`, `23194`, `23195`, `24133`, `24242`, `27235`, `28934`-`28936`) and in
`registry-of-kinds` at `5cf2b84` (still the head; nearest entries `21059` and `22242`); GitHub code
search `"kind: 22150"`, `"kinds: [22150"`, `"kind === 22150"`, `"kind == 22150"`, `"kind\":22150"` 0
hits each (controls `"kind: 27235"` 756, `"kind === 27235"` 39); the five EINUNDZWANZIG repositories
(`-verein`, `-portal`, `-group`, `-autobot`, `-esports`) contain `22150` only inside hex ids and
signatures in a browser log (control `32121`: 7 files in `einundzwanzig-verein`). The new tags
`action` and `entered-by` appear in none of the 100 NIP files at `b82211e` nor in the README's tag
table (controls: `["rank"` in `85.md`, `["method"` in `98.md`).

**Round 5, second pass** adds `2158` (Parameter Change), checked the same way on 2026-09-25: free in
the README kind table at `b82211e` (still the head); GitHub code search `"kind: 2158"`, `"kinds: [2158"`,
`"kind === 2158"`, `"kind == 2158"`, `"kind\":2158"` 0 hits each (control `"kind: 30023"` 988); the four
EINUNDZWANZIG repositories 0 files. The new tags `pairlimit`, `subtree`, `moves`, `effective` and `tip`
appear in none of the NIP files nor in the README's tag table. (`pairing` was avoided: it is already a
tag of `2150` in this NIP.)

**Round 5** adds `2156` (Season Genesis) and `2157` (Payout), checked on 2026-09-25: free in the kind
table of the `nostr-protocol/nips` README at `b82211e` (head, 2026-09-25 13:51 UTC; it added `10040`,
`21059`, `33534` and `38000`; nearest registered kinds are still `2022` and `4550`) and in
`registry-of-kinds` (`5cf2b84`, unchanged); GitHub code search `"kind: 2156"` 2 hits, both in a Unity
`ProjectSettings.asset` of `dartmouth-cs98/hack-a-thing-1-dat-a-visual` (not Nostr), `"kinds: [2156"`,
`"kind === 2156"`, `"kind == 2156"`, `"kind\":2156"` and the same five for `2157` 0 hits (controls
`"kind: 30023"` 954, `"kind === 30023"` 353); the four EINUNDZWANZIG repositories contain neither number
(control `32121`: 7 files in `einundzwanzig-verein`). The new tags `block`, `supply`, `subsidy`,
`weight`, `share`, `daily`, `halving`, `consensus` and `anchor` appear in none of the 100 NIP files
(snapshot at `62d5fed`; `b82211e` changed only the README) nor in the README's tag table (controls:
`["zap"` in `57.md`, `["rank"` in `85.md`). `claim` is a tag of NIP-43 (`["claim", "<invite code>"]` in
a relay join request); tags are read per kind, and in `2156` it only names the claim window. `x` is
used as in NIP-94 and NIP-56, for a SHA-256 hash. The `d` shapes `season/…`, `bounty/…` and `rank/…`
and the label values `release-block-0` and `void-block` appear nowhere in the NIPs.

**Round 4** adds no kind either. The new tags `match`, `pairing`, `clan`, `hashrate`, `teams` and
`teamname` appear in none of the 99 NIP files and the README of `nostr-protocol/nips` at `62d5fed`
(head on 2026-09-25), neither as a JSON tag nor in a tag table (control: `["zap"` is found in `57.md`). The kinds
used in round 4 (`0`, `10002`, `31923`, `31924`, `30000`, `14`, `13`, `1059`, `10050`, `9734`, `9735`)
are used with their registered meaning; `21059` (NIP-59, added 2026-05-28 in PR #2245) is not in the
README kind table.

**Round 3** adds no kind. The three new tags (`trust`, `gate`, `override`) appear in none of the 100
NIP files of `nostr-protocol/nips` at `62d5fed`, neither as a JSON tag nor in a tag table (control:
`["rank"` is found in `85.md`); the label namespace `space.einundzwanzig.esports` appears nowhere
either.

Families rejected on the way:

- `2100`/`12100`/`32100`: `32100` is used for playlists by the Nostria client (`nostria-app/nostria`,
  `src/app/services/playlist.service.ts`); `32101` appears in a relay test of `ptrinh/freeport`.
- `2121`/`32121`/`32122`: taken inside EINUNDZWANZIG itself. `einundzwanzig-verein` publishes the
  membership fee as kind `32121` (`app/Jobs/PublishPaymentEventToNostr.php`) and kept the 2024
  board election as kinds `32122` and `2121` (`docs/vereinshistorie/wahl-2024.md`).

The GitHub code search covers only indexed default branches and only the two phrasings above; a
constant written as `Kind(2150)` or `KIND = 2150` would not be found.

## Prior art

| work | status | kinds | verdict |
|---|---|---|---|
| [NIP-64 Chess (PGN)](https://github.com/nostr-protocol/nips/blob/master/64.md), PR [#1094](https://github.com/nostr-protocol/nips/pull/1094) | merged | `64` | **reused.** Chess game records and correspondence moves are kind `64` notes with additional tags. NIP-64 defines the record; this NIP adds who confirms it, how moves are chained and how the game is rated. Revised in round 2, when chess became the first game. |
| PR [#212](https://github.com/nostr-protocol/nips/pull/212) NIP-64 Chess (WIP) | closed, superseded by #1094 | | nothing to take |
| Issue [#1453](https://github.com/nostr-protocol/nips/issues/1453) Chess server | open | `20300` and a response, ephemeral | **avoid the events, share the model.** Ephemeral events (`20300`-`20304`) are not stored, so they can carry neither a correspondence game nor a result. Its model, a server that validates every move, is what the league does for blitz, off Nostr; matchmaking queues stay out of scope |
| [Jester](https://github.com/jesterui/jesterui) | app only | `30` (one event per move; listed in `registry-of-kinds`, while the official index lists `30` for NKBIP-03) | **avoid the kind, keep the idea.** One kind `30` event per move with JSON content instead of PGN, no confirmation by the opponent, and `30` is listed for NKBIP-03 in the official index. The correspondence chain here keeps Jester's idea (each move references the previous one) but uses NIP-64 notes, so every move is a readable PGN game |
| [chesstr](https://github.com/bordalix/chesstr) | app only | not named in its README (unconfirmed) | not applicable |
| PR [#1205](https://github.com/nostr-protocol/nips/pull/1205) Speedrunning | open since 2024-04-27 | `7602`, `30076` | **avoid.** Single-player run submissions; uses `g` for the game, which collides with the NIP-52 geohash meaning. The idea of a game dictionary is covered here by the league's game registry |
| PR [#2272](https://github.com/nostr-protocol/nips/pull/2272) NIP-101g Golf | open | `1501`, `1502`, `31501`, `33501` | **align.** Same split as here: an addressable live view that is "not authoritative" plus a regular final record that is. This NIP's ladder (`32152`) and attestation (`2154`) follow that pattern |
| PR [#1816](https://github.com/nostr-protocol/nips/pull/1816) NIP-101e Workouts | open | `1301`, `33401`, `33402` | different domain; numbers avoided |
| PR [#2415](https://github.com/nostr-protocol/nips/pull/2415) Encrypted Betting Pools | open | `8880`-`8882` | out of scope (no wagers in V1); numbers avoided |
| PR [#2285](https://github.com/nostr-protocol/nips/pull/2285) Agent Reputation Attestations | open | `30085`, `30086` | different subject; numbers avoided |
| [NIP-85 Trusted Assertions](https://github.com/nostr-protocol/nips/blob/master/85.md), PR [#1534](https://github.com/nostr-protocol/nips/pull/1534) | merged 2026-01-22, `draft` | `30382`-`30385`, `10040` | **reused for trust ranks, rejected for the rating attestation.** Its `rank` (0-100) is exactly a trust score, published by a service key per algorithm. For ratings it does not fit: assertions are addressable, so a correction would replace the old value and the history would be lost. The kind table of the NIP index lists `30382`-`30384` but not `30385` or `10040`, which the NIP defines |
| [NIP-51 Lists](https://github.com/nostr-protocol/nips/blob/master/51.md), follow sets | `draft` | `30000` | **reused** as the opponent list |
| [NIP-56 Reporting](https://github.com/nostr-protocol/nips/blob/master/56.md), [NIP-32 Labeling](https://github.com/nostr-protocol/nips/blob/master/32.md) | merged | `1984`, `1985` | **reused** for reports; NIP-56 allows `L`/`l` to qualify a report |
| [Vertex](https://vertexlab.io/docs/services/verify-reputation/) | service, NIP-90 DVM | request `5312`, response `6312` (Verify Reputation) | **not used for the gate.** Personalized or global PageRank over the Nostr follow graph, sorted by `globalPagerank`, `personalizedPagerank` or `followerCount`; the result is a DVM response, not a stored assertion, and its seed is not the association |
| Brainstorm / GrapeRank ([NosFabrica](https://github.com/NosFabrica/brainstorm_server)) | service | publishes `30382` with `rank`, `followers`, `reporters`, `muters`, `hops` | **align, not use.** Same publication format as here; GrapeRank weighs follows, mutes and reports with confidence values (defaults: attenuation 0.85, rigor 0.5); its observer is a user, not the league |
| nostr.band trust rank | service | `30382` (NIP-85 names `wss://nip85.nostr.band` in its example) | not used: a global rank, not anchored in the association |
| PR [#1718](https://github.com/nostr-protocol/nips/pull/1718) NIP-101 Decentralized Trust System | closed 2025-11-01, not merged | `33`, ratings -100 to 100 | avoid |
| PR [#761](https://github.com/nostr-protocol/nips/pull/761) Contact Cards, PR [#604](https://github.com/nostr-protocol/nips/pull/604) Rating Mass, PR [#2198](https://github.com/nostr-protocol/nips/pull/2198) testimonials | open | | not needed: relationship cards, paid ratings and endorsements solve other problems |
| [NIP-58 Badges](https://github.com/nostr-protocol/nips/blob/master/58.md), [NIP-52 Calendar](https://github.com/nostr-protocol/nips/blob/master/52.md), [NIP-22 Comments](https://github.com/nostr-protocol/nips/blob/master/22.md) | merged | `8`, `30009`; `31923`, `31924`; `1111` | **reuse**, see [Reused NIPs](#reused-nips) |
| [gamestr](https://github.com/teamgamestr/gamestr) "NIP-762" | app only, spec in its repo | `30762` | **avoid.** High scores as addressable events, so a new score replaces the old one; its `state` values (`active`, `verified`, `disputed`, `invalidated`) are close to this NIP's statuses, but it queries them with `#state`, a multi-letter filter that strfry refuses and rnostr ignores |
| [Nostr Arcade](https://github.com/NostrDanish/Nostr-Arcade) | app only | `34987` scores, `34988` game directory | **avoid.** Self-reported single-player scores, leaderboard computed by the client |
| [Relay Battle](https://github.com/NostrDanish/Relay-Battle) | app only | `3633` battle results | **avoid.** Simulated battles; its Elo leaderboard lives in the browser's localStorage |
| PR [#1519](https://github.com/nostr-protocol/nips/pull/1519) Nostr Unofficial Documents | closed 2024-09-30 | | the NUD idea lives on as custom NIPs on nostrhub.io, which could not be read (see above) |

No prior work covers team competition with mutual result confirmation and a third-party rating
attestation. Nothing fits well enough to extend, so this NIP defines its own kinds, reuses NIP-64 for
chess games and reuses the general NIPs listed above.

## Examples

The events below were signed with throwaway keys generated for the test bed (league, alice, bob,
carol, dave, erin; round 3 adds trust, frank, sybil1-3; round 4 adds grace, heidi, ivan and the league's
single-purpose keys; round 5 adds a second admin, the badge key and a wallet node) and published to
rnostr, strfry and khatru (relay proof, rounds 2 to 5; round 4 also to the league relay). All times are 2026-09-25, UTC. The examples of rounds 1 to 3 are revision 3,
those of [round 4](#revision-4-season-4-a-queue-game-a-tournament-with-a-mix-team-chat-and-the-prize-pool)
revision 4, those of [round 5](#revision-5-the-pre-season-the-season-chain-rank-badges-and-a-bounty) revision 5, those of
[round 6](#revision-6-a-roster-invitation-and-a-lineup-from-members) revision 6 (see the status note at the
top).

**Rank tiers re-signed in round 5.** The five ladders of seasons 1 to 3 printed below were signed
again with the 21 [rank tiers](#rank-tiers-rev-5) instead of five, with `created_at` one second later
than before, so that each replaces the five-tier version on the relays (relays keep the newest version
of an addressable event); everything else in them is unchanged, and the two season-3 ladders are now
printed in their closing versions, the ones the relays hold. The unprinted ladders of the test bed got
the same treatment, so each league-wide season keeps one tier set; the season-4 ladders were then
closed in round 5 with 21 tiers, and the blitz one is printed in that closing version. Earlier
versions of these ladders, still with five tiers, were replaced on the test bed. In the example world that breaks the frozen-parameter rule for
`tier`, which is the one change a replay of the attestations cannot detect (see
[Ladder](#ladder-32152)); it is a re-issue of the documentation, not something a league may do.

Round 4 keys: grace `5ccb330beff1652ff8b69f9478e9c97468c0a194e3bb3efd8f7d97cb1c139a6b`, heidi `e0102842c1cffa01a03dba34c07fe88a0907bdb3fc867106629ab8299243d0c1`, ivan `9f90338586f9de60a4fa24756c840a02d8cfb7748f02ad32cc80fac75a09fa6e`, notification key `eff5cbea34f91bc591542cd8e3065eedc7c12cd931d947b66aab93913d35d12f`, pool key
`3fe2a96fc8d3efe65eb05be97ab7b483b73c56118acbae12e75ac950e1d7e504`, LNURL server key `8f12a686b755d0ef25fc3113b1917b37831e5ec7557267ca16bddc8898594512`, sponsor desk key `f244d344d7b21530617c112228460ace791b96b317d00077a9efb0003acae3e2`, admin `2a0335d330a81f7c1d701d8c750593d03a0ab3d2c19bbcebd66faa21cb6c078c`, fan `ce9ffe39455312a9231fe9474296eceeae7777de3e078b92e9ddd2b2816f1c57`.

Round 3 keys: trust `21b9921fc310826ffbd9872995a4de1d9c5115042e99ede4e74786e39729ebd5`, frank `ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd`, sybil1 `16faeec19e94a135021a478221e4640ab4510dd26a6fbfcb1f20049762f42571`.

Pubkeys: league `8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753`, alice `e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e`, bob `b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7`, carol `eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356`, dave `fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea`, erin `31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f`.

### Rocket League: series, seasons, tournament

Game `rocket-league`, mode `2v2`, best of three.

- 09:35 both clans get a clan tag; Nakamoto Rockets add erin as substitute, erin accepts;
  season 1 opens (started 00:00).
- 09:36-10:00:30 match 1, a ladder challenge: Satoshi's Strikers beat Nakamoto Rockets 2-1 in
  games; game 2 was entered without goals; erin played instead of dave; confirmed and attested
  (1000/1000 to 1016/984).
- 09:46 the league draws the tournament `lan-2026-09` against block 968536 (mined 09:56:34, hash
  `00000000000000000001388388e2cb3c999ab1f9b349a2ae376d95ec9fc5c461`). The order puts Nakamoto
  Rockets in slot 1.
- 10:02-10:31 match 2, the tournament final: slot 1 challenges slot 2, Satoshi's Strikers accept
  and do not show up; the league attests a forfeit (984/1016 to 1001/999).
- 10:32 season 1 closes, both lineups still provisional (2 rated series each, 5 needed); 10:35
  season 2 opens with K 24 and carry-over factor 0.5: seeds 1001 and 999, because
  `round(+0.5)` and `round(-0.5)` round away from zero.
- 11:08 season 2 closes without a series when the league moves to league-wide seasons (see
  [Trust gate and league-wide season 3](#trust-gate-and-league-wide-season-3)). The ladder printed
  below is that closing version; it still carries the frozen `reset` and `seed` tags of the soft reset.

These ladders were signed before the provisional k-factor was decided and carry
`["provisional","5"]`, so k is 32 throughout (see [Open points](#open-points)).

The draw is backdated for the example: it was signed after block 968536 existed, with a
`created_at` before it. That is exactly what `created_at` cannot rule out, and why a real draw needs
the NIP-03 proof described above.

#### Clan (`32150`) with clan tag

```json
{
  "kind": 32150,
  "id": "4645d226d22c779df1ca0a48f6c49b2145585bcf5ecdbeb9d85bfce0ab3b4829",
  "pubkey": "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e",
  "created_at": 1790328900,
  "tags": [
    ["d", "satoshis-strikers"],
    ["name", "Satoshi's Strikers"],
    ["clantag", "STR"],
    ["picture", "https://example.org/strikers.png"],
    ["r", "https://portal.einundzwanzig.space/meetups/example-meetup"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "", "captain"],
    ["p", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "", "member"],
    ["alt", "Esports clan: Satoshi's Strikers [STR]"]
  ],
  "content": "Rocket League clan of an example meetup.",
  "sig": "78e78c33c09e5d749d20b7e524cc3b387d8afae01872943d3672a668e6a9c8c020d6e9605bfdcec83081aba39a86c76d9a5256cc773ef95607e1c86c9441c3cb"
}
```

#### Lineup (`32151`) with a substitute

```json
{
  "kind": 32151,
  "id": "f9f9c422ad3bc88fe70cb458f11afce84a97feb88d8c74b79b53c9a42d44636b",
  "pubkey": "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356",
  "created_at": 1790328900,
  "tags": [
    ["d", "nakamoto-rockets/rocket-league/2v2"],
    ["a", "32150:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets", ""],
    ["game", "rocket-league"],
    ["mode", "2v2"],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "", "captain"],
    ["p", "fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea", "", "player"],
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "", "substitute"],
    ["alt", "Esports lineup: Nakamoto Rockets, rocket-league 2v2"]
  ],
  "content": "",
  "sig": "e65b9ff0ffa67119947f03f8795794d274a3bc2561523354d8597052c9e50c2538ce6545ae3c5f687e2a59d74f64e918e0e2b3c6515e59913a22796a551e5523"
}
```

#### Ladder (`32152`), season 1 closed

```json
{
  "kind": 32152,
  "id": "163fcb3f3547b85674a2888414fe45b7a7e18e38c7bfaac6df5911ae2eb3664a",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790332381,
  "tags": [
    ["d", "rocket-league/2v2/season-1"],
    ["game", "rocket-league"],
    ["mode", "2v2"],
    ["season", "season-1"],
    ["starts", "1790294400"],
    ["rating", "elo", "1000", "32", "400"],
    ["tier", "bronze-1", "0"],
    ["tier", "bronze-2", "900"],
    ["tier", "bronze-3", "925"],
    ["tier", "silver-1", "950"],
    ["tier", "silver-2", "975"],
    ["tier", "silver-3", "1000"],
    ["tier", "gold-1", "1025"],
    ["tier", "gold-2", "1050"],
    ["tier", "gold-3", "1075"],
    ["tier", "platinum-1", "1100"],
    ["tier", "platinum-2", "1125"],
    ["tier", "platinum-3", "1150"],
    ["tier", "diamond-1", "1175"],
    ["tier", "diamond-2", "1200"],
    ["tier", "diamond-3", "1225"],
    ["tier", "champion-1", "1250"],
    ["tier", "champion-2", "1275"],
    ["tier", "champion-3", "1300"],
    ["tier", "grand-champion-1", "1325"],
    ["tier", "grand-champion-2", "1375"],
    ["tier", "grand-champion-3", "1425"],
    ["provisional", "5"],
    ["ends", "1790332320"],
    ["e", "3db359b35732851f07924b0a5587089a911f1543c9d28adf0479a0cdee395a60", ""],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", ""],
    ["a", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", ""],
    ["standing", "1", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", "1001", "1", "1", "provisional"],
    ["standing", "2", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", "999", "1", "1", "provisional"],
    ["alt", "Esports ladder: rocket-league 2v2, season-1, closed"]
  ],
  "content": "Season 1 is closed.",
  "sig": "fa57c6f3b979a3fb84da1a0869ac0939854dd5f3334c9aaba0efcdfe9a93f2da5098de7fc68df168027f7d8faca0e6700a7639a532fa99aeba6de317e75addae"
}
```

#### Ladder (`32152`), season 2 with a soft reset, closed at 11:08

```json
{
  "kind": 32152,
  "id": "dd547d37ca1f56ed1897fdc8ac6edcb84cabd61b453f3ec76e2f41da1ef7a576",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790334481,
  "tags": [
    ["d", "rocket-league/2v2/season-2"],
    ["game", "rocket-league"],
    ["mode", "2v2"],
    ["season", "season-2"],
    ["starts", "1790332500"],
    ["rating", "elo", "1000", "24", "400"],
    ["tier", "bronze-1", "0"],
    ["tier", "bronze-2", "900"],
    ["tier", "bronze-3", "925"],
    ["tier", "silver-1", "950"],
    ["tier", "silver-2", "975"],
    ["tier", "silver-3", "1000"],
    ["tier", "gold-1", "1025"],
    ["tier", "gold-2", "1050"],
    ["tier", "gold-3", "1075"],
    ["tier", "platinum-1", "1100"],
    ["tier", "platinum-2", "1125"],
    ["tier", "platinum-3", "1150"],
    ["tier", "diamond-1", "1175"],
    ["tier", "diamond-2", "1200"],
    ["tier", "diamond-3", "1225"],
    ["tier", "champion-1", "1250"],
    ["tier", "champion-2", "1275"],
    ["tier", "champion-3", "1300"],
    ["tier", "grand-champion-1", "1325"],
    ["tier", "grand-champion-2", "1375"],
    ["tier", "grand-champion-3", "1425"],
    ["provisional", "5"],
    ["reset", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rocket-league/2v2/season-1", "3db359b35732851f07924b0a5587089a911f1543c9d28adf0479a0cdee395a60", "0.5"],
    ["seed", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", "999"],
    ["seed", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", "1001"],
    ["a", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", ""],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", ""],
    ["ends", "1790334480"],
    ["alt", "Esports ladder: rocket-league 2v2, season-2, closed"]
  ],
  "content": "Season closed for the league-wide season-3.",
  "sig": "2456a2dd17ce187c00e3e24ccda8041f23972463316f7b2e0dc0d4926dbd2437884d058cd55c53b3fbf637998a71c05b9812c34bec954645db18b54d22b54771"
}
```

#### Challenge (`2150`) on the ladder

```json
{
  "kind": 2150,
  "id": "c11dc18be525c7fbe736aaddaf698f69c8322cf84d7e833d81d5add701233157",
  "pubkey": "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e",
  "created_at": 1790328960,
  "tags": [
    ["a", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", "", "challenger"],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", "", "challenged"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rocket-league/2v2/season-1", ""],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["bo", "3"],
    ["start", "1790329200"],
    ["start", "1790331000"],
    ["respond_by", "1790332200"],
    ["alt", "Esports challenge: best of 3, rocket-league 2v2"]
  ],
  "content": "Rematch? Now or at ten past ten.",
  "sig": "382b46c680e861203c8843963fc1001ec470d51c73d0077bf847d14bbaa0b399d48743cfd5acf28febdbb448183aa81e56759d0566b774b6f79b5e69348624db"
}
```

#### Challenge Answer (`2151`)

```json
{
  "kind": 2151,
  "id": "939421bd97c99df0de64d6d2a0e7a6ff31d0613988c2fea364b16d9ecbd91c3f",
  "pubkey": "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356",
  "created_at": 1790329080,
  "tags": [
    ["e", "c11dc18be525c7fbe736aaddaf698f69c8322cf84d7e833d81d5add701233157", "", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["a", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", ""],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", ""],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rocket-league/2v2/season-1", ""],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["status", "accepted"],
    ["start", "1790329200"],
    ["alt", "Esports challenge answer: accepted"]
  ],
  "content": "",
  "sig": "e6254238675dc3ac2498a87119ed34a9fe683e4cbf7e5b8c9594ff3af6932c0061bef3998af1e49d287a7c533b1848d348498480963bff5f752071940aa902b6"
}
```

#### Result Report (`2152`), 2-1 in games, game 2 without goals, a substitute played

```json
{
  "kind": 2152,
  "id": "8f6b8d9c4d96f06ac39eb69b72d510e69b5d2ff3625ef2c7b53ed916376bcdce",
  "pubkey": "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e",
  "created_at": 1790330280,
  "tags": [
    ["e", "c11dc18be525c7fbe736aaddaf698f69c8322cf84d7e833d81d5add701233157", "", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["a", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", ""],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", ""],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rocket-league/2v2/season-1", ""],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "", "challenger", "captain"],
    ["p", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "", "challenger", "player"],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "", "challenged", "captain"],
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "", "challenged", "substitute"],
    ["score", "1", "challenger", "3", "1"],
    ["score", "2", "challenged"],
    ["score", "3", "challenger", "2", "1", "ot"],
    ["alt", "Esports result report: challenger won 2-1 in games"]
  ],
  "content": "",
  "sig": "bb7d141f62869a0ec70a64a9a7e4da5e14b71f0bf0216d02343d736456a000ba6781bea7c11df27303aacaa6d087d51da2b7b5aa92ea23ac6da64cff5d91c979"
}
```

#### Result Response (`2153`)

```json
{
  "kind": 2153,
  "id": "24b1e6e1af7d5e04bceaaf4521d19e6c5bdbfbd3d583adcf2fba667c1d9217fa",
  "pubkey": "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356",
  "created_at": 1790330400,
  "tags": [
    ["e", "8f6b8d9c4d96f06ac39eb69b72d510e69b5d2ff3625ef2c7b53ed916376bcdce", "", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["e", "c11dc18be525c7fbe736aaddaf698f69c8322cf84d7e833d81d5add701233157", "", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["a", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", ""],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", ""],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rocket-league/2v2/season-1", ""],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["status", "confirmed"],
    ["alt", "Esports result response: confirmed"]
  ],
  "content": "",
  "sig": "754849d14df9322366d43af5f0bc769ba73b9a5ee8eea397ee3317d400f8ac355c72bbab50c2936350064d42e7bb3cfe20c8cc8a572bd2933095c516a8633ded"
}
```

#### League Attestation (`2154`), confirmed

```json
{
  "kind": 2154,
  "id": "eb5617feb044bc4dc95639ac5617eea631d3f8b8932e096b3ad8a7b171b6b259",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790330430,
  "tags": [
    ["e", "c11dc18be525c7fbe736aaddaf698f69c8322cf84d7e833d81d5add701233157", "", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["e", "939421bd97c99df0de64d6d2a0e7a6ff31d0613988c2fea364b16d9ecbd91c3f", "", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["e", "8f6b8d9c4d96f06ac39eb69b72d510e69b5d2ff3625ef2c7b53ed916376bcdce", "", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["e", "24b1e6e1af7d5e04bceaaf4521d19e6c5bdbfbd3d583adcf2fba667c1d9217fa", "", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rocket-league/2v2/season-1", ""],
    ["a", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", "", "challenger"],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", "", "challenged"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "", "challenger", "captain"],
    ["p", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "", "challenger", "player"],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "", "challenged", "captain"],
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "", "challenged", "substitute"],
    ["resolution", "confirmed"],
    ["winner", "challenger"],
    ["score", "1", "challenger", "3", "1"],
    ["score", "2", "challenged"],
    ["score", "3", "challenger", "2", "1", "ot"],
    ["elo", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", "1000", "1016"],
    ["elo", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", "1000", "984"],
    ["alt", "Esports league attestation: challenger won 2-1, Elo updated"]
  ],
  "content": "",
  "sig": "3f4038fd80da472b4f7c385bf96392f713e9ec539a98a383d3c4041e668931358ffd2b488ba575b1956c088a52a836d99fa4f7b595dc61df01472c6343adc778"
}
```

#### Tournament Draw (`2155`)

```json
{
  "kind": 2155,
  "id": "4f0e7d52c83349577cbf950683129009418083c9686f0d51865f30856864fb4c",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790329560,
  "tags": [
    ["tournament", "lan-2026-09"],
    ["name", "LAN-Abend September"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rocket-league/2v2/season-1", ""],
    ["format", "single-elimination"],
    ["a", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", "", "entrant"],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", "", "entrant"],
    ["draw", "968536", "sha256-v1"],
    ["alt", "Esports tournament draw: LAN-Abend September, 2 entrants, block 968536"]
  ],
  "content": "",
  "sig": "e5a68e42568f4c55a5d59b7398a9173c51d69c535c558b51937e4d1524bb37bd9868c621d546c8fbab478808167a21d7a8ae18d3f2d707060f99921a8dc6348f"
}
```

#### Challenge (`2150`) of a tournament

```json
{
  "kind": 2150,
  "id": "e1e15333f9b0de5f51ab932dddcaea995d45134aabffcd392109a8841617d815",
  "pubkey": "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356",
  "created_at": 1790330520,
  "tags": [
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", "", "challenger"],
    ["a", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", "", "challenged"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rocket-league/2v2/season-1", ""],
    ["e", "4f0e7d52c83349577cbf950683129009418083c9686f0d51865f30856864fb4c", "", "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753"],
    ["tournament", "lan-2026-09"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["bo", "3"],
    ["start", "1790331300"],
    ["respond_by", "1790331200"],
    ["alt", "Esports challenge: best of 3, rocket-league 2v2, tournament lan-2026-09"]
  ],
  "content": "Final, 10:15.",
  "sig": "8d2c16b5aee20b6b2390681428c99aea5f1f5f0a4774479e3e0a409fba978c396dbac21ac247a6c0394571c2929e920aefbd14c3ace2bfc133af5764605c1a42"
}
```

#### League Attestation (`2154`), forfeit after a no-show

```json
{
  "kind": 2154,
  "id": "3db359b35732851f07924b0a5587089a911f1543c9d28adf0479a0cdee395a60",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790332260,
  "tags": [
    ["e", "e1e15333f9b0de5f51ab932dddcaea995d45134aabffcd392109a8841617d815", "", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["e", "ef4a65c717833dc7c04aee301de669353c9f341435f7c8fd88ac93c959be1231", "", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rocket-league/2v2/season-1", ""],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", "", "challenger"],
    ["a", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", "", "challenged"],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["resolution", "forfeit"],
    ["winner", "challenger"],
    ["elo", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", "984", "1001"],
    ["elo", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", "1016", "999"],
    ["prev", "eb5617feb044bc4dc95639ac5617eea631d3f8b8932e096b3ad8a7b171b6b259"],
    ["alt", "Esports league attestation: forfeit, challenged lineup did not show up"]
  ],
  "content": "No-show: the challenged lineup was not in the lobby 15 minutes after the agreed start.",
  "sig": "e4a77f1a28e6e4f35b981eb0dbf4b948327334ca33ec49e1f820697c725b58f821ed40a12d16f9837004f42921ae35a5f818ab45796e140cfdbab0c7c27201a1"
}
```

### Chess: team match over boards, correspondence game

Game `chess`, ladders `chess/blitz/season-1` (`300+3`) and `chess/correspondence/season-1`
(`1/86400`), both `rates player`, `["provisional","5","40"]`.

- 10:40 both clans field a chess blitz lineup (alice, bob; carol, erin), and the four players accept
  it in new versions of their memberships; the two chess ladders open. Erin's new membership
  replaced the 09:35 version on every relay, which is why it is the one printed here.
- 10:41-11:00:40 team match over 2 boards. Board 1: alice (challenger side, White) mates carol in
  four moves, signs the game record, carol confirms. Board 2: erin (challenged side, White) and bob
  agree a draw, erin signs, bob confirms. Two board attestations on the blitz ladder, with k 40
  because everyone is provisional: alice 1020, carol 980, bob and erin 1000. The team result,
  1½:½ for Satoshi's Strikers, is derived from the two attestations and moves no rating.
- 11:08 both chess ladders close for the league-wide season 3. The blitz ladder printed below is the
  closing version: the standings after the team match, and `ends`.
- 10:40:30-10:55:20 correspondence game, bob (White) against dave: four moves as four kind `64`
  notes, each pointing at the previous one; the fourth (`Qh4#`, `0-1`) is the final record, signed by
  dave, and bob confirms. Attestation on the correspondence ladder: dave 1020, bob 980.

#### Clan Membership (`12150`), erin's current version: clan, Rocket League lineup, chess lineup

A revision-4 membership: from revision 6 on, the two lineup references are ignored and a new version
carries the clan `a` only (see [Revision 6](#revision-6-a-roster-invitation-and-a-lineup-from-members)).

```json
{
  "kind": 12150,
  "id": "c18b69abcc53f2b84f13a275273c0ad218cc3239cf2329abfd17565ecc41523c",
  "pubkey": "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f",
  "created_at": 1790332800,
  "tags": [
    ["a", "32150:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets", ""],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", ""],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/chess/blitz", ""],
    ["alt", "Esports clan membership and accepted lineups"]
  ],
  "content": "",
  "sig": "830f902c0fe7d022ab7b194a21716aefd1f0a062a945b3edc28559d5457f4cea626e348b3c97c014d9f7e61429fd9d7421d20a9806a10a89bd8ea97a48bb0041"
}
```

#### Ladder (`32152`), chess blitz after the team match, closed at 11:08

```json
{
  "kind": 32152,
  "id": "f99d4c5cf10555e9bf1b7e65255713625f4ad1cb4dafae8d5e96a23e078b4bd9",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790334481,
  "tags": [
    ["d", "chess/blitz/season-1"],
    ["game", "chess"],
    ["mode", "blitz"],
    ["season", "season-1"],
    ["starts", "1790294400"],
    ["rates", "player"],
    ["time_control", "300+3"],
    ["variant", "standard"],
    ["rating", "elo", "1000", "32", "400"],
    ["tier", "bronze-1", "0"],
    ["tier", "bronze-2", "900"],
    ["tier", "bronze-3", "925"],
    ["tier", "silver-1", "950"],
    ["tier", "silver-2", "975"],
    ["tier", "silver-3", "1000"],
    ["tier", "gold-1", "1025"],
    ["tier", "gold-2", "1050"],
    ["tier", "gold-3", "1075"],
    ["tier", "platinum-1", "1100"],
    ["tier", "platinum-2", "1125"],
    ["tier", "platinum-3", "1150"],
    ["tier", "diamond-1", "1175"],
    ["tier", "diamond-2", "1200"],
    ["tier", "diamond-3", "1225"],
    ["tier", "champion-1", "1250"],
    ["tier", "champion-2", "1275"],
    ["tier", "champion-3", "1300"],
    ["tier", "grand-champion-1", "1325"],
    ["tier", "grand-champion-2", "1375"],
    ["tier", "grand-champion-3", "1425"],
    ["provisional", "5", "40"],
    ["e", "d42de011c1773bb7c3348db67fb75272f7b13c4fe5785e4769a16f73c1d20251", ""],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["p", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7"],
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f"],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["standing", "1", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "1020", "1", "0", "provisional"],
    ["standing", "2", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "1000", "0", "0", "provisional", "1"],
    ["standing", "3", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "1000", "0", "0", "provisional", "1"],
    ["standing", "4", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "980", "0", "1", "provisional"],
    ["ends", "1790334480"],
    ["alt", "Esports ladder: chess blitz, season-1, closed"]
  ],
  "content": "Season closed for the league-wide season-3.",
  "sig": "7d5d4e184a2f2d37e32474708789c0711f1e728cb5f82a8497baed0f7c042006a41d8998bb136151f642956d429d9563f67dc4524058a3197432947d0d6ffb19"
}
```

#### Challenge (`2150`), chess team match over 2 boards

```json
{
  "kind": 2150,
  "id": "ac9246f90b26e8b95d1f45bcd597763047feddeb9c43f9730fcddb407269d7c6",
  "pubkey": "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e",
  "created_at": 1790332860,
  "tags": [
    ["a", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/chess/blitz", "", "challenger"],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/chess/blitz", "", "challenged"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/blitz/season-1", ""],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["boards", "2"],
    ["start", "1790333100"],
    ["respond_by", "1790336400"],
    ["alt", "Esports challenge: chess team match over 2 boards, blitz 5+3"]
  ],
  "content": "Two boards, 5+3, now?",
  "sig": "35a9b40385940adaa3f1f247742d77e39a842d6dc1cc851502b2f171f87b3c314c57cf9d25ae9a8d84dc1b93680d8ddfeb6497a91916b9e264cfc9e162c685a7"
}
```

#### Game Record (`64`), board 1, final

```json
{
  "kind": 64,
  "id": "4f38086ac42b5a26ba85d041bc1ee0b83abce3da224c66b79042434c9f17cddd",
  "pubkey": "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e",
  "created_at": 1790333700,
  "tags": [
    ["e", "ac9246f90b26e8b95d1f45bcd597763047feddeb9c43f9730fcddb407269d7c6", "", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["a", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/chess/blitz", ""],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/chess/blitz", ""],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/blitz/season-1", ""],
    ["board", "1"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "", "white"],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "", "black"],
    ["alt", "Chess game (PGN): alice vs carol, board 1, 1-0"]
  ],
  "content": "[Event \"EINUNDZWANZIG Esports, chess/blitz/season-1\"]\n[Site \"EINUNDZWANZIG Esports\"]\n[Date \"2026.09.25\"]\n[Round \"-\"]\n[White \"alice\"]\n[Black \"carol\"]\n[Result \"1-0\"]\n[Termination \"Normal\"]\n[TimeControl \"300+3\"]\n[Variant \"Standard\"]\n\n1. e4 e5 2. Bc4 Nc6 3. Qh5 Nf6 4. Qxf7# 1-0",
  "sig": "8a3a3ef464a2bd19a26156aff73dcf47b52c320c86f87d5573c3925178769c0cb4b2f1217f7ec9c4313fe869bff8b565860d6968f527209815d911fbfeca6f0b"
}
```

#### Result Response (`2153`) to a game record

```json
{
  "kind": 2153,
  "id": "95d18663d3c4458434063df2ffed7b32062bddc99a0f5eea74af47dfdb83b1a3",
  "pubkey": "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356",
  "created_at": 1790333760,
  "tags": [
    ["e", "4f38086ac42b5a26ba85d041bc1ee0b83abce3da224c66b79042434c9f17cddd", "", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["e", "ac9246f90b26e8b95d1f45bcd597763047feddeb9c43f9730fcddb407269d7c6", "", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["a", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/chess/blitz", ""],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/chess/blitz", ""],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/blitz/season-1", ""],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["status", "confirmed"],
    ["alt", "Esports result response: confirmed"]
  ],
  "content": "",
  "sig": "f0bebe5ef3e311c14cd56aaf50fd0369dbe86c765036e65c98d26cac59e733ec37528933ebefb096daf1a66130f5b2be5cd96f92f7bf6d6a4ba4f6d706538623"
}
```

#### League Attestation (`2154`), board 2, draw

```json
{
  "kind": 2154,
  "id": "d42de011c1773bb7c3348db67fb75272f7b13c4fe5785e4769a16f73c1d20251",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790334020,
  "tags": [
    ["e", "ac9246f90b26e8b95d1f45bcd597763047feddeb9c43f9730fcddb407269d7c6", "", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["e", "ac4d672db5e1bdc9a49ff98be10c49d91889d22171f1bbc16c9318e75c8d1e8d", "", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["e", "132554104060e0d6c7ddefe201ed2b0cd873f93a7e5be7f7969fbfd76634ecb5", "", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f"],
    ["e", "e5074068f14466ba7264bd84e5491821127c67d572253dfe2cdbb096b666a457", "", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/blitz/season-1", ""],
    ["a", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/chess/blitz", "", "challenger"],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/chess/blitz", "", "challenged"],
    ["p", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "", "challenger", "player"],
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "", "challenged", "player"],
    ["board", "2", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "1/2-1/2"],
    ["resolution", "confirmed"],
    ["winner", "draw"],
    ["elo", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "1000", "1000"],
    ["elo", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "1000", "1000"],
    ["prev", "6f3c1ca521d52d62e32e640c5a513ea2ab5fd2d2c42903623d2dcec9fbe92e51"],
    ["alt", "Esports league attestation: chess team match, board 2, draw"]
  ],
  "content": "",
  "sig": "7d0ed1444faf55b74438b220291866d5aafb6e71022088af445b33018ad4b01187982c878788bcaed5b28c4bd2ffd7421bfe96d32a8193229c3da1a459ab9c3c"
}
```

#### Challenge (`2150`), solo correspondence game

```json
{
  "kind": 2150,
  "id": "980a0081f52d3dbaac8ee0c56a95379a0b56958d8c8093429137f8f319a3133b",
  "pubkey": "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7",
  "created_at": 1790332830,
  "tags": [
    ["p", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "", "challenger"],
    ["p", "fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea", "", "challenged"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/correspondence/season-1", ""],
    ["color", "white"],
    ["start", "1790332920"],
    ["respond_by", "1790419200"],
    ["alt", "Esports challenge: correspondence chess, one move per day"]
  ],
  "content": "",
  "sig": "89796605a4cdbdb1d9dc589d7384c1ccf7a2d567578b0e17dbd6c2434fa69b2644612d89c4607a85e1d20bb2381c0cfe754f09b6efcd1e9ecf6d5b63631fcaff"
}
```

#### Game Record (`64`), correspondence move 3 of 4

```json
{
  "kind": 64,
  "id": "a69b27f02636fb8082ec08c529402adc44f1183ed5674a86a86b13a09219ee97",
  "pubkey": "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7",
  "created_at": 1790333400,
  "tags": [
    ["e", "980a0081f52d3dbaac8ee0c56a95379a0b56958d8c8093429137f8f319a3133b", "", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7"],
    ["e", "fb8c0a3de710097c6cb1f8d312adcd23fdb398374ee41f67272a77bd7b6de5b7", "", "fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/correspondence/season-1", ""],
    ["p", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "", "white"],
    ["p", "fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea", "", "black"],
    ["alt", "Chess game (PGN): bob vs dave, correspondence, after move 3"]
  ],
  "content": "[Event \"EINUNDZWANZIG Esports, chess/correspondence/season-1\"]\n[Site \"EINUNDZWANZIG Esports\"]\n[Date \"2026.09.25\"]\n[Round \"-\"]\n[White \"bob\"]\n[Black \"dave\"]\n[Result \"*\"]\n[TimeControl \"1/86400\"]\n[Variant \"Standard\"]\n\n1. f3 e5 2. g4 *",
  "sig": "35acc579424715f081bdb0c43924d4da49eec94babaef94abdcdd062df22c5028711833ab2ddf69ef5973054a12115337649b485e4734554fb9d123ef863ec9b"
}
```

#### Game Record (`64`), correspondence move 4, final

```json
{
  "kind": 64,
  "id": "8ea07536c2beb9646b8bf4de9267d29d7bad8b29a44368380ae810e2427eb3be",
  "pubkey": "fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea",
  "created_at": 1790333600,
  "tags": [
    ["e", "980a0081f52d3dbaac8ee0c56a95379a0b56958d8c8093429137f8f319a3133b", "", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7"],
    ["e", "a69b27f02636fb8082ec08c529402adc44f1183ed5674a86a86b13a09219ee97", "", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/correspondence/season-1", ""],
    ["p", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "", "white"],
    ["p", "fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea", "", "black"],
    ["alt", "Chess game (PGN): bob vs dave, correspondence, after move 4, 0-1"]
  ],
  "content": "[Event \"EINUNDZWANZIG Esports, chess/correspondence/season-1\"]\n[Site \"EINUNDZWANZIG Esports\"]\n[Date \"2026.09.25\"]\n[Round \"-\"]\n[White \"bob\"]\n[Black \"dave\"]\n[Result \"0-1\"]\n[Termination \"Normal\"]\n[TimeControl \"1/86400\"]\n[Variant \"Standard\"]\n\n1. f3 e5 2. g4 Qh4# 0-1",
  "sig": "ce403fc68cc32dd36c68710913b4e7f961f40f8237c75258693f63fe2deeb4b09240a594bd240e446c7d5d3abbb5edc5c61691f4736cba21ef2bd8d3e4c639e8"
}
```

#### League Attestation (`2154`), correspondence game

```json
{
  "kind": 2154,
  "id": "92e120162eb60b551fb3552f3fc52326e6848022032dc064504993828cc899ab",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790333700,
  "tags": [
    ["e", "980a0081f52d3dbaac8ee0c56a95379a0b56958d8c8093429137f8f319a3133b", "", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7"],
    ["e", "13323d43742b75714ceb0f14474ad2196a7f09b6c96ac83acae269bff8b7b15e", "", "fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea"],
    ["e", "8ea07536c2beb9646b8bf4de9267d29d7bad8b29a44368380ae810e2427eb3be", "", "fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea"],
    ["e", "4cf2e87772f84e43fb421d904e49b1b1bec43dccdf4f3819610361ffd3a011bd", "", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/correspondence/season-1", ""],
    ["p", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "", "challenger"],
    ["p", "fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea", "", "challenged"],
    ["board", "1", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea", "0-1"],
    ["resolution", "confirmed"],
    ["winner", "challenged"],
    ["elo", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "1000", "980"],
    ["elo", "fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea", "1000", "1020"],
    ["alt", "Esports league attestation: correspondence game 0-1, Elo updated"]
  ],
  "content": "",
  "sig": "0d26656c8b8d4f6f279b0114284928e57e88205e9a45f2dbbf71de64a091bc770048b0c122d9b58f550c9d3636e62c36bd5ba3c0096cf5dd6d4224cfadf7ed82"
}
```

### Trust gate and league-wide season 3

Anchors: alice and carol, who stand for paid members (in the test bed a local list plays the part of
the association's API). Trust minimum 50.

- 11:05 the trust key publishes its description (kind `0`). Every player publishes an opponent list:
  alice lists bob, erin and carol; bob lists alice, dave and frank; carol lists alice, erin and dave;
  dave, erin and frank list their counterparts. sybil1, sybil2 and sybil3 list each other (sybil1 also
  lists alice), and nobody lists them. 11:06 carol reports sybil1 for `multi-account`.
- 11:07 trust run 1: alice, carol (anchors) and bob, dave, erin (listed by an anchor) get rank 100.
  frank is listed only by bob, one layer further out: `raw = 0.5 * (0.5 / 3) / 3 = 0.028`, rank 46,
  below the minimum; he can play casual games only. The sybils are not reached and get no assertion.
- 11:08 all open ladders close; 11:10 season 3 opens for chess blitz, chess correspondence and Rocket
  League 2v2 with one parameter set and `trust` (minimum 50). Correspondence overrides `provisional`
  (3 results instead of 5). The chess ladders carry over half of the season-1 ratings; Rocket League 2v2
  starts fresh at 1000, because neither lineup had a series in season 2 (see [Open points](#open-points)).
- 11:10:30-11:12:50 the first series of season 3: Satoshi's Strikers beat Nakamoto Rockets 2-0 (1000/1000
  to 1020/980). Gatekeepers alice and carol list each other; roster players bob and erin have rank 100.
  The attestation references alice's list as it was at the accept (11:10:45).
- 11:11 alice adds frank. 11:12 trust run 2: frank is now in layer 1, rank 100; the league republishes
  only his assertion, the only rank that changed. alice's list and frank's rank-46 assertion are now
  replaced on every relay.
- 11:13-11:35 four rated blitz games, each gated at its accept: frank beats alice (1000/1010 to
  1021/989), alice beats bob, erin and alice draw, alice beats carol. Final: alice 1028, frank 1021,
  erin 1001, bob 979, carol 971.

#### Trust key description (kind `0`, NIP-85 appendix 1)

```json
{
  "kind": 0,
  "id": "3aafe21d0c6df8ead549ffc9338d8a994195e005e02239e0630b8cd24f1f3044",
  "pubkey": "21b9921fc310826ffbd9872995a4de1d9c5115042e99ede4e74786e39729ebd5",
  "created_at": 1790334300,
  "tags": [],
  "content": "{\"name\": \"TWENTY ONE Esports trust\", \"about\": \"Trust ranks of TWENTY ONE Esports, algorithm anchored-trust-v1: anchors are the paid members of EINUNDZWANZIG and the league admins; trust flows only through the players' league opponent lists (kind 30000, d esports/<league key>), alpha 0.5, at most 3 hops, rank = 100 + 25 * log2(8 * raw), clamped to 0..100; each counted report halves (at most two before an admin decides). A new algorithm version gets a new key.\", \"website\": \"https://example.org/protocol/trust\"}",
  "sig": "e64a24cbb202bfbe8b79fd224f4a9b7af90f1ba06ac5b155521c179a9044bbc0f91fd1ab4c1c61f905aeba5fc9c74613d5799f7fd7676a224b4f29c03e3bcf3d"
}
```

#### Opponent list (`30000`), alice after adding frank

```json
{
  "kind": 30000,
  "id": "2cd4a88dc0fa77aee4f2c6a5a38ace044672a3777b3700682112a14cb20da65f",
  "pubkey": "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e",
  "created_at": 1790334660,
  "tags": [
    ["d", "esports/8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753"],
    ["title", "TWENTY ONE Esports: opponents"],
    ["p", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7"],
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f"],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["p", "ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd"],
    ["alt", "Follow set: opponents for rated games in TWENTY ONE Esports"]
  ],
  "content": "",
  "sig": "831f59296e984735d994588a3555570d8ac1b35152f21b8f5b72ee3220d5bd943a46d27310611a013cbfba5b68f878458e49090526498762b9a31d78eb066ade"
}
```

#### Opponent list (`30000`), frank

```json
{
  "kind": 30000,
  "id": "f2091b20237727fe918bd5824a2a59af62d6ae017e07645517d0d3b16e470584",
  "pubkey": "ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd",
  "created_at": 1790334300,
  "tags": [
    ["d", "esports/8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753"],
    ["title", "TWENTY ONE Esports: opponents"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["p", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7"],
    ["alt", "Follow set: opponents for rated games in TWENTY ONE Esports"]
  ],
  "content": "",
  "sig": "adb85290ff505853200a353c6c6de3ed1bd7359a766a81d2af403e02e8dd211eda5a817e2f71e70b52a09a49ac85312456ff3aeb004271a07f2719eb22294008"
}
```

#### Trust assertion (`30382`), frank after alice listed him

Replaced on the relays in round 4 by trust run 3, which republished every rank with the anchor-list
reference; this version verifies locally and is kept in the league's archive.

```json
{
  "kind": 30382,
  "id": "7bd81582c65f4e81300633b3e4d100b87b4d82d64b8f9fc4e27cd33a41c220c4",
  "pubkey": "21b9921fc310826ffbd9872995a4de1d9c5115042e99ede4e74786e39729ebd5",
  "created_at": 1790334720,
  "tags": [
    ["d", "ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd"],
    ["p", "ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd"],
    ["rank", "100"],
    ["alt", "Trusted assertion: TWENTY ONE Esports trust rank"]
  ],
  "content": "",
  "sig": "b363ff21d372c4a19f2892bf8d29366a1f3bc98bfcb65a4e811aa1aa61ac31d743022259cecde4cebcdc9ae47efd157c33d0c5a43d735eb18074f45fa5fa059a"
}
```

#### Report (`1984`) with the league's label namespace

```json
{
  "kind": 1984,
  "id": "7d273685e865a5976eceb8b3814f17e518a00ac46359a41f1504f55cf3f63afc",
  "pubkey": "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356",
  "created_at": 1790334360,
  "tags": [
    ["p", "16faeec19e94a135021a478221e4640ab4510dd26a6fbfcb1f20049762f42571", "other"],
    ["L", "space.einundzwanzig.esports"],
    ["l", "multi-account", "space.einundzwanzig.esports"],
    ["alt", "Report: multi-account in TWENTY ONE Esports"]
  ],
  "content": "Three accounts of the same person asked me for casual games within a minute.",
  "sig": "c64e6c33efce91860ef21285c9af485148dde8d66b521cf4e18cb2da326baadbbcf4158b1fccd1c5b5a7d569db3aea77bdd1447afeb85abd5e3b85aac51c359e"
}
```

#### Ladder (`32152`), chess blitz season 3 after four games, with `trust`, closed at 11:42

The closing version: the standings after the four games, and `ends`. Rounds 3 and 4 printed the
version before it, which has the same tags without `ends`.

```json
{
  "kind": 32152,
  "id": "38ed368eb70a1934ecc79325c6f997f4723f48c66e821dc1fb32b5cf7da8eaa5",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790336521,
  "tags": [
    ["d", "chess/blitz/season-3"],
    ["game", "chess"],
    ["mode", "blitz"],
    ["season", "season-3"],
    ["starts", "1790334600"],
    ["rates", "player"],
    ["time_control", "300+3"],
    ["variant", "standard"],
    ["rating", "elo", "1000", "32", "400"],
    ["tier", "bronze-1", "0"],
    ["tier", "bronze-2", "900"],
    ["tier", "bronze-3", "925"],
    ["tier", "silver-1", "950"],
    ["tier", "silver-2", "975"],
    ["tier", "silver-3", "1000"],
    ["tier", "gold-1", "1025"],
    ["tier", "gold-2", "1050"],
    ["tier", "gold-3", "1075"],
    ["tier", "platinum-1", "1100"],
    ["tier", "platinum-2", "1125"],
    ["tier", "platinum-3", "1150"],
    ["tier", "diamond-1", "1175"],
    ["tier", "diamond-2", "1200"],
    ["tier", "diamond-3", "1225"],
    ["tier", "champion-1", "1250"],
    ["tier", "champion-2", "1275"],
    ["tier", "champion-3", "1300"],
    ["tier", "grand-champion-1", "1325"],
    ["tier", "grand-champion-2", "1375"],
    ["tier", "grand-champion-3", "1425"],
    ["provisional", "5", "40"],
    ["reset", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/blitz/season-1", "d42de011c1773bb7c3348db67fb75272f7b13c4fe5785e4769a16f73c1d20251", "0.5"],
    ["seed", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "1010"],
    ["seed", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "1000"],
    ["seed", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "1000"],
    ["seed", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "990"],
    ["trust", "21b9921fc310826ffbd9872995a4de1d9c5115042e99ede4e74786e39729ebd5", "50"],
    ["e", "8ef91fd31904d948e1a9755dc68d930ab7ba3a72309685b7f45f375c91d45b9c", ""],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["p", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7"],
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f"],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["p", "ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd"],
    ["standing", "1", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "1028", "2", "1", "provisional", "1"],
    ["standing", "2", "ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd", "1021", "1", "0", "provisional"],
    ["standing", "3", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "1001", "0", "0", "provisional", "1"],
    ["standing", "4", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "979", "0", "1", "provisional"],
    ["standing", "5", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "971", "0", "1", "provisional"],
    ["ends", "1790336520"],
    ["alt", "Esports ladder: chess blitz, season-3, closed"]
  ],
  "content": "",
  "sig": "9cf17a685f5b5d75cb31f2974be03f989ff36cc97cc56573005484818cdbedf89e4b8a72b26700a644ba77bf84aac066fe77182c840808c09469e3ab56fa810d"
}
```

#### Ladder (`32152`), chess correspondence season 3, with an override, closed at 11:42

The closing version (the opening version plus `ends`).

```json
{
  "kind": 32152,
  "id": "31a8bd771027f466f03705c31267709d198746ab3018036805dbfa5599b2ed41",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790336521,
  "tags": [
    ["d", "chess/correspondence/season-3"],
    ["game", "chess"],
    ["mode", "correspondence"],
    ["season", "season-3"],
    ["starts", "1790334600"],
    ["rates", "player"],
    ["time_control", "1/86400"],
    ["variant", "standard"],
    ["rating", "elo", "1000", "32", "400"],
    ["tier", "bronze-1", "0"],
    ["tier", "bronze-2", "900"],
    ["tier", "bronze-3", "925"],
    ["tier", "silver-1", "950"],
    ["tier", "silver-2", "975"],
    ["tier", "silver-3", "1000"],
    ["tier", "gold-1", "1025"],
    ["tier", "gold-2", "1050"],
    ["tier", "gold-3", "1075"],
    ["tier", "platinum-1", "1100"],
    ["tier", "platinum-2", "1125"],
    ["tier", "platinum-3", "1150"],
    ["tier", "diamond-1", "1175"],
    ["tier", "diamond-2", "1200"],
    ["tier", "diamond-3", "1225"],
    ["tier", "champion-1", "1250"],
    ["tier", "champion-2", "1275"],
    ["tier", "champion-3", "1300"],
    ["tier", "grand-champion-1", "1325"],
    ["tier", "grand-champion-2", "1375"],
    ["tier", "grand-champion-3", "1425"],
    ["provisional", "3", "40"],
    ["override", "provisional"],
    ["reset", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/correspondence/season-1", "92e120162eb60b551fb3552f3fc52326e6848022032dc064504993828cc899ab", "0.5"],
    ["seed", "fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea", "1010"],
    ["seed", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "990"],
    ["trust", "21b9921fc310826ffbd9872995a4de1d9c5115042e99ede4e74786e39729ebd5", "50"],
    ["p", "fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea"],
    ["p", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7"],
    ["ends", "1790336520"],
    ["alt", "Esports ladder: chess correspondence, season-3, closed"]
  ],
  "content": "",
  "sig": "5f75e5c9039a34506345f3274c93d0c001667ab1f0cdeaae3b739e66c86dce2942be49bac8716099a8754a961d8c8b9d5129e97da1e6ee2e29efe0df9d82e951"
}
```

#### League Attestation (`2154`), solo blitz game with trust gate

The two `gate` rows are the gatekeepers frank and alice: each row names the rank, the assertion
(`7bd81582…` for frank, `b531b8a0…` for alice) and the opponent
list that named the other player (`f2091b20…`, `2cd4a88d…`).

```json
{
  "kind": 2154,
  "id": "5f6ea0b4ef5a8cc6a11de23db1b3b3940c0a928dd23b3c37f5cdfd0d793ec8d4",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790335020,
  "tags": [
    ["e", "51847780cc5f5af243d8a76778c4ed8dec8ac8fd5a2ea11a4d082654d893e4b2", "", "ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd"],
    ["e", "13152fe8dfbb10d704ca93e58c516ba8fbfc08ced3c8c0e0c9a78b3c27097e1a", "", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["e", "1f366eea8e743118ac93ea08d1ec616b012be70ce58aea64932a5a4a3f6f1579", "", "ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd"],
    ["e", "99bd8eca1f64d63d43d743f220f0c4f71a8b88ebeebdd64ff7f54bc07b1828a7", "", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/blitz/season-3", ""],
    ["p", "ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd", "", "challenger"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "", "challenged"],
    ["board", "1", "ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "1-0"],
    ["resolution", "confirmed"],
    ["winner", "challenger"],
    ["elo", "ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd", "1000", "1021"],
    ["elo", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "1010", "989"],
    ["trust", "21b9921fc310826ffbd9872995a4de1d9c5115042e99ede4e74786e39729ebd5", "50"],
    ["gate", "ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd", "100", "7bd81582c65f4e81300633b3e4d100b87b4d82d64b8f9fc4e27cd33a41c220c4", "f2091b20237727fe918bd5824a2a59af62d6ae017e07645517d0d3b16e470584"],
    ["gate", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "100", "b531b8a0e38dcd87d631b96b04b1fae516911f4244860751041310147edbe6f5", "2cd4a88dc0fa77aee4f2c6a5a38ace044672a3777b3700682112a14cb20da65f"],
    ["alt", "Esports league attestation: chess blitz 1-0, Elo updated, trust gate passed"]
  ],
  "content": "",
  "sig": "1168b5dd8538d9b7878d851163c81474f6094148d9b6ae543e3aee120f29bd73bc94dccdfc51a5bad5f05b58be698a9de1fa112deec455e90235ab6fb81c0f25"
}
```

#### League Attestation (`2154`), Rocket League series with trust gate

Gatekeepers are the two captains alice and carol (rows with an opponent list); bob and erin are rated
roster players (rows without one). alice's row references her list as it was at 11:10:45
(`79706e73…`). She replaced it at 11:11, so this version is no longer on any relay
and comes from the league's archive.

```json
{
  "kind": 2154,
  "id": "07b34e58b92ba5ccad51ad70f3bd25a702a2fbc8aea057c4c86715c2111d3b66",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790334770,
  "tags": [
    ["e", "b693977edb312111d70a0d24c72c8d3779ae1055b35e16f5234c23bc9f67293b", "", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["e", "7d6d8200d54337a38685a28bab7529cb7f21703b169f10cba4d20fc687c367e7", "", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["e", "7a000f2499f3851904feee14a863343794dff71637034640b287657e31a38559", "", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["e", "e7445cd8963af31dee95d1681912fe259a199e4b969901453ec5f547eb508809", "", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rocket-league/2v2/season-3", ""],
    ["a", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", "", "challenger"],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", "", "challenged"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "", "challenger", "captain"],
    ["p", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "", "challenger", "player"],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "", "challenged", "captain"],
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "", "challenged", "substitute"],
    ["resolution", "confirmed"],
    ["winner", "challenger"],
    ["score", "1", "challenger", "3", "1"],
    ["score", "2", "challenger", "2", "0"],
    ["elo", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", "1000", "1020"],
    ["elo", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", "1000", "980"],
    ["trust", "21b9921fc310826ffbd9872995a4de1d9c5115042e99ede4e74786e39729ebd5", "50"],
    ["gate", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "100", "b531b8a0e38dcd87d631b96b04b1fae516911f4244860751041310147edbe6f5", "79706e73e1fff0bf6c830e745f7c0529ea014b30cab5263254a68c10a41500ca"],
    ["gate", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "100", "89850f148f5e4f5379bb1d0887a5ea53ab68fbde9f7ccb8cf23d50fbc46c1ede", "f165132bdca088790250a86806a4df61b7e16b3cc5049eb0315dc2043f4ef11e"],
    ["gate", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "100", "e2ef388c573ef4eda0ad654d2bd7f6e857c55d70e68315fea2c7121cede0f0e3", ""],
    ["gate", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "100", "4ed5373f07cbcb77ea55940c5c6d1a45e8861b038a9ec0df70cfd3b8486d16e6", ""],
    ["alt", "Esports league attestation: challenger won 2-0, Elo updated, trust gate passed"]
  ],
  "content": "",
  "sig": "b55b025a7b59b3e10872dc9dc1d71a548b2a1425544ba7867a74f8d870b10e23a433c509273de2db960ce5d59026b761375a8cbb3b4426fdb9c983177c44aef5"
}
```

### Revision 4: season 4, a queue game, a tournament with a mix team, chat and the prize pool

- 11:40 alice, erin, grace and frank publish DM relay lists (`10050`) with the league relay. The trust
  key publishes the anchor list (alice and carol) and, in trust run 3, republishes every rank with an
  `e` to that list; all six ranks stay 100, and the ranks recomputed from relay data with the published
  list are equal.
- 11:42 all season-3 ladders close; 11:43 season 4 opens for chess blitz, chess correspondence and
  Rocket League 2v2 with `hashrate` `3 2 1 5` (win, draw, loss, team win bonus). Carry-over 0.5:
  alice 1028 to 1014, frank 1021 to 1011, erin 1001 to 1001, bob 979 to 989, carol 971 to 985 (halves
  away from zero); Satoshi's Strikers 1020 to 1010, Nakamoto Rockets 980 to 990. Correspondence had no
  rated result in season 3 and starts without `reset`.
- 11:44 the league publishes its tournament calendar and the TWENTY ONE Rocket League 2v2 Cup #1
  (`31923`, 12:20 to 12:45) and draws the solo pool frank, grace, heidi and ivan into teams of two
  against block 968545. The chain tip was 968543 (mined 11:26:12); 968545 was mined at 12:15:36. Draw
  order: grace, heidi, frank, ivan, so Laser Eyes Lions are grace and heidi, and
  Stack Sats Squad are frank and ivan; with block 968544 the teams would have been different.
- 11:45 the rated blitz queue pairs alice and erin as match #421. alice's app signs the challenge
  (`pairing` `queue`), erin's app the answer, two seconds later. alice and erin chat (NIP-17); alice
  wins in four moves, erin confirms, the league attests: alice 1014 to 1033,
  erin 1001 to 982, both provisional (k 40). Clan hashrate: Satoshi's
  Strikers 3, Nakamoto Rockets 1.
- 12:16 seeding: Satoshi's Strikers (1010) and Nakamoto Rockets (990), then the mix teams in draw
  order. Semi-final 2, match #422: carol challenges Laser Eyes Lions (grace and heidi); the notification key tells
  grace. Laser Eyes Lions win 2-1; the attestation carries no `elo`, and nobody earns Block
  Height or hashrate for it.
- 12:21 to 12:46 zaps to the Cup: a fan 21 000 sats, the admin's top-up 100 000, the sponsor desk for
  Satoshi's Pizza 50 000; a fan's zap at 12:46 comes after `end`. The pool is 171 000 sats. Three
  forged receipts are on the ndak relays too; the count rejects them, the league relay refused the one
  not signed by the LNURL server key.

Not printed: the three closing season-3 ladders, the correspondence ladder, the other five assertions,
the season-4 genesis versions (replaced by the versions printed), the queue game's answer, record and
response, the tournament's answer and response, the second chat message and all gift wraps except one.
They are in the relay proof, round 4.

#### League profile (kind `0`)

```json
{
  "kind": 0,
  "id": "7eba1e251c66ce3b8c5c574a6b417d5f4644e351eb323337a79b3963f130876c",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790338320,
  "tags": [
  ],
  "content": "{\"name\": \"TWENTY ONE Esports\", \"about\": \"League key of TWENTY ONE Esports. Signs ladders (32152), attestations (2154), tournament draws (2155) and the tournament calendar (31923, 31924). Protocol: https://example.org/protocol\", \"website\": \"https://example.org\", \"lud16\": \"pool@example.org\"}",
  "sig": "c06aba194bf541d8755c4a172d25119ee1b4c229ce27294089ffb7bf70bbe40a4b81940682d2782f7909c7bf5fcb73c1c6ce3670f782936054a53d98ac694880"
}
```

#### League relay list (`10002`)

```json
{
  "kind": 10002,
  "id": "87812225080c20d06746b7e69aa29cf8b92c3c42b0d78957561003175988b146",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790338320,
  "tags": [
    ["r", "wss://relay.example.org"]
  ],
  "content": "",
  "sig": "db087d5fa604ad2cbacf27182950074ffb554c9a3f2d4f878aab94d0ae0064c5cc6d28e3ba6dd438e7eb78f74471fa0d2f551f2b2562f38373980e9b5ad7f06e"
}
```

#### Anchor list (`30000`, trust key)

```json
{
  "kind": 30000,
  "id": "564f2c3bc0251a498812ce46b158c5740b6be6fd42ead0cb6c41c3360dedaecd",
  "pubkey": "21b9921fc310826ffbd9872995a4de1d9c5115042e99ede4e74786e39729ebd5",
  "created_at": 1790336430,
  "tags": [
    ["d", "esports/8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753/anchors"],
    ["title", "TWENTY ONE Esports: trust anchors"],
    ["description", "Anchors of anchored-trust-v1: the paid members of EINUNDZWANZIG and the league admins."],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["alt", "Follow set: trust anchors of TWENTY ONE Esports"]
  ],
  "content": "",
  "sig": "1ea71fc7024879486dc4d0c7133b859a2321796baf62cf6c63b26b52b8693c6fb62b425f08ac24b452d433f19513b19a3926666ff8ee8d1ad71bacb7ebd52b80"
}
```

#### Trust assertion (`30382`), erin, trust run 3

The `e` names the anchor list the rank was computed from. Replaced on the relays in round 5 by trust
run 4, which adds `anchor` (printed with the round-5 examples); this version verifies locally.

```json
{
  "kind": 30382,
  "id": "3d58d6bdfdcfea05dfcb88249a32990fc2a3d287ac6eaa17bedb3bf86ea1ce40",
  "pubkey": "21b9921fc310826ffbd9872995a4de1d9c5115042e99ede4e74786e39729ebd5",
  "created_at": 1790336460,
  "tags": [
    ["d", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f"],
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f"],
    ["rank", "100"],
    ["e", "564f2c3bc0251a498812ce46b158c5740b6be6fd42ead0cb6c41c3360dedaecd", "wss://relay.example.org"],
    ["alt", "Trusted assertion: TWENTY ONE Esports trust rank"]
  ],
  "content": "",
  "sig": "add70f5f917ba51e7cfc287c8c5d6ae671e5f0b195580d98211f525fcb3ead1ad9b3a271559662173aa27426672a7b760d1133c3412fca267df57be90155325c"
}
```

#### Ladder (`32152`), chess blitz season 4 after match #421, with `hashrate`, closed at 13:05

The closing version (round 5): the standings after match #421 and `ends`. Round 4 printed the version
before it, which has the same tags without `ends`.

```json
{
  "kind": 32152,
  "id": "dc8e5a38a56b64efd6a0d47bdf1ee3daef430267a59619522c8447cb6c29a23b",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790341500,
  "tags": [
    ["d", "chess/blitz/season-4"],
    ["game", "chess"],
    ["mode", "blitz"],
    ["season", "season-4"],
    ["starts", "1790336580"],
    ["rates", "player"],
    ["time_control", "300+3"],
    ["variant", "standard"],
    ["rating", "elo", "1000", "32", "400"],
    ["tier", "bronze-1", "0"],
    ["tier", "bronze-2", "900"],
    ["tier", "bronze-3", "925"],
    ["tier", "silver-1", "950"],
    ["tier", "silver-2", "975"],
    ["tier", "silver-3", "1000"],
    ["tier", "gold-1", "1025"],
    ["tier", "gold-2", "1050"],
    ["tier", "gold-3", "1075"],
    ["tier", "platinum-1", "1100"],
    ["tier", "platinum-2", "1125"],
    ["tier", "platinum-3", "1150"],
    ["tier", "diamond-1", "1175"],
    ["tier", "diamond-2", "1200"],
    ["tier", "diamond-3", "1225"],
    ["tier", "champion-1", "1250"],
    ["tier", "champion-2", "1275"],
    ["tier", "champion-3", "1300"],
    ["tier", "grand-champion-1", "1325"],
    ["tier", "grand-champion-2", "1375"],
    ["tier", "grand-champion-3", "1425"],
    ["provisional", "5", "40"],
    ["trust", "21b9921fc310826ffbd9872995a4de1d9c5115042e99ede4e74786e39729ebd5", "50"],
    ["hashrate", "3", "2", "1", "5"],
    ["reset", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/blitz/season-3", "8ef91fd31904d948e1a9755dc68d930ab7ba3a72309685b7f45f375c91d45b9c", "0.5"],
    ["seed", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "1014"],
    ["seed", "ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd", "1011"],
    ["seed", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "1001"],
    ["seed", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "989"],
    ["seed", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "985"],
    ["e", "35a4e64d31d72f88c9130974dc5556b6a31e0fae3f9712d62da1863e532fb905", "wss://relay.example.org"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["p", "ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd"],
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f"],
    ["p", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7"],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["standing", "1", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "1033", "1", "0", "provisional"],
    ["standing", "2", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "982", "0", "1", "provisional"],
    ["ends", "1790341500"],
    ["alt", "Esports ladder: chess blitz, season-4, closed"]
  ],
  "content": "",
  "sig": "17ec5dd0243974b6374dbd8b97183b41e03d3170029f93221bd0eb5d8aff97b2b8bad84ac1ba6ac0a838aced5e987e58b917d3af04e5eb3f96fd0d0ea481999d"
}
```

#### Challenge (`2150`), queue pairing, match #421

Signed by alice's app right after the pairing; `start` is five seconds ahead, `respond_by` 30 seconds.

```json
{
  "kind": 2150,
  "id": "c3f31023bb2752f39131d16f1b82881bae678ba34914a6f366567828a7ee60d2",
  "pubkey": "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e",
  "created_at": 1790336700,
  "tags": [
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "", "challenger"],
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "", "challenged"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/blitz/season-4", "wss://relay.example.org"],
    ["color", "white"],
    ["start", "1790336705"],
    ["respond_by", "1790336730"],
    ["match", "421"],
    ["pairing", "queue"],
    ["alt", "Esports challenge: chess blitz 5+3, queue pairing, match #421"]
  ],
  "content": "",
  "sig": "5d869c6059734e13d12959535f8c25483b3cded07b9281b3350a9eeaef89c5fab942f4e6c4f297b779061e5115f526f5ae5a0392fb7921cbb71d4656ae9d4e5b"
}
```

#### League Attestation (`2154`), match #421, with `match` and `clan`

Four `e` references (challenge, answer, final record, response); the `gate` rows carry no opponent list, because the queue paired the players.

```json
{
  "kind": 2154,
  "id": "35a4e64d31d72f88c9130974dc5556b6a31e0fae3f9712d62da1863e532fb905",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790337160,
  "tags": [
    ["e", "c3f31023bb2752f39131d16f1b82881bae678ba34914a6f366567828a7ee60d2", "wss://relay.example.org", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["e", "32417456caf4a8d19fe3a5121364f7df8d1b7d81b48d3d53fb0c95872a4cfd47", "wss://relay.example.org", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f"],
    ["e", "624511fee687cb28edebb543cbd6357393a61e0ba824beb812737e749c9bea30", "wss://relay.example.org", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["e", "6c3e3934fc308178dc594b0eaeb81d80bdf917fb017c2f79ee0b407aff88d46d", "wss://relay.example.org", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/blitz/season-4", "wss://relay.example.org"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "", "challenger"],
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "", "challenged"],
    ["board", "1", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "1-0"],
    ["resolution", "confirmed"],
    ["winner", "challenger"],
    ["elo", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "1014", "1033"],
    ["elo", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "1001", "982"],
    ["match", "421"],
    ["clan", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "32150:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers"],
    ["clan", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "32150:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets"],
    ["trust", "21b9921fc310826ffbd9872995a4de1d9c5115042e99ede4e74786e39729ebd5", "50"],
    ["gate", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "100", "aef61dde38f4b3c95718cb7edbc75fb7cdb9397b21f197c65bc7d7e80c8541b0", ""],
    ["gate", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "100", "3d58d6bdfdcfea05dfcb88249a32990fc2a3d287ac6eaa17bedb3bf86ea1ce40", ""],
    ["alt", "Esports league attestation: match #421, alice beat erin, Elo updated, trust gate passed (queue pairing)"]
  ],
  "content": "",
  "sig": "7fc0df05a6c16ac74e45b86504347f306900f01b0d50d4a76bbca5100580d42d7fe88a5f55a2ba63dc959628cccaad737e6c85df6c61c5709f04bc130dfa01d3"
}
```

#### Tournament calendar (`31924`)

```json
{
  "kind": 31924,
  "id": "e49c0839bc9cfa1a37aee8e79f7b0b0dfca72ddf7d1392eb0b57e90bd6832aed",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790336640,
  "tags": [
    ["d", "tournaments"],
    ["title", "TWENTY ONE Esports tournaments"],
    ["a", "31923:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rl-2v2-cup-1", "wss://relay.example.org"],
    ["alt", "Calendar: TWENTY ONE Esports tournaments"]
  ],
  "content": "",
  "sig": "d5581ce58307a3c8e139775519d7cc6be024bf4482051424362a8afe6fbb07d76cb364a8e86371b6ddebadc9f0e6dac5c7b4d6d782f772791ed539e7b7930183"
}
```

#### Tournament (`31923`)

The `zap` tag routes zaps to the pool key.

```json
{
  "kind": 31923,
  "id": "be86b01c8fa111e822d8186b081af9562fbbb3e3c1a5dd0401827b07dd81f283",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790336640,
  "tags": [
    ["d", "rl-2v2-cup-1"],
    ["title", "TWENTY ONE Rocket League 2v2 Cup #1"],
    ["summary", "Single elimination, best of 3, clan lineups and mix teams from the solo pool. Unrated when a mix team plays."],
    ["image", "https://example.org/tournaments/rl-2v2-cup-1.png"],
    ["start", "1790338800"],
    ["end", "1790340300"],
    ["D", "20721"],
    ["start_tzid", "Europe/Berlin"],
    ["location", "https://example.org/tournaments/rl-2v2-cup-1"],
    ["r", "https://example.org/tournaments/rl-2v2-cup-1/rules"],
    ["t", "esports"],
    ["t", "rocketleague"],
    ["a", "31924:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:tournaments", "wss://relay.example.org"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rocket-league/2v2/season-4", "wss://relay.example.org"],
    ["zap", "3fe2a96fc8d3efe65eb05be97ab7b483b73c56118acbae12e75ac950e1d7e504", "wss://relay.example.org", "1"],
    ["alt", "Tournament: TWENTY ONE Rocket League 2v2 Cup #1, 2026-09-25 12:20 UTC"]
  ],
  "content": "Rocket League 2v2, single elimination, best of 3. Clan lineups are seeded by their rating at registration close; the mix teams of the solo draw follow in draw order. Prize pool: every zap to this event, split 50/30/20 among the players who played. Rules: https://example.org/tournaments/rl-2v2-cup-1/rules",
  "sig": "fdc2458f24bbd6057ce19f54c7f1cd558f46a452a777ef63cc64782c8d63a934a843d5409ec427feeb0fa45906d42a40d754a1c2f321f88301abf0a77079e814"
}
```

#### Tournament Draw (`2155`), solo pool into mix teams

```json
{
  "kind": 2155,
  "id": "82aab215e5e7cb2e6426c62d5abbfe155da4df3a63f4eacf7f361a2eadf61350",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790336670,
  "tags": [
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rocket-league/2v2/season-4", "wss://relay.example.org"],
    ["a", "31923:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rl-2v2-cup-1", "wss://relay.example.org"],
    ["p", "ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd", "", "entrant"],
    ["p", "5ccb330beff1652ff8b69f9478e9c97468c0a194e3bb3efd8f7d97cb1c139a6b", "", "entrant"],
    ["p", "e0102842c1cffa01a03dba34c07fe88a0907bdb3fc867106629ab8299243d0c1", "", "entrant"],
    ["p", "9f90338586f9de60a4fa24756c840a02d8cfb7748f02ad32cc80fac75a09fa6e", "", "entrant"],
    ["draw", "968545", "sha256-v1"],
    ["teams", "2"],
    ["teamname", "Laser Eyes Lions"],
    ["teamname", "Stack Sats Squad"],
    ["teamname", "HODL Hurricanes"],
    ["format", "single-elimination"],
    ["name", "TWENTY ONE Rocket League 2v2 Cup #1"],
    ["alt", "Tournament draw: solo pool of the TWENTY ONE Rocket League 2v2 Cup #1, block 968545"]
  ],
  "content": "Solo pool of the Cup: four players, teams of two, drawn from the hash of block 968545.",
  "sig": "9117d00ca108d035464483df0a6ea86ee40c74bf759b394a26ce476ae93620f4302ae56bbceaa7e8131889ee1ba5fd2d45a3a38d839006c4c609a0b2d759c407"
}
```

#### Challenge (`2150`), lineup against a mix team, match #422

The challenged side is a roster side: the two members of Laser Eyes Lions, exactly team 1 of the draw.

```json
{
  "kind": 2150,
  "id": "758a5f2ab0e12649b6982e31bd4a3cacad4f03c2329adbff5cd33d13d7accfc5",
  "pubkey": "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356",
  "created_at": 1790338590,
  "tags": [
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", "wss://relay.example.org", "challenger"],
    ["p", "5ccb330beff1652ff8b69f9478e9c97468c0a194e3bb3efd8f7d97cb1c139a6b", "", "challenged"],
    ["p", "e0102842c1cffa01a03dba34c07fe88a0907bdb3fc867106629ab8299243d0c1", "", "challenged"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rocket-league/2v2/season-4", "wss://relay.example.org"],
    ["a", "31923:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rl-2v2-cup-1", "wss://relay.example.org"],
    ["e", "82aab215e5e7cb2e6426c62d5abbfe155da4df3a63f4eacf7f361a2eadf61350", "wss://relay.example.org", "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753"],
    ["bo", "3"],
    ["start", "1790338800"],
    ["respond_by", "1790338770"],
    ["match", "422"],
    ["pairing", "tournament"],
    ["alt", "Esports challenge: best of 3, rocket-league 2v2, Cup #1 semi-final, Nakamoto Rockets vs Laser Eyes Lions, match #422"]
  ],
  "content": "",
  "sig": "576e5a57fa77d04c9500a95c58c92600a48d719b41871a957bc76b4045a092b27300dbf9a85fdf5a340e1d05d68129db40a96541a05ec6e5c4096ec3a2eb9cce"
}
```

#### Result Report (`2152`) by a mix-team player

```json
{
  "kind": 2152,
  "id": "60d0a606b445d6b126a9f3119f9be950b4a3a383be3d2f8e756cbd65cb747c29",
  "pubkey": "5ccb330beff1652ff8b69f9478e9c97468c0a194e3bb3efd8f7d97cb1c139a6b",
  "created_at": 1790339460,
  "tags": [
    ["e", "758a5f2ab0e12649b6982e31bd4a3cacad4f03c2329adbff5cd33d13d7accfc5", "wss://relay.example.org", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", "wss://relay.example.org"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rocket-league/2v2/season-4", "wss://relay.example.org"],
    ["a", "31923:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rl-2v2-cup-1", "wss://relay.example.org"],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "", "challenger", "captain"],
    ["p", "fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea", "", "challenger", "player"],
    ["p", "5ccb330beff1652ff8b69f9478e9c97468c0a194e3bb3efd8f7d97cb1c139a6b", "", "challenged", "player"],
    ["p", "e0102842c1cffa01a03dba34c07fe88a0907bdb3fc867106629ab8299243d0c1", "", "challenged", "player"],
    ["score", "1", "challenger", "3", "1"],
    ["score", "2", "challenged", "0", "2"],
    ["score", "3", "challenged", "3", "4", "ot"],
    ["alt", "Esports result report: Laser Eyes Lions won 2-1 in games"]
  ],
  "content": "",
  "sig": "e9ba1e6c226d9c2d6fe7991eb2cc66920ef7b458ce4045640703b9bb544206de6d0067b067f33b6568e48852d72415d057e10f6b07f409045dd479ef8f9e7c08"
}
```

#### League Attestation (`2154`), match #422, unrated

No `elo`, `trust`, `gate` or `clan`; the tournament `a` is copied from the challenge.

```json
{
  "kind": 2154,
  "id": "193066c186c41c582649adcdc0c47d0c7840565060208333774fe4b548b5a38c",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790339550,
  "tags": [
    ["e", "758a5f2ab0e12649b6982e31bd4a3cacad4f03c2329adbff5cd33d13d7accfc5", "wss://relay.example.org", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["e", "8edafb3af1e28ba2377dc4d1339a9b87dbf33ffc0ac05f2ca7af0ade9982eabd", "wss://relay.example.org", "5ccb330beff1652ff8b69f9478e9c97468c0a194e3bb3efd8f7d97cb1c139a6b"],
    ["e", "60d0a606b445d6b126a9f3119f9be950b4a3a383be3d2f8e756cbd65cb747c29", "wss://relay.example.org", "5ccb330beff1652ff8b69f9478e9c97468c0a194e3bb3efd8f7d97cb1c139a6b"],
    ["e", "b594771f804337ef8fd857c54de3e3f9fe5db57e9611c4b89d9cde5842f34506", "wss://relay.example.org", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rocket-league/2v2/season-4", "wss://relay.example.org"],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", "wss://relay.example.org", "challenger"],
    ["a", "31923:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rl-2v2-cup-1", "wss://relay.example.org"],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "", "challenger", "captain"],
    ["p", "fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea", "", "challenger", "player"],
    ["p", "5ccb330beff1652ff8b69f9478e9c97468c0a194e3bb3efd8f7d97cb1c139a6b", "", "challenged", "player"],
    ["p", "e0102842c1cffa01a03dba34c07fe88a0907bdb3fc867106629ab8299243d0c1", "", "challenged", "player"],
    ["resolution", "confirmed"],
    ["winner", "challenged"],
    ["score", "1", "challenger", "3", "1"],
    ["score", "2", "challenged", "0", "2"],
    ["score", "3", "challenged", "3", "4", "ot"],
    ["match", "422"],
    ["alt", "Esports league attestation: match #422, Laser Eyes Lions (mix team) beat Nakamoto Rockets 2-1, unrated"]
  ],
  "content": "",
  "sig": "5a3a3c98b7342e56ab46cb7180091983fe17cb5fe688a03160b004d88f76b5366fed9aaec8cdb313665ab243b947254a750bd6386c20250566fbd666b7dfb9d0"
}
```

#### DM relay list (`10050`), alice

```json
{
  "kind": 10050,
  "id": "deccc1e49c0077306a859054d638b47d69c6a9445a9020548292ba9772a676de",
  "pubkey": "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e",
  "created_at": 1790336420,
  "tags": [
    ["relay", "wss://relay.example.org"]
  ],
  "content": "",
  "sig": "af463b201411d6a02d39e77380d7935ef4a5787a54ea3c1145990dd51ce3a81570aaeab3b5f8e2f2a1c296e9e3f5619c5c6987c7750ebf0b68ec99dfbba39461"
}
```

#### Chat message, rumor (kind `14`, unsigned)

What erin's client finds inside the seal inside the gift wrap below; relays never see it.

```json
{
  "kind": 14,
  "id": "f158492ed9c0d8de7ff213db8398e7fe37881ae838fff2fdb6aca7d0df4a58e8",
  "pubkey": "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e",
  "created_at": 1790336715,
  "tags": [
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "wss://relay.example.org"],
    ["match", "421"]
  ],
  "content": "gl hf"
}
```

#### Gift wrap (`1059`) of that message to erin

Signed by a one-time key; `created_at` is random, up to two days before the message.

```json
{
  "kind": 1059,
  "id": "c62f658a04e62008a151e3dca0b5bb50429b2cb0069794bac4170565df2f9e03",
  "pubkey": "4078be089d1a19d0ce41e9baff5669dc4aa34adcd4271b446460f05255936cc9",
  "created_at": 1790245362,
  "tags": [
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f"]
  ],
  "content": "AnQrR/SLKHEK4rS3v77CsrCSjPZAygL7Cdj6pKtMZxMTpTK0M6J2jG80R8yfpyk7EO0jIBVwOofGe0HaKMiw0Y7fWDHuQdVL4EeRZBSW9OrcJUBeHFFvMhOFobzkh+ZtXL0mrIeEBSqj7pFtK7O9qW6t1+wk3A7KoT7u4smHScw+0aFLDGvOVq7Znj5/MT2ZElrVFbv922zeDb9PAIqn3xTYJ8HOLmvOf0wcELKBUrVosK+fkRTHn7ezdwD47fQzhYOyitM/9mM2/DY3kw2Cn6ZWfSUxOp7DPnv4cqZ+rdUxlq/nJIhg2nUR/DcQTwyhiIsLzIa76B4BVbO0164yAPKN33SMU7K8LA5e5WCAvu0coRZO4fvx7vX2xsUC1tjVp4OIzJNmovGEn7CGxHMmrjdsc9oHaoi3eE1WcyniDDJ8ngoO5mRrOrKKOHcQz2Z1XgWUdJ1qdtf2Hqq+MTo1thmRvheD30Q+XOxkIxiF8je99siOEJWF18G3tSQJzgzQwJWLPq+ZVyDqBdfsR9lVxwuv9rI1QVgqHsOLdP+skbBcGfOT7eLjYnpvMxvUH22ylM7PC96GWAgDeI0EbCptF1G3apC1M4ZAUWzaaUfmugnu9DmCxc4Ma0TB2LCrlQXzZkozc8QymCf81poZzwotMN9qkoq4INhdK+6I4pIFV/LF88KuU2ywJbnF6VTrl8SyiwBwh4ZGBiXINQFgtsWJDSdBssQBji4cBL5mQSTaho4Y04kmI1MZfH/L5flX/ISrNpYLoHMphDms8le7dJpSijEDynQEmsPIY9oP6GcNK1VKQdrBM2x1lsQp4nVCJ7LsCOsPnZMcZMk9hb5wHvTLH/9icLwp0NZ05AKI6WkQLX8BqpGEXfx+ruPCE8v32AxXTUG3Gpwhj5OkQ8NNTnscv5pUmw+p6ARChCHqIXhXIFakNMLC/SD+D6g0YHHgEoeXHblMn1tuEnZ04rI1HCBquOsJ7FecRhDtAzIRMIlFYj10ESsjvLZJXL5jxKbU6w7G9+aLKfhMZv6iN8y3rQahOQzSFsQTuR4fX7XHTAZNIeuJMObe33u/ccHS3l08USljkgSijSZnSUWaRhEho5YyA6Q2X8SDNdlPiD9oKFjeU3IWrRPjYPt4sZ6v2f1OyGU2lwjjv1kawT49Y4YcvS8fK0e22+gr9KLpRwcQ5LmBEtBf0qZunCnKF26Os5x8oHChKkgcOcYaMD5mllZAXhlf56ZdrtmJVAPYRbtxe1RmuQneVq20LhEiB+RODJ563yEWI68rpCTV0AYEkcD6tfBSjvps7Ic8C6qe4Uh+r6fYRVeUca8sZ3Ao2gq9h5yItLwwYPmRUW40UgTKKJV8aLVF/Qejsv3ys5uFr43zbjS0nCsT1omR8D+cbbwclFAPn0gfC5jqE5zrq4vgfIuMFRxviTHlnTyI6OmtLswVoULTctSOQ7k=",
  "sig": "f3e46eddd7eb4945399bbafd0c467ce382854346f5cb8cd421ea00499ec4a906ead5fa3a12d95ae524370b84b12e557b2a998596497967fcc73fd9d3f1fc00a6"
}
```

#### Notification key profile (kind `0`)

No `10050` exists for this key, so NIP-17 clients do not offer to reply.

```json
{
  "kind": 0,
  "id": "fbb969146d9ea0c2e90dfee714f8159452716a2924e129474966300a47806a98",
  "pubkey": "eff5cbea34f91bc591542cd8e3065eedc7c12cd931d947b66aab93913d35d12f",
  "created_at": 1790338330,
  "tags": [
  ],
  "content": "{\"name\": \"TWENTY ONE Esports\", \"about\": \"Notifications from TWENTY ONE Esports. This account does not read replies. Turn notifications on or off at https://example.org/settings/notifications\", \"website\": \"https://example.org\", \"bot\": true}",
  "sig": "a7af0a834df5ef0e6bbf0df6d7c119641eb9324ecffbc28be501c5c765b94aa4e707d9c50ea9734058d50a70111a95ca4619830b9bcea591a488b42516594b45"
}
```

#### Notification, rumor (kind `14`, unsigned)

```json
{
  "kind": 14,
  "id": "764d0bae9c9b1922c599c7fdb656c8a7f25490d7a49cf10f8dfb04f650830761",
  "pubkey": "eff5cbea34f91bc591542cd8e3065eedc7c12cd931d947b66aab93913d35d12f",
  "created_at": 1790338600,
  "tags": [
    ["p", "5ccb330beff1652ff8b69f9478e9c97468c0a194e3bb3efd8f7d97cb1c139a6b", "wss://relay.example.org"],
    ["match", "422"]
  ],
  "content": "Your semi-final with Laser Eyes Lions against Nakamoto Rockets starts at 12:20 UTC (match #422): https://example.org/m/422"
}
```

#### Pool key profile (kind `0`)

```json
{
  "kind": 0,
  "id": "34a1ad28a706a4d3d177154dd053cb05b971cb438a5c8b141ac474b0604ee874",
  "pubkey": "3fe2a96fc8d3efe65eb05be97ab7b483b73c56118acbae12e75ac950e1d7e504",
  "created_at": 1790338325,
  "tags": [
  ],
  "content": "{\"name\": \"TWENTY ONE Esports prize pool\", \"about\": \"Receives the zaps to TWENTY ONE Esports tournaments; each tournament's pool is the sum of the zap receipts that reference it.\", \"lud16\": \"pool@example.org\", \"website\": \"https://example.org/tournaments\"}",
  "sig": "307e1bbf179e952f0621f4470a52b7f1ca3370d0befd8dcdc93ca58fe9f02d8730f200a039ccac876702ecf2f63d0692a6f918f49bc8da160c2ac3e1364852ea"
}
```

#### Zap receipt (`9735`), sponsor

Signed by the league's LNURL server key. `description` is the sponsor desk's zap request with the comment that names the sponsor; the invoice is a regtest fixture (`lnbcrt`) signed by a throwaway node key, its description hash is SHA-256 of `description`.

```json
{
  "kind": 9735,
  "id": "0e8ac13d99613989e275a4ddc2f6ca2aa29d397879ca0707cf51b14307f8545e",
  "pubkey": "8f12a686b755d0ef25fc3113b1917b37831e5ec7557267ca16bddc8898594512",
  "created_at": 1790339105,
  "tags": [
    ["p", "3fe2a96fc8d3efe65eb05be97ab7b483b73c56118acbae12e75ac950e1d7e504"],
    ["P", "f244d344d7b21530617c112228460ace791b96b317d00077a9efb0003acae3e2"],
    ["a", "31923:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rl-2v2-cup-1"],
    ["k", "31923"],
    ["bolt11", "lnbcrt500000n1p4tvelqpp5v6tvuj7rchcs288x4w8gghejnum60rglhc58j58t7p8c6zaqnyussp53xwhe8pvskvtnyt5zq9996e6yytxdymgvaawjevacvzyxz84xgsqhp5dfzmn9epnwltkxwhk4ud52pkmt27y3nk9ggxq2a56skqgx43vthsxqrrssnvhf5k4pl9mmjtpe4m76xe5xxqesujpnqsh6c3u5whl6pp5kqvmzpd8ua2k82t78dvkghmv5hmwxp96a87gjy8wc0rr66eua55xs03sq344kz0"],
    ["description", "{\"kind\":9734,\"id\":\"acbbbed5cad03826aec637f1f75ae2f2afc518c54671578320fac004583927b4\",\"pubkey\":\"f244d344d7b21530617c112228460ace791b96b317d00077a9efb0003acae3e2\",\"created_at\":1790339040,\"tags\":[[\"relays\",\"wss://relay.example.org\"],[\"amount\",\"50000000\"],[\"lnurl\",\"LNURL1DP68GURN8GHJ7ETCV9KHQMR99EHHYEE09EMK2MRV944KUMMHDCHKCMN4WFK8QTMSDAHKC8FH5GV\"],[\"p\",\"3fe2a96fc8d3efe65eb05be97ab7b483b73c56118acbae12e75ac950e1d7e504\"],[\"a\",\"31923:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rl-2v2-cup-1\"],[\"k\",\"31923\"]],\"content\":\"Sponsor: Satoshi's Pizza\",\"sig\":\"857967f86c87e5e8968865a5b4484084b994459abf272e3c4397b7f5d499ea40236fb342cf72d95bb444de8de8a2a0333021693069b69317a98de5834db7ae44\"}"],
    ["preimage", "ff24fd7226eeaa3202490becb9d326319c98b3ea4b4889dd4c67f8811d3d37db"]
  ],
  "content": "",
  "sig": "a6a26fe58aea3a165199898a36df4e62874dec681d10e43e22251f8e0c16b3fdcdf600bed06452b21b4b4b596f3937f8ca6292986fa4ae9e76dd74701c35ee3e"
}
```


### Revision 5: the Pre-Season, the season chain, rank badges and a bounty

The test bed's **Pre-Season** is its chain season under the final rules of revision 5, **compressed
for the examples**: eras of 15 minutes and forty minutes in all (production: eras of weeks, a season of
months). In production it is the league's first chain season. Supply 1 000 000 sats, subsidy 2 100,
weights per winning player chess blitz 1, correspondence 2, Rocket League 2v2 2.5, share cap 50 % per
game and era, 10 blocks per player, game and day, and the looser Pre-Season defaults `pairlimit` 3 per
day and 5 per season, `subtree` 90.

It follows the test bed's **season 5** (13:15 to 15:15), which the first draft of revision 5 released
with two admin labels and ran with the fixed rules 4, 7 and 8 of that draft; its events are on the
relays and in the relay proof (round 5, R5.1 to R5.10) but no longer printed here, except for the rank
badge, which comes from it. Season 5 returned 2 094 226 sats of its supply and 1 000 sats of fees to the
reserve.

Round 5 keys: second admin `0ba8b0422694f439dab12f7ff561920f6c7b7b220657a2e32824f31c4b114b5f`, badge key `e643b4d007da1346d9601214b3e89ae88f2db80fc3fed8a617ca2ad6293ef120`, and a Lightning node that
stands for the players' wallet provider, node id `028a94478f6c86781277c0fcb6da9a5786b8a8f2cc7bed026f6b16f2dd802ae43e` (it signs the payout invoices).

- 13:03 the league opened its **reserve** (a NIP-75 zap goal); 13:07 the admin topped it up with
  2 100 000 sats. 13:10 trust run 4 added the anchor subtree to every assertion: alice, bob and frank are
  in alice's subtree (100 %), carol and dave in carol's (100 %), erin in carol's with 57 % (alice 43 %).
- 15:35 the league publishes its **admin list** (the board: admin and admin2); 15:36 the Pre-Season
  announcement (`31923`, Block 0 planned for 15:45).
- 15:44 the admin retypes the supply and releases Block 0 with one label; the parameter digest is
  `82f1fa0c62e7aa3efd843b7b5251a697a7d05abeb48a6b9460a80df9324d0ca1`. 15:45 the league signs the genesis. The reserve holds 2 110 226 sats at that
  moment (the ledger over season 5 in the relay proof).
- 15:45:30 the Pre-Season ladders open with `starts` = Block 0, carry-over factor 0.5 from season 5 and
  an `e` to the genesis; 15:45:40 a bounty on alice, which fan funds with 21 000 sats.
- 15:57 admin2 changes two parameters, **effective 15:58**: one block per pairing and day, three per
  season (`pairlimit` 1 3), and rule 7 back to 51 %. The change names block 2 as the tip.

| match | attested | game | result | chain |
|---|---|---|---|---|
| #431 | 15:50:30 | chess blitz | alice beats erin | block 1, era 1: 2 100 |
| #432 | 15:55:30 | chess blitz | alice beats erin again | block 2, era 1: 2 100 (voided in the review) |
| #433 | 16:02:30 | chess blitz | carol beats alice | block 3, era 2: 1 050 |
| #434 | 16:07:30 | chess blitz | alice beats erin a third time | no block: rule 4, the change at 15:58 allows one block per pairing and day, and alice and erin have two |
| #435 | 16:17:30 | Rocket League 2v2 | Satoshi's Strikers beat Nakamoto Rockets 2-1 | block 4, era 3: 1 312 each for alice and bob |

- Match #434 would have mined before the change (three blocks per pairing and day); it is attested
  after 15:58 and judged by the new limit. Blocks 1 and 2 keep the parameters they were attested under.
- Fees: fan zaps 2 100 sats on the series #435 (block 4: 1 050 each for alice and bob).
- The bounty goes to carol: #433 is the first result alice loses that meets `bounty-v1`.
- 16:25 the Pre-Season ends; 16:25:30 the ladders close. 16:28 the review voids block 2 (#432). 16:30 the
  settlement: alice 4,462 sats (blocks 1 and 4: 2 100 + 1 312, fees 1 050), bob 2,362 (block
  4: 1 312, fees 1 050), carol 22,050 (block 3: 1 050, bounty 21 000). Back to the reserve: the
  unmined 992 126 and the voided 2 100.

All of it recomputes from relay data alone, on each of the three relays (relay proof, round 5, R5.12).

#### League reserve: zap goal (`9041`)

```json
{
  "kind": 9041,
  "id": "760523a302a189819997f038aedf3eba738a36eff5486dda6427c98bb64f3564",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790341380,
  "tags": [
    ["relays", "wss://relay.example.org"],
    ["amount", "21000000000"],
    ["summary", "The league reserve of TWENTY ONE Esports: season supplies are drawn from it, and unmined sats, voided rewards, fees without a block and unclaimed payouts return to it."],
    ["image", "https://example.org/reserve.png"],
    ["r", "https://example.org/mining"],
    ["zap", "3fe2a96fc8d3efe65eb05be97ab7b483b73c56118acbae12e75ac950e1d7e504", "wss://relay.example.org", "1"],
    ["alt", "Zap goal: TWENTY ONE Esports league reserve"]
  ],
  "content": "TWENTY ONE Esports league reserve",
  "sig": "2ecd85d180f1d7ec69d798d8d65a70e58ba683390fcbf2d1f9cb5f8aaf7352a73bf4b64b19fd5a61e1e8b53e75ae677db44a5dbe46e4a35b9c636de3243c2623"
}
```

#### Zap receipt (`9735`) to the reserve, the admin's top-up

```json
{
  "kind": 9735,
  "id": "6892e1d87cdc23583f8caaa8e9c7f01a4917f8c87ef2a097104c860b97e6fbaa",
  "pubkey": "8f12a686b755d0ef25fc3113b1917b37831e5ec7557267ca16bddc8898594512",
  "created_at": 1790341650,
  "tags": [
    ["p", "3fe2a96fc8d3efe65eb05be97ab7b483b73c56118acbae12e75ac950e1d7e504"],
    ["P", "2a0335d330a81f7c1d701d8c750593d03a0ab3d2c19bbcebd66faa21cb6c078c"],
    ["e", "760523a302a189819997f038aedf3eba738a36eff5486dda6427c98bb64f3564", "wss://relay.example.org"],
    ["k", "9041"],
    ["bolt11", "lnbcrt21000000n1p4tvu05pp5cyakkxa6g95hqmeune6gpaw5r8xek3ghr70q27sqcc0jgketfsgssp5h54m7e2rh9f48cth2seqaz47styqqymluc95eq9ndvmtzlwmtzwqhp55t8j4x6jlehmun2eekrgemmc7pmr7av3tvsw7h2flfqdwukx3zssxqrrssxtd2lj94r6krvlg9hl7r7nfh5f89ep3ph7ph63w6n6h4s7ujrxcq2l9mnevfl236k37wu3e0r3j2cmm8n9r6nahdlveelrm6dhy5v0qqm7eznp"],
    ["description", "{\"kind\":9734,\"id\":\"f5e66f5ffc0f16e169c147dc83f3fc628df4d359fd5819b67c336be6b4977032\",\"pubkey\":\"2a0335d330a81f7c1d701d8c750593d03a0ab3d2c19bbcebd66faa21cb6c078c\",\"created_at\":1790341620,\"tags\":[[\"relays\",\"wss://relay.example.org\"],[\"amount\",\"2100000000\"],[\"lnurl\",\"LNURL1DP68GURN8GHJ7ETCV9KHQMR99EHHYEE09EMK2MRV944KUMMHDCHKCMN4WFK8QTMSDAHKC8FH5GV\"],[\"p\",\"3fe2a96fc8d3efe65eb05be97ab7b483b73c56118acbae12e75ac950e1d7e504\"],[\"e\",\"760523a302a189819997f038aedf3eba738a36eff5486dda6427c98bb64f3564\",\"wss://relay.example.org\"],[\"k\",\"9041\"]],\"content\":\"League top-up\",\"sig\":\"acf2f865c6f7eec176126d8a8bcb784f659cbff568e2cc8f8cbc920501406fba80bfa9876058fd6d13aac2f033a51ba93886fdc6ccb9ca6e6c6963bcb1afb199\"}"],
    ["preimage", "8acb401776dcb6121e3d83e5424c708466e7e2f7ea8dcc8925884054a9bf5641"]
  ],
  "content": "",
  "sig": "c77b32d5c1a5cf3cce674e73e49eec46f3fcb1f2a55dd2b7edd72efaedf24c527fef300dfd77b8058be1e154c5c587be63698eeaa3a50d28c22b0beb76f27075"
}
```

#### Trust assertion (`30382`), erin, trust run 4, with `anchor`

```json
{
  "kind": 30382,
  "id": "92547c7cf81b18d73454dd89431119c7d47b99a13db6735ffaec2cc3eaeb4d74",
  "pubkey": "21b9921fc310826ffbd9872995a4de1d9c5115042e99ede4e74786e39729ebd5",
  "created_at": 1790341800,
  "tags": [
    ["d", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f"],
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f"],
    ["rank", "100"],
    ["anchor", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "57"],
    ["e", "564f2c3bc0251a498812ce46b158c5740b6be6fd42ead0cb6c41c3360dedaecd", "wss://relay.example.org"],
    ["alt", "Trusted assertion: TWENTY ONE Esports trust rank"]
  ],
  "content": "",
  "sig": "233e6bdd9ad3d0bbd45f4347af844e02a5214e08b4dec0f0d9988d83b86eee87fbafb9616c65b36ec062cb0d73ba60c9f014b3a3a69478c36d199b29d14e48bf"
}
```

#### Admin list (`30000`, league key)

```json
{
  "kind": 30000,
  "id": "d957dc7bb9ff198912695ccc1cfd314e6d86fc20349189d25f3817b7b69b56e6",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790350500,
  "tags": [
    ["d", "esports/8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753/admins"],
    ["title", "TWENTY ONE Esports: admins"],
    ["description", "The board of the association. Each may release Block 0 and change season parameters."],
    ["p", "2a0335d330a81f7c1d701d8c750593d03a0ab3d2c19bbcebd66faa21cb6c078c"],
    ["p", "0ba8b0422694f439dab12f7ff561920f6c7b7b220657a2e32824f31c4b114b5f"],
    ["alt", "Follow set: admins of TWENTY ONE Esports"]
  ],
  "content": "",
  "sig": "5539e945646f80eb77f39f1096ad374b62ad6f289c097174dbe01e59cdd7fefd605bd4a80719ee506c9da19fb24c61c6fc00596b12b88d99eb9b8fe72b140d0f"
}
```

#### Season announcement (`31923`), Pre-Season

```json
{
  "kind": 31923,
  "id": "2944549024be335e245c6df2ed37bb87a433f9f8f2ee9fd5cc502cb5a314f45c",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790350560,
  "tags": [
    ["d", "season/pre-season"],
    ["title", "TWENTY ONE Esports Pre-Season: Block 0"],
    ["summary", "Pre-Season starts at Block 0. Until then casual games only; rated play and mining rest."],
    ["image", "https://example.org/seasons/pre-season.png"],
    ["start", "1790351100"],
    ["end", "1790353500"],
    ["D", "20721"],
    ["start_tzid", "Europe/Berlin"],
    ["location", "https://example.org/"],
    ["r", "https://example.org/rules"],
    ["t", "esports"],
    ["t", "season"],
    ["alt", "Calendar event: TWENTY ONE Esports Pre-Season, Block 0 planned for 2026-09-25 15:45 UTC"]
  ],
  "content": "Pre-Season: build your clan, find opponents, mine the first blocks. Looser rules than later seasons.",
  "sig": "07affb6fbe2f4314bef5e3ce80cf17c0945d1c47ec921d2e4411b1b587c8a4f6229bffaed82523fce2e907a5c234873ec270d30bcf2320eca3d26fb2c0515a0b"
}
```

#### Release (`1985`) by one admin

```json
{
  "kind": 1985,
  "id": "beac64dcd199f9e9f7273887efdce948a6d3abfd2a91dbabb3cde9d76a29b129",
  "pubkey": "2a0335d330a81f7c1d701d8c750593d03a0ab3d2c19bbcebd66faa21cb6c078c",
  "created_at": 1790351040,
  "tags": [
    ["L", "space.einundzwanzig.esports"],
    ["l", "release-block-0", "space.einundzwanzig.esports"],
    ["a", "31923:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:season/pre-season", "wss://relay.example.org"],
    ["x", "82f1fa0c62e7aa3efd843b7b5251a697a7d05abeb48a6b9460a80df9324d0ca1"],
    ["alt", "Label: release of Block 0 of the TWENTY ONE Esports Pre-Season"]
  ],
  "content": "Release Block 0 of the Pre-Season. Supply retyped: 1000000.",
  "sig": "cf537532276d0dd69a888b636607fe35f7b2ea8d275a0fca9e2f305d48626a890be09472018816c14b08985f70d672e4664046968b0c3c4d03ef84fac1124663"
}
```

#### Season Genesis (`2156`), Block 0 of the Pre-Season

```json
{
  "kind": 2156,
  "id": "d805ee9f8c91193a344f1c4869d8728f16cf95eae9b9be65c070276886dd7d25",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790351100,
  "tags": [
    ["season", "pre-season"],
    ["supply", "1000000"],
    ["subsidy", "2100"],
    ["weight", "chess/blitz", "1"],
    ["weight", "chess/correspondence", "2"],
    ["weight", "rocket-league/2v2", "2.5"],
    ["share", "chess", "50"],
    ["share", "rocket-league", "50"],
    ["daily", "chess", "10"],
    ["daily", "rocket-league", "10"],
    ["pairlimit", "3", "5"],
    ["subtree", "90"],
    ["moves", "20"],
    ["halving", "900"],
    ["ends", "1790353500"],
    ["claim", "7776000"],
    ["consensus", "season-chain-v1"],
    ["a", "31923:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:season/pre-season", "wss://relay.example.org"],
    ["e", "760523a302a189819997f038aedf3eba738a36eff5486dda6427c98bb64f3564", "wss://relay.example.org"],
    ["e", "beac64dcd199f9e9f7273887efdce948a6d3abfd2a91dbabb3cde9d76a29b129", "wss://relay.example.org"],
    ["e", "d957dc7bb9ff198912695ccc1cfd314e6d86fc20349189d25f3817b7b69b56e6", "wss://relay.example.org"],
    ["p", "2a0335d330a81f7c1d701d8c750593d03a0ab3d2c19bbcebd66faa21cb6c078c", "", "release"],
    ["alt", "Season genesis: TWENTY ONE Esports Pre-Season, Block 0"]
  ],
  "content": "25/Sep/2026 TWENTY ONE Esports: Chancellor on brink of second checkmate. Block 0 of the Pre-Season. Demo season of the protocol examples: eras of 15 minutes, forty minutes in total.",
  "sig": "448a07b976872cb369101b7feb1418ee052904a6f1f90bb83bb42e95e56f70232b496f4e6cfb1fdaae88e465c3dea6cf8a07eeaefc1f9b4b7823ec2431ae7cbc"
}
```

#### Ladder (`32152`), chess blitz Pre-Season, closed

```json
{
  "kind": 32152,
  "id": "fcdad8b676ac874862206be296d6d5bb42750dd8f4c0796a8e7b85192a04544c",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790353530,
  "tags": [
    ["d", "chess/blitz/pre-season"],
    ["game", "chess"],
    ["mode", "blitz"],
    ["season", "pre-season"],
    ["starts", "1790351100"],
    ["rates", "player"],
    ["time_control", "300+3"],
    ["variant", "standard"],
    ["rating", "elo", "1000", "32", "400"],
    ["tier", "bronze-1", "0"],
    ["tier", "bronze-2", "900"],
    ["tier", "bronze-3", "925"],
    ["tier", "silver-1", "950"],
    ["tier", "silver-2", "975"],
    ["tier", "silver-3", "1000"],
    ["tier", "gold-1", "1025"],
    ["tier", "gold-2", "1050"],
    ["tier", "gold-3", "1075"],
    ["tier", "platinum-1", "1100"],
    ["tier", "platinum-2", "1125"],
    ["tier", "platinum-3", "1150"],
    ["tier", "diamond-1", "1175"],
    ["tier", "diamond-2", "1200"],
    ["tier", "diamond-3", "1225"],
    ["tier", "champion-1", "1250"],
    ["tier", "champion-2", "1275"],
    ["tier", "champion-3", "1300"],
    ["tier", "grand-champion-1", "1325"],
    ["tier", "grand-champion-2", "1375"],
    ["tier", "grand-champion-3", "1425"],
    ["provisional", "5", "40"],
    ["trust", "21b9921fc310826ffbd9872995a4de1d9c5115042e99ede4e74786e39729ebd5", "50"],
    ["hashrate", "3", "2", "1", "5"],
    ["reset", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/blitz/season-5", "9ebe444310505b73330e4f9a8913f6d5d2837b4f3659e0ddefe093f50d77d311", "0.5"],
    ["seed", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "1021"],
    ["seed", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "1012"],
    ["seed", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "1010"],
    ["seed", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "991"],
    ["seed", "ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd", "991"],
    ["seed", "fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea", "982"],
    ["e", "d805ee9f8c91193a344f1c4869d8728f16cf95eae9b9be65c070276886dd7d25", "wss://relay.example.org"],
    ["e", "453f2089fdf02dceac6111c25ed5ce5e98588571d9827a6d0a68b4f0ff2459c6", "wss://relay.example.org"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["p", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7"],
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f"],
    ["p", "ea65ddba42312e14cfaccb3c24c6637043001b2adc4a8246b01f6b8af52a65cd"],
    ["p", "fbda8bb20a5448716a32782dece9f8a624a6e3c846dd2a8bcd4e4bb45cd338ea"],
    ["standing", "1", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "1049", "3", "1", "provisional"],
    ["standing", "2", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "1034", "1", "0", "provisional"],
    ["standing", "3", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "941", "0", "3", "provisional"],
    ["ends", "1790353500"],
    ["alt", "Esports ladder: chess blitz, pre-season, closed"]
  ],
  "content": "",
  "sig": "133add6a2aee122a61466179d586ee289ee03813d1d38b7d778c699843f7bfed414f62f848f6ead86441fc00779832cfc4b295cdfc6440f5359f2131b4dfb891"
}
```

#### League Attestation (`2154`), match #431: block 1

```json
{
  "kind": 2154,
  "id": "9e2a58715080116baac79e81f21c6768ed111be41887291e14c6994d88760b39",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790351430,
  "tags": [
    ["e", "95ed14da12975fe67d97e02b7c12fb29afed21b7be1efa2ed1cd7cfc3e63a74f", "wss://relay.example.org", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["e", "98dcd3f46da354a8e2fcd363246ae40ee3c0332e2ab1aeb246901661e9a40484", "wss://relay.example.org", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f"],
    ["e", "40b754ce423bd631c3e817bb11cb13e3517cd3ebcafd5ddbf418f807676c3598", "wss://relay.example.org", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["e", "e4765b8e21a6a7b07605b2eadcbbc982fab3a1b358d1502dbbc5ea3171f4f9a5", "wss://relay.example.org", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/blitz/pre-season", "wss://relay.example.org"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "", "challenger"],
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "", "challenged"],
    ["board", "1", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "1-0"],
    ["resolution", "confirmed"],
    ["winner", "challenger"],
    ["elo", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "1021", "1039"],
    ["elo", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "991", "973"],
    ["match", "431"],
    ["clan", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "32150:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers"],
    ["clan", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "32150:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets"],
    ["trust", "21b9921fc310826ffbd9872995a4de1d9c5115042e99ede4e74786e39729ebd5", "50"],
    ["gate", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "100", "b6acf48740c2f675234b3450816662085b543206b45d1fbc7f5c2f8718de371e", "2cd4a88dc0fa77aee4f2c6a5a38ace044672a3777b3700682112a14cb20da65f"],
    ["gate", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "100", "92547c7cf81b18d73454dd89431119c7d47b99a13db6735ffaec2cc3eaeb4d74", "0ea4c0711e09a7d9ef8857e1910079760cb423890ef594c918f2b720cce20134"],
    ["block", "1", "d805ee9f8c91193a344f1c4869d8728f16cf95eae9b9be65c070276886dd7d25"],
    ["alt", "Esports league attestation: match #431, alice beat erin, block 1"]
  ],
  "content": "",
  "sig": "c8327fed09ba225ca0b5f2d37370398105835e8798b69f70066a953732823c8eec1f09907927990e82e2fe0007863fcd3929991acef15fd3a576cce7445d02f8"
}
```

#### Parameter Change (`2158`)

```json
{
  "kind": 2158,
  "id": "a8f00fdc7c9ff251728451fc3c1d821b64667ca280b78ee97e1f00e2e014b32f",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790351820,
  "tags": [
    ["e", "d805ee9f8c91193a344f1c4869d8728f16cf95eae9b9be65c070276886dd7d25", "wss://relay.example.org"],
    ["e", "d957dc7bb9ff198912695ccc1cfd314e6d86fc20349189d25f3817b7b69b56e6", "wss://relay.example.org"],
    ["effective", "1790351880"],
    ["tip", "48a5c5adf596a5d03a037d9d2a99128ddbfb478fc10f406dbfed3382c8a5895d"],
    ["pairlimit", "1", "3"],
    ["subtree", "51"],
    ["p", "0ba8b0422694f439dab12f7ff561920f6c7b7b220657a2e32824f31c4b114b5f", "", "change"],
    ["alt", "Season parameter change: TWENTY ONE Esports Pre-Season, effective 2026-09-25 15:58 UTC"]
  ],
  "content": "Week-1 review of the Pre-Season: too many repeat pairings. One block per pairing and day, three per season; rule 7 back to a share of 51 %. Applies to blocks attested from 15:58 on.",
  "sig": "d427d514eb5bef1ce13f0bf63823cab259b4710ae8aef75bcf5f4de8de1168e5bae04672b5dfccf275e1c5e210a37e200c59c6ea6236b0c522e854b7f77c9751"
}
```

#### League Attestation (`2154`), match #434: no block under the changed limit

The `block` tag names block 3, the tip at 16:07:30; the change's `tip` (block 2) lies before it, as
required for an attestation after `effective`.

```json
{
  "kind": 2154,
  "id": "453f2089fdf02dceac6111c25ed5ce5e98588571d9827a6d0a68b4f0ff2459c6",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790352450,
  "tags": [
    ["e", "0eaed9aae646cbf1d7d13b85af6358889203da3b5a2a036ae46d08ec599cf454", "wss://relay.example.org", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["e", "c199f3bc06d328cdfd2eeddd5bca20bbb4db390fcd961d7bbdcd8b46feeb65ee", "wss://relay.example.org", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f"],
    ["e", "5123cd8bbe2906251f9a7757f451eaba860284afc0abe36e6a6702e0ea29359c", "wss://relay.example.org", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["e", "ce792648b1b13cc06f972818f18e1feb025e4a45398e2ef2f0c9416d6c5984bd", "wss://relay.example.org", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/blitz/pre-season", "wss://relay.example.org"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "", "challenger"],
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "", "challenged"],
    ["board", "1", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "1-0"],
    ["resolution", "confirmed"],
    ["winner", "challenger"],
    ["elo", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "1033", "1049"],
    ["elo", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "957", "941"],
    ["prev", "ffa767be87ef01596e501f423a521170976f9b8dee55b6be5e26fbcdf3bd42f7"],
    ["match", "434"],
    ["clan", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "32150:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers"],
    ["clan", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "32150:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets"],
    ["trust", "21b9921fc310826ffbd9872995a4de1d9c5115042e99ede4e74786e39729ebd5", "50"],
    ["gate", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "100", "b6acf48740c2f675234b3450816662085b543206b45d1fbc7f5c2f8718de371e", "2cd4a88dc0fa77aee4f2c6a5a38ace044672a3777b3700682112a14cb20da65f"],
    ["gate", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "100", "92547c7cf81b18d73454dd89431119c7d47b99a13db6735ffaec2cc3eaeb4d74", "0ea4c0711e09a7d9ef8857e1910079760cb423890ef594c918f2b720cce20134"],
    ["block", "", "ffa767be87ef01596e501f423a521170976f9b8dee55b6be5e26fbcdf3bd42f7"],
    ["alt", "Esports league attestation: match #434, alice beat erin, no block"]
  ],
  "content": "",
  "sig": "190e00d8039a0a4bbd1955d901d9c7e1b0444a1ad83e00472952e42ac9246f9b7456b5d352e7ba2ec97e9136ee16aa9c7dc93ddb535b33f620b3a66cddebea58"
}
```

#### Challenge (`2150`), match #435, with the league's `zap` tag

```json
{
  "kind": 2150,
  "id": "9c669f73d7aca95b525a115dcc0e2ee225f021ab253932ef186a267ff49b34cd",
  "pubkey": "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e",
  "created_at": 1790352480,
  "tags": [
    ["a", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", "wss://relay.example.org", "challenger"],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", "wss://relay.example.org", "challenged"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rocket-league/2v2/pre-season", "wss://relay.example.org"],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["bo", "3"],
    ["start", "1790352540"],
    ["respond_by", "1790352530"],
    ["match", "435"],
    ["zap", "3fe2a96fc8d3efe65eb05be97ab7b483b73c56118acbae12e75ac950e1d7e504", "wss://relay.example.org", "1"],
    ["alt", "Esports challenge: best of 3, rocket-league 2v2, match #435"]
  ],
  "content": "Pre-Season series?",
  "sig": "151d9033cc60eed01f4170058f154511fa3d507c2743e67fbca3f8df7907e266bfad0ca6180fbdbf8ee76931f0d1b29cea13a7e206c30a08f84c789d23d1e461"
}
```

#### Zap receipt (`9735`), a fee on match #435

```json
{
  "kind": 9735,
  "id": "6b498cc51c7243c9a16a577c23496d013b87d630fb5b505ab70a855a9848feba",
  "pubkey": "8f12a686b755d0ef25fc3113b1917b37831e5ec7557267ca16bddc8898594512",
  "created_at": 1790352735,
  "tags": [
    ["p", "3fe2a96fc8d3efe65eb05be97ab7b483b73c56118acbae12e75ac950e1d7e504"],
    ["P", "ce9ffe39455312a9231fe9474296eceeae7777de3e078b92e9ddd2b2816f1c57"],
    ["e", "9c669f73d7aca95b525a115dcc0e2ee225f021ab253932ef186a267ff49b34cd", "wss://relay.example.org"],
    ["k", "2150"],
    ["bolt11", "lnbcrt21000n1p4td82spp5wtgamx2y3h6suk4dr62jnc29kfetnw4qgy5jl0r32pdrl5zx9xhqsp57cjh997tddktfkvxxk7lhdqphd8z828xvkxnm7g32fgy2kwdf4nqhp59cakzjvdc7fynzgwfd9gmdr6e8syjku2008qv4rgv8envpsundvsxqrrsstjh6snd9ny8nacdk8rpefyzc7ptec0hytp3rfza6yhpqxlytl66ktwp30l9znrpv9029kraka5xmwa4an345fazazngfay63gkd9tsgqu54rxf"],
    ["description", "{\"kind\":9734,\"id\":\"16e202fe5f6c3fe8516f847712f385607bb66b5b3b782e5c62b43204f2bb2f9a\",\"pubkey\":\"ce9ffe39455312a9231fe9474296eceeae7777de3e078b92e9ddd2b2816f1c57\",\"created_at\":1790352720,\"tags\":[[\"relays\",\"wss://relay.example.org\"],[\"amount\",\"2100000\"],[\"lnurl\",\"LNURL1DP68GURN8GHJ7ETCV9KHQMR99EHHYEE09EMK2MRV944KUMMHDCHKCMN4WFK8QTMSDAHKC8FH5GV\"],[\"p\",\"3fe2a96fc8d3efe65eb05be97ab7b483b73c56118acbae12e75ac950e1d7e504\"],[\"e\",\"9c669f73d7aca95b525a115dcc0e2ee225f021ab253932ef186a267ff49b34cd\",\"wss://relay.example.org\"],[\"k\",\"2150\"]],\"content\":\"Go Strikers!\",\"sig\":\"422d56422211a4b4d2a8424be763c590d0a7b1e15fa10c7f56151cd142fb849c923d1f7864930ef63a930a2d0a715034266b373fd0a3d499f695d075e5604956\"}"],
    ["preimage", "f94f3c4167543d70bb1ca1d570fc9e5553e2eb4e0359a211b86fbfdd2f7da9ec"]
  ],
  "content": "",
  "sig": "a5e4634b77db9295d80b75d6255f5a6ac81cf9e6e2b321de52d347229644b11f738f50889c2d10e22e464e5b36402b5c2a181411dfda42b19b0796c17dce342a"
}
```

#### League Attestation (`2154`), match #435: block 4, a team block

```json
{
  "kind": 2154,
  "id": "089e24a4a97fdfd63279349db3061fe37f2f8293a5152bc8249b970c3f881f65",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790353050,
  "tags": [
    ["e", "9c669f73d7aca95b525a115dcc0e2ee225f021ab253932ef186a267ff49b34cd", "wss://relay.example.org", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["e", "4db1e2cf400ec671375d054b69b1a363ebf42bf8f95e05933755b1fbb7e415f2", "wss://relay.example.org", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["e", "c79eba6e17cf48a9b42a63f7f452569f613ba975205d9d46f19186b87f6582bf", "wss://relay.example.org", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["e", "9a8fcadb05671f996d48ea91f4ea4adb7ac44a1f177082a9aa4fdee298456a82", "wss://relay.example.org", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:rocket-league/2v2/pre-season", "wss://relay.example.org"],
    ["a", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", "wss://relay.example.org", "challenger"],
    ["a", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", "wss://relay.example.org", "challenged"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "", "challenger", "captain"],
    ["p", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "", "challenger", "player"],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "", "challenged", "captain"],
    ["p", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "", "challenged", "substitute"],
    ["resolution", "confirmed"],
    ["winner", "challenger"],
    ["score", "1", "challenger", "2", "0"],
    ["score", "2", "challenged", "1", "3"],
    ["score", "3", "challenger", "4", "3", "ot"],
    ["elo", "32151:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers/rocket-league/2v2", "1010", "1029"],
    ["elo", "32151:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets/rocket-league/2v2", "990", "971"],
    ["match", "435"],
    ["clan", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "32150:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers"],
    ["clan", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "32150:e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e:satoshis-strikers"],
    ["clan", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "32150:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets"],
    ["clan", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "32150:eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356:nakamoto-rockets"],
    ["trust", "21b9921fc310826ffbd9872995a4de1d9c5115042e99ede4e74786e39729ebd5", "50"],
    ["gate", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "100", "b6acf48740c2f675234b3450816662085b543206b45d1fbc7f5c2f8718de371e", "2cd4a88dc0fa77aee4f2c6a5a38ace044672a3777b3700682112a14cb20da65f"],
    ["gate", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356", "100", "2ad85db4ff5612ef3b9cf6ea3c201e196a704342538048b1689e60b29c1594a7", "f165132bdca088790250a86806a4df61b7e16b3cc5049eb0315dc2043f4ef11e"],
    ["gate", "b92ec75c453613118d2ffa18c9bedacdf98bcff1ef40e53a2693533aebb9cef7", "100", "331aa07e66cbb19ebdd1200df687b6afcee70eda6194f2429212435c87d7b71d", ""],
    ["gate", "31209e670baeaca225089f2b1ad662d497f2da7e78b740bdc67218b00f1eaf0f", "100", "92547c7cf81b18d73454dd89431119c7d47b99a13db6735ffaec2cc3eaeb4d74", ""],
    ["block", "4", "ffa767be87ef01596e501f423a521170976f9b8dee55b6be5e26fbcdf3bd42f7"],
    ["alt", "Esports league attestation: match #435, Satoshi's Strikers beat Nakamoto Rockets 2-1, block 4"]
  ],
  "content": "",
  "sig": "9c6d6799f3c1d07ba849e0e8c3e3c06c22c975a24e48685d2157fd9b74f949fcc1889e1fa4388d8645974c204b61edbb1a090aeef9d3d06549c997774a7f6720"
}
```

#### Bounty (`31923`)

```json
{
  "kind": 31923,
  "id": "ab44645fdfaaf4ed546d1ea0a8539dc1239d5bddb8ec7df6626a1c2e44716abb",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790351140,
  "tags": [
    ["d", "bounty/alice-blitz-pre"],
    ["title", "Bounty: beat alice in chess blitz"],
    ["summary", "The first player who beats alice in a rated blitz game of the Pre-Season takes the bounty."],
    ["start", "1790351160"],
    ["end", "1790353500"],
    ["D", "20721"],
    ["start_tzid", "Europe/Berlin"],
    ["location", "https://example.org/bounties/alice-blitz-pre"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "wss://relay.example.org", "target"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/blitz/pre-season", "wss://relay.example.org"],
    ["zap", "3fe2a96fc8d3efe65eb05be97ab7b483b73c56118acbae12e75ac950e1d7e504", "wss://relay.example.org", "1"],
    ["t", "esports"],
    ["t", "chess"],
    ["t", "bounty"],
    ["alt", "Esports bounty: beat alice in a rated chess blitz game of the Pre-Season"]
  ],
  "content": "Claim rule bounty-v1 on chess/blitz/pre-season. Paid with the season's settlement; unclaimed, it goes to the league reserve.",
  "sig": "c3c7bc9ccecf6cf4c63e9ad23027855870916a49d91b58e8cc9fb73c514ab3437a736173bf6ea37faf4c445024e93010e67e176436308bf4197ff6f8fdac6b3f"
}
```

#### Rank badge definition (`30009`), alice, chess blitz

From season 5: alice's rank was revealed as Gold II at 14:01 and fell to Gold I at 14:39, which
replaced the first version on every relay. In the Pre-Season she has four rated blitz results, is
provisional again, and the definition keeps Gold I, naming season 5.

```json
{
  "kind": 30009,
  "id": "e5ffe120015a4e01008741ac5ae2ea1bab3375fc3a6efc85a1dd0adf8ed6dc89",
  "pubkey": "e643b4d007da1346d9601214b3e89ae88f2db80fc3fed8a617ca2ad6293ef120",
  "created_at": 1790347140,
  "tags": [
    ["d", "rank/chess/blitz/e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["name", "Chess blitz · Gold I"],
    ["description", "Gold I in chess blitz, season-5, TWENTY ONE Esports. Rank badge: it changes with the rank."],
    ["image", "https://example.org/badges/rank/chess/gold-1-v1.png", "1024x1024"],
    ["thumb", "https://example.org/badges/rank/chess/gold-1-v1-256.png", "256x256"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["a", "32152:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:chess/blitz/season-5", "wss://relay.example.org"],
    ["alt", "Badge: chess blitz rank of alice, Gold I"]
  ],
  "content": "",
  "sig": "74e6282c601a82f724469f052b47bf074399d21e9c929ea9edd1de577626a2e5ae47e7d6ab479f2b7d7ac26df2d5702c804656f0e1b6fa534ed1cd458b8b1789"
}
```

#### Badge award (`8`)

```json
{
  "kind": 8,
  "id": "991c006acd0c5e04cc8669b321b147f7b3f8c750218d27e2f3229795de9c2f59",
  "pubkey": "e643b4d007da1346d9601214b3e89ae88f2db80fc3fed8a617ca2ad6293ef120",
  "created_at": 1790344865,
  "tags": [
    ["a", "30009:e643b4d007da1346d9601214b3e89ae88f2db80fc3fed8a617ca2ad6293ef120:rank/chess/blitz/e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "wss://relay.example.org"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "wss://relay.example.org"],
    ["alt", "Badge award: chess blitz rank of alice"]
  ],
  "content": "",
  "sig": "c03fbccd0769732447497475669e180616c20ae54f47e0cbba36c2a8df405b24cfbf347e7dc23d54aa31c6bcfa5753e3453fb8a301b04dff9f9f41fb2207c336"
}
```

#### Profile badges (`10008`), alice

```json
{
  "kind": 10008,
  "id": "869aade408447fa7b9ef9d1cd78e6ccb0d58d86a8e3a365b260345770e145d79",
  "pubkey": "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e",
  "created_at": 1790344920,
  "tags": [
    ["a", "30009:e643b4d007da1346d9601214b3e89ae88f2db80fc3fed8a617ca2ad6293ef120:rank/chess/blitz/e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e", "wss://relay.example.org"],
    ["e", "991c006acd0c5e04cc8669b321b147f7b3f8c750218d27e2f3229795de9c2f59", "wss://relay.example.org"],
    ["alt", "Profile badges"]
  ],
  "content": "",
  "sig": "7b985eb715bf3ef9d36b6132b4b71ed2a2e7d6d2814c1a2a06810d86cb926f9d1360d07a386bc3b908d134e446324d8b487be0acc1cb656b07116aa5a6708288"
}
```

#### Correction (`1985`): the review voids block 2

```json
{
  "kind": 1985,
  "id": "0a6034d7e9d45e7dc0eaf76e6abe457c5886a5ee5f1b29a13be704b482546b81",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790353680,
  "tags": [
    ["L", "space.einundzwanzig.esports"],
    ["l", "void-block", "space.einundzwanzig.esports"],
    ["e", "48a5c5adf596a5d03a037d9d2a99128ddbfb478fc10f406dbfed3382c8a5895d", "wss://relay.example.org"],
    ["alt", "Label: Pre-Season review voids block 2"]
  ],
  "content": "Pre-Season review: block 2 (match #432) is void. Second win of the same pairing within five minutes, with move times that match an engine on both sides; the rating result stays, the reward returns to the league reserve.",
  "sig": "767aee52546765f12e5c6deb288a5612d7e76589f6af00e0b7441f7d9cc5703eea6ce8727aa57c337e9cb14c086b711adb2e7f0b5382f40e6db36fe5b41fcb82"
}
```

#### Payout (`2157`), alice

```json
{
  "kind": 2157,
  "id": "306ea322f8cb6ca1f9332f437ff0a781106002af7f69c870610b23d48dff4d9e",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790353800,
  "tags": [
    ["e", "d805ee9f8c91193a344f1c4869d8728f16cf95eae9b9be65c070276886dd7d25", "wss://relay.example.org"],
    ["p", "e221ff8c3afc1a9e4a6e1d589cbcb6b85beb93d1beed2f8ce8347876b4c0413e"],
    ["e", "9e2a58715080116baac79e81f21c6768ed111be41887291e14c6994d88760b39", "wss://relay.example.org"],
    ["e", "089e24a4a97fdfd63279349db3061fe37f2f8293a5152bc8249b970c3f881f65", "wss://relay.example.org"],
    ["e", "9c669f73d7aca95b525a115dcc0e2ee225f021ab253932ef186a267ff49b34cd", "wss://relay.example.org"],
    ["bolt11", "lnbcrt44620n1p4tdg2vpp5j3mndza3v4azctn584jthyrqkmw3w97axgj2hg5h5xustlesrnvqsp56rh22zz5lx3rndcxskjsrtmtrnwzyc7l6fd3ag9qu3zg4wa75gcqhp5yduvssu90760zy7rrjmg04umrdvsufng3plxclhvp7l7kctyjxnqxqrrssdt8p5xnmru8p3q2we627uru3rnxj5s05glm9fjad9hft2ugwapvnfgyeck769c43szttacmppdtp7pdf6e33w6709ky2ntw0y4sw4mcqnqz737"],
    ["preimage", "3cc7b36de6f0027783f7d589179b7ded2513ac96b3dfb144d88ad13f8c4a2248"],
    ["alt", "Esports payout: Pre-Season settlement for alice"]
  ],
  "content": "",
  "sig": "f492f1f6f3e35df92b48e3f546a87cdc9e39c2d545dc5104cac2b0e58c36bf0291a85477a4bdadab0ba9cea9e7172303a2da640ad7395b2a5711987e41f22ac6"
}
```

#### Payout (`2157`), carol, with the bounty

```json
{
  "kind": 2157,
  "id": "c4ae9d225840c7d73bf74e01068e7f3eb8b1736c6e15ac6a80015bdb395d3a1b",
  "pubkey": "8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753",
  "created_at": 1790353820,
  "tags": [
    ["e", "d805ee9f8c91193a344f1c4869d8728f16cf95eae9b9be65c070276886dd7d25", "wss://relay.example.org"],
    ["p", "eaf06c97871749252d375400b00cc5901e8a205ec4aebc5ae9168b0b66471356"],
    ["e", "ffa767be87ef01596e501f423a521170976f9b8dee55b6be5e26fbcdf3bd42f7", "wss://relay.example.org"],
    ["a", "31923:8a0f19d2c34bc11582c2ee67470d69379673852cb1939bc9a2eaf81d1ddc4753:bounty/alice-blitz-pre", "wss://relay.example.org"],
    ["bolt11", "lnbcrt220500n1p4tdgtqpp5vflvw9qat8qx2x8cc6hec3wl4wdle8nxlamaypaqe75q896h59fssp589l2hv2fetrul2rcz99nmsj7u99emrvgssd22qc98xf70sjcsckqhp5686dvquqgj87n9mhe0tt8zjewes3r04d20tk77ts9h82qqkxjwqsxqrrssr5z6l7l2xk8cmhsxqnghxtum3pa8u738z4ad2aqk86t332clenqjta8c7c7t66xanv53es7phhsudruz3crgza9nda9gcqq8ynrlqxgp2anua8"],
    ["preimage", "9aa5a5a4eea63e9fc79bca83a0123ba23861f1832d112b8cf06fedc19bb08b2f"],
    ["alt", "Esports payout: Pre-Season settlement for carol"]
  ],
  "content": "",
  "sig": "afa9897036a541b07ac0029587ac2854cde8f8e14ad27f9c92dc0dad5dd5992069b064a0b1d0bd426ac7a76a393b2fa9f3e0fde0c50ec8851194c8b4099e6a42"
}
```


### Revision 6: a roster invitation and a lineup from members

Keys of round 4 (heidi, grace, ivan). All times 2026-09-25, UTC.

- 21:10 heidi founds **Block Builders** [BLD] with her own membership.
- 21:11 heidi lists grace and ivan in the clan: two invitations into the roster, without lineup, mode or
  role.
- 21:12 grace and 21:13 ivan accept, each with a membership that names the clan and nothing else.
- 21:14 heidi signs the 2v2 lineup from the three active members: heidi captain, grace player, ivan
  substitute. Neither grace nor ivan signs anything for it.

#### Clan (`32150`), the roster with two invitations

```json
{
  "kind": 32150,
  "id": "ff0ad357996553cfffbc2ac82ffd6a7aa5114fca05d4b8ef245f8894825d3678",
  "pubkey": "e0102842c1cffa01a03dba34c07fe88a0907bdb3fc867106629ab8299243d0c1",
  "created_at": 1790370660,
  "tags": [
    ["d", "block-builders"],
    ["name", "Block Builders"],
    ["clantag", "BLD"],
    ["p", "e0102842c1cffa01a03dba34c07fe88a0907bdb3fc867106629ab8299243d0c1", "", "captain"],
    ["p", "5ccb330beff1652ff8b69f9478e9c97468c0a194e3bb3efd8f7d97cb1c139a6b", "", "member"],
    ["p", "9f90338586f9de60a4fa24756c840a02d8cfb7748f02ad32cc80fac75a09fa6e", "", "member"],
    ["alt", "Esports clan: Block Builders [BLD]"]
  ],
  "content": "Rocket League clan of three solo-pool players.",
  "sig": "bc522f74ee7ec1d15da358ef44118bf1a005d1cc06060bb78ed24f2bd3c299d824f24b42b849ff069fd085e50b79cb9a51210cea608e00072670f1e8b330dc13"
}
```

#### Clan Membership (`12150`), grace accepts: the clan only

```json
{
  "kind": 12150,
  "id": "0127f1391cf5473e6431a7415d2f151ee70483668244ddd86ba06506bb20075a",
  "pubkey": "5ccb330beff1652ff8b69f9478e9c97468c0a194e3bb3efd8f7d97cb1c139a6b",
  "created_at": 1790370720,
  "tags": [
    ["a", "32150:e0102842c1cffa01a03dba34c07fe88a0907bdb3fc867106629ab8299243d0c1:block-builders", ""],
    ["alt", "Esports clan membership"]
  ],
  "content": "",
  "sig": "fd955ca5850061dc4f514ba91bdfbdce367b1d3f817a1ff6103d30c77b91932f787228b8ac7711693d1da6495c8d4d39a92e93b064ce559786fd6346276f69fd"
}
```

#### Lineup (`32151`) from active members

```json
{
  "kind": 32151,
  "id": "2172b5c618fcf2d5ce4ceb1f5ac7cabea90e3ced91cc1afd0f8adc4dbedefe8f",
  "pubkey": "e0102842c1cffa01a03dba34c07fe88a0907bdb3fc867106629ab8299243d0c1",
  "created_at": 1790370840,
  "tags": [
    ["d", "block-builders/rocket-league/2v2"],
    ["a", "32150:e0102842c1cffa01a03dba34c07fe88a0907bdb3fc867106629ab8299243d0c1:block-builders", ""],
    ["game", "rocket-league"],
    ["mode", "2v2"],
    ["p", "e0102842c1cffa01a03dba34c07fe88a0907bdb3fc867106629ab8299243d0c1", "", "captain"],
    ["p", "5ccb330beff1652ff8b69f9478e9c97468c0a194e3bb3efd8f7d97cb1c139a6b", "", "player"],
    ["p", "9f90338586f9de60a4fa24756c840a02d8cfb7748f02ad32cc80fac75a09fa6e", "", "substitute"],
    ["alt", "Esports lineup: Block Builders, rocket-league 2v2"]
  ],
  "content": "",
  "sig": "002dd7e8b69b6725efc4e35695958c01bf9a32a5aed16128572c00f6726565f646c15ee54ce4a476c9a84cb0ffdd59c8a2ecfa8dd32c1786a2a68113bbcbfb62"
}
```

## Open points

- **Revision 8 has no signed example yet.** The badge definitions, awards, profile lists and share
  posts of revision 8 are tested against a local `nak serve` relay in the app's browser test, not yet
  on the ndak test bed (rnostr, strfry, khatru) like the examples of rounds 1 to 6. Also open: Season
  Wrapped as a series of four story cards (the app draws one), and a `30009` definition cached by a
  client showing an old tier until it fetches again (NIP-58 has no cache invalidation).

- **Pomegranate is a custodian of use.** A Google login's key is FROST-sharded 3-of-4 across
  independent operators, but the operators produce partial signatures and ECDH shares for whatever
  the coordinating `central` server asks, and `central` decrypts NIP-44 itself. `central` can
  therefore sign and read as the user without the user; it cannot extract the key. Relevant for
  results signed by Google logins and for their chats.
- **Queue collusion and the pairing limit.** Two players joining the queue at the same moment in a
  small pool can steer a pairing; the pairing limit (same pair at most `n` rated games per day) is
  league policy and not specified.
- **Seeding tie-break** for lineups with equal rating at registration close, and what happens to
  reserves of the solo pool, are tournament policy.
- **Retention of gift wraps** on the league relay (keep, or delete after a period) is open.
- **League key scope.** Signing tournaments with the league key adds `31923` and `31924` to its
  allowlist; a separate tournament key would keep the league key narrower but split the authority
  over draws and tournaments.
- **Latency of remote signing** (queue pairings, chat) for Google logins was not measured.
- **Provisional k-factor for Rocket League.** The chess ladders carry `["provisional","5","40"]`. The
  Rocket League examples were signed before that decision and carry `["provisional","5"]` (k 32 all
  season). If season 1 of Rocket League should also use k 40 while provisional, its ladders get the
  second value; the arithmetic is already defined.
- **Clan rating per mode.** The top-three average is per chess mode (blitz, correspondence), since
  each mode has its own player ladder; which one the clan page shows first is a design question.
- **Rocket League 1v1**: settled in revision 7.1, it is a player ladder
  ([Rocket League 1v1](#rocket-league-1v1-rev-71)).
- **Correspondence time-outs and pauses** (holidays) are not modelled beyond the time control; a
  time-out is a league `forfeit`.
- **Fair play** (engine use) is out of scope; a verdict would be `admin` or `void` with a public
  reason.
- **Forfeits move ratings.** This draft rates `forfeit` like any series result. If a no-show should
  only cost the absent lineup, step 4 of [Rating](#rating) needs a different formula for forfeits.
- The rules for switching clans while a challenge is running (block the switch, or allow it and let
  the roster in the report show who played) are league policy. P4 decided that accepting another
  clan's invitation is a switch ([Ownership](#ownership-rev-5)); a running challenge is not covered.
- Anti-farming limits (plan, open question 4) are league policy; this NIP only reserves the check in
  rule 11. A double-elimination bracket can pair the same lineups twice in one evening, so the
  policy should exempt tournament challenges.
- **Carry-over across a quiet season.** A season seeds only entities with a rated result in it
  (step 3 of [Season transition](#season-transition)). With league-wide seasons, every game that is not
  played for a whole season loses its ratings: Rocket League 2v2 season 3 starts both lineups at 1000,
  although season 2 had seeded them 999/1001. If ratings should survive a quiet season, the rule
  becomes "one seed per entity with a rating in `A` (a result or a seed)"; the season-3 examples would
  then be re-signed.
- **Trust parameters** (minimum 50; algorithm with 0.5 per hop, three hops, 25 points per halving) are
  calibrated on constructed graphs, not on real players. Whether members' lists are short enough for
  newcomers to reach 50 is only known once there are members' lists.
- **Team matches and the trust gate.** Gatekeepers of a lineup match are the two acting captains; the
  players meeting on a chess board need no mutual listing, only the rank. If board opponents should
  also list each other, the pairing of boards would have to be known at the accept.
- **Global Rating in small ladders and after a season change.** A percentile in a ladder of two is
  coarse, and a new season starts without Global Ratings. Shrinking percentiles toward 0.5 in small
  ladders, or counting the previous season until five results exist, are options; neither is specified.
- Corrections after an attestation are not defined in V1 for ratings. Revision 5 corrects only
  rewards (a `void-block` label); a later version may add a `correction` resolution that references the
  corrected attestation.
- **Rule 7 in a small league** (rev. 5). With few anchors most pairs fall into one anchor subtree: in
  the test bed 6 of 15 pairs at a threshold of 51, two of them not clan mates. The threshold is a
  parameter (`subtree`) and can change during a season; its value should be measured on the real
  anchor list before Block 0 of the Pre-Season.
- **Join requests by clan link** (rev. 6, planned). A player may ask to join through a clan's link, and a
  captain confirms. Only the owner signs `32150`, so a captain's confirmation cannot be the listing
  itself: either the owner's key lists the player later, or the league lets a captain's confirmation
  stand for it, which needs a rule of its own. A membership (`12150`) signed as the request would leave
  the player's current clan at once, so the request stays league data until the listing exists.
- **Admin list history.** Relays keep only the newest admin list; a genesis or a change that names an
  older version needs the league's archive to be checked once the board has changed.

- **Legal and tax questions** of paying sats from a donated pot for won games are not checked.
- **The reserve's goal amount.** NIP-75 requires one; the examples use 21 000 000 sats as a number that
  promises nothing. Whether clients show that as a target is a design question.
- **Revision 7 has no signed example yet.** A director result (`2154` with `entered-by`), a draw and a
  tournament without a ladder `a`, and a tournament consent (`22150`, signed but not published) still
  need their round in the relay proof.
- **Director results are not verifiable** (rev. 7). `entered-by` names a person, but the director's
  key signs nothing. A director could sign a Result Report (`2152`) of their own, with the tournament
  `a` and no challenge, which the attestation would reference by `e`; that needs rules for a report
  without a challenge and is not specified.
- **Draw block distance.** The draw commitment holds only if the draw is public before block `H`; a
  NIP-03 proof needs a confirmation below `H` ([Tournament Draw](#tournament-draw-2155-optional)). A
  league that commits to the next block (`H` = tip + 1) meets rule 17 but can never prove it with
  OpenTimestamps, and loses the commitment if the block is found between reading the tip and
  publishing. How far ahead is tournament policy.
- **Tournament prize pools** (rev. 7). Until the league runs a tournament's pool, its `31923` carries
  no `zap` and zaps to it are refused ([Tournaments](#tournaments)); the pool, the endpoint's refusal
  and the version that adds `zap` are implemented later.
- **Not every rule has a failing example.** The signed examples show wins that fail rules 1, 4 and 7,
  and the checker shows rule 9 as a counterfactual (the same games with a supply of 8 400 sats). Failures
  of rules 0, 2, 3, 5 and 8 exist only in the checker's code, not in any signed event.
