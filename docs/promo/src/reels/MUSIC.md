# Reel music (2026-09-27)

One track per reel, the same for DE and EN, so both language cuts feel like one campaign.
`render-reels.mjs` reads this table. It cuts the track at the window of the reel's length with
the highest mean short-term loudness (EBU R128 `S`, start >= 4 s), fades in 0.3 s and out 1.5 s,
and normalises to -14 LUFS integrated, true peak -1.5 dBTP (single-pass `loudnorm`).

Chosen by pace and title, measured for loudness (all candidates sit at -15.1 to -15.9 LUFS);
not chosen by ear: nobody listened to them in this pass. Left out on purpose: "Primal Retard
Drop" (slur in the title) and "Steuern sind Raub" (political slogan), both wrong for a
recruitment campaign.

| Reel | Track |
|---|---|
| blitz | `Not Your Keys, Not Your Coins (DnB remix)_v1.mp3` |
| daily | `Physics_v1.mp3` |
| clans | `Bitcoin Warriors_v1.mp3` |
| invite | `Einundzwanzig Song_v1.mp3` |
| watch | `Samurai's Stand_v1.mp3` |
