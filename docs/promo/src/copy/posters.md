# Poster copy — 10 motifs, casual recruitment (round 2)

CTA on every poster: **esports.einundzwanzig.space**
Every claim here is checked against `facts.md`. Written under the round-2 rules (no
mining, season, ranks, pot figures or Rocket League; since 2026-09-28 only the season
is off limits, see README) — and, corrected in round 2: no clan "lineup"/board
count (that mechanic is Rocket League only), no clan logo at founding (it comes
after, on the manage page), no "against other members" (membership is not a play
requirement).

Screen references point at the filenames documented in
`docs/promo/src/build-gallery-manifest.mjs` (the `SCREEN_URL` map) — confirm current
file names in `assets/screens/{desktop,mobile,stream}` with the design lead before
rendering.

---

## 1. Nostr login

**DE Headline:** Anmelden ohne Passwort
**DE Subline:** Ein Klick mit deinem Nostr-Schlüssel oder Google-Konto — sofort spielbereit.
**DE Bullets:**
- Nostr-Erweiterung, Nostr-App oder Google
- Kein Passwort, kein Formular
- Jeder darf sofort spielen
- Mitgliedschaft ist keine Voraussetzung

**EN Headline:** No password. Just your key.
**EN Subline:** One tap with your Nostr key or Google account — you're straight in.
**EN Bullets:**
- Nostr extension, Nostr app, or Google
- No password, no signup form
- Anyone can play right away
- Membership is not required

**CTA:** esports.einundzwanzig.space
**Designer note:** No screen for `/login` exists in the current asset set — take a
fresh screenshot of the login screen (desktop + mobile), English UI.

---

## 2. Blitz chess, right now

**DE Headline:** Blitzschach — jetzt sofort spielen
**DE Subline:** 5+3 Minuten gegen andere Spieler, Casual-Ladder inklusive, kein Warten.
**DE Bullets:**
- Blitz 5+3 direkt im Browser
- Gegner in Sekunden über die Lobby
- Casual-Ladder zählt schon jetzt, zum Spaß
- Züge auf Deutsch notiert — K, D, T, L, S statt K, Q, R, B, N

**EN Headline:** Blitz chess. Play right now.
**EN Subline:** 5+3 minutes against other players, casual ladder included, no waiting around.
**EN Bullets:**
- Blitz 5+3, right in your browser
- Find an opponent in seconds
- Casual ladder counts already, just for fun
- Anyone can watch live, no login needed

**CTA:** esports.einundzwanzig.space
**Designer note:** `00-ChessLobby` (desktop/mobile) for the lobby moment, or
`22-Ladder` for the casual-ladder screen. The German piece-letter bullet is a nice
detail if the board crop shows a move list — otherwise drop it, it doesn't need a
poster of its own.

---

## 3. Fernschach

**DE Headline:** Ein Zug am Tag reicht
**DE Subline:** Fernschach per Korrespondenz, mit Nostr-DM, wenn dein Gegner gezogen hat.
**DE Bullets:**
- Ein Zug pro Tag, kein Zeitdruck
- Nostr-DM sagt dir, wenn du dran bist
- Fordere jeden Spieler direkt heraus
- Läuft nebenbei mit, zwischen Kaffee und Meeting

**EN Headline:** One move a day. Done.
**EN Subline:** Correspondence chess, with a Nostr DM whenever it's your turn.
**EN Bullets:**
- One move a day, zero time pressure
- A Nostr DM tells you when it's your move
- Challenge any player directly
- Fits between coffee and your next meeting

**CTA:** esports.einundzwanzig.space
**Designer note:** No dedicated screen for `chess/challenge` or `me/correspondence`
in the current asset set — use `01-ChessGame` as a generic board visual, or take a
fresh screenshot of the Fernschach challenge screen.

---

## 4. Clans

**DE Headline:** Gründe deinen Clan
**DE Subline:** Name, Tag und Logo — hol deine Mitspieler per Einladungslink dazu.
**DE Bullets:**
- Clan mit Namen und Tag gründen
- Logo hochladen, auf der Verwaltungsseite
- Mitspieler per Einladungslink dazuholen
- Jeder Clan hat seine eigene, öffentliche Seite

