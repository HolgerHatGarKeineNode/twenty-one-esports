<?php

namespace App\Support\StreamBot;

use LogicException;

/**
 * Every word the stream chat bot says (P22), in one place, English only.
 *
 * Each template has one or more variants; a variant is a list of lines
 * (at most four). `:placeholder` values come from the builders
 * (StreamBotBuilders), already formatted. A line whose placeholder is null
 * is left out, so an optional fact (a prize pot, a clan count) needs no
 * extra variant. Links are always the last thing on their line: chat
 * clients take the URL up to the next space, and trailing punctuation
 * would end up inside it.
 *
 * Layout: zap.stream and zap.observer show chat text with `white-space:
 * normal`, so the line breaks collapse into spaces there (measured
 * 2026-09-27: zap.stream src/element/chat/chat-message.tsx renders the
 * content inside a plain <span>; zap.observer's chat line is
 * `whitespace-normal`). Every line therefore starts with an emoji, which
 * separates the lines when they run together. Clients that keep line
 * breaks show them as a small card.
 *
 * Rules (checked by StreamBotCopy::violations() on every message):
 * no `#` anywhere (no hashtags, a standing rule of this project), and at
 * least one direct http(s) link. Change the wording freely; keep the
 * placeholders of a template.
 */
final class StreamBotCopy
{
    /** Most lines one message may have. */
    public const MAX_LINES = 4;

