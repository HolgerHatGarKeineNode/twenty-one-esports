/* UI text for the mock-ups: taken verbatim from the app (lang/de.json keys,
 * English = the key). This is interface text as the product shows it, not
 * campaign copy; campaign copy comes only from src/copy/*.md.
 * Names and clan tags are the local dev/seed data (factory data, no prod). */
(function () {
  'use strict';
  const S = {
    // chess lobby (pages/chess/⚡lobby)
    findOpponent: { en: 'Find an opponent', de: 'Gegner finden' },
    blitzLive: { en: 'Blitz 5+3, live', de: 'Blitz 5+3, live' },
    playersSearching: { en: 'players searching now', de: 'Spieler suchen gerade' },
    casual: { en: 'Casual', de: 'Casual' },
    casualElo: { en: 'casual Elo only', de: 'nur Casual-Elo' },
    logInToPlay: { en: 'Log in to play', de: 'Anmelden und spielen' },
    dailyChallenge: { en: 'Daily challenge', de: 'Fernschach-Herausforderung' },
    inviteOnline: { en: 'Invite a friend who is online', de: 'Freund einladen, der online ist' },
    // login (pages/auth/login)
    nostrExt: { en: 'Nostr extension or app', de: 'Nostr-Erweiterung oder App' },
    nostrFor: { en: 'for people who already have a Nostr key', de: 'für alle, die schon einen Nostr-Schlüssel haben' },
    google: { en: 'Continue with Google', de: 'Weiter mit Google' },
    noPassword: { en: 'No password to remember.', de: 'Kein Passwort zum Merken.' },
    newHere: { en: 'New here? The same buttons create your player.', de: 'Neu hier? Dieselben Knöpfe legen deinen Spieler an.' },
    // game + daily
    yourMove: { en: 'Your move', de: 'Dein Zug' },
    dailyVs: { en: 'Daily chess 12 against satsjaeger, your move', de: 'Fernschach 12 gegen satsjaeger, dein Zug' },
    dailyShort: { en: 'Daily 12, your move', de: 'Fernschach 12, dein Zug' },
    playYourMove: { en: 'Play your move', de: 'Deinen Zug spielen' },
    // watch
    liveGames: { en: 'Live games', de: 'Laufende Partien' },
    watch: { en: 'Watch', de: 'Zuschauen' },
    spectators: { en: 'Spectators', de: 'Zuschauer' },
    liveMove: { en: 'Live · move :move · :side to move', de: 'Live · Zug :move · :side am Zug' },
    white: { en: 'White', de: 'Weiß' },
    black: { en: 'Black', de: 'Schwarz' },
    // clans (pages/clans/⚡create, ⚡manage, ⚡show)
    startClan: { en: 'Start a clan', de: 'Clan gründen' },
    tag: { en: 'tag', de: 'Kürzel' },
    currentLogo: { en: 'Current logo', de: 'Aktuelles Logo' },
    createJoinLink: { en: 'Create join link', de: 'Beitrittslink erstellen' },
    copyClanLink: { en: 'Copy clan link', de: 'Clan-Link kopieren' },
    clans: { en: 'Clans', de: 'Clans' },
    // footer (components/shell/footer)
    rules: { en: 'Rules', de: 'Regeln' },
    howVerified: { en: 'How results are verified', de: 'So werden Ergebnisse geprüft' },
    openSource: { en: 'Open source', de: 'Open Source' },
    legal: { en: 'Legal notice', de: 'Impressum' },
    // tournaments
    openForSignup: { en: 'Open for sign-up', de: 'Offen zur Anmeldung' },
    round1: { en: 'Round 1', de: 'Runde 1' },
    semifinal: { en: 'Semifinal', de: 'Halbfinale' },
    final: { en: 'Final', de: 'Finale' },
    signUp: { en: 'Sign up', de: 'Anmelden' },
    // invites (InviteCard + pages/invites/⚡link)
    beatMe: { en: 'Beat me at blitz?', de: 'Schlägst du mich im Blitz?' },
    tapSeat: { en: '5+3 chess, casual. Tap to take the seat.', de: 'Schach 5+3, casual. Tippen und Platz nehmen.' },
    copyInvite: { en: 'Copy invite link', de: 'Einladungslink kopieren' },
    inviteReady: { en: 'Invite ready. Send them this link:', de: 'Einladung bereit. Schick diesen Link:' },
    invitesBlitz: { en: 'satsjaeger invites you to a blitz game', de: 'satsjaeger lädt dich zu einer Blitzpartie ein' },
    takeSeat: { en: 'Take the seat', de: 'Platz nehmen' },
    logInSit: { en: 'Log in to sit down', de: 'Anmelden und Platz nehmen' },
    // reels
    opponentFound: { en: 'Opponent found', de: 'Gegner gefunden' },
    copied: { en: 'Copied', de: 'Kopiert' },
    linkCopied: { en: 'Link copied', de: 'Link kopiert' },
    yourDailyGames: { en: 'Your daily games', de: 'Deine Fernschachpartien' },
    dailyTheirs: { en: 'Daily chess :number against :name, their move', de: 'Fernschach :number gegen :name, Gegner am Zug' },
    dailyYours: { en: 'Daily chess :number against :name, your move', de: 'Fernschach :number gegen :name, dein Zug' },
    name: { en: 'Name', de: 'Name' },
    noLogoYet: { en: 'no logo yet', de: 'noch kein Logo' },
    manageFields: { en: 'Name, tag, description, logo and meetup link', de: 'Name, Kürzel, Beschreibung, Logo und Meetup-Verknüpfung' },
    search: { en: 'Search players, clans or match #', de: 'Spieler, Clans oder Match-# suchen' },
    logIn: { en: 'Log in', de: 'Anmelden' },
    casualLadder: { en: 'Casual ladder', de: 'Casual-Ladder' },
    casualNote: { en: 'permanent, no tier, no reward, no season reset', de: 'dauerhaft, kein Rang, keine Belohnung, kein Season-Reset' },
    colPlayer: { en: 'Player', de: 'Spieler' },
    colElo: { en: 'Elo', de: 'Elo' },
    colGames: { en: 'Games', de: 'Partien' },
    colWdl: { en: 'W / D / L', de: 'S / R / N' },
    // login (pages/auth/login), reel 6
    waitingConfirm: { en: 'Waiting for your confirmation', de: 'Warte auf deine Bestätigung' },
    // tournaments (pages/tournaments/⚡show), reel 7
    signupOpen: { en: 'Sign-up open', de: 'Anmeldung offen' },
    whoPlays: { en: 'Who plays', de: 'Wer spielt' },
    openSpot: { en: 'Open spot', de: 'Freier Platz' },
    yourSpot: { en: 'Your spot?', de: 'Dein Platz?' },
    isIn: { en: ':a is in.', de: ':a ist dabei.' },
    confirmNostr: { en: 'Confirm with your Nostr key. You can pull out until sign-up closes.', de: 'Bestätige mit deinem Nostr-Schlüssel. Bis Anmeldeschluss kannst du dich wieder abmelden.' },
    theDraw: { en: 'The draw', de: 'Die Auslosung' },
    blockHash: { en: 'Block hash', de: 'Block-Hash' },
    seeding: { en: 'Seeding', de: 'Setzliste' },
    byEloAtClose: { en: 'by Elo at sign-up close', de: 'nach Elo bei Anmeldeschluss' },
    reported: { en: 'Reported', de: 'Gemeldet' },
    confirm: { en: 'Confirm', de: 'Bestätigen' },
    winner: { en: 'Winner', de: 'Sieger' },
    playersReport: { en: 'Players report results', de: 'Spieler melden Ergebnisse' },
    // upcoming tournaments (pages/tournaments/⚡show, prize pool, formats), reels 10-12
    prizePool: { en: 'Prize pool', de: 'Preispool' },
    zapPool: { en: 'Zap the pool', de: 'Pool zappen' },
    sponsors: { en: 'Sponsors', de: 'Sponsoren' },
    groupStage: { en: 'Group stage', de: 'Gruppenphase' },
    groupsThenKo: { en: 'Groups first, then a knockout final', de: 'Erst Gruppen, dann K.-o.-Finale' },
    upperBracket: { en: 'Upper bracket', de: 'Oberes Bracket' },
    lowerBracket: { en: 'Lower bracket', de: 'Unteres Bracket' },
    doubleElim: { en: 'Double Elimination', de: 'Double Elimination' },
    twoStage: { en: 'Two Stage', de: 'Two Stage' },
    // ladder (casual only, pre-season)
    blitzLadder: { en: 'Blitz ladder', de: 'Blitz-Ladder' },
    casualUntil: { en: 'casual until Block 0', de: 'casual bis Block 0' },
  };
  window.UI = (key, lang, vars) => {
    const e = S[key];
    if (!e) throw new Error('UI string missing: ' + key);
    let s = e[lang] ?? e.en;
    for (const [k, v] of Object.entries(vars || {})) s = s.replace(':' + k, v);
    return s;
  };
})();
