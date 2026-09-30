# Reel scripts — 9 reels, DE + EN (round 2; reels 6–8 added 2026-09-27, reel 9 later that day)

Format: 1080×1920, ≥3s per beat, ≤8 words on screen per beat. Reels 1–9 were written
under the round-2 rules (no mining/season/rank content, no Rocket League, no pot
figures); since 2026-09-28 only the season is off limits (README). Corrected in round 2: no clan lineup or
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
Beat 4 holds for every tournament: series games are reported and confirmed, chess games
run on the site with a clock, in director mode the directors enter the result, and all three
branches end in "Winners move on" (`⚡show.blade.php:268-270`). So beat 4 names no game, no
way of reporting and no score; the mock shows the round-1 pairs from beat 3 settling and the
winners advancing to the semifinal. No prizes, no pot, no dates.

**DE**
1. (3s) Turniere. Offen für jeden. — *Turnierkarte, die Plätze füllen sich*
2. (3s) Anmelden mit deinem Nostr-Schlüssel — *Anmelden, Bestätigung, „ist dabei“*
3. (3s) Nach Elo gesetzt, per Bitcoin-Block gelost — *Block-Hash rastet ein, Setzliste wird Runde 1*
4. (3s) Ergebnis steht. Sieger rückt vor. — *Runde 1 entscheidet sich Paarung für Paarung, die Sieger ziehen ins Halbfinale*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) Tournaments. Open to everyone. — *tournament card, spots filling up*
2. (3s) Sign up with your Nostr key — *sign up, confirm, "is in"*
3. (3s) Seeded by Elo, drawn by a Bitcoin block — *block hash locks in, seeds become round 1*
4. (3s) Result's in. The winner moves on. — *round 1 settles pair by pair, the winners move into the semifinal*
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

---

## Reel 9 — GRASP

Sources: gitworkshop.dev repo card "TWENTY ONE Esports", branch master, 4/4 servers in sync (read 2026-09-27); one `git push origin master` reached relay.ngit.dev, gitnostr.com, ngit.danconwaydev.com (GRASP servers) and github.com (mirror), checked with `git ls-remote` on all four; `git clone nostr://…` tested; GRASP = relay + git server in one (ngit.dev/how-it-works). Scene texts (in `reels/reel.html`, `GRASP_TXT`): complaint DE "Wieso ist der Code nur auf GitHub? Ihr seid doch auf Nostr, oder?", EN "Why is your code only on GitHub? Thought you were all about Nostr?"; reply DE "Ihr hattet recht. Jetzt läuft's auch über Nostr.", EN "You were right. It's on Nostr too now."

**DE**
1. (3s) Ihr habt uns genervt. Liebevoll. — *Nostr-Notiz von nodebert, Reaktionen poppen auf*
2. (3s) Ein Push. Vier Server. — *Terminal, `git push origin master`, vier Zeilen erscheinen*
3. (3s) Jetzt offen auf Nostr-Git — *gitworkshop.dev Repo-Karte, „4/4" grün*
4. (3s) Danke fürs Nerven, ehrlich gemeint. — *nodeberts Notiz, unsere Antwort, oranges Herz, `git clone nostr://…`*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) You nagged us. Lovingly. — *Nostr note from nodebert, reactions pop up*
2. (3s) One push. Four servers. — *terminal, `git push origin master`, four lines appear*
3. (3s) Now live on Nostr git — *gitworkshop.dev repo card, "4/4" green*
4. (3s) Thanks for nagging. We mean it. — *nodebert's note, our reply, orange heart, `git clone nostr://…`*
5. (3s) esports.einundzwanzig.space — *logo + URL*

---

## Reel 10 — Sats pot

Sources: live system 2026-09-28, tournament page https://esports.einundzwanzig.space/tournaments/2
("21,000 Sats, Zero Ball Control", Rocket League, 1v1, Two Stage, unrated, 12 places / 1 taken,
prize pool as the tournament sets it: 21,000 sats, split 50 % / 30 % / 20 % among the top 3,
anyone can zap the pool on the tournament page ("Zap the pool"), starts Sun 4 Oct 2026 18:00 CEST,
sign-up closes 17:00 CEST, created by "El Presidento Ben"). The pool is the organizer's set
figure (the wallet balance is not shown), so the copy says "prize pool", never "paid in".
Written by the kommunikator; "Echter Pot"/"Real pot" in beat 1 changed to "Preispool"/"prize pool".

