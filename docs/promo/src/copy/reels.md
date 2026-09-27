# Reel scripts — 8 reels, DE + EN (round 2; reels 6–8 added 2026-09-27)

Format: 1080×1920, ≥3s per beat, ≤8 words on screen per beat. No mining/season/rank
content, no Rocket League, no pot figures. Corrected in round 2: no clan lineup or
board-count visual (Rocket League only), no clan logo shown at the founding step,
opponents are "andere Spieler"/"other players", not "Mitglieder"/"members".

---

## Reel 1 — Blitz chess

**DE**
1. (3s) Lust auf eine schnelle Partie? — *Startseite / Chess-Lobby*
2. (3s) Blitz 5+3, direkt im Browser — *Lobby, Warteschlange*
3. (3s) Gegner gefunden. Die Uhr läuft. — *Brett mit Uhr*
4. (3s) Casual-Ladder zählt schon jetzt — *Ladder-Screen*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) Fancy a quick game? — *homepage / chess lobby*
2. (3s) Blitz 5+3, right in your browser — *lobby, matchmaking*
3. (3s) Opponent found. Clock's running. — *board with clock*
4. (3s) Casual ladder counts already — *ladder screen*
5. (3s) esports.einundzwanzig.space — *logo + URL*

---

## Reel 2 — Fernschach

**DE**
1. (3s) Keine Zeit für eine ganze Partie? — *Handy, Alltagsszene*
2. (3s) Fernschach: ein Zug am Tag — *Korrespondenz-Brett*
3. (3s) Nostr-DM sagt dir, wenn du dran bist — *DM-Benachrichtigung*
4. (3s) Zwischen Kaffee und Meeting erledigt — *Handy, ein Zug*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) No time for a full game? — *phone, everyday scene*
2. (3s) Correspondence chess: one move a day — *correspondence board*
3. (3s) A Nostr DM tells you when it's your move — *DM notification*
4. (3s) Fits between coffee and your meeting — *phone, one move*
5. (3s) esports.einundzwanzig.space — *logo + URL*

---

## Reel 3 — Clans

**DE**
1. (3s) Schach ist besser mit einem Clan — *Clan-Übersicht*
2. (3s) Gründe deinen Clan, wähl Name und Tag — *Clan-Erstellung*
3. (3s) Logo hochladen, in der Verwaltung — *Manage-Seite, Logo-Upload*
4. (3s) Freunde per Einladungslink dazuholen — *Einladungslink-Screen*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) Chess is better with a clan — *clans overview*
2. (3s) Start your clan — name and tag — *clan creation*
3. (3s) Upload a logo, on the manage page — *manage page, logo upload*
4. (3s) Bring in friends with an invite link — *invite link screen*
5. (3s) esports.einundzwanzig.space — *logo + URL*

---

## Reel 4 — Invite friends

**DE**
1. (3s) Schach macht zu zweit mehr Spaß — *zwei Personen, Handys*
2. (3s) Ein Einladungslink. Kein Account nötig. — *Einladungslink-Screen*
3. (3s) Freund klickt — landet direkt im Spiel — *Invite-Preview*
4. (3s) Gemeinsam auf der Casual-Ladder — *Ladder mit zwei Namen*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) Chess is more fun together — *two people, phones*
2. (3s) One invite link. No account needed. — *invite link screen*
3. (3s) Friend clicks — straight into the game — *invite preview*
4. (3s) Climb the casual ladder together — *ladder with two names*
5. (3s) esports.einundzwanzig.space — *logo + URL*

---

## Reel 5 — Watch live

**DE**
1. (3s) Keine Lust zu spielen? Schau zu. — *Sofa, Handy*
2. (3s) Jede laufende Partie, live — *Übersicht laufender Partien*
3. (3s) Zug für Zug mitverfolgen — *einzelnes Brett, live*
4. (3s) Ohne Login, ohne Anmeldung — *Gast-Ansicht*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) Not in the mood to play? Watch. — *couch, phone*
2. (3s) Every live game, right now — *live games overview*
3. (3s) Follow it move by move — *single board, live*
4. (3s) No login, no signup — *guest view*
5. (3s) esports.einundzwanzig.space — *logo + URL*

---

## Reel 6 — Nostr login

