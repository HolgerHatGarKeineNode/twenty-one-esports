/**
 * Proof of Pong's figure picker (plan "Proof of Pong", P3; resources/views/pong/partials/figures.blade.php) on the
 * lobby, the start card of a game against a bot and the waiting card of a live match: a grid of portraits as radio
 * buttons named `figure`, so the lobby's form sends the pick without any script.
 *
 * The script keeps the last pick in localStorage `pong-figure` (and restores it where the page has not picked one
 * itself, data-fixed), shows the picked figure's name and tagline, and tells the page (`pong-figure` event on the
 * document, detail { id }).
 */
const KEY = 'pong-figure';

export function storedFigure() {
    try {
        return localStorage.getItem(KEY);
    } catch {
        return null;
    }
}

function attach(picker) {
    const radios = [...picker.querySelectorAll('input[name=figure]')];
    const name = picker.querySelector('[data-pick-name]');
    const tagline = picker.querySelector('[data-pick-tagline]');
    const img = picker.querySelector('[data-pick-img]');

    const show = (radio, tell) => {
        if (name) name.textContent = radio.dataset.name;
        if (tagline) tagline.textContent = radio.dataset.tagline;
        if (img) {
            img.src = radio.dataset.img;
            img.alt = radio.dataset.name;
        }
        picker.dataset.picked = radio.value;
        if (tell) document.dispatchEvent(new CustomEvent('pong-figure', { detail: { id: radio.value } }));
    };

    const stored = storedFigure();
    const restore = picker.dataset.fixed === undefined ? radios.find((radio) => radio.value === stored) : null;
    if (restore) restore.checked = true;
    const checked = radios.find((radio) => radio.checked) ?? radios[0];
    if (checked) {
        checked.checked = true;
        show(checked, !!restore);
    }

    radios.forEach((radio) => radio.addEventListener('change', () => {
        if (!radio.checked) return;
        try {
            localStorage.setItem(KEY, radio.value);
        } catch {
            // Kept for this visit only.
        }
        show(radio, true);
    }));
}

export function attachPickers() {
    document.querySelectorAll('[data-pong-picker]').forEach((picker) => {
        if (picker.dataset.attached) return;
        picker.dataset.attached = '1';
        attach(picker);
    });
}

attachPickers();
document.addEventListener('livewire:navigated', attachPickers);
