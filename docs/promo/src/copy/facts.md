# Facts behind the copy — source per claim, live or not

Site: esports.einundzwanzig.space. Rule: nothing goes into a poster, reel or post
unless it is listed here as LIVE, with a source. Anything marked NOT LIVE must not
appear in the copy at all — not as a countdown, not as a teaser.

**Round 2 (2026-09-27):** corrected against the coordinator's and design-lead's
checks (clan logo, German piece letters, open source, UI terminology, membership
wording). One additional correction below (clan lineup/boards) came from my own
follow-up reading of `resources/views/pages/clans/⚡manage.blade.php` and
`⚡show.blade.php` once the logo question sent me back into that code — not
something I was asked to check, but it changes what the clan motif can honestly say.

Membership check (Hard Gate 2): membership is **not** a play gate.
- `app/Support/Membership.php:13-16` (docblock): "Membership is a badge and perk
  flag; it never decides whether someone may play."
- `resources/views/pages/auth/login.blade.php:56`: "Everyone can play everything:
  ladders, clans, challenges, tournaments."
- `resources/views/pages/auth/login.blade.php:29-42`: login is Nostr (extension/app)
  or Google — no Lightning option.

→ Copy conclusion, corrected in round 2: never write "against other members" /
"gegen andere Mitglieder" / "Fordere jedes Mitglied heraus" for an opponent — that
implies membership is required to be an opponent, which is false. Use "other
players" / "andere Spieler", and "Bitcoiner" where it adds warmth without implying
a membership requirement.

## LIVE — used in the copy