**DE**
1. (3s) 21.000 Sats Preispool — *Zahl knallt ins Bild*
2. (3s) Rocket League 1v1, 12 Plätze, 11 frei — *Turnierkarte, ein Feld: „Dein Platz?"*
3. (3s) Platz 1 bis 3 teilen sich den Pot — *Podium 50 % / 30 % / 20 %*
4. (3s) Zapp den Pot größer — *Blitze fliegen in den Pot, „Pool zappen"*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) 21,000 sats prize pool — *number slams onto screen*
2. (3s) Rocket League 1v1, 12 spots, 11 open — *tournament card, one slot says "Your spot?"*
3. (3s) Top 3 split the pot — *podium 50% / 30% / 20%*
4. (3s) Zap the pot bigger — *bolts flying into the pot, "Zap the pool"*
5. (3s) esports.einundzwanzig.space — *logo + URL*

---

## Reel 11 — Fifa 2026

Sources: live system 2026-09-28, tournament page https://esports.einundzwanzig.space/tournaments/1
("EINUNDZWANZIG Fifa 2026", EA Sports FC 26, 1v1, Two Stage: groups first, then a knockout,
unrated, no prize pool, 16 places / 2 taken, starts Sat 3 Oct 2026 18:00 CEST, sign-up closes
17:00 CEST, created by "markusturm"). Written by the kommunikator.

**DE**
1. (3s) EA Sports FC 26 wartet — *Turnierkarte mit Cover*
2. (3s) Erst Gruppen, dann K.-o.-Runde — *Format-Übersicht, Two Stage*
3. (3s) 16 Plätze, 14 noch frei — *Wer spielt: 2 / 16*
4. (3s) Anmelden mit deinem Nostr-Schlüssel — *Anmeldung, Bestätigung, „ist dabei"*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) EA Sports FC 26 is here — *tournament card with cover*
2. (3s) Groups first, then knockout — *format overview, Two Stage*
3. (3s) 16 spots, 14 still open — *who plays: 2 / 16*
4. (3s) Sign up with your Nostr key — *sign-up, confirm, "is in"*
5. (3s) esports.einundzwanzig.space — *logo + URL*

---

## Reel 12 — Casual cups

Sources: `facts.md` 61-66 (`config/esports.php` `casual_cups`, commit `beabfc89`, prod
https://esports.einundzwanzig.space/tournaments read 2026-10-01: all fourteen cups, seven games in
EU and US, each at its weekend slot, 0 of 4 spots). Rewritten 2026-10-01 by the design lead: the
previous cut (four games on Saturday evening, "two losses and out", "no rating") went stale with
`beabfc89` and was partly wrong (double elimination only from six players; a cup series moves the
casual Elo). No date in the copy: the slots repeat, the cup numbers do not.

**DE**
1. (3s) Sieben Spiele, sieben Casual Cups — *sieben Cover: Schach, Rocket League, FC 26, FC 27, AoE2, Mühle, Dame*
2. (3s) Freitag bis Sonntag, in EU und US — *das Wochenende: Fr 18:00 FC 26, 20:00 FC 27; Sa 15:00 Mühle, 20:00 Schach und Rocket League; So 15:00 Dame, 20:00 AoE2; dieselbe Uhrzeit in Berlin und New York*
3. (3s) Wird es voll, wächst der Cup — *vier Plätze füllen sich, bei einem freien Platz wächst der Cup auf acht*
4. (3s) Platz nehmen, mit deinem Nostr-Schlüssel — *Anmelden, Bestätigung mit dem Nostr-Schlüssel, „zap_zoe ist dabei.“*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) Seven games, seven casual cups — *seven covers: chess, Rocket League, FC 26, FC 27, AoE2, nine men's morris, checkers*
2. (3s) Friday to Sunday, in the EU and US — *the weekend: Fri 18:00 FC 26, 20:00 FC 27; Sat 15:00 morris, 20:00 chess and Rocket League; Sun 15:00 checkers, 20:00 AoE2; the same time in Berlin and New York*
3. (3s) Fills up? The cup grows. — *four spots fill, with one left the cup grows to eight*
4. (3s) Take a seat with your Nostr key — *sign up, confirm with the Nostr key, "zap_zoe is in."*
5. (3s) esports.einundzwanzig.space — *logo + URL*

