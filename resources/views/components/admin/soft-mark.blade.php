@props(['mark'])

{{--
    One soft mark of an account (App\Support\FairPlay\SoftMarks), admins only: green when it speaks for a real,
    separate player (`good`), amber when it is a sign to look closer (`warn`), plain otherwise. The board's chip
    (`rv-tag`); its text wraps on a narrow screen instead of overflowing.
--}}
<span @class([
    'rv-tag h-auto min-h-5 py-0.5 whitespace-normal',
    'is-ok' => $mark['tone'] === 'good',
    'is-btc' => $mark['tone'] === 'warn',
]) data-test="soft-mark" data-mark="{{ $mark['key'] }}" data-tone="{{ $mark['tone'] }}">{{ $mark['label'] }}</span>