**EN Headline:** Start your clan
**EN Subline:** Name, tag and logo — bring in teammates with an invite link.
**EN Bullets:**
- Found a clan with a name and tag
- Upload a logo, on the clan's manage page
- Invite teammates with a join link
- Every clan gets its own public page

**CTA:** esports.einundzwanzig.space
**Designer note:** `23-Clans` (index) or `24-ClanShow`. Do **not** show a lineup or
board-count graphic here — that UI element is the Rocket League roster and would
misrepresent a chess clan. If the screen crop includes it, crop it out or pick a
screen state without a lineup.

---

## 5. Tournaments

**DE Headline:** Melde dich fürs Turnier an
**DE Subline:** Alle Formate, Anmeldung offen, in der Pre-Season schon aktiv.
**DE Bullets:**
- Jedes Turnierformat verfügbar
- Anmeldung direkt über die Seite
- Turnierleiter tragen Ergebnisse live ein
- Bracket und Auslosung öffentlich einsehbar

**EN Headline:** Sign up for a tournament
**EN Subline:** Every format, open sign-up, already running in the Pre-Season.
**EN Bullets:**
- Every tournament format supported
- Sign up right on the site
- Directors enter results live
- Bracket and draw, visible to everyone

**CTA:** esports.einundzwanzig.space
**Designer note:** `34-Tournaments` (list) or `35-TournamentShow` (bracket).

---

## 6. Watch live

**DE Headline:** Live zuschauen, ohne Login
**DE Subline:** Jede laufende Partie live verfolgen, Zug für Zug, auch als Gast.
**DE Bullets:**
- Alle laufenden Partien auf einer Seite
- Auch ohne eigenes Konto nutzbar
- Suche findet Spieler und Partien schnell
- Perfekt für eine kurze Pause

**EN Headline:** Watch live. No login.
**EN Subline:** Follow any live game, move by move, even as a guest.
**EN Bullets:**
- Every live game, one page
- Works without an account
- Search finds players and games fast
- Perfect for a quick break

**CTA:** esports.einundzwanzig.space
**Designer note:** `66-OpenMatches`, or `01-ChessGame` for a single live board.

---

## 7. Invite friends

**DE Headline:** Hol deine Freunde ins Spiel
**DE Subline:** Ein Einladungslink, kein Account nötig zum Ansehen — sofort startklar.
**DE Bullets:**
- Ein Einladungslink für Freunde
- Funktioniert auch ganz ohne Login
- Führt direkt zur Partie oder zum Clan
- Teilen über Signal, Nostr, überall

**EN Headline:** Bring your friends to play
**EN Subline:** One invite link, no account needed to look — ready in seconds.
**EN Bullets:**
- One invite link for your friends
- Works even without logging in
- Drops them straight into a game or clan
- Share it on Signal, Nostr, anywhere

**CTA:** esports.einundzwanzig.space
**Designer note:** the auto-generated invite-card preview at `/i/{code}` (wide and
square formats already exist per `InviteCardController`) — use a real invite
preview, not a mock.

---

## 8. Open source

**DE Headline:** Offener Code, offen für alle
**DE Subline:** Der komplette Quellcode liegt auf GitHub, verlinkt direkt im Footer.
**DE Bullets:**
- Quellcode öffentlich auf GitHub
- Verlinkt im Footer der Seite
- Passt zum Bitcoin-Grundgedanken: nicht vertrauen, nachprüfen
- Fehler gefunden? Ein Issue reicht

**EN Headline:** Open code, open to anyone
**EN Subline:** The full source lives on GitHub, linked right in the footer.
**EN Bullets:**
- Source code public on GitHub
- Linked in the site's footer
- On brand for Bitcoin: don't trust, verify
- Found a bug? Open an issue

**CTA:** esports.einundzwanzig.space
**Designer note:** no dedicated screen — use the GitHub mark plus a short code
snippet or the footer strip itself (`resources/views/components/shell/footer.blade.php`)
as the visual; this motif is optional (only render it if it earns a spot in the
week's rotation — it's the least casual-recruitment-relevant of the eight).

---

## 9. Mühle