Sources: `resources/views/pages/auth/login.blade.php:25-45` (Google button first, then
"Nostr extension or app", "No password to remember.", "New here? The same buttons create
your player.", "Waiting for your confirmation"); `:56` "Everyone can play everything".
Beat 4 says "right away" because login creates the player (the "same buttons" line) and
membership is not a play gate (`app/Support/Membership.php:13-16`).

**DE**
1. (3s) Anmelden ohne Passwort — *Passwortfeld wird gestrichen, Pixel-Schlüssel fällt ein*
2. (3s) Mit Nostr, per Erweiterung oder App — *Login-Karte, Klick auf Nostr, wartet auf Bestätigung*
3. (3s) Kein Nostr? Dann mit Google. — *Login-Karte, Klick auf Google*
4. (3s) Neu hier? Du spielst sofort mit. — *neuer Spieler, Gegner finden*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) No password. Just your key. — *password field struck out, pixel key drops in*
2. (3s) Your Nostr key, from an extension or app — *login card, tap Nostr, waiting for confirmation*
3. (3s) No Nostr yet? Use Google. — *login card, tap Google*
4. (3s) New here? You can play right away. — *new player, find an opponent*
5. (3s) esports.einundzwanzig.space — *logo + URL*

---

## Reel 7 — Tournaments

Sources: live page https://esports.einundzwanzig.space/tournaments/1 (read 2026-09-27) and
`resources/views/pages/tournaments/⚡show.blade.php:261-270` ("Confirm with your Nostr key",
"players are seeded by Elo ... The hash of the next Bitcoin block seeds the bracket",
"Report your series; the other side confirms it"); `app/Support/Tournaments/DrawOrder.php:5-10`.
"Open to everyone": `login.blade.php:56` ("ladders, clans, challenges, tournaments").
Report + confirm is the path for series games (the live tournament is one); a chess
tournament's games run on the site with a clock instead (`⚡show.blade.php:270`), so the
beat-4 mock shows a series score and names no game. No prizes, no pot, no dates.

**DE**
1. (3s) Turniere. Offen für jeden. — *Turnierkarte, die Plätze füllen sich*
2. (3s) Anmelden mit deinem Nostr-Schlüssel — *Anmelden, Bestätigung, „ist dabei“*
3. (3s) Nach Elo gesetzt, per Bitcoin-Block gelost — *Block-Hash rastet ein, Setzliste wird Runde 1*
4. (3s) Du meldest, dein Gegner bestätigt. — *Match gemeldet, Gegner bestätigt, Sieger zieht weiter*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) Tournaments. Open to everyone. — *tournament card, spots filling up*
2. (3s) Sign up with your Nostr key — *sign up, confirm, "is in"*
3. (3s) Seeded by Elo, drawn by a Bitcoin block — *block hash locks in, seeds become round 1*
4. (3s) You report. Your opponent confirms. — *match reported, opponent confirms, winner moves on*
5. (3s) esports.einundzwanzig.space — *logo + URL*

---

## Reel 8 — Open source

Sources: `resources/views/components/shell/footer.blade.php:31,39` (footer links
`https://github.com/HolgerHatGarKeineNode/twenty-one-esports`, "Open source"); `gh repo view`
2026-09-27: visibility PUBLIC, issues enabled; the beat-3 code is
`app/Support/Tournaments/DrawOrder.php:18-29`, verbatim. "Nicht vertrauen, nachprüfen" is
the poster's own line (`posters.md`, motif 8).

**DE**
1. (3s) Nicht vertrauen. Nachprüfen. — *Footer, Klick auf Open Source*
2. (3s) Der Quellcode liegt offen auf GitHub — *Repo, Ordner der App*
3. (3s) Prüf selbst, wie gelost wird — *DrawOrder.php, Zeile für Zeile*
4. (3s) Fehler gefunden? Schreib ein Issue. — *Issues, neues Issue, leeres Feld*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) Don't trust. Verify. — *footer, tap Open source*
2. (3s) The source code is public on GitHub — *repo, the app's folders*
3. (3s) Check for yourself how the draw works — *DrawOrder.php, line by line*
4. (3s) Found a bug? Open an issue. — *issues, new issue, empty field*
5. (3s) esports.einundzwanzig.space — *logo + URL*
