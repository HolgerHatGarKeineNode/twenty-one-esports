/**
 * Hyperbitcoinization's world as the page draws it (plan "Hyperbitcoinization", P2): the ten currency spaces,
 * the 53 territories in the rules core's order (App\Support\Hyper\HyperMap::IDS), their neighbours, the six
 * factions, the event cards and the soundboard pools. Data only: no rule is decided here, the server's
 * snapshot and events say what happened.
 *
 * Visible texts are English keys marked with T(): the page translates them at render time (i18n.js, the
 * dictionary App\Support\Hyper\HyperTexts hands in). T() itself returns its argument; it only marks a key
 * for tests/Feature/Hyper/HyperTextsTest.php. Names that read the same in English and German stay plain.
 */
export const T = (key) => key;

export const ZONES = {
    dollar: { name: T('Dollar Zone'), bank: 'Fed', color: '#2f62d8', sats: 2, bonus: T('+25 % fiat income') },
    sa: { name: T('South American Union'), bank: 'Banco Central do Brasil', color: '#3c9c40', sats: 1, bonus: T('+2 fiat per turn') },
    pound: { name: 'Pound Zone', bank: 'Bank of England', color: '#7b45d6', sats: 0.5, bonus: T('10 % interest instead of inflation') },
    euro: { name: T('Euro Bloc'), bank: T('ECB'), color: '#2d8bd6', sats: 1.5, bonus: T('Plebs 20 % cheaper') },
    swiss: { name: 'Swiss Refuge', bank: 'SNB', color: '#d63a3a', sats: 0.5, bonus: T('Vault: always +1 defence') },
    rubel: { name: T('Rouble Fortress'), bank: 'Bank of Russia', color: '#e07b22', sats: 2, bonus: T('+1 defence in the whole space') },
    yuan: { name: T('Yuan Sphere'), bank: 'PBoC', color: '#c42a2a', sats: 3, bonus: T('Every 3rd pleb free') },
    yen: { name: 'Yen Realm', bank: 'BoJ', color: '#e6c22c', sats: 0.5, bonus: T('Your fiat does not decay') },
    afro: { name: T('Afro Union'), bank: T('African Central Bank'), color: '#17a39a', sats: 3, bonus: T('+2 free plebs per turn') },
    ozean: { name: T('Australia & Oceania'), bank: 'RBA', color: '#9b3fc6', sats: 1, bonus: T('+1 defence in the whole space') },
};

/** [id, name, zone, flags: b = central bank, m = mining hub], in HyperMap::IDS order. */
export const TDEF = [
    ['alaska', 'Alaska', 'dollar'], ['kanada', T('Canada'), 'dollar'], ['westk', T('West Coast'), 'dollar'], ['texas', 'Texas', 'dollar', 'm'], ['ny', 'New York', 'dollar', 'b'], ['mexiko', T('Mexico'), 'dollar'],
    ['kolumbien', T('Colombia'), 'sa'], ['brasilia', 'Brasília', 'sa', 'b'], ['paraguay', 'Paraguay', 'sa', 'm'], ['argentinien', T('Argentina'), 'sa'],
    ['island', T('Iceland'), 'pound', 'm'], ['irland', T('Ireland'), 'pound'], ['london', 'London', 'pound', 'b'],
    ['skandi', T('Scandinavia'), 'euro'], ['frankfurt', 'Frankfurt', 'euro', 'b'], ['westeuropa', T('Western Europe'), 'euro'], ['osteuropa', T('Eastern Europe'), 'euro'], ['mittelmeer', T('Mediterranean'), 'euro'],
    ['zuerich', T('Zurich'), 'swiss', 'b'],
    ['moskau', T('Moscow'), 'rubel', 'b'], ['sibirien', T('West Siberia'), 'rubel'], ['jakutien', T('Yakutia'), 'rubel'], ['kamtschatka', T('Far East'), 'rubel'], ['kasach', T('Kazakhstan'), 'rubel', 'm'], ['zentralasien', T('Central Asia'), 'rubel'],
    ['mongolei', T('Mongolia'), 'yuan'], ['mandschurei', T('Manchuria'), 'yuan'], ['peking', T('Beijing'), 'yuan', 'b'], ['xinjiang', 'Xinjiang', 'yuan'], ['tibet', 'Tibet', 'yuan'], ['shanghai', 'Shanghai', 'yuan'],
    ['suedostasien', 'Indochina', 'yuan'], ['myanmar', 'Myanmar', 'yuan'], ['indien', T('India'), 'yuan'], ['pakistan', 'Pakistan', 'yuan'],
    ['tokio', T('Tokyo'), 'yen', 'b'], ['seoul', 'Seoul', 'yen'],
    ['kairo', T('Cairo'), 'afro', 'b'], ['libyen', T('Libya'), 'afro'], ['nahost', T('Arabia'), 'afro'], ['persien', T('Persia'), 'afro'], ['maghreb', 'Maghreb', 'afro'], ['sahel', 'Sahel', 'afro'],
    ['nigeria', T('West Africa'), 'afro'], ['horn', T('Horn of Africa'), 'afro', 'm'], ['kenia', T('East Africa'), 'afro'], ['kongo', T('Congo'), 'afro'], ['sambesi', T('Zambezi'), 'afro'], ['suedafrika', T('South Africa'), 'afro'],
    ['indonesien', T('Indonesia'), 'ozean'], ['perth', 'Perth', 'ozean'], ['sydney', 'Sydney', 'ozean', 'b'], ['neuseeland', T('New Zealand'), 'ozean'],
];