---

## Reel 13 — Mühle

Sources: `facts.md` 17-21 (`NineMensMorrisRules.php` docblock: place nine men, then move along lines, fly
at three men, a mill removes an enemy man; `NineMensMorris.php`: Blitz 5+3, `'300+3'`). Five beats of 3 s, 18.4 s with the cuts, as every reel.
Points use the rules' notation (files a-g, ranks 1-7, a1 bottom left). The positions are one game replayed through `NineMensMorrisRules` (`src/gen-boardgames.php`, plies 1-18 and 50).

**DE**
1. (3s) Neun Steine setzen — *leeres Mühle-Brett, Weiß und Schwarz setzen abwechselnd Steine auf freie Punkte, Zähler „in der Hand“ zählt von 9 runter*
2. (3s) Drei in einer Reihe: Stein weg — *Schwarz setzt den letzten Stein auf c5: Linie c3-c4-c5 leuchtet auf, der weiße Stein auf g4 wird vom Brett genommen*
3. (3s) Bei drei Steinen darfst du springen — *späte Stellung, Schwarz hat nur noch drei Steine; der Stein auf d1 springt nach a4, schließt a4-b4-c4, der weiße Stein auf c3 wird genommen*
4. (3s) Blitz 5+3, live im Browser — *Brett mit beiden Uhren auf 5:00; nach jedem Zug kommen 3 Sekunden dazu*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) Place nine men — *empty morris board, White and Black place men on free points in turn, the "in hand" counter counts down from 9*
2. (3s) Three in a row: take a man — *Black places its last man on c5: the line c3-c4-c5 lights up, the white man on g4 is taken off the board*
3. (3s) Down to three? Your men fly — *late position, Black has only three men left; the man on d1 flies to a4, closes a4-b4-c4, the white man on c3 is taken*
4. (3s) Blitz 5+3, live in your browser — *the board with both clocks at 5:00; every move adds 3 seconds*
5. (3s) esports.einundzwanzig.space — *logo + URL*

---

## Reel 14 — Dame

Sources: `facts.md` 17-19, 22-25 (`CheckersRules.php` docblock: compulsory capture, men capture backwards, capture
chains, flying king; `Checkers.php`: Blitz 5+3, `'300+3'`; lobby: casual ladder top five). Five beats of 3 s, 18.4 s with the cuts, as every reel.
DE calls the game and the king "Dame", EN "checkers" and "king". Squares as in chess notation (a1 dark, bottom left). The positions are one game replayed through `CheckersRules` (`src/gen-boardgames.php`, plies 19, 23 and 34).

**DE**
1. (3s) Schlagen ist Pflicht — *8×8-Brett, Weiß am Zug: nur der Schlag e3xg5 ist erlaubt, markiert, der Stein springt über f4*
2. (3s) Schlagkette: Feld für Feld klicken — *Weiß klickt a5, c7, e5, g3: der Stein springt dreimal, die drei schwarzen Steine verschwinden erst nach dem Zug*
3. (3s) Die Dame fliegt die Diagonale — *schwarze Dame auf c3: sie schlägt d4, dann aus der Entfernung g3, und landet auf h2*
4. (3s) Blitz 5+3, eigene Casual-Ladder — *beide Uhren bei 5:00, das Brett spielt Blitz, darunter die Casual-Ladder: der erste Sieg zählt*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) Capturing is compulsory — *8×8 board, White to move: only the capture e3xg5 is allowed and marked, the man jumps over f4*
2. (3s) One move, a whole capture chain — *White clicks a5, c7, e5, g3: the man jumps three times, the three black men leave only after the move*
3. (3s) Kings fly the diagonal — *black king on c3: it captures d4, then g3 from a distance, and lands on h2*
4. (3s) Blitz 5+3, own casual ladder — *both clocks at 5:00, the board plays blitz, below it the casual ladder: the first win counts*
5. (3s) esports.einundzwanzig.space — *logo + URL*

