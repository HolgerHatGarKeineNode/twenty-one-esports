@props(['for'])

{{--
    The validation message of one admin form field, under it. On a long form whose save button sits in a bar at the bottom
    (the tournament form, order E: the name now stands far below the top) the first message on the page brings itself into
    view and focuses its field, so a failed save never leaves the reason off screen. Only the first one acts: later
    messages stay where they are. Reduced motion: no smooth scroll.
--}}
@error($for)
    <span class="text-loss" role="alert" data-field-error="{{ $for }}" x-data
          x-init="$nextTick(() => {
              if (document.querySelector('[data-field-error]') !== $el) return;
              const field = $el.closest('label')?.querySelector('input, textarea, select');
              field?.focus({ preventScroll: true });
              $el.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
          })">{{ $message }}</span>
@enderror