export const ADJ_LIST = {
    alaska: ['kanada', 'westk', 'kamtschatka'], kanada: ['westk', 'texas', 'ny', 'island'], westk: ['texas', 'mexiko'], texas: ['ny', 'mexiko'], ny: ['mexiko', 'irland'],
    mexiko: ['kolumbien'], kolumbien: ['brasilia', 'paraguay', 'argentinien'], brasilia: ['paraguay', 'argentinien', 'nigeria'], paraguay: ['argentinien'],
    island: ['irland', 'london', 'skandi'], irland: ['london', 'westeuropa'], london: ['skandi', 'frankfurt', 'westeuropa'],
    skandi: ['frankfurt', 'osteuropa', 'moskau'], frankfurt: ['osteuropa', 'westeuropa', 'zuerich', 'mittelmeer'], westeuropa: ['zuerich', 'mittelmeer', 'maghreb'],
    zuerich: ['mittelmeer'], osteuropa: ['mittelmeer', 'moskau'], mittelmeer: ['moskau', 'nahost', 'persien', 'kairo', 'libyen', 'maghreb'],
    moskau: ['sibirien', 'kasach', 'persien'], sibirien: ['jakutien', 'kasach', 'mongolei'], jakutien: ['kamtschatka', 'mongolei', 'mandschurei'],
    kamtschatka: ['mandschurei', 'tokio', 'seoul'], kasach: ['zentralasien', 'xinjiang'], zentralasien: ['xinjiang', 'persien'],
    mongolei: ['mandschurei', 'peking', 'xinjiang'], mandschurei: ['peking', 'seoul'], peking: ['tibet', 'shanghai', 'seoul'],
    xinjiang: ['tibet', 'pakistan'], tibet: ['shanghai', 'suedostasien', 'myanmar', 'indien'], shanghai: ['suedostasien', 'tokio'],
    suedostasien: ['myanmar', 'indonesien'], myanmar: ['indien'], indien: ['pakistan'], pakistan: ['persien'], persien: ['nahost'], tokio: ['seoul'],
    nahost: ['kairo', 'horn'], kairo: ['libyen', 'sahel', 'kongo', 'horn', 'kenia'], libyen: ['maghreb', 'sahel'], maghreb: ['sahel'],
    sahel: ['nigeria', 'kongo'], nigeria: ['kongo'], kongo: ['kenia', 'sambesi'], horn: ['kenia'], kenia: ['sambesi'], sambesi: ['suedafrika'], suedafrika: ['perth'],
    indonesien: ['perth', 'sydney'], perth: ['sydney'], sydney: ['neuseeland'],
};

