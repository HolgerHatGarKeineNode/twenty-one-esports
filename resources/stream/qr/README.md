Static QR codes for the stream scenes, made once with qrencode 4.1.1 (no QR package in the app), EC level M, quiet zone 4, modules #17120A on white:

- `lnurl.svg`: `qrencode -t SVG --svg-path -l M -m 4 -s 1 --foreground=17120A --background=FFFFFF -o lnurl.svg "lightning:LNURL1DP68GURN8GHJ7EM9W3SKCCNE9E3K7MF09EMK2MRV944KUMMHDCHKCMN4WFK8QTM5DPJKYETWR0HT4D"` (bech32 LNURL, LUD-01, of `https://getalby.com/.well-known/lnurlp/theben`)
- `site.svg`: the same command with `-o site.svg "https://esports.einundzwanzig.space"`

Checked by rendering to PNG (`rsvg-convert -w 400`) and decoding with `zbarimg --raw`.
