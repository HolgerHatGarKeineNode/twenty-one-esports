{{--
    Placeholder of a Hyperbitcoinization match page (plan "Hyperbitcoinization", P2a). Part b replaces this view with the
    full-screen game page; the contract it builds on is the snapshot below (HyperMatches::snapshot(), as JSON) and the
    endpoints and channels documented in App\Http\Controllers\HyperMatchController and App\Support\Hyper\HyperMatches.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>Hyperbitcoinization</title>
</head>
<body>
    <script type="application/json" id="hyper-snapshot">@json($snapshot)</script>
</body>
</html>