**DE Headline:** Mühle — Blitz im Browser
**DE Subline:** Neun Steine, drei in einer Reihe: Blitz 5+3 live, jeder Zug vom Server geprüft.
**DE Bullets:**
- Setzen, ziehen, springen bei drei Steinen
- Drei in einer Reihe: Gegnerstein nehmen
- Blitz 5+3, Gegner über die Lobby
- Eigene Casual-Ladder für Mühle

**EN Headline:** Nine men's morris, live
**EN Subline:** Three in a row takes a man: blitz 5+3 live, every move checked by the server.
**EN Bullets:**
- Place, move, fly once you're at three
- A mill takes one of their men
- Blitz 5+3, find an opponent in the lobby
- Its own casual ladder

**CTA:** esports.einundzwanzig.space
**Designer note:** the Mühle board (24 points, three nested squares) with a mill
on one line; DE UI, English UI for the EN cut. Facts 17-21 in `facts.md`; feature is on master behind
`ESPORTS_BOARD_GAMES` (off on prod until switched on).

---

## 10. Dame

**DE Headline:** Dame nach deutschen Regeln
**DE Subline:** 8×8, Schlagzwang, fliegende Dame: Blitz 5+3 live, jeder Zug vom Server geprüft.
**DE Bullets:**
- Schlagzwang: wer schlagen kann, muss
- Steine schlagen auch rückwärts
- Schlagketten, Feld für Feld geklickt
- Die Dame fliegt über die Diagonale

**EN Headline:** Checkers, German rules
**EN Subline:** 8×8, capture is compulsory, kings fly: blitz 5+3 live, every move checked by the server.
**EN Bullets:**
- Capturing is compulsory
- Men capture backwards too
- Capture chains, clicked square by square
- Flying kings run the whole diagonal

**CTA:** esports.einundzwanzig.space
**Designer note:** 8×8 board with a capture in progress (a chain or a king on a long
diagonal). In the DE cut the king is called "Dame", in the EN cut "king". Facts 17-19 and
22-25 in `facts.md`; feature is on master behind `ESPORTS_BOARD_GAMES` (off on prod until switched on).

---

## 11. Mempool → Block

**DE Headline:** Erst Mempool, dann Block
**DE Subline:** Jede Partie wird ein Würfel, mit Avatar und Namen der Spieler. Läuft eine Season, schürft dein gewerteter Sieg einen Block.
**DE Bullets:**
- Schach, Mühle, Dame, Rocket League, EA FC
- Links: gespielt. Rechts: live und als Nächstes.
- Casual ist zum Spaß da und schürft nie
- Die Block-Belohnung halbiert sich jede Epoche

**EN Headline:** Mempool first. Then a block.
**EN Subline:** Every match becomes a cube, with the players' avatars and names on it. While a season runs, your rated win mines a block.
**EN Bullets:**
- Chess, morris, checkers, Rocket League, EA FC
- Left: played. Right: live and up next.
- Casual is for fun and never mines
- The block reward halves every era