---

## Reel 15 — Mempool → Block

Sources: `facts.md` 28-33 (`app/Support/Matches/{MempoolStrip,MatchBlocks,ChainStamps}.php`,
`components/block-strip.blade.php`, `pages/⚡mining`, `config/season.php`; /matches and /mining read
on prod 2026-09-29). Five beats of 3 s, 18.4 s with the cuts, as every reel. The reward figure in
beat 4 is the Pre-Season draft as prod's /mining shows it (Rocket League 1v1, era 1), shown with the
app's own "draft, not released" label; the captions carry no figure. Casual cubes never mine. Shows
Mühle and Dame cubes: post only once the board game switches are on in prod.

**DE**
1. (3s) Jede Partie landet im Mempool — *der Mempool-Streifen, Würfel aller fünf Spiele fallen hinein, casual entsättigt, Avatare und Clan-Logos darunter*
2. (3s) Läuft sie, füllt sich der Würfel — *rechts vom Trenner: laufende Partien, der Würfel füllt sich Zug für Zug, „live“ mit grünem Punkt*
3. (3s) Season läuft? Dein gewerteter Sieg wird zum Block — *die Zeile über dem Streifen wechselt auf die Season-Fassung; das gewertete Rocket-League-1v1 endet 3 : 1, der Deckel wird orange, „Block 1“ fällt darunter*
4. (3s) Jeder Block bringt Sats, halbiert je Epoche — */mining: Block 1 hängt an der Season-Chain, der Zähler läuft auf die Belohnung pro siegreichem Spieler, markiert „Entwurf, nicht freigegeben“*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) Every match lands in the mempool — *the mempool strip, cubes of all five games drop in, casual ones desaturated, avatars and clan logos below*
2. (3s) While it's played, the cube fills up — *right of the divider: running games, the cube fills move by move, "live" with a green dot*
3. (3s) Season running? Your rated win becomes a block — *the line above the strip switches to its season wording; the rated Rocket League 1v1 ends 3 : 1, the lid turns orange, "Block 1" drops in below*
4. (3s) Every block pays sats, halving every era — */mining: Block 1 joins the season chain, the counter runs to the reward per winning player, labelled "draft, not released"*
5. (3s) esports.einundzwanzig.space — *logo + URL*

---

## Reel 16 — On the stream

Sources: `facts.md` 34-39 (`resources/views/stream/rotation/e1-win`, `e2-climbers`, `PrideSlides.php`,
`config/twentyone.php` "streams 24/7"). The stream's own text stays English in both cuts. The block
slide and the tournament-run slide (beat 4) are plan P3/P5: post only once they are on the prod stream.
The casual Elo is the app's `EloRating` with `season.casual`: +20 for one win, +57 for three.

**DE**
1. (3s) Dein Matt. Dein Moment. — *Brett, Légals Matt (Paris 1750) landet, das Matt-Feld leuchtet*
2. (3s) Rund um die Uhr: Sieger im Stream — *der Stream „LIVE“: LATEST WIN · GG, satsjaeger mit Krone, beat hodlqueen, +20 casual Elo*
3. (3s) Dein Aufstieg, groß im Bild — *Climbers of the week: drei Avatare, die Balken steigen auf +57, +39, +20 casual Elo*
4. (3s) Dein Block in der Season. Dein Turnierlauf. — *zwei Stream-Slides: Block 1 mit Avatar, und der Weg durchs Halbfinale ins Finale*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) Your mate. Your moment. — *board, Légal's mate (Paris 1750) lands, the mate square lights up*
2. (3s) Around the clock, winners go on stream — *the stream, "LIVE": LATEST WIN · GG, satsjaeger with a crown, beat hodlqueen, +20 casual Elo*
3. (3s) Your climb, big on screen — *Climbers of the week: three avatars, the bars rise to +57, +39, +20 casual Elo*
4. (3s) Your block in season. Your bracket run. — *two stream slides: Block 1 with an avatar, and the path through the semifinal into the final*
5. (3s) esports.einundzwanzig.space — *logo + URL*

