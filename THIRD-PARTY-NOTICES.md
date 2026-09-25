# Third-party notices

The project's own code is under the [MIT license](LICENSE). The files below come
from third parties and keep their own licences.

## Fonts for the stream scene (`resources/fonts/stream/`)

Used only to render the live-game scene of the 24/7 stream
(`resources/views/stream/scene.blade.php`, rendered by `rsvg-convert`).

| File | Font | Copyright | Licence |
|---|---|---|---|
| `Unbounded-800.ttf` | Unbounded, weight 800 (static instance) | Copyright 2022 The Unbounded Project Authors (https://github.com/googlefonts/unbounded) | SIL Open Font License 1.1 |
| `JetBrainsMono-700-latin.ttf`, `JetBrainsMono-700-latin-ext.ttf` | JetBrains Mono, weight 700 (latin and latin-ext subsets) | Copyright 2020 The JetBrains Mono Project Authors (https://github.com/JetBrains/JetBrainsMono) | SIL Open Font License 1.1 |

The full licence text is in [`resources/fonts/stream/OFL.txt`](resources/fonts/stream/OFL.txt).
The copyright lines above are the ones in the fonts' own `name` tables.

## Chess pieces in the stream scene

The piece symbols in `resources/views/stream/scene.blade.php` are the "cburnett"
set by Colin M.L. Burnett (Wikimedia Commons, `File:Chess_{k,q,r,b,n,p}{l,d}t45.svg`).
The files are multi-licensed (GFDL 1.2+, CC BY-SA 3.0, BSD, GPL 2+); they are used
here under the BSD 3-clause licence. Changes: element ids removed, whitespace
collapsed, each piece wrapped in an SVG `<symbol>`.

```
Copyright (c) Colin M.L. Burnett

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:

1. Redistributions of source code must retain the above copyright notice, this
   list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright notice,
   this list of conditions and the following disclaimer in the documentation
   and/or other materials provided with the distribution.

3. Neither the name of the copyright holder nor the names of its contributors
   may be used to endorse or promote products derived from this software
   without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
```
