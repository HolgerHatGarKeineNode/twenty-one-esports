<?php

namespace App\Support\Hyper;

/**
 * The board of Hyperbitcoinization: 53 territories in 10 currency spaces, generated from the game page
 * (resources/js/hyper/prototype/index.html) with tools/hbsim/balance.json, so the PHP core and the Rust
 * simulator play the same map. Territories are indexed 0..52 in the page's order; neighbours keep the
 * page's order too, because the bots pick the first of equals. Do not edit; regenerate:
 *
 *   cd tools/hbsim && node export.mjs ../../resources/js/hyper/prototype/index.html map.json \
 *     && node maprs.mjs map.json && node mapphp.mjs map.json ../../app/Support/Hyper/HyperMap.php
 */
final class HyperMap
{
    public const int COUNT = 53;

    /** @var list<string> */
    public const array IDS = ['alaska', 'kanada', 'westk', 'texas', 'ny', 'mexiko', 'kolumbien', 'brasilia', 'paraguay', 'argentinien', 'island', 'irland', 'london', 'skandi', 'frankfurt', 'westeuropa', 'osteuropa', 'mittelmeer', 'zuerich', 'moskau', 'sibirien', 'jakutien', 'kamtschatka', 'kasach', 'zentralasien', 'mongolei', 'mandschurei', 'peking', 'xinjiang', 'tibet', 'shanghai', 'suedostasien', 'myanmar', 'indien', 'pakistan', 'tokio', 'seoul', 'kairo', 'libyen', 'nahost', 'persien', 'maghreb', 'sahel', 'nigeria', 'horn', 'kenia', 'kongo', 'sambesi', 'suedafrika', 'indonesien', 'perth', 'sydney', 'neuseeland'];

    /** @var list<string> */
    public const array NAMES = ['Alaska', 'Kanada', 'Westküste', 'Texas', 'New York', 'Mexiko', 'Kolumbien', 'Brasília', 'Paraguay', 'Argentinien', 'Island', 'Irland', 'London', 'Skandinavien', 'Frankfurt', 'Westeuropa', 'Osteuropa', 'Mittelmeer', 'Zürich', 'Moskau', 'Westsibirien', 'Jakutien', 'Fernost', 'Kasachstan', 'Zentralasien', 'Mongolei', 'Mandschurei', 'Peking', 'Xinjiang', 'Tibet', 'Shanghai', 'Indochina', 'Myanmar', 'Indien', 'Pakistan', 'Tokio', 'Seoul', 'Kairo', 'Libyen', 'Arabien', 'Persien', 'Maghreb', 'Sahel', 'Westafrika', 'Horn von Afrika', 'Ostafrika', 'Kongo', 'Sambesi', 'Südafrika', 'Indonesien', 'Perth', 'Sydney', 'Neuseeland'];

    /** @var list<int> zone index per territory */
    public const array ZONE = [0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 3, 3, 3, 3, 3, 4, 5, 5, 5, 5, 5, 5, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 7, 7, 8, 8, 8, 8, 8, 8, 8, 8, 8, 8, 8, 8, 9, 9, 9, 9];

    /** @var list<bool> */
    public const array BANK = [false, false, false, false, true, false, false, true, false, false, false, false, true, false, true, false, false, false, true, true, false, false, false, false, false, false, false, true, false, false, false, false, false, false, false, true, false, true, false, false, false, false, false, false, false, false, false, false, false, false, false, true, false];

    /** @var list<bool> */
    public const array MINE = [false, false, false, true, false, false, false, false, true, false, true, false, false, false, false, false, false, false, false, false, false, false, false, true, false, false, false, false, false, false, false, false, false, false, false, false, false, false, false, false, false, false, false, false, true, false, false, false, false, false, false, false, false];

