@props(['for'])

{{--
    The validation message of one admin form field, under it. On a long form whose save button sits in a bar at the bottom
    (the tournament form, order E: the name now stands far below the top) the first message on the page brings itself into
    view and focuses its field, so a failed save never leaves the reason off screen: when it appears (init), and on every
    later failed save (`admin-form-invalid`, TournamentFormatChooser::validateForm()), whose morph keeps a message that did
    not change in place without running its init again. Only the first message acts. Reduced motion: no smooth scroll.
--}}
@error($for)
    <span class="text-loss" role="alert" data-field-error="{{ $for }}"
          x-data="{ reveal() {
              if (! $el.isConnected || document.querySelector('[data-field-error]') !== $el) return;
              $el.closest('label')?.querySelector('input, textarea, select')?.focus({ preventScroll: true });
              $el.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
          } }"
          x-init="$nextTick(() => reveal())"
          x-on:admin-form-invalid.window="$nextTick(() => reveal())">{{ $message }}</span>
@enderror