| # | Claim | Source | Live? |
|---|---|---|---|
| 1 | Nostr login (extension or app) and Google login, no password | `resources/views/pages/auth/login.blade.php:29-42` | LIVE |
| 2 | Blitz chess 5+3, played in the browser against other players | `app/Games/Chess.php:8-9,33` ("blitz 5+3 ... rated per player"); `routes/web.php:82` (`chess.lobby`) | LIVE |
| 3 | A casual ladder exists now, before Block 0; it is for fun, not the rated season | `routes/web.php:104-105` ("the rated season ladder **and the casual ladder**"); `app/Providers/AppServiceProvider.php:63-64` ("without a run ... rated play stays closed") | LIVE (casual only) |
| 4 | Correspondence chess: one move a day, challenge any player. **UI term: "Fernschach"**, not "Daily-Schach" (coordinator + design-lead check, round 2) | `app/Games/Chess.php:8-9,34` ("daily (one move per day)"); `routes/web.php:87-91` | LIVE |
| 5 | Nostr DM notifications (e.g. it's your move) | `routes/web.php:67-74` (`notifications.dm-off`: "the last line of every notification DM") | LIVE |
| 6 | German chess notation: pieces shown as K D T L S, not K Q R B N | `app/Support/Chess/SanNotation.php:6-16` (`display()`); `resources/js/sanNotation.js` (client twin, per coordinator) | LIVE — **added in round 2** (was flagged unverified in round 1; now confirmed with an exact source) |
| 7 | Founding a clan: name, tag, description, optional meetup import — **no logo at creation** ("Uploads come with the next update.") | `resources/views/pages/clans/⚡create.blade.php:301` (disabled upload button, exact string) | LIVE, minus the logo |
| 8 | Uploading a clan logo **after** founding, on the clan's manage page | `resources/views/pages/clans/⚡manage.blade.php:186-195` (`updatedLogo`, `ClanLogos::rules()`), `:320-351` (`saveEdit`, stores the file); `tests/Browser/ClanEditTest.php` (per coordinator) | LIVE — **corrected in round 2**: copy must say the logo comes on the manage page, not at founding |
| 9 | Clan roster: invite a player, or share a clan join link | `resources/views/pages/clans/⚡manage.blade.php:442-488` (`prepareInvite`, `createJoinLink`) | LIVE |
| 10 | Tournaments: all formats, sign-up, directors enter results | `routes/web.php:113-125`; `app/Providers/AppServiceProvider.php:76-84` (`create-tournaments`, `manage-tournament`, `direct-tournament`) | LIVE |
| 11 | Tournaments run casually pre-Block 0 (no live payouts yet) | Coordinator instruction (2026-09-27) | LIVE (casual only) |
| 12 | Watching live games, guests included, no login needed | `routes/web.php:83-85` ("Every live chess game, for guests too (P10, spectating)") | LIVE |
| 13 | Search (players, games), open to guests | `routes/web.php:150` | LIVE |
| 14 | Invite links ("Einladungslink"): one link, works for guests, no login needed to open it | `routes/web.php:52-60` | LIVE |
| 15 | Open source: the footer links the GitHub repo | `resources/views/components/shell/footer.blade.php:31,39` (`https://github.com/HolgerHatGarKeineNode/twenty-one-esports`, `__('Open source')`) | LIVE — **added in round 2** (was flagged unverified in round 1; now confirmed with an exact source) |
| 16 | The repo is on Nostr git (NIP-34): one push reaches 3 GRASP servers (relay.ngit.dev, gitnostr.com, ngit.danconwaydev.com) and the GitHub mirror; browsable on gitworkshop.dev; `git clone nostr://…` works | `git ls-remote` on all four servers after `git push origin master` (2026-09-27, 561e320 and b89b868); gitworkshop.dev repo page shows 4/4; clone tested into a scratch dir | LIVE — added 2026-09-27 |

## Added 2026-09-29 — Mühle and Dame (on master since f091699e, behind `ESPORTS_BOARD_GAMES`)

The copy is written in the present tense as the product works; it carries no date and no "now live".
**Do not post before prod has `ESPORTS_BOARD_GAMES`, `ESPORTS_BOARD_GAME_NINE_MENS_MORRIS` and
`ESPORTS_BOARD_GAME_CHECKERS` switched on** (all off by default, `config/esports.php` `board_games`).
Board games play casual; nothing about rated board games, mining or sats for them.

| # | Claim | Source | Live? |
|---|---|---|---|
| 17 | Blitz 5+3 for both games | `app/Games/NineMensMorris.php` and `app/Games/Checkers.php`: `new GameMode('blitz', 'Blitz 5+3', …, '300+3')` | master |
| 18 | The server checks every move; a win moves the casual rating of the game | `resources/views/pages/board/⚡lobby.blade.php:75` ("The server checks every move.") and `:401` ("Blitz 5+3, live on this site. The server checks every move; a win moves your casual rating of :game.") | master |
| 19 | Find an opponent, invite a player, own casual ladder (top five) per game, all from the game's own lobby | `⚡lobby.blade.php:32-45` ("'Find opponent' joins the board game's own queue … 'Looking to play' lets others invite this player … its casual ladder's top five"); `:472` ("Find opponent"), `:527` ("Casual ladder") | master |
| 20 | Mühle: nine men each, placed one by one, then moved along a line to a neighbouring point; at three men a side flies to any empty point | `app/Support/Board/NineMensMorrisRules.php:16-20` ("Each side has nine men and places them one by one on empty points … A side down to three men flies: one man to any empty point.") | master |
| 21 | Mühle: three own men on one line are a mill; closing it removes one enemy man | `NineMensMorrisRules.php:21-23` ("Three own men on one line are a mill. Closing a mill … removes one man of the other side") — the copy says "one man" and leaves out the protection of men inside a mill | master |
| 22 | Dame: German rules on 8×8 | `app/Support/Board/CheckersRules.php:8` ("Checkers ("Dame") by German rules on 8 x 8") | master |
| 23 | Dame: capture is compulsory; men capture forward and backward | `CheckersRules.php:18-19` ("Capturing is compulsory: while any capture exists, only captures are legal. Men capture forward and backward.") | master |
| 24 | Dame: capture chains, the path is clicked square by square | `CheckersRules.php:20-21` ("A capture chain goes on as long as the capturing piece can capture again"); `:34-35` ("`c3xe5xc7` a capture chain … The path to click is the squares in that order."); scene c3-e5-g7 is a chain of two jumps over d4 and f6 | master |
| 25 | Dame: flying king ("Dame" in German, "king" in English); captures one enemy piece any distance away and lands directly behind it | `CheckersRules.php:15-17` ("a king ("Dame") moves any distance along a diagonal … (flying king)"); `:23-24` ("A king captures a single enemy piece any distance away on its diagonal and lands on the square directly behind it.") | master |
| 26 | Spectators watch live on a public channel | `resources/js/boardGame.js:9-11` ("spectators on the public `board.{id}.watch` channel"). "Guests" / "without login" is NOT claimed: the header says spectators, not guests | master |
| 27 | Game name: EN "Nine men's morris" / "Checkers", DE "Mühle" / "Dame" | `app/Games/NineMensMorris.php:26` ("Nine Men's Morris"), `Checkers.php:32` ("Checkers"); DE names from the class docblocks ("Mühle", `Dame` on German pages) | master |
**Left out for lack of a source I could read:** "in cups and tournaments" (commit 8c1a8906 says so, but the
lobby only renders `<x-tournaments.cup-mentions :game="$slug" />` for cups that exist, and
`reels.md` 12 lists only four games with automatic cups); "guests can watch without login" (see 26); the
rated search on the lobby page (season/mining wording, off limits).

## Added 2026-09-29 — mempool, on the stream, tournaments live (motifs 11-13)

Written by the design lead (not the kommunikator), checked claim by claim against master `8021940e`
and, where a number is shown, against prod's public pages read on 2026-09-29. **The user lifted the
"nothing about the season" rule for these motifs** (2026-09-29, the /matches mempool strip with the
"real" version once a season runs); every season claim stays conditional ("while a season runs",
"once a season runs"), with no date and no countdown.

| # | Claim | Source | Live? |
|---|---|---|---|
| 28 | /matches shows a strip titled "Mempool": matches of every game, finished on the left (newest next to the divider), running and scheduled on the right; casual and rated | `app/Support/Matches/MempoolStrip.php` docblock ("played on the left and waiting on the right, casual and rated"); `components/block-strip.blade.php`; prod /matches 2026-09-29 shows it (chess and Rocket League only, board games off) | prod |
| 29 | Every cube shows its game by colour and logo, avatars (players) or clan logos under it; casual cubes are desaturated and carry "casual"; a running cube fills up with the game (ply / expected ply) and says "move n" | `block-strip.blade.php` docblock; `app.css` `.bs-cube.is-casual { filter: saturate(0.3) }`; `MatchBlocks::oneVsOne()` (`level`, `move :n`, expected ply 80 chess, 60 board games) | prod |
| 30 | The strip's lead line: "Rated wins mine blocks only while a season runs." (off season) / "A fair rated win mines a block of the season chain." (season live) | `pages/matches/⚡index.blade.php` (`$strip['live']`), `MempoolStrip::build()` `live` = `Seasons::isLive()`; prod shows the off-season line | prod |
| 31 | A finished rated match that mined carries its block ("Block 812") under its cube, the lid turns orange; casual matches have no stamp and never mine | `ChainStamps.php` (stamp only from a `SeasonAttestation` with a height; "Before Block 0 there are no attestations"); `app.css` `a.bs-cube.is-fin.is-mined { --top: var(--color-btc) }`; `config/season.php` `casual` ("never counted for badges, the season chain, rewards") | master, needs a running season |
| 32 | The first block a season mines is Block 1; Block 1 follows Block 0 | `pages/⚡mining.blade.php` ("No block yet. The first fair rated win mines block 1.", "Block 1 follows Block 0") | prod |
| 33 | Reward per block: per winning player, halves every era, paid once after the season review. **Figure:** prod /mining 2026-09-29, "Rocket League 1v1 win pays 2 100 sats per winning player, era 1", under "The numbers below are the Pre-Season draft. The board can still change them before it releases Block 0." Same as `config/season.php` `chain.subsidy` 2 100 at weight 1000 (`SeasonParameters::rewardPerPlayer`). Rated chess and rated board games "not open", so the sample block is a Rocket League 1v1 | `pages/⚡mining.blade.php` lead and tiles; `SeasonParameters.php` docblock (`reward/player = floor(subsidy * w_milli / (1000 * 2^(n - 1)))`); prod /mining | draft: the figure appears only in reel 15 beat 4, labelled "draft, not released" (`lang/de.json`), never in a caption, poster or post |
| 34 | The stream runs 24/7 | `config/twentyone.php` (`about`: "This channel streams 24/7"); `lang/de.json` "…around the clock" (pages/⚡live) | prod |
| 35 | The stream shows the latest win: the winner's avatar with a crown, "LATEST WIN · GG", "beat :name", the casual Elo change | `resources/views/stream/rotation/e1-win.blade.php`; `PrideSlides.php` (`win`) | master |
| 36 | The stream shows "Climbers of the week", the three biggest casual chess Elo gains of 7 days | `e2-climbers.blade.php`; `PrideSlides.php` (`climbers`) | master |
| 37 | Casual Elo numbers shown: +20 (one win), +39 (two), +57 (three), each against a new player at 1000 | `App\Support\Rating\EloRating` with `season.casual` (start 1000, provisional K 40), replayed 2026-09-29 | computed |
| 38 | The stream text is English only, in both cuts | plan `2026-09-29T2215-stream-slides-stolz-und-turniere` ("Stream-Texte sind nur Englisch") | — |
| 39 | A season-block slide and a tournament-run slide on the stream | plan `2026-09-29T2215-stream-slides-stolz-und-turniere` P3 (Season-Block) and P5 (Turnier-Slides) | **planned, not on master**: do not post `onstream` before they air |
| 40 | Tournaments are on the stream today: sign-up hero and bracket preview ("Who plays", "Your spot?") | `stream/rotation/ta1-hero`, `ta2-bracket`; `TournamentSlides.php` | master |
| 41 | Live bracket with results, champion moment, next tournament slide | plan P5 ("laufend (echtes Bracket mit Ergebnissen …), beendet (Champion …) + FOMO-Slide") | **planned, not on master**: do not post `livecup` before it airs |
| 42 | Sign-up with the Nostr key | `lang/de.json` "Confirm with your Nostr key. You can pull out until sign-up closes." (fact 10, reel 7) | prod |
| 43 | Tournament games: chess, Rocket League, EA Sports FC | reels 10-12 sources (tournaments 1, 2 and the casual cups, live system 2026-09-28) | prod |
| 44 | Players never pay anything to play or sign up; the copy names no fee of any kind | user rule (memory `no-fees-ever`) | rule |

**Left out:** any date or countdown for Block 0; any claim that a season runs now; the supply
("not announced yet" on prod); pots of future tournaments; payouts to named players (none exist).

## Rewritten 2026-10-01 — Age of Empires II (motif 14): the lobby tournament

Written by the design lead against master `82efb81a` and the plan text. The user changed the
AoE2 tournament format on 2026-10-01 (plan step 10, phase P10); **P10 was being built when
this was written, so every claim marked "plan" is the plan's text, not a shipped feature.
Post the motif only once P10 is live on prod**, and re-read the claims against the built
feature first (the lobby card's strings are pending in `check-ui-strings.mjs` until then).
The plan file is gitignored (plans stay local): `docs/plans/2026-09-28T2048-aoe2-und-trackmania.md` "Schritt 10 — AoE2-Lobby-Turnier", in the main checkout.

Facts 45-52 and 54-57 described the motif's first cut (casual matchmaking, the cup final,
commit `585b8ca0`); they were retired with this rewrite and are in the git history. Facts
53, 58, 59 and 60 still hold and keep their numbers. Pseudonymous throughout: players are
their pixel avatar and their name (user, 2026-10-01).

| # | Claim | Source | Live? |
|---|---|---|---|
| 53 | **Not built:** checking a result against the game's own match history. The copy never claims an automatic check: the result is a screenshot, confirmed by the tournament director (fact 74) | `app/Games/AgeOfEmpires2.php` docblock ("checking the result against the game's match history comes with a later phase") | not built |
| 58 | **Not claimed:** mining. AoE2 mines only once the board takes up its draft proposal; the copy says nothing about blocks, rewards or the season | `config/season.php` `chain.age_of_empires_2_proposal` ("DRAFT values … AoE2 mines only once the board fills them in") | draft |
| 59 | The game's cover art, used cropped to its logo on the marble band (no painted characters in the frame); the square poster shows only the tag strip | `AgeOfEmpires2::assets()` `GameCover('age-of-empires-2', [480, 800])`; `public/images/games/age-of-empires-2-800.jpg` | prod |
| 60 | The game's colour and mark: fuchsia family `#F0ABFC` / `#D946EF` / `#86198F` / `#701A75`, the castle keep icon | `resources/css/app.css` (`--color-aoe`, `-aoe-2`, `-aoe-deep`, `-aoe-deep-2`); `resources/views/components/icon.blade.php` (`'castle'`) | prod |
| 67 | An AoE2 tournament runs only as Free for All in **one round**: everyone signed up plays in lobbies of at most 8, split evenly (9 → 5 + 4, 17 → 6 + 6 + 5); each lobby is one match, no further round | plan ("AoE2-Turniere laufen nur im Format `free-for-all` als **eine Runde**: alle Angemeldeten in Lobbys zu je höchstens 8 (AoE2-Lobby-Maximum), ausgeglichen geteilt (9 → 5+4, 17 → 6+6+5); jede Lobby ist ein Match, keine weitere Runde."); the format exists in code as `TournamentFormat::FreeForAll` (`app/Enums/TournamentFormat.php`, label "Free for All") | plan (P10) |
| 68 | At least 3 sign-ups; no cap on the field (several lobbies in parallel), so "3 to 8 players a lobby" | plan ("Mindestens 3 Anmeldungen; Kapazität frei (mehrere Lobbys parallel).") | plan (P10) |
| 69 | A diplomacy game: Lock Teams off, Allied Victory on; everyone starts alone, an odd count is no special case | plan ("Diplomatie-Partie: „Lock Teams“ aus, „Allied Victory“ an … jeder startet allein — ungerade Anzahl ist kein Sonderfall.") | plan (P10) |
| 70 | Victory condition Time Limit, 2 h; after that the in-game score decides | plan ("Siegbedingung „Time Limit“ 2 h (danach Punktestand im Spiel)") | plan (P10) |
| 71 | The map size follows the lobby size: 2 Tiny, 3 Small, 4 Medium, 5-6 Normal, 7-8 Large; fixed per lobby at the draw. The hero's lobby of six shows Normal | plan ("Lobby-Daten dynamisch je Lobby-Größe, zur Auslosung festgeschrieben: Kartengröße nach Spielerzahl (2 Tiny, 3 Small, 4 Medium, 5–6 Normal, 7–8 Large)") | plan (P10) |
| 72 | Map Arabia, free civilisations, population 200, spectator delay 2 min (a restart in the first 5 min is also in the plan, not shown) | plan (same line: "Karte Arabia, freie Völker, Bevölkerung 200, Zuschauer-Verzögerung 2 min, Neustart in den ersten 5 min.") | plan (P10) |
| 73 | Several players can share 1st place (the allied survivors; with several lobbies, every lobby's winners, fact 78); the others rank by the order they went out (across lobbies grouped by that place number, fact 78); the place-1 pot is split equally among them. A pot exists only where the tournament has one (pots are per tournament, `app/Support/Prizes/PrizePool.php`); "its pot" names no amount | plan ("Platz 1 können mehrere teilen (die verbündeten Überlebenden), übrige nach Reihenfolge des Ausscheidens; Pot für Platz 1 zu gleichen Teilen."); today's payout already splits tied places (`lang/de.json` "Tied places share their percentages.") | plan (P10) |
| 74 | The result is reported with a screenshot of the end screen and confirmed by the tournament director | plan ("Meldung mit Endbildschirm-Screenshot, Bestätigung durch die Turnierleitung."); "Tournament directors" / "Turnierleitung" is the app's term (`lang/de.json`) | plan (P10) |
| 75 | The hero's mock-up strings "Free for All", "1st place" / "Platz 1", "Map" / "Karte", "players" / "Spieler" are the app's own | `lang/de.json` (checked by `check-ui-strings.mjs`) | prod |
| 76 | The lobby card's other strings ("Lobby settings", "Map size", "Lock Teams", "Allied Victory", the map sizes, …) follow the plan's wording; they are tagged `plan: 'P10'` in `src/lib/ui-strings.js` and listed as pending by `check-ui-strings.mjs` until `lang/de.json` carries them (`UI_STRICT=1` fails on them) | `src/lib/ui-strings.js`; plan lines of facts 69-72 | plan (P10) |

Players in the kit (satsjaeger and hodlqueen allied, kai_blitz, zap_zoe, taproot_tim and
orange_olga out) are the kit's factory names with their pixel avatars, not people. **Left
out:** any automatic result check (53); mining or rewards (58); any Elo or ladder effect (a
lobby result carries none, fact 79); the casual matchmaking and the weekend cup, which are not
this motif's subject (the weekend cup becomes a lobby cup with P10, facts 61 and 77, shown in
reel 12); players never pay to sign up (fact 44).

## Added 2026-10-01 — the casual cups on the weekend (reel 12, rewritten)

Checked against master `96d2cd41` and prod `/tournaments` read 2026-10-01 (all fourteen cups
listed, each "#1", 0 of 4 spots). Replaces the reel-12 sources of 2026-09-28 (four games on
Saturday evening), stale since `beabfc89`.

| # | Claim | Source | Live? |
|---|---|---|---|
| 61 | The league runs casual cups for seven games: chess (blitz), Rocket League 1v1, EA FC 26 1v1, EA FC 27 1v1, AoE2 as a lobby cup (Free for All, one round, fact 77), nine men's morris (blitz), checkers (blitz); the board game cups only while their game is on | `config/esports.php` `casual_cups.enabled` default and `casual_cups.games` ("a cup runs only while the board game is switched on"); prod `/tournaments`: Chess, Rocket League, EA FC 26, EA FC 27, AoE2, Nine Men's Morris and Checkers Casual Cup, EU #1 and US #1 each. AoE2's format: coordinator 2026-10-01, "the AoE2 weekend casual cup switches to the lobby format with P10, and the migration converts the open cups" | prod; AoE2's format plan (P10) |
| 62 | Weekend slots: Friday 18:00 EA FC 26, 20:00 EA FC 27; Saturday 15:00 nine men's morris, 20:00 chess and Rocket League; Sunday 15:00 checkers, 20:00 AoE2 | `casual_cups.games.*.slot` (`friday 18:00`, `friday 20:00`, `saturday 15:00`, `saturday 20:00` ×2, `sunday 15:00`, `sunday 20:00`); commit `beabfc89`; prod start times (e.g. "8:00 PM Sat, Oct 3, Berlin" chess EU, "3:00 PM Sun, Oct 4, New York" checkers US) | prod |
| 63 | One cup per game and region, EU and US, at the same local time (Europe/Berlin, America/New_York) | `casual_cups.regions`; config docblock ("an EU and a US cup of a game start at the same local time") | prod |
| 64 | A cup (except AoE2's, fact 77) opens with 4 places and grows to 8, then 16, whenever one place is left, until 60 minutes before sign-up closes | `casual_cups.sizes` `[4, 8, 16]`, `growth_freeze_minutes` 60; docblock ("whenever only one place is left (3/4, 7/8 ...) the league raises it to the next size") | prod |
| 65 | Except AoE2's cup (fact 77), the format is set at the start by how many play: 6 or more a double elimination, 2 to 5 one live evening (a match or a round robin) | docblock ("min_players or more a double elimination, 2 to min_players - 1 a small cup's live evening"), `min_players` 6; `lang/de.json` "The format is set at the start, by how many play." (shown on prod) | prod |
| 66 | No prizes; a cup series moves the casual Elo (not "no rating", as the old cut said); AoE2's lobby results move no Elo (fact 79) | (`TournamentRunTest` "an RL 1v1 tournament series reported by its players moves the two players' casual Elo"); casual cups set no pot | prod |

**Corrected:** the 2026-09-28 cut said "Four players, two losses and out" and "No rating". A
double elimination needs six players (fact 65), and cup results move the casual Elo (fact 66).

**Changed 2026-10-01 (P10):** AoE2's weekend cup becomes a lobby cup. P10's assumptions, from its
implementer, accepted by the coordinator on 2026-10-01 (not shipped when written, so marked plan):

| # | Claim | Source | Live? |
|---|---|---|---|
| 77 | An AoE2 lobby cup has a fixed capacity of 40 places (5 lobbies of 8) and does not grow; it is one round, each lobby one match (fact 67) | P10 implementer's assumption, accepted by the coordinator 2026-10-01; plan step 10 (fact 67) | plan (P10) |
| 78 | All lobby winners share tournament place 1; everyone else is grouped by their place number inside their lobby | P10 implementer's assumption, accepted by the coordinator 2026-10-01; plan step 10 ("Platz 1 können mehrere teilen …") | plan (P10) |
| 79 | Lobby results carry no Elo and no chain attestation | P10 implementer's assumption, accepted by the coordinator 2026-10-01 | plan (P10) |

**Posting gate for reel 12:** it now shows AoE2's cup as Free for All, so post it only once P10 is
live on prod, like motif 14.

## Corrected in round 2 — clan "lineup" is Rocket League only, not a chess feature

Round 1's clan copy said clans have a "Kader"/"lineup" and play chess "team matches
over 2 or 3 boards." Re-reading the actual clan pages while checking the logo
question shows that is not accurate for chess:

- `resources/views/pages/clans/⚡manage.blade.php:55` — `private const array MODES = ['3v3', '2v2', '1v1'];` with the comment "Rocket League modes in display order."
- `resources/views/pages/clans/⚡manage.blade.php:548-554` (`editLineup`) filters `$lineup->game === 'rocket-league'` explicitly.
- `resources/views/pages/clans/⚡show.blade.php:90-100` (`lineups()`) is commented "RL lineups in mode order" and filters `where('game', 'rocket-league')`.
- Same file, `:266-271`: "Clan Rating · chess" is "Average of the 3 best solo Elos. Chess has no separate team Elo" — and it needs rated blitz Elo, which needs Block 0 (`:282`: "Clan Ratings start at Block 0, with the first rated blitz games").
- Same file, `:337`: the Elo-over-time chart is explicitly "Rocket League 3v3 lineup, per rated series" — not chess.

So: the only live, chess-relevant, pre-Block0 clan features are founding a clan,
uploading its logo afterward, and building its roster (invite / join link). There
is no live chess "team match over N boards" UI to advertise, and "Lineup" as a
noun describes the Rocket League feature specifically — using it for a chess clan
motif would (re-)introduce a Rocket League concept by implication, which the zero-RL
rule forbids regardless of vocabulary. `posters.md`/`reels.md`/`posts.md` no longer
claim a chess lineup or a board-count team format for clans.

## Checked and deliberately left OUT

| Claim | Why it is not in the copy |
|---|---|
| Rank badges, share cards, "rank up" cards | Coordinator: arrive only with the rated season. `routes/web.php:159-192` shows the code paths exist, but nothing is awarded pre-Block 0. |
| Any mention of mining, blocks, block rewards, halving, the season chain (motifs 1-10; lifted for motifs 11-13 on 2026-09-29, see facts 28-33) | Coordinator, verbatim (2026-09-26 correction): "kein Mining, weil die Season noch gar nicht läuft." `routes/web.php:107-108` (`mining`) is a "rest state before Block 0" — a placeholder. |
| The rated ladder / rated Elo / official ranking / Clan Rating / Clan Hashrate | `app/Providers/AppServiceProvider.php:63-64`: rated play "stays closed" pre-season. `resources/views/pages/clans/⚡show.blade.php:282,309`: Clan Rating and Hashrate both start at Block 0. |
| "Money on the street", any pot size, any sats-earned claim | Hard gate + coordinator correction; this is exactly what the old `docs/promo/src/build-gallery-manifest.mjs:66` ad titles did. |
| A countdown to Block 0 or the season start | Coordinator: "no countdown, no promises." At most one honest line ("the rated season starts later"), used once in `posts.md`, never as a headline. |
| Rocket League, and by extension the clan "lineup"/board-count team format | User rule: zero RL references. See the round-2 correction above — the only live lineup mechanic is RL's. |
| Chess team matches over 2/3 boards, as a clan feature | `app/Games/Chess.php:8-9` describes the result *schema* for a team match, but no live UI builds or plays one for chess (see correction above) — code existing ≠ a feature a player can use today. |

## Membership disclosure used throughout

Anyone can play after logging in with Nostr or Google — no membership required.
Opponents are "other players" / "andere Spieler" (or "Bitcoiner" for warmth), never
"other members" / "andere Mitglieder" (source: `resources/views/pages/auth/login.blade.php:56`).

## Terminology — German UI terms used as given (coordinator + design-lead check, round 2)

Confirmed by the coordinator against `lang/de.json`; I did not re-derive these
independently in this pass (no grep tool available to me here), but I did verify
the clan/lineup facts above independently once I was back in the clan views.

- **Fernschach**, not "Daily-Schach"
- **Einladungslink**, not "Invite-Link"
- **Brett** for a chess board
- **Casual-Ladder** (kept as the UI's own label — Denglisch by design, used only as
  a label; surrounding prose stays plain German, e.g. "läuft schon jetzt, nur zum Spaß")
- **Lineup** — confirmed as a real UI term, but not used in this copy: it names the
  Rocket League roster mechanic specifically (see the round-2 clan correction
  above), and using it for chess clans would misrepresent a feature that doesn't
  exist. Flagging this explicitly rather than silently dropping it.
