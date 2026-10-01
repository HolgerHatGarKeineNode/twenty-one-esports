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

Written by the design lead against master `82efb81a` and the plan text, then re-sourced on
2026-10-01 against the built feature: P10 branch `210edd80` (worktree `agent-a1fa5f230f4c56867`, not merged to
master when written, not on prod). Every claim below now cites that code; the lobby card's
strings are the app's own (`lang/de.json` on that branch, checked by `check-ui-strings.mjs`
with `UI_LANG_JSON` until the branch is merged). **Post the motif only once P10 is live on
prod.** The plan (`docs/plans/2026-09-28T2048-aoe2-und-trackmania.md`, "Schritt 10", local
only) set the format; the code below is what was built from it.

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
| 67 | An AoE2 tournament runs only as Free for All in **one round**: every entry plays one match in a lobby of at most 8, split evenly over the fewest lobbies (9 → 5 + 4, 17 → 6 + 6 + 5); nobody advances | `app/Support/Tournaments/Lobbies.php` docblock ("plays its tournaments only as Free for All in one round … the draw splits the entries evenly over the fewest lobbies (9 = 5 + 4, 17 = 6 + 6 + 5) and nobody advances"), `split()`; `config/esports.php` `series.lobby_rules.age-of-empires-2.lobby` `max_players` 8 | P10 branch `210edd80` |
| 68 | At least 3 entries, else the tournament is called off; "3 to 8 players a lobby" | `app/Support/Tournaments/Lobbies.php` `minEntries()`; `config/esports.php` `series.lobby_rules.age-of-empires-2.lobby` `min_entries` 3; rules line "every player is in one lobby of :min to :max" | P10 branch `210edd80` |
| 69 | A diplomacy game: everyone starts alone, Lock Teams off ("Teams sperren": Aus), Allied Victory on ("Bündnissieg": An) | `app/Support/Tournaments/Lobbies.php` `rules()` ("A diplomacy game: everyone starts alone, Lock Teams off, Allied Victory on."), `facts()`; `config/esports.php` `series.lobby_rules.age-of-empires-2.lobby` `lock_teams` false, `allied_victory` true; `lang/de.json` "Lock Teams" → "Teams sperren", "Allied Victory" → "Bündnissieg" | P10 branch `210edd80` |
| 70 | Victory: Time Limit, 2 hours; allies still standing when it ends share place 1 | `app/Support/Tournaments/Lobbies.php` `rules()` ("Victory: Time Limit, :time. Allies still standing when it ends … share place 1"), `facts()` ("Time Limit, :time"); `config/esports.php` `series.lobby_rules.age-of-empires-2.lobby` `time_limit_minutes` 120. The plan's "then the in-game score" is not in the code, so the copy does not say it | P10 branch `210edd80` |
| 71 | The map size follows the lobby's players: 2 Tiny, 3 Small, 4 Medium, 5-6 Normal, 7-8 Large (DE: Winzig, Klein, Mittel, Normal, Groß), fixed at the draw; a lobby of six plays Normal | `config/esports.php` `series.lobby_rules.age-of-empires-2.lobby` `map_sizes`; `app/Support/Tournaments/Lobbies.php` `mapSize()`, `mapSizesLine()`; `lang/de.json` "Tiny" → "Winzig" … "Large" → "Groß" | P10 branch `210edd80` |
| 72 | Map Arabia, civilisations free pick, population 200, spectator delay 2 minutes, restart once in the first 5 minutes | `config/esports.php` `series.lobby_rules.age-of-empires-2.lobby` (`map`, `civilizations` free, `population`, `spectator_delay_minutes` 2, `restarts` 1, `restart_minutes` 5); `app/Support/Tournaments/Lobbies.php` `facts()` | P10 branch `210edd80` |
| 73 | Several players share place 1 (the allies still standing); the others rank by the order they were defeated, the first one out last; the card shows "#1" for each and "Shared place 1: …"; a shared place 1 splits its prize equally (a pot exists only where the tournament has one, `app/Support/Prizes/PrizePool.php`) | `app/Support/Tournaments/Lobbies.php` `rules()` ("… share place 1; everyone else ranks by the order they were defeated", "A shared place 1 splits its prize equally."); `resources/views/components/⚡tournament-lobbies.blade.php` ("Place 1 for every ally still standing at the end; everyone else by the order they were defeated, the first one out last."); `app/Support/Tournaments/LobbyResults.php` ("Shared place 1: :names") | P10 branch `210edd80` |
| 74 | One player reports the places with a screenshot of the end screen; a tournament director confirms (the card reads "reported, waiting for a director", then "decided") | `app/Support/Tournaments/Lobbies.php` `rules()` ("One player reports the places with a screenshot of the end screen, within :time after the time limit; a tournament director confirms."); `resources/views/components/⚡tournament-lobbies.blade.php` status chips; `app/Support/Tournaments/LobbyResults.php` docblock | P10 branch `210edd80` |
| 75 | The hero's and the reels' mock-up strings are the lobby card's own, EN and DE ("Lobby :number", ":count players", "Map size", "Lock Teams"/"Teams sperren", "Allied Victory"/"Bündnissieg", "Victory"/"Sieg", "Time Limit, :time", "Spectator delay", "Restart", "Shared place 1: :names", "Screenshot of the end screen", "decided" …); "Arabia" and "200" are config values the card prints as they are | `src/lib/ui-strings.js` (no entry is tagged `plan` any more); `lang/de.json` on P10 branch `210edd80`; `resources/views/components/⚡tournament-lobbies.blade.php`; `app/Support/Tournaments/Lobbies.php` `facts()` | P10 branch `210edd80` |
| 76 | The league names each lobby and sets its password at the draw; both show only to the lobby's players and the directors (not drawn on the posters) | `app/Support/Tournaments/Lobbies.php` `rules()`, `name()`, `password()`; `resources/views/components/⚡tournament-lobbies.blade.php` ("Only this lobby's players and the directors see this. It is never published.") | P10 branch `210edd80` |

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
| 61 | The league runs casual cups for seven games: chess (blitz), Rocket League 1v1, EA FC 26 1v1, EA FC 27 1v1, AoE2 as a lobby cup (Free for All, one round, fact 77), nine men's morris (blitz), checkers (blitz); the board game cups only while their game is on | `config/esports.php` `casual_cups.enabled` default and `casual_cups.games` ("a cup runs only while the board game is switched on"); prod `/tournaments`: Chess, Rocket League, EA FC 26, EA FC 27, AoE2, Nine Men's Morris and Checkers Casual Cup, EU #1 and US #1 each. AoE2's format: `app/Support/Tournaments/CasualCups.php` on P10 branch `210edd80` (fact 77) | prod; AoE2's format P10 branch `210edd80` |
| 62 | Weekend slots: Friday 18:00 EA FC 26, 20:00 EA FC 27; Saturday 15:00 nine men's morris, 20:00 chess and Rocket League; Sunday 15:00 checkers, 20:00 AoE2 | `casual_cups.games.*.slot` (`friday 18:00`, `friday 20:00`, `saturday 15:00`, `saturday 20:00` ×2, `sunday 15:00`, `sunday 20:00`); commit `beabfc89`; prod start times (e.g. "8:00 PM Sat, Oct 3, Berlin" chess EU, "3:00 PM Sun, Oct 4, New York" checkers US) | prod |
| 63 | One cup per game and region, EU and US, at the same local time (Europe/Berlin, America/New_York) | `casual_cups.regions`; config docblock ("an EU and a US cup of a game start at the same local time") | prod |
| 64 | A cup (except AoE2's, fact 77) opens with 4 places and grows to 8, then 16, whenever one place is left, until 60 minutes before sign-up closes | `casual_cups.sizes` `[4, 8, 16]`, `growth_freeze_minutes` 60; docblock ("whenever only one place is left (3/4, 7/8 ...) the league raises it to the next size") | prod |
| 65 | Except AoE2's cup (fact 77), the format is set at the start by how many play: 6 or more a double elimination, 2 to 5 one live evening (a match or a round robin) | docblock ("min_players or more a double elimination, 2 to min_players - 1 a small cup's live evening"), `min_players` 6; `lang/de.json` "The format is set at the start, by how many play." (shown on prod) | prod |
| 66 | No prizes; a cup series moves the casual Elo (not "no rating", as the old cut said); AoE2's lobby results move no Elo (fact 79) | (`TournamentRunTest` "an RL 1v1 tournament series reported by its players moves the two players' casual Elo"); casual cups set no pot | prod |

**Corrected:** the 2026-09-28 cut said "Four players, two losses and out" and "No rating". A
double elimination needs six players (fact 65), and cup results move the casual Elo (fact 66).

**Changed 2026-10-01 (P10):** AoE2's weekend cup becomes a lobby cup. First recorded as P10's
assumptions, then re-sourced against the built code on its branch (`210edd80`, not on prod):

| # | Claim | Source | Live? |
|---|---|---|---|
| 77 | An AoE2 casual cup is a lobby cup from the start: Free for All in one round, 40 places (5 lobbies of 8), no growth; the open cups are converted | `app/Support/Tournaments/CasualCups.php` docblock ("a lobby game's cup (Age of Empires II) is Free for All in one round from the start, with Lobbies::cupCapacity() places and no growth"), `'capacity' => $lobby ? Lobbies::cupCapacity($game) …`; `app/Support/Tournaments/Lobbies.php` `cupCapacity()` ("5 lobbies of 8 by default"); `config/esports.php` `series.lobby_rules.age-of-empires-2.lobby` `cup_capacity` 40; conversion: coordinator 2026-10-01 | P10 branch `210edd80` |
| 78 | With several lobbies, every lobby's winners are place 1 together, then the next place number of any lobby, and so on | `app/Support/Payouts/TournamentPlacements.php` docblock ("every lobby's winners are place 1 together, then the next place number of any lobby") | P10 branch `210edd80` |
| 79 | Lobby results move no Elo and are not attested on the chain | `app/Support/Tournaments/LobbyResults.php` docblock ("Lobby results move no Elo and are not attested: a rating is between two sides.") | P10 branch `210edd80` |

**Posting gate for reel 12:** it now shows AoE2's cup as Free for All, so post it only once P10 is
live on prod, like motif 14.

**Added 2026-10-01: Blockfill (motifs 15-16, reel 19).** Live on prod since 2026-10-01
(esports.einundzwanzig.space/blockfill read that day, HTTP 200, the week board shown). Sources
are master `8dfce13a`.

| # | Claim | Source | Live? |
|---|---|---|---|
| 80 | Blockfill is the league's own game: mine 40 blocks (clear 40 rows) as fast as you can | `resources/views/pages/stacker/⚡play.blade.php` docblock ("Blockfill, the league's own stacking game … mine 40 blocks (clear 40 rows) as fast as you can"), string "Mine 40 blocks as fast as you can." / "Schürfe 40 Blöcke so schnell du kannst."; `resources/js/stacker/engine.js` `GOAL_LINES = 40` | LIVE |
| 81 | A full row is a mined block: it flashes as one, "+N blocks mined" shows beside the well, and the chain under it has one cube per mined block out of 40 | `resources/js/stacker/renderer.js` `drawWell` ("lights the `mined` lowest rows as freshly mined blocks"); play blade ("The chain: one cube per mined block", "blocks mined") | LIVE |
| 82 | Practice is open to everyone, guests included, no login; a ranked run needs a login and a keyboard | play blade docblock ("Practice for everyone, guests included … ranked runs need a login"); strings "Practice needs no login. Log in for ranked runs.", "Ranked runs need a keyboard. Here you can practise with touch." | LIVE |
| 83 | The league replays every ranked run's inputs on the same engine; it counts once it reaches the same time | `resources/js/stacker/verify.mjs` docblock; string "A ranked run counts once the league has replayed its inputs and reached the same time."; "Verified: the league replayed your run to the same time" | LIVE |
| 84 | The weekly hunt: your best verified ranked run of the week counts, the fastest time wins, a tie goes to the earlier run, a new week starts every Monday at 00:00 Berlin time | string on /blockfill ("Your best verified ranked run of the week counts. The fastest time wins, a tie goes to the earlier run. A new week starts every Monday at 00:00 Berlin time."); `app/Support/Stacker/BlockfillWeeks.php` docblock | LIVE |
| 85 | The week's place 1 is "Last week's winner" on the page; a Blockfill week pays no zap and no prize: the copy says "wins the board", never sats | play blade (`lastWinner()`, "Last week's winner"); `app/Support/Lightning/WinnerZaps.php` ("A Blockfill week … is a game's weekly board, not a tournament won: no zap.") | LIVE |
| 86 | Each piece is coloured on the page's own fee-rate scale. The posters and the reel show the colours, not the legend: no "fee" in promo text, the league never charges players | `resources/js/stacker/palette.js` docblock; user rule "no fees, ever" | LIVE (legend left out on purpose) |
| 87 | The run on the posters and in reel 19: seed `b10cf111000000000000000021e5b007`, engine bf1, default handling (DAS 10, ARR 2, SDF 20), 875 inputs, 2109 ticks = 0:35.150, 111 pieces, 40 rows in ten clears of four. Played by the kit's placement bot at a human pace (3.16 pieces per second), not by a player; it is on no board. `verify.mjs` with the limits of `config/esports.php` answers ok (and refuses the same log with one move mirrored: `unfinished`) | `src/gen-blockfill-run.mjs`, `src/lib/blockfill.run.js` | generated 2026-10-01 |
| 88 | The week board poster shows the public board as /blockfill shows it (place, name, best verified time), fetched right before rendering; on 2026-10-01: week 28 Sep - 5 Oct 2026, one row, "El Presidento Ben" 5:16.500 | `src/fetch-blockfill-week.mjs` → `data/blockfill-week.js` | changes with every verified run |
| 89 | Blockfill is its own game in the mempool look: no other game's name, trademark or wording in the copy | user brief 2026-10-01 | rule |

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
