{{--
    Home, 1:1 from Main.dc.html (from 1024 px) and MobileHome.dc.html (below).
    Static sample data until P7 brings the real feed.
--}}
@php
    use App\Support\SampleData;

    $rankings = SampleData::rankings();
    $hashrate = SampleData::hashrate();
    $counters = SampleData::counters();
@endphp

<x-layouts::app section="home">
    <x-block-strip :finished="SampleData::finishedBlocks()" :running="SampleData::runningBlocks()" />

    @include('pages.home.hero', ['counters' => $counters])

    <div class="grid grow grid-cols-1 gap-4 px-4 pb-6 lg:grid-cols-2 lg:gap-5 lg:px-12 lg:pb-10">
        @include('pages.home.rankings', ['rankings' => $rankings])
        @include('pages.home.games')
        @include('pages.home.tournament')
        @include('pages.home.hashrate', ['hashrate' => $hashrate])
        @include('pages.home.perks')
    </div>
</x-layouts::app>
