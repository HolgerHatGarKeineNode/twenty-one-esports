---
paths:
  - 'resources/views/**'
---

# Views

## Game views use the shared templates, never an own arrangement
Every game context (lobby, match, leaderboard, series room, tournament) renders through the one shared template for its kind: board/arcade/strategy → lobby + match template, best-time → leaderboard template, own-copy series → series template. A new game is a GameRegistry entry + kind + cover/emblem, never a new page layout. Same slots in the same place for every game (header, sub-nav tabs, primary content, chat rail, secondary slot). A deviation needs a measured reason and a design-canvas update first.

## Chat is primary, social extras are a collapsed secondary slot
Chat sits in the right rail (desktop) / a tab or sheet (phone) at the same place in every template, filling the first screen height (min 480 px), sticky, composer pinned at the bottom. Nostr follower/following lists, comments, share, zap and invite widgets live in ONE secondary slot: one line by default, details behind a disclosure, loaded only on open, never above the page's primary content, no layout shift.

## Layout grid, reading width, density and filter rows
One 12-column grid in a max-width container (≈1280–1360 px); full bleed only for the key-art band (≤ 320 px high). Running text max 65–75 characters. Main content left/centre, chat + secondary right; no alternating full/half-width modules. First desktop screen shows purpose, primary action and real data. Chip filter rows stay on one line: "Alle" first, then by count; show every chip that fits (zero-count chips dimmed, still clickable) and collapse only the overflow into a trailing "+N weitere" dropdown when the row runs out of width (phone: horizontal scroll with edge fade). Tournaments with a pot or made by an organizer get the featured treatment above auto cups.

## No native form controls, scoreboard result entry
Never render a browser-native select, checkbox or radio; use the custom dropdown/checkbox components. Interactive elements (buttons, tabs, chips, counters) never wrap their text. Result entry everywhere uses the scoreboard pattern: both sides as big named blocks, large score inputs with −/+ (≥ 48 px), "Nur Sieger" toggle per game, a summary line before submit, inline errors at the row. Never ship spec/demo panels (state lists) on a real page.

## Only real features, no gamer-tag nudges
UI shows only features and data that exist in code and prod; no invented levels, XP, ranks or streaks. Keep the Bitcoin identity visible (Mempool strip, season chain/blocks, sats, Block 0). Gamer tags are not required: own-copy games work by sharing private match credentials in the match room, so never prompt players to add a gamer tag or link a game account, and never link a Nostr profile to a gamer tag publicly.