export const SEA = new Set(['ny|irland', 'brasilia|nigeria', 'kanada|island', 'island|irland', 'island|london', 'island|skandi', 'alaska|kamtschatka', 'shanghai|tokio', 'kamtschatka|tokio', 'suedafrika|perth', 'mittelmeer|kairo', 'mittelmeer|libyen', 'mittelmeer|maghreb', 'westeuropa|maghreb', 'indonesien|perth', 'indonesien|sydney', 'sydney|neuseeland', 'nahost|horn', 'suedostasien|indonesien', 'irland|westeuropa', 'london|westeuropa', 'london|skandi', 'london|frankfurt', 'tokio|seoul', 'peking|seoul'].map((k) => k.split('|').sort().join('|')));

export const PIN_AT = { irland: [756, 213], london: [796, 202], frankfurt: [854, 220], westeuropa: [786, 272], mittelmeer: [890, 272], osteuropa: [912, 218], skandi: [868, 150], island: [736, 152], kanada: [470, 190], alaska: [262, 170], indonesien: [1360, 470], neuseeland: [1494, 700], seoul: [1312, 282], tokio: [1366, 300], kamtschatka: [1330, 170], ny: [478, 276], texas: [412, 292], kolumbien: [478, 460] };

/** The ocean names the map data (public/hyper/mapdata.js) carries in German, as keys of the page's texts. */
export const OCEANS = { 'PAZIFISCHER OZEAN': T('PACIFIC OCEAN'), 'ATLANTISCHER OZEAN': T('ATLANTIC OCEAN'), 'INDISCHER OZEAN': T('INDIAN OCEAN'), 'SÜDLICHER OZEAN': T('SOUTHERN OCEAN'), NORDPOLARMEER: T('ARCTIC OCEAN') };

/** Switzerland is a few pixels wide on a world map: drawn as an enlarged vault (×SWISS_ZOOM around its centre). */
export const SWISS_ZOOM = 3.2;

/** Territories per zone, adjacency as sets: built once from the tables above. */
export const ADJ = Object.fromEntries(TDEF.map(([id]) => [id, new Set()]));
Object.entries(ADJ_LIST).forEach(([a, list]) => list.forEach((b) => { ADJ[a].add(b); ADJ[b].add(a); }));
export const ZONE_OF = Object.fromEntries(TDEF.map(([id, , zone]) => [id, zone]));
export const BANK = Object.fromEntries(TDEF.map(([id, , , f = '']) => [id, f.includes('b')]));
export const MINE = Object.fromEntries(TDEF.map(([id, , , f = '']) => [id, f.includes('m')]));
export const ZT = Object.fromEntries(Object.keys(ZONES).map((z) => [z, TDEF.filter((t) => t[2] === z).map((t) => t[0])]));

/**
 * The six sides, keyed as the server names them (App\Support\Hyper\HyperGame::FACTIONS). `por` names the
 * painted portrait, token and soldier (public/hyper/art/por-*, tok-*, sol-*); each brings its own meme for
 * the moment it topples a bank and wins the game.
 */
export const FACTIONS = {
    bitcoiner: { por: 'you', side: 'Bitcoiner', name: 'Bitcoin-Bot', color: '#f7931a', tag: T('TOPPLED'), win: [T('HYPERBITCOINIZATION'), T('Fiat is history.')] },
    fed: { por: 'fed', side: 'Fed', name: 'Fed-Bot', color: '#43d17a', tag: 'BRRRR!', win: ['MONEY PRINTER GO BRRR', T('The printing press runs hot. Forever.')] },
    ezb: { por: 'ezb', side: T('ECB'), name: T('ECB Bot'), color: '#57a6ff', tag: 'WHATEVER IT TAKES', win: ['WHATEVER IT TAKES', T('Europe is now a form in triplicate.')] },
    goldbug: { por: 'goldbug', side: 'Goldbug', name: 'Goldbug', color: '#a98bff', tag: T('GOLD WINS'), win: [T('TOLD YOU SO'), T('Gold for 5000 years. And now here too.')] },
    shitcoiner: { por: 'shit', side: 'Shitcoiner', name: 'Shitcoiner', color: '#ff5fb0', tag: 'TO THE MOON', win: [T('WEN LAMBO? NOW!'), T('New token, new luck. The chart points up. For now.')] },
    nocoiner: { por: 'no', side: 'Nocoiner', name: 'Nocoiner', color: '#d7deee', tag: T('BITCOIN IS DEAD'), win: [T('BITCOIN IS DEAD'), T('For the 475th time. This time for real.')] },
};