---

## Reel 17 — Tournaments live

Sources: `facts.md` 40-44. The live bracket and champion slides are plan P5: post only once they are
on the prod stream. Sign-up with the Nostr key is the kit's own sign-up scene (reel 7). No fee of any
kind exists or is mentioned.

**DE**
1. (3s) Das Turnier läuft live im Stream — *der Stream „LIVE“: Halbfinale, ein Match läuft, der grüne Punkt pulsiert*
2. (3s) Ergebnis rein, Sieger rückt vor — *die Halbfinals entscheiden sich, die Sieger rücken ins Finale*
3. (3s) Ein Champion trägt die Krone — *das Finale fällt, kai_blitz bekommt die Krone, Champion*
4. (3s) Das nächste öffnet. Nimm Platz. — *Nächstes Turnier, Anmeldung offen: die Plätze füllen sich, „Dein Platz?“ leuchtet*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) The tournament runs live on stream — *the stream, "LIVE": semifinals, one match running, the green dot pulses*
2. (3s) Result in, winner moves on — *the semifinals are decided, the winners move into the final*
3. (3s) One champion. One crown. — *the final is decided, kai_blitz gets the crown, Champion*
4. (3s) The next one opens. Take a seat. — *Next tournament, sign-up open: the seats fill up, "Your spot?" lights up*
5. (3s) esports.einundzwanzig.space — *logo + URL*

---

## Reel 18 — Age of Empires II

Sources: `facts.md` 45-60 (`app/Games/AgeOfEmpires2.php`, `config/esports.php` `casual` and
`casual_cups`, `pages/matches/partials/casual-steps` and `card-composer`, `⚡room`, `lobbyCards.js`,
prod /games/age-of-empires-2 and /tournaments read 2026-10-01). Five beats of 3 s, 18.4 s with the
cuts, as every reel. The cup seats in beat 4 are kit players; the casual Elo is fact 54 (+20 for a
first win). No automatic result check is shown or said (fact 53): players enter and confirm.

**DE**
1. (3s) Age of Empires II. Dein 1v1 wartet. — *das Cover-Logo, darunter „1v1-Gegner finden“; der Cursor klickt, „Gegner gefunden“: satsjaeger gegen kai_blitz*
2. (3s) Lobby und Passwort, verschlüsselt im Chat — *Match-Chat: die Karte „Age-of-Empires-II-Lobby“ mit e21-58 und frischem Passwort, die Schritte haken ab: Bereit, Lobby geteilt, Beigetreten*
3. (3s) Du gewinnst. Dein Name steigt. — *Sieger je Spiel: drei Linien, zwei fließen zu satsjaeger, 2 Spiele gewonnen; die 1v1-Ladder zählt +20*
4. (3s) Sonntags 20 Uhr: der AoE2 Casual Cup — *zwei Cup-Karten, EU 20:00 Berlin und US 20:00 New York, die Plätze füllen sich, „Dein Platz?“ leuchtet*
5. (3s) esports.einundzwanzig.space — *Logo + URL*

**EN**
1. (3s) Age of Empires II. Your 1v1 awaits. — *the cover logo, "Find a 1v1 opponent" below it; the cursor clicks, "Opponent found": satsjaeger vs kai_blitz*
2. (3s) Lobby and password, encrypted in chat — *match chat: the "Age of Empires II lobby" card with e21-58 and a fresh password, the steps tick off: Ready, Lobby shared, Joined*
3. (3s) You win. Your name climbs. — *winner per game: three lines, two flow to satsjaeger, 2 games won; the 1v1 ladder counts +20*
4. (3s) Sundays at 8 pm: the AoE2 Casual Cup — *two cup cards, EU 20:00 Berlin and US 20:00 New York, the seats fill up, "Your spot?" lights up*
5. (3s) esports.einundzwanzig.space — *logo + URL*