    /**
     * @var array<string, list<list<string>>>
     */
    public const TEMPLATES = [
        // Upcoming tournaments, open for sign-up.
        'tournament_signup' => [
            ['🏆 Sign-up is open: :name', '🎮 :game · starts :starts', '🪑 :spots:pot', '👉 Grab your spot: :url'],
            ['📣 New on the board: :name', '🗓️ :starts · :game', '🪑 :spots:pot', '👉 :url'],
            ['🆕 :name is taking names', '🎮 :game · starts :starts', '🪑 :spots:pot', '👉 Claim a seat: :url'],
            ['📋 Open card: :name', '🗓️ Starts :starts · :game', '🪑 :spots:pot', '👉 :url'],
            ['🎫 :name has room', '🎮 :game, first move :starts', '🪑 :spots:pot', '👉 Sign up: :url'],
            ['🏟️ :name is on the board', '🗓️ :starts · :game', '🪑 :spots:pot', '👉 Take a look: :url'],
        ],
        'tournament_last_call' => [
            ['⏳ Last call for :name', '🚪 Sign-up closes in :left, :open still free', '👉 :url'],
            ['⏳ Only :left left to join :name', '🪑 :open still free · :game', '👉 Sign up: :url'],
            ['🚨 :name closes in :left', '🪑 :open seats left · :game', '👉 Last seats: :url'],
            ['⌛ :left until :name locks', '🚪 :open still open', '👉 :url'],
        ],

        // Happening now.
        'tournament_live' => [
            ['🔴 :name is running right now', '📺 Brackets, boards and results live on the big-screen view', '👉 :url'],
            ['📺 :name is live: every match as it happens', '👉 :url'],
            ['🔴 :name is on the TV view', '👀 Boards update as the games finish', '👉 :url'],
            ['📡 :name, in progress', '👉 Follow the bracket: :url'],
        ],
        'live_game' => [
            ['♟️ Live on the board: :white vs :black', '⏱️ :mode, in progress', '👀 Watch: :url'],
            ['👀 :white and :black are playing :mode right now', '👉 Spectate: :url'],
            ['♟️ :white vs :black, :mode, clock running', '👀 Pull up a chair: :url'],
            ['🔴 A board is live: :white against :black', '⏱️ :mode', '👉 :url'],
        ],
        'live_games' => [
            ['🔴 :count games are live right now', '👀 Pick a board and watch: :url'],
            ['🔴 :count matches live this minute', '👉 Watch: :url'],
            ['👀 :count boards are in progress', '👉 Choose one: :url'],
            ['📡 :count live games, one page', '👉 :url'],
        ],
        'live_series' => [
            ['🏁 :game: :home vs :away, best of :best_of', '👉 Follow the match: :url'],
            ['🏁 :home vs :away in :game, best of :best_of', '👀 Catch it live: :url'],
            ['⚔️ Series on: :home vs :away', '🎮 :game, best of :best_of', '👉 :url'],
            ['📺 :home and :away are in a :game series', '🏁 Best of :best_of', '👉 Watch the page: :url'],
        ],
        // A chess team match of two clans (plan "Schach Rapid und Clan", P6), posted by the live_series builder.
        'live_team_match' => [
            ['♟️ Clan team match: :home vs :away, :boards boards of rapid at once', '👉 Watch every board: :url'],
            ['♟️ :home vs :away: a chess team match over :boards boards is on', '👀 Follow the boards live: :url'],
            ['🛡️ :home vs :away, :boards boards at once', '👀 Every board is on the match page', '👉 :url'],
            ['♟️ Team rapid: :home against :away', '🏁 :boards boards, one match', '👉 :url'],
        ],

        // Results and pride.
        // The GG the moment a tournament is decided (ChampionChat), outside the rotation: one winner, then a shared place 1.
        'tournament_gg' => [
            ['🏆 GG! :winner wins :name 🎉 :url'],
            ['🏆 GG! :winner win :name 🎉 :url'],
        ],
        'tournament_winner' => [
            ['🥇 :winner won :name', '🎉 GG to everyone who played', '👉 Bracket and results: :url'],
            ['🏆 Champion of :name: :winner', '👉 See how it went: :url'],
            ['🥇 :name belongs to :winner', '🎉 Well played, the whole field', '👉 :url'],
            ['🏆 Final of :name: :winner', '👉 The bracket: :url'],
        ],
        'rank_up' => [
            ['📈 Rank up! :player reached :tier in :game', '👉 :url'],
            ['🎖️ :player climbed to :tier (:game)', '👉 Profile and badges: :url'],
            ['📈 :player just reached :tier in :game', '👉 :url'],
            ['🎖️ :player has a new tier, :tier in :game', '👉 See the badge: :url'],
        ],
        'new_clan' => [
            ['🛡️ New clan: :name [:tag]', '🤝 Say hi and challenge them', '👉 :url'],
            ['🛡️ Welcome to the league, :name [:tag]!', '👉 Meet the clan: :url'],
            ['🆕 Clan founded: :name [:tag]', '🤝 The challenge page is open', '👉 :url'],
            ['🛡️ [:tag] :name is in', '👉 Say hello: :url'],
        ],
        'ladder_top' => [
            ['🪜 :ladder ladder right now', ':podium', '👉 Full ladder: :url'],
            ['🪜 Where the :ladder ladder stands', ':podium', '👉 See it all: :url'],
            ['🥇 :ladder podium today', ':podium', '👉 The rest of the ladder: :url'],
            ['🪜 Top of :ladder', ':podium', '👉 :url'],
        ],
        'season_live' => [
            ['⛏️ The season is live: rated wins mine blocks on the league chain', '👉 Watch the chain grow: :url'],
            ['⛏️ Season is on: every rated win mines a block', '👉 Watch it grow: :url'],
            ['⛏️ Rated wins are mining. The chain is moving', '👉 Follow it: :url'],
            ['⛓️ Season chain is live. A rated win is a block', '👉 :url'],
        ],
        'season_countdown' => [
            ['⛏️ Block 0 in :left', '🔓 Then rated play and mining start, until then every game is casual', '👉 :url'],
            ['⛏️ :left until Block 0 hits', '🔓 Rated play and mining kick in then, casual for now', '👉 :url'],
            ['⏳ Block 0 in :left', '🎮 Casual until then, rated after', '👉 :url'],
            ['⛏️ :left on the clock before mining starts', '👉 The chain: :url'],
        ],
        'stats' => [
            ['📊 So far: :players players, :clans clans, :games games played', '👉 Join them: :url'],
            ['📊 :players players, :clans clans, :games games so far', '👉 Come join in: :url'],
            ['📊 The league right now: :players players, :clans clans', '♟️ :games games already played', '👉 :url'],
            ['👥 :players players in, :clans clans beside them', '📊 :games games on the board', '👉 :url'],
        ],

        // What you can do.
        'play_blitz' => [
            ['⚡ Fancy a game? Rapid 10+5 or blitz 5+3 in the lobby', '👉 :url'],
            ['♟️ One rapid game before bed? 10+5, right in the browser', '👉 :url'],
            ['⚡ Blitz 5+3 is open. The clock starts when both sit', '👉 :url'],
            ['♟️ Rapid 10+5, in the browser, no install', '👉 Take a board: :url'],
            ['🧠 One sharp game. The lobby is the whole path', '👉 :url'],
            ['🌙 Late and awake? Rapid is one click', '👉 :url'],
            ['🏁 Casual Elo moves when you play, not when you watch', '👉 Sit down: :url'],
            ['🎯 Blitz or rapid, same lobby, same ladder', '👉 :url'],
        ],
        'daily_chess' => [
            ['📬 No time for a live game? Daily chess: one move a day, whenever it suits you', '👉 Challenge someone: :url'],
            ['📬 Short on time? Daily chess: one move a day, no rush', '👉 Start a game: :url'],
            ['📬 One move today keeps a real game alive', '👉 Daily chess: :url'],
            ['🌙 Daily chess waits. It does not expire at midnight', '👉 :url'],
            ['♟️ No clock, one move a day, a real opponent', '👉 Open a game: :url'],
            ['📬 Correspondence, not a chore: a move when you have one', '👉 :url'],
        ],
        'clan_challenge' => [
            ['🚗 :games: clans challenge clans in best-of series', '👉 Send a challenge: :url'],
            ['🚗 Clans face off in best-of series across :games', '👉 Challenge one: :url'],
            ['🛡️ Clan versus clan, best of series: :games', '👉 Throw it: :url'],
            ['⚔️ A clan ladder is there to be used: :games', '👉 :url'],
            ['🏁 Not a one-off: best of series in :games', '👉 Challenge a clan: :url'],
            ['🤝 Call out another clan. :games', '👉 :url'],
        ],
        'invite_friend' => [
            ['💌 Play a friend: make an invite link in the lobby and send it anywhere', '👉 :url'],
            ['💌 Got a friend to play? Grab an invite link from the lobby', '👉 :url'],
            ['🤝 A friend, a link, a board. That is the path', '👉 :url'],
            ['📨 The lobby writes the link. You only send it', '👉 :url'],
            ['🎮 No scavenger hunt: invite link in, friend plays', '👉 :url'],
            ['🔗 One link from the lobby, one friend on the other side', '👉 :url'],
        ],
        'clans' => [
            ['🛡️ Better together: join a clan or start your own', '👥 :count clans are already in', '👉 :url'],
            ['🛡️ Solo or squad: join a clan or start one up', '👥 :count clans already here', '👉 :url'],
            ['🏴 A clan is a tag and people who show up', '👥 :count clans are already in', '👉 :url'],
            ['🤝 Join one or found one. Both count', '👥 :count clans already here', '👉 :url'],
            ['🛡️ The clan list is open', '👥 :count clans are already in', '👉 Find yours: :url'],
            ['👥 Squads are how the long season gets played', '🛡️ :count clans already here', '👉 :url'],
        ],
        'badges' => [
            ['🎖️ Every rank comes with a badge you can show on your Nostr profile', '👉 Start climbing: :url'],
            ['🎖️ Climb the ranks, earn a badge for your Nostr profile', '👉 :url'],
            ['🏅 A tier is a badge, not a private number', '👉 Climb: :url'],
            ['🎖️ The ladder pays in badges you can pin to Nostr', '👉 :url'],
            ['📈 Silver, gold, and on: each tier has a badge', '👉 Start: :url'],
            ['🥇 Rank up here, show it on your Nostr profile', '👉 :url'],
        ],
        'all_games' => [
            ['🎮 :games: every game and mode on one page', '👉 :url'],
            ['🎮 One page, every game and mode: :games', '👉 Check it out: :url'],
            ['🗂️ The whole league on one page: :games', '👉 :url'],
            ['🎯 Pick a game, see the mode, sit down', '🎮 :games', '👉 :url'],
            ['🧭 Not sure where to start? The games page', '🎮 :games', '👉 :url'],
            ['🎮 Boards, lobbies, weeks: :games', '👉 All of it: :url'],
        ],
        'login' => [
            ['🔑 Log in with Nostr or Google and you are in', '👉 :url'],
            ['🔑 Nostr or Google login, takes seconds', '👉 :url'],
            ['🔐 Nostr or Google. Then you are a player, not a guest', '👉 :url'],
            ['🪪 Login is the gate. The games are past it', '👉 :url'],
            ['⚡ Sign in once. Rating, clan and invites stick', '👉 :url'],
            ['🔑 Two doors: Nostr or Google', '👉 :url'],
        ],
        'zap' => [
            ['⚡ Enjoying the stream? Zap it right here, every sat says thanks', '🌐 More on the site: :url'],
            ['⚡ Liking the stream? A zap goes a long way', '🌐 :url'],
            ['🧡 A zap on the stream is a sat that landed', '🌐 :url'],
            ['⚡ The stream runs all day. A zap is how you wave', '🌐 :url'],
            ['💸 No speech needed: zap the stream', '🌐 More of the league: :url'],
            ['🙏 If the overlay made you grin, zap it', '🌐 :url'],
        ],

        // TMNF's weekly time attack (plan "Trackmania und Restposten"), in the chat only: the running week, a new
        // best time of it, the finished week's podium. Drivers by Nostr key or league name, never a TMNF login.
        // The favourite link (tmtp://#addfavourite=…) stays on the week page: zap.stream would render its `#` as a hashtag.
        'tmnf_week' => [
            ['🏎️ TMNF time attack this week: :track', '🏁 Drive it on our server :server, your best finish until :ends counts', '👉 How to join: :url'],
            ['🏁 :name runs on :track', '🏎️ Free to play: join :server in TMNF and set a time before :ends', '👉 Join in: :url'],
            ['🏎️ This week\'s TMNF track is :track', '🏁 Server :server, best finish before :ends', '👉 How to join: :url'],
            ['🏁 :name, track :track, open until :ends', '🏎️ Our server: :server', '👉 :url'],
        ],
        'tmnf_top' => [
            ['🥇 New best time in :name: :player', '⏱️ :time on :track:gap', '👉 Beat it: :url'],
            ['⚡ :player tops :name with :time on :track', '🏎️ Think you are faster?', '👉 Your turn: :url'],
            ['🥇 :player just took :name', '⏱️ :time on :track:gap', '👉 The week page: :url'],
            ['🏎️ New lead in :name: :player, :time', '🏁 :track:gap', '👉 Beat it: :url'],
        ],
        'tmnf_podium' => [
            ['🏆 :name is over: :winner wins on :track', ':podium', '👉 All weeks: :url'],
            ['🏁 Final board of :name on :track', ':podium', '👉 :url'],
            ['🏆 :track is settled. :winner takes :name', ':podium', '👉 Every week: :url'],
            ['🥇 :name, final: :winner', ':podium', '👉 :url'],
        ],

        // Notes on the bot's own profile (kind 1, TournamentNotes), one per tournament, by its status
        // when the note goes out. The `nostr:naddr1…` of the calendar event follows after a blank line.
        // Every profile note has several wordings; ProfileNotes picks the one after the type's last note, so the same
        // wording never follows itself. A tournament note always names its start (TournamentNotes finds a moved one by it).
        'tournament_note_open' => [
            ['🏆 New tournament: :name', '🎮 :game · starts :starts', '💰 :pot sats in the pot', '👉 Sign up: :url'],
            ['🏆 :name starts :starts. Sign up: :url'],
            ['👀 Worth a seat: :name starts :starts.', '🎮 :game', '💰 :pot sats in the pot', '👉 :url'],
            ['🆕 :name is open. It starts :starts.', '🎮 :game', '💰 :pot sats in the pot', '👉 Take a seat: :url'],
            ['📣 Fresh card: :name starts :starts.', '👉 Sign up: :url'],
        ],
        'tournament_note_running' => [
            ['🏆 Tournament on now: :name', '🎮 :game · started :starts', '💰 :pot sats in the pot', '👉 Follow it: :url'],
            ['🔴 :name is under way. Follow it: :url'],
            ['📺 It is moving: :name started :starts.', '🎮 :game', '👉 Watch the bracket: :url'],
            ['🔴 Live bracket: :name', '🎮 :game · started :starts', '👉 :url'],
        ],
        'tournament_note_finished' => [
            ['🏆 Tournament: :name', '🎮 :game · played :starts', '💰 :pot sats in the pot', '👉 Bracket and results: :url'],
            ['🏁 :name is decided. See how it went: :url'],
            ['🏁 That one is over: :name, played :starts.', '🎮 :game', '👉 :url'],
            ['🏆 :name is in the books', '🎮 :game · played :starts', '👉 Results: :url'],
        ],
        // Free-places reminders on the same profile (FreePlaceNotes, P49), while sign-up is open and
        // places are left; the `nostr:naddr1…` follows after a blank line here too.
        'tournament_note_places' => [
            ['🪑 :free of :places places left: :name', '🎮 :game · starts :starts', '⏳ Sign-up closes in :left', '👉 Grab a place: :url'],
            ['🪑 :name still has :free places. Sign up: :url'],
            ['🪑 Room left in :name, :free of :places, closes in :left.', '🎮 :game · starts :starts', '👉 :url'],
            ['🚨 :free seats left in :name', '⏳ Closes in :left · starts :starts', '👉 :url'],
        ],
        // Blockfill's week notes on the same profile (BlockfillNotes, plan "Blockfill", P6): a week once it is open,
        // its winner and top 3 once it is finished; the week's `nostr:naddr1…` follows after a blank line once published.
        'blockfill_note_week' => [
            ['🧱 A new Blockfill week is open: :name', '⏱️ Mine :blocks blocks as fast as you can, your best ranked run of the week counts', '🗓️ Until :ends', '👉 Play: :url'],
            ['⛏️ :name is open until :ends. Play: :url'],
            ['🧱 Fresh board: :name runs until :ends.', '🧱 :blocks blocks, best run counts', '👉 :url'],
            ['🆕 :name is the live Blockfill week', '⏱️ Best verified run before :ends', '👉 Play: :url'],
        ],
        'blockfill_note_winner' => [
            ['🏆 :name goes to :winner in :time', '🧱 Top 3: :podium', '🔁 A new week is on, every run starts from zero', '👉 All weeks: :url'],
            ['🥇 :winner wins :name in :time', '🧱 Podium: :podium', '🔁 Fresh week, fresh board', '👉 All weeks: :url'],
        ],
        // A verified run that took the running week's first place; `:gap` is empty for the week's first one.
        'blockfill_note_top' => [
            ['🥇 New first place in :name: :player', '⏱️ :time:gap', '🗓️ The week runs until :ends', '👉 Beat it: :url'],
            ['🥇 :player leads :name. Beat it: :url'],
            ['🥇 New name on top of :name: :player.', '⏱️ :time:gap', '👉 :url'],
            ['⚡ :player takes :name', '⏱️ :time:gap', '🗓️ Open until :ends', '👉 Beat it: :url'],
        ],
        // Several first places since the bot's last one (a burst) in one note: `:count` of them, the leader now and
        // the others who held the top meanwhile (`:others` null, so left out, when the leader only beat themself).
        'blockfill_note_top_burst' => [
            ['🥇 :count new first places in :name', '🏁 On top now: :player in :time', '⚔️ Also held it: :others', '👉 Beat it: :url'],
            ['🔥 First place changed hands :count times in :name', '🥇 :player leads with :time', '⚔️ Before: :others', '👉 Your turn: :url'],
        ],
        // TMNF's week notes (TmnfNotes, plan "Trackmania und Restposten", P2), as Blockfill's: the week and its track
        // once it is open, a new first place (`:gap` empty for the week's first), the winner and top 3.
        'tmnf_note_week' => [
            ['🏁 A new TMNF week is open: :name', '🏎️ Track :track on our own server, your best finish of the week counts', '🗓️ Until :ends', '👉 How to join: :url'],
            ['🏎️ :name is on: Track :track', '🏁 Free to play on our own server, only your best finish counts', '🗓️ Open until :ends', '👉 How to join: :url'],
        ],
        'tmnf_note_winner' => [
            ['🏆 :name goes to :winner in :time on :track', '🏁 Top 3: :podium', '🔁 A new week is on, every time starts from zero', '👉 All weeks: :url'],
            ['🥇 :winner wins :name on :track in :time', '🏁 Podium: :podium', '🔁 Fresh week, fresh board', '👉 All weeks: :url'],
        ],
        // On the profile only with `esports.stream_bot.tmnf_notes.top_on_profile`; the chat tells new best times (tmnf_top).
        'tmnf_note_top' => [
            ['🥇 New first place in :name: :player', '⏱️ :time on :track:gap', '🗓️ The week runs until :ends', '👉 Beat it: :url'],
            ['⚡ :player takes the lead in :name', '⏱️ :time on :track:gap', '🗓️ Open until :ends', '👉 Your turn: :url'],
        ],
        'tmnf_note_top_burst' => [
            ['🥇 :count new first places in :name', '🏁 On top now: :player in :time on :track', '⚔️ Also held it: :others', '👉 Beat it: :url'],
            ['🔥 First place changed hands :count times in :name', '🥇 :player leads with :time on :track', '⚔️ Before: :others', '👉 Your turn: :url'],
        ],
        // A finished tournament's champion on the same profile (ChampionNotes), once per tournament: the winner tagged,
        // the prize only once it is paid out; the `nostr:naddr1…` and the rendered champion slide follow after blank lines.
        'champion_note' => [
            ['🏆 :winner wins :name (:game)', '🎉 Congratulations, champion!', '⚡ :prize sats prize paid out', '👉 Bracket and results: :url'],
            ['🏆 :winner takes :name. 👉 :url'],
            ['🏆 That title is settled: :winner wins :name.', '🎮 :game', '⚡ :prize sats paid out to the champion', '👉 :url'],
            ['🎉 Champion: :winner, :name', '🎮 :game', '👉 The bracket: :url'],
        ],
        // Pride notes on the same profile (PrideNotes): players named for what they did, tagged; the
        // rendered slide follows after a blank line. Written by the kommunikator (2026-09-28).
        'pride_note_win' => [
            ['⚡ :winner takes the win over :loser (:mode)', '📈 :elo', '👉 Watch the game: :url'],
            ['🏆 :winner beats :loser. Watch it: :url'],
            ['👏 Nice one: :winner beat :loser in :mode.', '📈 :elo', '👉 :url'],
            ['⚡ :mode goes to :winner', '👀 Over :loser', '📈 :elo', '👉 Watch: :url'],
        ],
        'pride_note_climbers' => [
            ['🚀 Biggest gainers of the last :days days', '📈 :players', '👉 See the ladder: :url'],
            ['📈 Climbing fast this week', '📈 :players', '🗓️ Over the last :days days', '👉 :url'],
            ['🪜 Who moved up in :days days', '📈 :players', '👉 The ladder: :url'],
        ],
        'pride_note_signups' => [
            ['🙌 Welcome aboard: :players', '📝 :count new sign-ups', '❓ Who joins next?', '👉 :url'],
            ['📝 :players just signed up. 👉 :url'],
            ['🙌 New faces: :players.', '📝 :count sign-ups', '👉 :url'],
            ['🆕 :count new players', '🙌 :players', '👉 Come in after them: :url'],
        ],
        'pride_note_prizes' => [
            ['💰 :pot sats in the pot for :name', '🥇 :places', '🎁 :sponsors', '👉 :url'],
            ['⚡ :pot sats up for grabs: :name', '🥇 :places', '🎁 :sponsors', '👉 Sign up: :url'],
            ['💰 :name is holding :pot sats', '🥇 :places', '🎁 :sponsors', '👉 :url'],
        ],
        // A won series (Rocket League, EA Sports FC 1v1), in place of pride_note_win: the match page, not a game to watch.
        'pride_note_series_win' => [
            ['⚡ :winner takes the series over :loser (:mode)', '📈 :elo', '👉 See the match: :url'],
            ['🏆 :winner wins the series against :loser in :mode', '📈 :elo', '🎉 Well played to both', '👉 See the match: :url'],
        ],
        // A board game that won its winner a knockout tournament in its final (plan "Mühle und Dame", P7), in place of pride_note_win.
        'pride_note_tournament_win' => [
            ['🏆 :winner wins :tournament', '⚔️ Deciding game over :loser (:mode)', '📈 :elo', '👉 See the tournament: :url'],
            ['🥇 :tournament goes to :winner', '⚔️ Beat :loser in :mode', '🎉 Well played to both', '👉 :url'],
        ],
        // A lobby tournament's place 1 (plan "AoE2 und Trackmania", P8), in place of pride_note_win: every player on it tagged.
        'pride_note_lobby_win' => [
            ['🏆 :first in :tournament', '🎉 :winners', '🏰 :mode, one lobby match, :players players', '👉 See the places: :url'],
            ['🥇 :tournament is decided', '🏆 :first: :winners', '🏰 Diplomacy in :mode, :players players', '👉 :url'],
        ],
        // The same for a table (Swiss, round robin): the last game decided nothing on its own.
        'pride_note_tournament_table_win' => [
            ['🏆 :winner wins :tournament (:mode)', '📈 :elo', '👉 See the tournament: :url'],
            ['🥇 :tournament goes to :winner', '🎉 Well played, everyone', '👉 :url'],
            ['🏆 Table closed: :winner takes :tournament', '🎮 :mode', '👉 :url'],
        ],

        // Chat tips for games that had no line of their own. A builder offers one only while that game is registered.
        'proof_of_pong' => [
            ['🏓 Proof of Pong: first to 21, in the browser', '🕹️ A bot, or a live 1v1 that moves Elo', '👉 :url'],
            ['🏓 Pong, and the ball is a Bitcoin meme', '⚡ To 21, win by 2, right here', '👉 Play: :url'],
            ['🕹️ Proof of Pong is on: paddle, arena, Elo', '👉 :url'],
            ['🏓 No install. Proof of Pong to 21', '👀 Spectate a live match or take a paddle', '👉 :url'],
            ['⚡ One more game of Pong. The ladder remembers it', '👉 :url'],
            ['🏓 Classic Pong, league Elo, meme arena', '👉 Sit down: :url'],
        ],
        'hyperbitcoinization' => [
            ['🗺️ Hyperbitcoinization: a map for 2 to 6', '⏱️ Live turns, or one move a day', '👉 :url'],
            ['🌍 Take the map. Hyperbitcoinization is open', '🎲 Live or correspondence, your table', '👉 :url'],
            ['🗺️ A table is waiting in Hyperbitcoinization', '👉 Open one: :url'],
            ['⚔️ 2 to 6 seats, one map, factions with a point of view', '👉 Hyperbitcoinization: :url'],
            ['🌙 No hour to sit? Correspondence Hyperbitcoinization, a day a turn', '👉 :url'],
            ['🗺️ The season ladder tracks the map, not the chat', '👉 Play: :url'],
        ],
        'blockfill_play' => [
            ['🧱 Blockfill: clear the week\'s blocks, best time counts', '⏱️ Your run, your replay, your place', '👉 :url'],
            ['⛏️ A Blockfill run starts from zero. The week does not', '👉 Play: :url'],
            ['🧱 Stack, clear, submit. Blockfill ranks the verified time', '👉 :url'],
            ['⏱️ First place this week is a time. Beat it in Blockfill', '👉 :url'],
            ['🧱 Blockfill is in the browser. No account in another game', '👉 :url'],
            ['🏁 One good run is the whole Blockfill week', '👉 Try it: :url'],
        ],
        'board_game' => [
            ['♟️ :game, one move a day, on our board', '👉 :url'],
            ['🧩 :game is correspondence: a move when you have one', '👉 Open the lobby: :url'],
            ['♟️ No clock. :game waits for your move', '👉 :url'],
            ['🧩 :game sits next to chess. Same league, slower clock', '👉 :url'],
            ['📬 A daily board: :game', '👉 :url'],
            ['♟️ :game, played here, rated here', '👉 :url'],
        ],
        'aoe2' => [
            ['🏰 Age of Empires II: best of 1 or 3, on your own copy', '👉 How a match works: :url'],
            ['🏰 AoE2 1v1 is a player ladder. 2v2 and 3v3 are clans', '👉 :url'],
            ['⚔️ Age of Empires II is on the league board', '🏰 Host the lobby in the game, report the winner here', '👉 :url'],
            ['🏰 One map, a winner, Elo. Age of Empires II', '👉 :url'],
            ['🛡️ Clan lineups play AoE2 2v2 and 3v3', '👉 See the page: :url'],
            ['🏰 Age of Empires II: Definitive Edition, best of series', '👉 :url'],
        ],
        'ea_fc' => [
            ['⚽ EA Sports FC: best of 1 or 3, goals count', '👉 :url'],
            ['⚽ FC 1v1 is a player ladder. 2v2 is a clan lineup', '👉 :url'],
            ['🏟️ EA Sports FC, on your copy, reported here', '👉 :url'],
            ['⚽ A series, not a friendly: EA Sports FC', '👉 :url'],
            ['🥅 A game won on penalties is still a win. Log the series', '👉 EA Sports FC: :url'],
            ['⚽ Two editions, two ladders. Pick yours', '👉 :url'],
        ],
    ];