/** The event cards: name, the card's big glyph, the rule text and the joke (texts as on the page, v21). */
export const CARDS = {
    brrrr: { name: T('Money Printer'), art: 'BRRR', text: T('+8 free plebs. Next round everyone pays 50 % more per pleb.'), joke: T('Jerome ordered new ink.') },
    attack51: { name: T('51% Attack'), art: '51%', text: T('Take a neighbouring territory with at most 3 units without rolling.'), joke: T('Enough hashrate for new rules.') },
    keys: { name: 'Lost Keys', art: 'SEED?', text: T('The richest opponent loses 30 % of their sats.'), joke: T('The seed was in the recycling.') },
    diamond: { name: 'Diamond Hands', art: '◆', text: T('One of your territories defends with +1 until your next turn.'), joke: T('Nobody sells here.') },
    salvador: { name: T('El Salvador Move'), art: '±25', text: T('Coin flip: your sats +25 % or −25 %.'), joke: T('Do not look at the app.') },
    scam: { name: 'Exit Scam', art: '✈', text: T('An enemy central bank loses 2 units.'), joke: T('Governor and gold now in Dubai.') },
    pizza: { name: T('Pizza Day'), art: '10K', text: T('You lose 10 % of your sats but get 3 free plebs.'), joke: T('10,000 BTC for two pizzas. Delicious.') },
    lagarde: { name: T('Lagarde’s Crystal Ball'), art: '◎', text: T('+3 fiat and a look at the next card.'), joke: T('Looks professional.') },
    dip: { name: 'Buy the Dip', art: '↘', text: T('Plebs cost half this turn.'), joke: T('Everything on sale.') },
    nokeys: { name: 'Not Your Keys', art: '∅', text: T('An enemy territory with exactly 1 unit turns neutral.'), joke: T('The cleaner was thorough.') },
};

/** The page's help: the four steps of a turn. */
export const HOW = [
    ['1', T('Recruit'), T('Fiat comes from territories and central banks. Buy plebs and place them. Leftover fiat is eaten by inflation.')],
    ['2', T('Attack'), T('Tap a glowing territory, then a pulsing neighbour. You see the odds before the roll.')],
    ['3', T('Fortify'), T('Move troops once per turn into a neighbouring territory of yours, then end the turn.')],
    ['₿', T('How to win'), T('Knock everyone out, or hold the most central banks at the round limit. Sats are loot: they buy maxis and ASICs and are credited to you.')],
];

/** The stickers of the emote bar (App\Support\Hyper\HyperEmotes::STICKERS) with their label. */
export const STICKERS = { gg: 'GG', brrr: 'BRRR', hodl: 'HODL', rekt: 'REKT', wagmi: 'WAGMI', ngmi: 'NGMI' };

/*
 * Every clip of the EINUNDZWANZIG soundboard (public/hyper/s), in themed pools; a pool hands out the clip that
 * rested longest (audio.js). `f:<faction>` are the bots' lines.
 */
