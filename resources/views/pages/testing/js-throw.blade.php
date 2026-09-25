{{--
    Positive control for tests/Browser/RouteSweepTest.php: a bare page that
    throws synchronously on load. It has to fail the sweep's own collector
    helper, or the collector is decorative.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Positive control: JS error</title>
</head>
<body>
    <p>This page throws on purpose.</p>
    <script>
        throw new Error('Positive control: injected JS error.');
    </script>
</body>
</html>