    /**
     * One variant of a template with its values filled in, lines joined
     * with a newline; lines whose placeholder is null are left out.
     *
     * @param  array<string, string|int|null>  $values
     */
    public static function render(string $template, int $variant, array $values): string
    {
        $variants = self::TEMPLATES[$template] ?? throw new LogicException("Unknown stream bot template [{$template}].");
        $lines = $variants[$variant % count($variants)];
        $out = [];

        foreach ($lines as $line) {
            preg_match_all('/:([a-z_]+)/', $line, $matches);
            $replace = [];
            $skip = false;

            foreach ($matches[1] as $name) {
                if (! array_key_exists($name, $values)) {
                    throw new LogicException("Stream bot template [{$template}] needs the value [{$name}].");
                }

                if ($values[$name] === null) {
                    $skip = true;

                    break;
                }

                $replace[':'.$name] = (string) $values[$name];
            }

            if (! $skip) {
                $out[] = strtr($line, $replace);
            }
        }

        return implode("\n", $out);
    }

    /** "3 days 4 h", "2 h 15 min", "40 min"; never less than a minute. */
    public static function duration(int $seconds): string
    {
        $seconds = max(60, $seconds);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $days.' '.($days === 1 ? 'day' : 'days').($hours > 0 ? ' '.$hours.' h' : '');
        }