    /** @var list<list<int>> */
    public const array ADJ = [[1, 2, 22], [0, 2, 3, 4, 10], [0, 1, 3, 5], [1, 2, 4, 5], [1, 3, 5, 11], [2, 3, 4, 6], [5, 7, 8, 9], [6, 8, 9, 43], [6, 7, 9], [6, 7, 8], [1, 11, 12, 13], [4, 10, 12, 15], [10, 11, 13, 14, 15], [10, 12, 14, 16, 19], [12, 13, 16, 15, 18, 17], [11, 12, 14, 18, 17, 41], [13, 14, 17, 19], [14, 15, 18, 16, 19, 39, 40, 37, 38, 41], [14, 15, 17], [13, 16, 17, 20, 23, 40], [19, 21, 23, 25], [20, 22, 25, 26], [0, 21, 26, 35, 36], [19, 20, 24, 28], [23, 28, 40], [20, 21, 26, 27, 28], [21, 22, 25, 27, 36], [25, 26, 29, 30, 36], [23, 24, 25, 29, 34], [27, 28, 30, 31, 32, 33], [27, 29, 31, 35], [29, 30, 32, 49], [29, 31, 33], [29, 32, 34], [28, 33, 40], [22, 30, 36], [22, 26, 27, 35], [17, 39, 38, 42, 46, 44, 45], [17, 37, 41, 42], [17, 40, 37, 44], [17, 19, 24, 34, 39], [15, 17, 38, 42], [37, 38, 41, 43, 46], [7, 42, 46], [39, 37, 45], [37, 46, 44, 47], [37, 42, 43, 45, 47], [46, 45, 48], [47, 50], [31, 50, 51], [48, 49, 51], [49, 50, 52], [51]];

    /** @var list<float> start value per territory (pp of win rate, 4 players), sorts the value-balanced deal */
    public const array START_VALUE = [-0.31, -0.49, -0.21, -1.45, -0.75, 0.51, 6.02, 6.7, 7.63, 7.01, -1.2, 0.21, -1.08, 0.35, -0.96, 0.58, 0.56, 0.08, 0.0, -3.49, -3.26, -3.13, -2.2, -4.9, -3.23, -1.88, -1.88, -2.62, -2.17, -1.81, -1.78, -1.33, -1.59, -1.75, -1.73, -2.38, -0.21, 0.43, 0.06, -0.02, -0.18, 0.03, 0.15, 0.75, -0.07, 0.34, 0.2, 0.29, 0.5, 3.44, 2.89, 4.07, 5.21];

    /** @var list<string> */
    public const array ZONE_KEYS = ['dollar', 'sa', 'pound', 'euro', 'swiss', 'rubel', 'yuan', 'yen', 'afro', 'ozean'];

    /** @var list<string> */
    public const array ZONE_NAMES = ['Dollar-Raum', 'Südamerika-Bund', 'Pound Zone', 'Euro-Block', 'Swiss Refuge', 'Rubel-Festung', 'Yuan-Sphäre', 'Yen-Realm', 'Afro-Union', 'Australien & Ozeanien'];

    /** @var list<string> */
    public const array ZONE_BANKS = ['Fed', 'Banco Central do Brasil', 'Bank of England', 'EZB', 'SNB', 'Bank of Russia', 'PBoC', 'BoJ', 'Afrikanische Zentralbank', 'RBA'];

    /** @var list<float> sats per turn for holding the whole currency space */
    public const array ZONE_SATS = [2.0, 1.0, 0.5, 1.5, 0.5, 2.0, 3.0, 0.5, 3.0, 1.0];

    /** @var list<list<int>> territories per zone */
    public const array ZONE_TERRITORIES = [[0, 1, 2, 3, 4, 5], [6, 7, 8, 9], [10, 11, 12], [13, 14, 15, 16, 17], [18], [19, 20, 21, 22, 23, 24], [25, 26, 27, 28, 29, 30, 31, 32, 33, 34], [35, 36], [37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48], [49, 50, 51, 52]];

    /** @var array{open: array<int, float>, limit: array<int, float>} extra start plebs per later seat, by player count */
    public const array SEAT_COMP = ['open' => [2 => 9.5, 3 => 6.0, 4 => 4.5, 5 => 2.0, 6 => 1.5], 'limit' => [2 => 9.5, 3 => 6.0, 4 => 4.0, 5 => 1.5, 6 => 1.0]];
}
