@props(['letters' => [], 'words' => [], 'cells' => [], 'shown' => []])

{{--
    The last results of a ladder entry, oldest first (Ladder.dc.html "Form,
    last 5"): one square per result with its letter, so colour never carries
    the result alone; the whole strip reads as one image for screen readers.

    letters: list of W|D|L, oldest first
    words:   W|D|L => the spoken word
    cells:   W|D|L => classes of the square
    shown:   W|D|L => the letter on screen (translated)
--}}
<span role="img" aria-label="{{ __('Last results, oldest first: :list', ['list' => implode(', ', array_map(fn (string $letter): string => $words[$letter], $letters))]) }}"
      {{ $attributes->class('flex items-center gap-[3px]') }}>
    @foreach ($letters as $letter)
        <span aria-hidden="true" class="inline-flex size-5 items-center justify-center rounded-tag text-[11px] font-bold {{ $cells[$letter] }}">{{ $shown[$letter] }}</span>
    @endforeach
</span>