**CTA:** esports.einundzwanzig.space
**Designer note:** the /matches mempool strip (`components/block-strip`) at hero scale: casual
cubes desaturated as in the app, one rated Rocket League 1v1 win with the orange lid and its
"Block 1" stamp, and the block card as /mining lists it. No reward figure on the poster (the
figure is the board's draft until Block 0, facts 28-33). Shows Mühle and Dame cubes: **post only
once the board game switches are on in prod.**

---

## 12. On the stream

**DE Headline:** Dein Sieg. Dein Name. Im Stream.
**DE Subline:** Der Livestream läuft rund um die Uhr und zeigt, wer gewinnt: deinen Sieg, deinen Aufstieg, deinen Block, deinen Turnierlauf.
**DE Bullets:**
- Dein letzter Sieg: dein Avatar mit Krone, deine Casual-Elo
- Die drei größten Aufsteiger der Woche
- Dein Block, sobald eine Season läuft
- Dein Weg durch den Turnierbaum, Runde für Runde

**EN Headline:** Your win. Your name. On stream.
**EN Subline:** The live stream runs around the clock and shows who wins: your win, your climb, your block, your tournament run.
**EN Bullets:**
- Your latest win: your avatar with a crown, your casual Elo
- The week's three biggest climbers
- Your block, once a season runs
- Your path through the bracket, round by round

**CTA:** esports.einundzwanzig.space
**Designer note:** the stream frame with the "LATEST WIN · GG" slide (e1) and three slide
thumbnails. The stream's own text is English only, in both cuts. Facts 34-39: the win and the
climbers are on master; the block slide and the tournament-run slide are the plan
`2026-09-29T2215-stream-slides-stolz-und-turniere` (P3, P5). **Post only once those slides are
on the prod stream.**

---

## 13. Tournaments live

**DE Headline:** Genug zugeschaut? Nimm Platz.
**DE Subline:** Turniere laufen live im Stream, Runde für Runde, bis ein Champion die Krone trägt. Im nächsten kannst du dabei sein.
**DE Bullets:**
- Turnierbaum live, mit jedem Ergebnis
- Der Champion mit Krone, groß im Bild
- Anmelden mit deinem Nostr-Schlüssel
- Schach, Rocket League und EA FC

**EN Headline:** Watched enough? Grab a seat.
**EN Subline:** Tournaments run live on stream, round by round, until a champion wears the crown. In the next one, you can be in it.
**EN Bullets:**
- A live bracket, every result as it lands
- The champion with the crown, big on screen
- Sign up with your Nostr key
- Chess, Rocket League and EA FC

**CTA:** esports.einundzwanzig.space
**Designer note:** the stream frame with a decided bracket (semifinals, final, champion with
crown) and the next tournament's sign-up card with one free seat marked "Your spot?". Facts
40-44. The live bracket slides are plan P5: **post only once they are on the prod stream.**

---

## 14. Age of Empires II

**DE Headline:** Eine Lobby. Bis zu acht Imperien.
**DE Subline:** Turniere in Age of Empires II sind ein einziges Match: „Teams sperren“ aus, „Bündnissieg“ an. Wer am Ende verbündet steht, teilt sich Platz 1 und seinen Pot.
**DE Bullets:**
- 3 bis 8 Spieler je Lobby, 9 werden 5 + 4
- Arabia, Kartengröße nach Lobbygröße
- Sieg: Zeitlimit, 2 Stunden
- Screenshot als Beleg, Turnierleitung bestätigt

**EN Headline:** One lobby. Up to eight empires.
**EN Subline:** Age of Empires II tournaments are one single match: Lock Teams off, Allied Victory on. Whoever stands allied at the end shares 1st place and its pot.
**EN Bullets:**
- 3 to 8 players a lobby, 9 split into 5 + 4
- Arabia, map size follows the lobby size
- Victory: Time Limit, 2 hours
- Screenshot as proof, the director confirms

**CTA:** esports.einundzwanzig.space
**Designer note:** the game's cover cropped to its logo (no painted characters in frame), one
lobby of six players on its result, worded as the app's lobby card: the two allied survivors
are #1 together ("Shared place 1: …", lit, linked), the other four #3 to #6 in the order they
went out, the proof below (screenshot of the end screen, reported, decided); beside it the
card's settings, with the map-size scale Tiny to Large stopped at Normal for six. Players are
pixel avatars and names. Facts 67-79. The lobby tournament (P10) is built on its branch
(`210edd80`) but not on prod: **post only once it is live on prod.**

---

## 15. Blockfill

**DE Headline:** Schürfe 40 Blöcke.
**DE Subline:** Jede volle Reihe wird ein Block. Wer diese Woche am schnellsten ist, gewinnt die Bestenliste.
**DE Bullets:**
- Sofort üben, ohne Login
- Gewertet: anmelden, mit Tastatur spielen
- Die Liga spielt jeden gewerteten Lauf nach
- Neue Woche jeden Montag, 00:00 Uhr Berliner Zeit

**EN Headline:** Mine 40 blocks.
**EN Subline:** Every full row becomes a block. Fastest this week wins the board.
**EN Bullets:**
- Practice right away, no login
- Ranked: log in, play on a keyboard
- The league replays every ranked run
- New week every Monday, 00:00 Berlin time

**CTA:** esports.einundzwanzig.space/blockfill
**Designer note:** the game page at hero scale, drawn by the app's own renderer
(`resources/js/stacker/renderer.js`) on the engine's state at tick 1441 of one seed run
(`src/gen-blockfill-run.mjs`, accepted by `verify.mjs`): 20 of 40 blocks mined, a straight
piece over the open right column. The page's fee-rate legend is left out (no "fee" on a promo,
the league charges none). Facts 80-89. Blockfill is its own game in the mempool look: no other
game's name or wording.

