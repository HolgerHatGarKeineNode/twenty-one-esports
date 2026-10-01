Static QR codes for the stream scenes, made once with qrencode 4.1.1 (no QR package in the app), EC level M, modules #17120A on white, no margin (`-m 0`): resources/views/stream/rotation/partials/qr.blade.php adds the 4-module quiet zone itself:

- `lnurl.svg`: `qrencode -t SVG --svg-path -l M -m 0 -s 1 --foreground=17120A --background=FFFFFF -o lnurl.svg "lightning:LNURL1DP68GURN8GHJ7EM9W3SKCCNE9E3K7MF09EMK2MRV944KUMMHDCHKCMN4WFK8QTM5DPJKYETWR0HT4D"` (bech32 LNURL, LUD-01, of `https://getalby.com/.well-known/lnurlp/theben`)
- `site.svg`: the same command with `-o site.svg "https://esports.einundzwanzig.space"`
- `blockfill.svg`: the same command with `-o blockfill.svg "https://esports.einundzwanzig.space/blockfill"` (the call to play of Blockfill, f5)

Checked by rendering the zap and scan scenes to PNG and decoding them with `zbarimg --raw`.
