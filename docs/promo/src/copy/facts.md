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

## Added 2026-10-01 — Age of Empires II (motif 14)

Written by the design lead, checked claim by claim against master `96d2cd41` (Age of Empires II
added in `813bf8f2`, its cup slot in `beabfc89`) and against prod's public pages read on
2026-10-01: `/games/age-of-empires-2` answers 200 and shows "1v1 2v2 3v3 BO1/3" and "Play alone
or with your clan. Every series moves your Elo."; `/tournaments` lists "AoE2 Casual Cup EU #1"
(Sun 4 Oct, 8:00 PM Berlin, 0 of 4 spots) and "AoE2 Casual Cup US #1" (starts
`2026-10-05T00:00:00Z` = Sun 4 Oct, 8:00 PM New York). Pseudonymous throughout: players are
their Nostr avatar and name, never a face (user, 2026-10-01).

| # | Claim | Source | Live? |
|---|---|---|---|
| 45 | Age of Empires II: Definitive Edition ("AoE2") is a series game: 1v1 is a player ladder, 2v2 and 3v3 are clan lineups; best of 1 or 3 | `app/Games/AgeOfEmpires2.php` docblock ("1v1 is a player ladder … 2v2 and 3v3 are clan lineups") and `modes()` (`[1, 3]`, `'player' : 'lineup'`); prod game page "1v1 2v2 3v3 BO1/3" | prod |
| 46 | A game has a winner and no score: each game is entered by its winner only, the series counts games won | `AgeOfEmpires2::hasGoals()` (false); `SeriesGame::validateResult()` refuses goals; `lang/de.json` "winner per game" / "Sieger je Spiel", ":count games won" / ":count Spiele gewonnen" | prod |
| 47 | AoE2 is in the casual 1v1 (queue or invite), played best of 1 | `config/esports.php` `casual.games` (`'age-of-empires-2'`); `app/Support/Series/CasualMatches.php` (`'best_of' => … min($mode->bestOf)`); prod game page "searching 1v1" | prod |
| 48 | The host is drawn, hosts a lobby in the game with a password and spectators allowed, and shares its name and password as a card in the encrypted match chat; the league never sees it | `resources/views/pages/matches/partials/casual-steps.blade.php` ("Host a lobby in Age of Empires II with a password and spectators allowed, then share its name and password in the chat."); `lang/de.json` "The host is drawn. The host shares the lobby (Rocket League, Age of Empires II) … in the encrypted match chat. The league never sees it."; card title "Age of Empires II lobby" / "Age-of-Empires-II-Lobby" (`⚡room.blade.php` `lobbyTitle`) | prod |
| 49 | The lobby card comes pre-filled with the name `e21-<match number>` and a fresh six-character password for every new lobby | `pages/matches/⚡room.blade.php` (`'lobbyName' => 'e21-'.$match->number`); `resources/js/roomChat.js` `openComposer()` ("A fresh password for every new lobby"); `resources/js/lobbyCards.js` `randomPassword(length = 6)`, alphabet `abcdefghjkmnpqrstuvwxyz23456789` (the kit's `k7m2px` uses only these) | prod |
| 50 | The guest finds the lobby by its name in the game's lobby browser, joins with the password, then confirms "I am in the lobby" | `casual-steps.blade.php` ("Find the lobby by its name in the lobby browser, join with the password from the card in the chat, then confirm here.", button "I am in the lobby") | prod |
| 51 | Either player may send their Steam or Xbox name as a card, only on "Send card" | `card-composer.blade.php` (Steam / Xbox select, "Your name goes to your opponent only, so you find each other in the game. The league never sees it."); `lobbyCards.js` `ACCOUNT_CARDS['age-of-empires-2'] = ['steam', 'xbox']` | prod |
| 52 | The steps of a casual 1v1: Ready, Lobby shared, Joined, Result (either player), Confirmed (the other player); a report nobody answers is confirmed by the league | `casual-steps.blade.php` step list (`'Ready'`, `'Lobby shared'`, `'Joined'`, `'Result'` "either player", `'Confirmed'` "the other player"); `lang/de.json` "Nobody reports: the match is void. A report nobody answers is confirmed by the league." | prod |
| 53 | **Not built:** checking a result against the game's own match history. The copy never claims an automatic check; players report and confirm | `AgeOfEmpires2.php` docblock ("checking the result against the game's match history comes with a later phase") | not built |
| 54 | Every series moves your Elo; before the season it is the casual Elo (start 1000, provisional K 40): a first win against a new player is +20 | prod game page "Play alone or with your clan. Every series moves your Elo."; `config/season.php` `casual` ("A second Elo per game and mode for casual play, the same arithmetic"); +20 as fact 37. The reel's cup final moving the 1v1 ladder rests on `app/Support/Rating/RatingService.php` docblock ("A tournament series between two single players … rates the two players", "A casual one goes to the permanent casual ladder"), read, not replayed | prod |
| 55 | The AoE2 Casual Cup runs per region, EU and US, and starts on Sunday at 20:00 on its region's clock (Europe/Berlin, America/New_York); rounds best of 1, the final best of 3 | `config/esports.php` `casual_cups.games['age-of-empires-2']` (`'best_of' => 1, 'final_best_of' => 3, 'slot' => ['weekday' => 'sunday', 'time' => '20:00']`), `casual_cups.regions`; commit `beabfc89`; prod `/tournaments` (EU #1 and US #1, above) | prod |
| 56 | AoE2 can be picked for a tournament: planned at about 21 min a game, best-of-1 rounds and a best-of-3 final | `app/Support/Tournaments/GameProfile.php` (`'age-of-empires-2/1v1', … => new self(…, 21, 5, 5, 1, 3, [1, 3], …)`, "planned at about 21 min a game … Bo1 rounds and a Bo3 final") | prod |
| 57 | Clans play AoE2 as 2v2 and 3v3 lineups; the clan page lists the lineups of every series game | `AgeOfEmpires2::modes()`; commit `813bf8f2` ("Clan page, clan list and clan invite list the lineups of every series game, not only Rocket League's") | prod |
| 58 | **Not claimed:** mining. AoE2 mines only once the board takes up its draft proposal; the copy says nothing about blocks, rewards or the season | `config/season.php` `chain.age_of_empires_2_proposal` ("DRAFT values … AoE2 mines only once the board fills them in") | draft |
| 59 | The game's cover art, used cropped to its logo on the marble band (no painted characters in the frame) | `AgeOfEmpires2::assets()` `GameCover('age-of-empires-2', [480, 800])`; `public/images/games/age-of-empires-2-800.jpg` (read by the kit from the app, as the upcoming-tournament reels do) | prod |
| 60 | The game's colour and mark: fuchsia family `#F0ABFC` / `#D946EF` / `#86198F` / `#701A75`, the castle keep icon | `resources/css/app.css` (`--color-aoe`, `-aoe-2`, `-aoe-deep`, `-aoe-deep-2`); `resources/views/components/icon.blade.php` (`'castle'`) | prod |

Players and the sample match in the kit (satsjaeger 2 : 1 kai_blitz, match #58) are the kit's
factory names with their pixel avatars, not people. **Left out:** any automatic result check
(53); mining or rewards for AoE2 (58); the 24/7 stream's latest-win slide, which covers series
games (`PrideSlides::win()`), because it is the `onstream` motif's claim and is on master only
(fact 35); a fixed cup date (the posters say "on Sundays", the date goes stale).

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
