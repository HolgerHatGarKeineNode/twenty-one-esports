/**
 * The player picker (<x-player-picker>): an ARIA combobox that suggests
 * existing players while you type (name, NIP-05 or npub) and hands the
 * chosen player's id to Livewire through x-modelable. Typed text is never a
 * value: editing the field drops the pick, so a submit without a pick sends
 * null and the server answers with its own error.
 *
 * The ids to leave out (the organizer, directors already added) are read
 * from `data-exclude` on every search, because a Livewire morph updates that
 * attribute but never re-runs x-data.
 *
 * `submit-on-pick`: choosing a player submits the surrounding form, for
 * "add to the list" forms where the list itself shows the result.
 */
export default function playerPicker({ url, submitOnPick = false, labels = {} }) {
    return {
        picked: null,
        chosen: null,
        query: '',
        results: [],
        active: -1,
        open: false,
        message: '',
        request: 0,

        init() {
            // The server reset the value (e.g. after adding): clear the shown pick too.
            this.$watch('picked', (value) => {
                if ((value === null || value === '') && this.chosen !== null) {
                    this.chosen = null;
                    this.query = '';
                }
            });
        },

        typed() {
            this.chosen = null;
            this.picked = null;
        },

        exclude() {
            try {
                return JSON.parse(this.$root.dataset.exclude || '[]');
            } catch {
                return [];
            }
        },

        async search() {
            const term = this.query.trim();
            const request = ++this.request;

            if (this.chosen !== null || term.length < 2) {
                this.close();

                return;
            }

            const params = new URLSearchParams({ q: term });
            this.exclude().forEach((id) => params.append('exclude[]', id));

            let response;
            try {
                response = await fetch(`${url}?${params}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            } catch {
                response = null;
            }

            if (request !== this.request) {
                return;
            }

            if (response === null || !response.ok) {
                this.results = [];
                this.active = -1;
                this.message = response?.status === 429 ? labels.tooMany : labels.failed;
                this.open = true;

                return;
            }

            this.results = await response.json();
            this.active = -1;
            this.message = this.results.length === 0 ? labels.none : '';
            this.open = true;
        },

        move(delta) {
            if (this.results.length === 0) {
                return;
            }
            this.open = true;
            this.active = (this.active + delta + this.results.length) % this.results.length;
            this.$nextTick(() => document.getElementById(this.optionId(this.active))?.scrollIntoView({ block: 'nearest' }));
        },

        enter(event) {
            if (this.open && this.active >= 0 && this.results[this.active]) {
                event.preventDefault();
                this.choose(this.results[this.active]);
            }
        },

        choose(player) {
            this.chosen = player;
            this.picked = player.id;
            this.query = player.name;
            this.close();

            if (submitOnPick) {
                this.$nextTick(() => this.$root.closest('form')?.requestSubmit());
            }
        },

        close() {
            this.open = false;
            this.results = [];
            this.active = -1;
            this.message = '';
        },

        optionId(index) {
            return `${this.$root.dataset.pickerId}-opt-${index}`;
        },

        get status() {
            if (!this.open) {
                return '';
            }

            return this.message !== '' ? this.message : (labels.found || '').replace(':count', this.results.length);
        },
    };
}