---

## 16. Blockfill week board

**DE Headline:** Die schnellsten Miner dieser Woche
**DE Subline:** Blockfill: 40 Blöcke gegen die Uhr. Es zählt dein bester geprüfter Lauf der Woche, die schnellste Zeit gewinnt.
**DE Bullets:**
- Die Liga spielt jeden gewerteten Lauf nach
- Bei Gleichstand zählt der frühere Lauf
- Neue Woche jeden Montag, 00:00 Uhr Berliner Zeit

**EN Headline:** This week's fastest miners
**EN Subline:** Blockfill: 40 blocks against the clock. Your best verified run of the week counts, the fastest time wins.
**EN Bullets:**
- The league replays every ranked run
- A tie goes to the earlier run
- New week every Monday, 00:00 Berlin time

**CTA:** esports.einundzwanzig.space/blockfill
**Designer note:** a template. The board ("This week's hunt") renders from
`data/blockfill-week.js`, which `src/fetch-blockfill-week.mjs` reads from the public /blockfill
page: place, name and best verified time as the page shows them, up to five rows; with fewer
than three, the rest are open, dashed places. **Fetch again and re-render right before
posting**; the board changes with every verified run. Formats: 9:16, 4:5, 1:1. Facts 84-89.

---

## 17. TrackMania Nations Forever

**DE Headline:** Unser TMNF-Server. Bestzeit der Woche.
**DE Subline:** TrackMania Nations Forever ist kostenlos auf Steam. Fahr A01-Race auf TWENTY ONE: Der Server misst jede Zielankunft, deine schnellste der Woche zählt.
**DE Bullets:**
- Kostenlos auf Steam, Beitritt über die Favoriten
- Login einmal mit einem Code im Server-Chat verknüpfen
- Nadeos Autorenzeit auf A01-Race: 0:24.540
- Neue Woche jeden Montag, 00:00 Uhr Berliner Zeit

**EN Headline:** Our TMNF server. Best time of the week.
**EN Subline:** TrackMania Nations Forever is free on Steam. Drive A01-Race on TWENTY ONE: the server times every finish, your fastest of the week counts.
**EN Bullets:**
- Free on Steam, join from your Favourites
- Link your login once with a code in the server chat
- Nadeo's author time on A01-Race: 0:24.540
- New week every Monday, 00:00 Berlin time

**CTA:** esports.einundzwanzig.space/scores/tmnf
**Designer note:** the game's official cover (Steam store art, the same file the site shows) with
a tag strip in the game's teal (time attack, the track, timed by our server), the How to join
card with its four steps in the app's own words (pages/scores/partials/tmnf-join), and the track
card with Nadeo's author time. No player and no player's time: the board changes every week.
Formats: all five, the stream banner included. Facts 90-98. **The track is the one
`config/esports.php` lists today (A01-Race); a second track rotates it (`TmnfWeeks::trackFor`):
check the track before posting and re-render if it changed.**

---

## Dropped or corrected since round 1 (see `facts.md` for full reasoning)

- Clan logo moved from "at founding" to "on the manage page, after founding."
- Clan "lineup"/"Kader" and "team match over 2 or 3 boards" removed — that UI
  mechanic is Rocket League only, not live for chess.
- "Gegen andere Mitglieder" / "against other members" replaced with "andere
  Spieler" / "other players" throughout — membership is not a play requirement.
- German piece letters (K D T L S) added as a bullet on the blitz-chess motif.
- Open source added as an eighth (optional) motif.
- "Daily-Schach" renamed to "Fernschach" (the UI's own term); "Invite-Link"
  renamed to "Einladungslink."
