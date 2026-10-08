/**
 * The game page's texts (plan "Hyperbitcoinization", P2): English keys, translated by the dictionary the page
 * hands in (App\Support\Hyper\HyperTexts, values through __() and lang/de.json). A key without an entry shows
 * as it is, in English. `:name` placeholders are replaced as in Laravel.
 */
let dictionary = {};
let locale = 'en';

export function setTexts(texts, language = 'en') {
    dictionary = texts && typeof texts === 'object' ? texts : {};
    locale = language;
}

export function t(key, params = {}) {
    let text = typeof dictionary[key] === 'string' ? dictionary[key] : key;

    // Longest name first, so `:sats` is never cut by `:s`.
    for (const name of Object.keys(params).sort((a, b) => b.length - a.length)) {
        text = text.split(':' + name).join(String(params[name]));
    }

    return text;
}

/** Numbers as the language writes them, one decimal at most. */
export function fmt(n) {
    return (Math.round(n * 10) / 10).toLocaleString(locale === 'de' ? 'de-DE' : 'en-US', { maximumFractionDigits: 1 });
}

export function language() {
    return locale;
}
