# Poster copy — 8 motifs, casual recruitment (round 2)

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
