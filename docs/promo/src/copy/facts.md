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
| Any mention of mining, blocks, block rewards, halving, the season chain | Coordinator, verbatim (2026-09-26 correction): "kein Mining, weil die Season noch gar nicht läuft." `routes/web.php:107-108` (`mining`) is a "rest state before Block 0" — a placeholder. |
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