        return $hours > 0 ? $hours.' h'.($minutes > 0 ? ' '.$minutes.' min' : '') : $minutes.' min';
    }

    public static function variants(string $template): int
    {
        return count(self::TEMPLATES[$template] ?? []);
    }

    /**
     * A name from the database (a player, clan or tournament) made safe for
     * the chat: one line, no `#` (it would render as a hashtag), no links or
     * `nostr:` references of its own, at most `$max` characters. Empty when
     * nothing is left.
     */
    /**
     * A tournament's name in a result post, without a sats amount in it (user, 2026-10-04: "Der Bot darf keine Gewinn
     * Zahlen schreiben"; "@UWE wins 21,000 Sats, Zero Ball Control" read as a prize): "21,000 Sats, Zero Ball Control"
     * becomes "Zero Ball Control". A name that is nothing but an amount stays as it is.
     */
    public static function resultName(?string $name, int $max = 40): string
    {
        $clean = self::clean($name, 200);
        $stripped = trim((string) preg_replace('/(?<![\p{L}\p{N}])[\d][\d.,\s\x{202F}\x{00A0}]*\s*k?\s*sats?\b[\s,:;\-–—|·]*/iu', ' ', $clean), " \t,:;-–—|·");
        $stripped = trim((string) preg_replace('/\s{2,}/u', ' ', $stripped));

        return self::clean($stripped === '' ? $clean : $stripped, $max);
    }

    public static function clean(?string $name, int $max = 40): string
    {
        $name = (string) $name;
        $name = (string) preg_replace('~\b[a-z][a-z0-9+.-]*://\S*|\bwww\.\S+|\bnostr:\S+~iu', ' ', $name);
        $name = str_replace('#', '', $name);
        $name = trim((string) preg_replace('/[\s\p{Cc}\p{Cf}]+/u', ' ', $name));

        return mb_strlen($name) > $max ? rtrim(mb_substr($name, 0, $max - 1)).'…' : $name;
    }

    /**
     * What is wrong with a message, empty when nothing: a `#` (hashtag), a
     * `t` tag, no http(s) link, too many lines, an empty text.
     *
     * @param  list<list<string>>  $tags
     * @return list<string>
     */
    public static function violations(string $content, array $tags = []): array
    {
        $problems = [];

        if (trim($content) === '') {
            $problems[] = 'empty';
        }

        if (str_contains($content, '#')) {
            $problems[] = 'contains # (hashtag)';
        }

        foreach ($tags as $tag) {
            if (($tag[0] ?? null) === 't') {
                $problems[] = 't tag';
            }
        }

        if (preg_match('~https?://\S+~', $content) !== 1) {
            $problems[] = 'no link';
        }

        if (count(explode("\n", $content)) > self::MAX_LINES) {
            $problems[] = 'more than '.self::MAX_LINES.' lines';
        }

        return $problems;
    }
}