export const POOLS = {"start":["maurice_duedueduedueduem","heyheyhey","gruppe_komm-in-die-gruppe","kinski"],"turn":["heyheyhey","maurice_duedueduedueduem","fussball_nun-zu-den-fakten","maurice_sehr-kluge-frage","nordwolle_hier-oben-alter","nordwolle_zack-zack-fertig","kellner_ich-denke-ja","90-minuten-hardcore","ich-haett-bock","gruppe_bester-mann-markus","nordwolle_verstehste","fussball_wichtig-fuer-mich"],"conquer":["gruppe_das-geilste","maurice_all-time-high","gruppe_brudi","fussball_ich-lach-mich-tot","nordwolle_zack-zack-fertig","dennis-traum","gigi-pos","fussball_klar-und-deutlich","kellner_abholen-diesen-dissidenten","rothbard","proletopia","fussball_absolut-reines-gewissen"],"bank":["niko_niko-job-ezb","hosp_der-markt-crasht","politik_sonneborn-unfaehig-kriminell","politik_criminals_twice","politik_eu-kommission","schnabl-cbdc","politik_freiheit-stirbt-zentimeterweise","panzerknacker_keine-schutzmechanismen-mehr","niko_niko-passiert","panzerknacker_selten-so-viel-scheisse-gelesen"],"ezb":["niko_niko-ezb-gedicht","niko_niko-job-ezb","politik_sonneborn-zur-EU","politik_sonneborn-nichtdenleyen"],"fed":["hosp_der-markt-crasht","schnabl-cbdc","politik_fake-schnabl"],"zone":["rabbithole","saylor-nosecondbest","gruppe_ich-habs-geschafft","became-personal","nordwolle_die-welt-verbessern","panzerknacker_dezentralisierung","maurice_all-time-high"],"fail":["panzerknacker_so-eine-scheisse","fussball_was-hier-ablaeuft","fussball_scheissdreck","fussball_absolute-frechheit","fussball_nicht-nachzuvollziehen","fussball_woran-hat-et-jelegen","kinski_ist-das-zu-fassen","kinski_so-bescheuert","panzerknacker_bullshit","panzerknacker_bullenscheisse","niko_niko-tag-verderben","hast-du-gase-eingeatmet","politik_was-isn-jetzt-scho-wieda-passiert","fussball_scheiss-der-immer-gelabert-wird"],"swiss":["fussball_respektlos-ohne-ende","politik_ehrenwort","fussball_nachweis-meiner-unschuld","fussball_keine-weitere-diskussion"],"hodl":["fussball_keine-weitere-diskussion","kinski_auf-deinen-platz-zurueckverweisen","saylor-nosecondbest","fussball_respektlos-ohne-ende"],"held":["fussball_respektlos-ohne-ende","kinski_auf-deinen-platz-zurueckverweisen","kinski_mach-doch-deinen-scheiss","kinski_kannste-deine-eigene-scheisse-fressen","fussball_absolut-reines-gewissen","politik_vdb-sosindwirnicht","nordwolle_hoert-euch-mal-selber-zu","panzerknacker_auch-das-ist-bullshit","fussball_klar-und-deutlich","fussball_alles-bla-bla-bla"],"lost":["panzerknacker_dann-ist-das-zeug-weg","fussball_kampagne-gegen-mich","fussball_kann-ich-nicht-mehr-hoeren","nordwolle_ich-krieg-einen-zuviel","du-bist-so-alleine","fussball_warum-die-schaerfe-reinkommt","herr-turm-impulsiv","panzerknacker_so-eine-scheisse","kinski_ein-wahnsinniger"],"low":["fussball_tiefpunkt","du-bist-so-alleine","fussball_ich-habe-fertig"],"out":["kinski_schafft-den-weg-hier","politik_sonneborn-danke-chef","kinski_mach-deine-eigenen-sachen"],"inflation":["niko_niko-inflationsziel","kellner_preisanstieg","politik_scholz-doppel-wumms","politik_sofort-unverzueglich","panzerknacker_bitcoin-kostet","nordwolle_planer-bezahl-ich"],"maxi":["saylor-nosecondbest","rothbard","gigi-pos","ole-sekte","panzerknacker_pass-mal-auf"],"asic":["niko_niko-schuh-mining","panzerknacker_stromgeschwindigkeit","nordwolle_stromschnellen","panzerknacker_wie-das-teil-funktioniert"],"hit":["fussball_ich-lach-mich-tot","nordwolle_zack-zack-fertig","kellner_ich-denke-ja","gruppe_bester-mann-markus","dennis-traum","maurice_sehr-kluge-frage"],"ath":["maurice_all-time-high","gruppe_das-geilste"],"crash":["hosp_der-markt-crasht","fussball_ich-habe-fertig"],"win":["gruppe_ich-habs-geschafft","maurice_outro","maurice_all-time-high"],"lose":["fussball_ich-habe-fertig","politik_kurz-ichwillnichtmehr","fussball_tiefpunkt"],"card:brrrr":["politik_scholz-doppel-wumms","niko_niko-gaga-sein","niko_niko-inflationsziel"],"card:attack51":["kellner_abholen-diesen-dissidenten","sunny_was-fuer-eine-dummheit","panzerknacker_pass-mal-auf"],"card:keys":["panzerknacker_dann-ist-das-zeug-weg","alltagsgeschichten"],"card:diamond":["fussball_keine-weitere-diskussion","saylor-nosecondbest"],"card:salvador":["gruppe_komm-in-die-gruppe","gruppe_trading"],"card:scam":["politik_strache-bsoffene-gschicht","politik_ehrenwort","politik_strache-entschuldigung"],"card:pizza":["ich-haett-bock","fussball_drei-weizenbier"],"card:lagarde":["ich-muss-nachdenken","politik_die-duemmsten-fragen","politik_ich-verstehe-diese-fragen","politik_ein-teil-dieser-antworten"],"card:dip":["panzerknacker_investment","gruppe_trading","internet-bucks"],"card:nokeys":["politik_ehrenwort","alltagsgeschichten","panzerknacker_dann-ist-das-zeug-weg"],"f:fed":["hosp_der-markt-crasht","politik_fake-schnabl","schnabl-cbdc","politik_sicherheit-vs-buergerrecht"],"f:ezb":["niko_niko-ezb-gedicht","politik_eu-trilog","politik_sonneborn-zur-EU","politik_baerbock-360","politik_eu-kommission","niko_niko-gaga-sein"],"f:goldbug":["panzerknacker_alternative-zu-gold","panzerknacker_was-soll-mir-das-bringen","panzerknacker_investment","panzerknacker_bitcoin-kostet"],"f:shitcoiner":["hosp_iota","wassup-my-iota-community","sunny_smart-contract-auf-cardano","sunny_fast-tangle-management-system-physics","sunny_versprechen-versprechen","sunny_noch-n-jahr","sunny_hashtag-dezentral","sunny_irgendwas-mal-bringen","fussball_cyberhornets"],"f:nocoiner":["politik_kurz-mittag-gegessen","nordwolle_kann-man-doch-verstehen","cerca-wette","nordwolle_die-zehnte-datingapp","politik_vertrauensvorschuss","politik_spritze-innen-arm","nordwolle_verwalter","politik_kurz-ichwillnichtmehr","fussball_freunde-der-sonne"]};
POOLS.price = POOLS.inflation;

/** A clip that interrupts another only with a higher priority; 5 plays even in a bot's turn (start, win, lose). */
export const PRIO = { start: 5, win: 5, lose: 5, out: 4, bank: 4, ezb: 4, fed: 4, zone: 4, ath: 4, crash: 4, conquer: 3, lost: 3, inflation: 3, price: 3, swiss: 3, hodl: 3, held: 3, low: 3, turn: 2, fail: 2, maxi: 2, asic: 2, hit: 1 };

/** A soundboard clip's id as a short label for the clip picker: "maurice_all-time-high" -> "Maurice: all time high". */
export function clipLabel(id) {
    const text = String(id);
    const cut = text.indexOf('_');
    const who = cut < 0 ? '' : text.slice(0, cut);
    const words = text.slice(cut + 1).replace(/[-_]/g, ' ');

    return who === '' ? words.charAt(0).toUpperCase() + words.slice(1) : who.charAt(0).toUpperCase() + who.slice(1) + ': ' + words;
}
