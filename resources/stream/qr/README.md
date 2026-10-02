Static QR codes for the stream scenes, made once with qrencode 4.1.1 (no QR package in the app), EC level M, modules #17120A on white, no margin (`-m 0`): resources/views/stream/rotation/partials/qr.blade.php adds the 4-module quiet zone itself:

- `lnurl.svg`: `qrencode -t SVG --svg-path -l M -m 0 -s 1 --foreground=17120A --background=FFFFFF -o lnurl.svg "lightning:LNURL1DP68GURN8GHJ7ETNWPHHYARN9EJKJMN4DEJ85AMPDEAXJEEWWDCXZCM99UH8WETVDSKKKMN0WAHZ7MRWW4EXCUP0WPHK7MQ8XAETL"` (bech32 LNURL, LUD-01, of `https://esports.einundzwanzig.space/.well-known/lnurlp/pool`, the league pool and the profile's lud16 since 2026-10-03; until then `theben@getalby.com`). A plain payment through it goes to the league reserve.
- `site.svg`: the same command with `-o site.svg "https://esports.einundzwanzig.space"`
- `blockfill.svg`: the same command with `-o blockfill.svg "https://esports.einundzwanzig.space/blockfill"` (the call to play of Blockfill, f5)
- `tmnf.svg`: the same command with `-o tmnf.svg "https://esports.einundzwanzig.space/scores/tmnf#join"` (How to join TMNF, the call to join g3)

Checked by rendering the zap and scan scenes to PNG and decoding them with `zbarimg --raw`.
