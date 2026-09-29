# Reel music (2026-09-27)

**Reels use instrumentals only, never a vocal track** (user, 2026-09-27: „alle Reels bitte nur noch mit
random instrumental Songs unterlegen … Und in Zukunft auch keine Vocal Songs mehr für Reels nutzen“).
`render-reels.mjs` refuses any track outside `assets/music/instrumental/`.

The instrumentals are numbered like the user numbers them: `assets/music/instrumental/NN.mp3`
(01–12 from the first batch, 13–22 from the second; a new batch continues the count). Each reel
gets a different one, drawn at random; DE and EN of a reel share it, so both cuts feel like one
campaign. `render-reels.mjs` cuts the track at the window of the reel's length with the highest
mean short-term loudness (EBU R128 `S`, start >= 4 s), fades in 0.3 s and out 1.5 s, and
normalises to -14 LUFS integrated, true peak -1.5 dBTP (single-pass `loudnorm`).

| Reel | Track |
|---|---|
| blitz | `instrumental/17.mp3` |
| daily | `instrumental/06.mp3` |
| clans | `instrumental/11.mp3` |
| invite | `instrumental/18.mp3` |
| watch | `instrumental/14.mp3` |
| login | `instrumental/10.mp3` |
| tournaments | `instrumental/01.mp3` |
| opensource | `instrumental/20.mp3` |
| grasp | `instrumental/09.mp3` |
| satspot | `instrumental/05.mp3` |
| fifa | `instrumental/12.mp3` |
| cups | `instrumental/03.mp3` |
| morris | `instrumental/08.mp3` |
| checkers | `instrumental/21.mp3` |
| mempool | `instrumental/13.mp3` |
| onstream | `instrumental/02.mp3` |
| livecup | `instrumental/07.mp3` |
