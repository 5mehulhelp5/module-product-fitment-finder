// Shared selection store — keeps Make/Model/Year/Part in sync across every
// vehicleCompatPartFinder() instance on the page (desktop hero, mobile hero,
// product-detail sidebar, header modal). Server-side filtering: each
// dropdown click triggers an AJAX call that returns only the matching
// options for that field given the current selections — no preloaded tree,
// no client-side bidirectional filter logic.
// Auto-match the finder's accent colour to the active theme: read the computed
// background of a probe primary button (Luma `.action.primary` / Hyva
// `.btn-primary`) and expose it as the `--vc-accent` CSS variable, so the chips,
// dropdown hovers, focus rings, selection dots and the Find button all pick up
// the theme colour. Falls back to the admin Accent Colour / CSS default when the
// theme paints no button colour.
(function () {
    function themeAccent() {
        var probe = document.createElement('button');
        probe.className = 'action primary btn btn-primary';
        probe.style.cssText = 'position:absolute!important;left:-9999px;top:0;width:1px;height:1px;padding:0;margin:0;border:0;visibility:hidden;pointer-events:none';
        document.body.appendChild(probe);
        var bg = window.getComputedStyle(probe).backgroundColor;
        probe.remove();
        return (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') ? bg : '';
    }
    function applyThemeAccent() {
        try {
            var accent = themeAccent();
            if (!accent) { return; }
            document.documentElement.style.setProperty('--vc-accent', accent);
            document.querySelectorAll('.vc-partfinder, .vc-find-page').forEach(function (el) {
                el.style.setProperty('--vc-accent', accent);
            });
        } catch (e) { /* leave the CSS/admin fallback in place */ }
    }
    if (document.readyState !== 'loading') { applyThemeAccent(); }
    else { document.addEventListener('DOMContentLoaded', applyThemeAccent); }
})();

document.addEventListener('alpine:init', () => {
    if (window.Alpine && !window.Alpine.store('vehicleCompatSel')) {
        window.Alpine.store('vehicleCompatSel', {
            make: '', model: '', year: '', part: '',
            makeLabel: '', modelLabel: '', partLabel: '',
            optionsMake:  [], optionsModel: [], optionsYear: [], optionsPart: [],
            loadedMake:  false, loadedModel: false, loadedYear: false, loadedPart: false,
            loadingMake: false, loadingModel: false, loadingYear: false, loadingPart: false,
            _urlHydrated: false
        });
    }
});

function vehicleCompatPartFinder(carKeysPartsBaseUrl, optionsUrl) {
    return {
        // Default the options endpoint to /vehiclecompat/options/index. Callers can
        // override by passing a second argument for testing / routing tweaks.
        optionsUrl: optionsUrl || '/vehiclecompat/options/index',

        // v1.1.1: Transient flag flipped true for ~1.5s after a successful
        // "Save Selection to Garage" click. Drives the "Saved!" confirmation
        // micro-interaction on the Save button.
        savedFeedback: false,

        // ---- Proxy state to the shared Alpine store ----
        get selectedMake()      { return Alpine.store('vehicleCompatSel').make; },
        set selectedMake(v)     { Alpine.store('vehicleCompatSel').make = v; },
        get selectedModel()     { return Alpine.store('vehicleCompatSel').model; },
        set selectedModel(v)    { Alpine.store('vehicleCompatSel').model = v; },
        get selectedYear()      { return Alpine.store('vehicleCompatSel').year; },
        set selectedYear(v)     { Alpine.store('vehicleCompatSel').year = v; },
        get selectedPart()      { return Alpine.store('vehicleCompatSel').part; },
        set selectedPart(v)     { Alpine.store('vehicleCompatSel').part = v; },

        get selectedMakeLabel()  { return Alpine.store('vehicleCompatSel').makeLabel; },
        set selectedMakeLabel(v) { Alpine.store('vehicleCompatSel').makeLabel = v; },
        get selectedModelLabel() { return Alpine.store('vehicleCompatSel').modelLabel; },
        set selectedModelLabel(v){ Alpine.store('vehicleCompatSel').modelLabel = v; },
        get selectedYearLabel()  { return this.selectedYear ? String(this.selectedYear) : ''; },
        get selectedPartLabel()  { return Alpine.store('vehicleCompatSel').partLabel; },
        set selectedPartLabel(v) { Alpine.store('vehicleCompatSel').partLabel = v; },

        // Hydrate selection IDs from URL params once across all instances.
        // Labels stay empty until first dropdown open populates them — that's
        // OK because the trigger button falls back to "Select X" placeholder.
        init() {
            const s = Alpine.store('vehicleCompatSel');
            if (!s._urlHydrated) {
                s._urlHydrated = true;
                try {
                    const params = new URLSearchParams(window.location.search);
                    const m  = params.get('make_id');
                    const md = params.get('model_id');
                    const y  = params.get('year');
                    const p  = params.get('part_id');
                    if (m  && Number(m)  > 0) s.make  = String(Number(m));
                    if (md && Number(md) > 0) s.model = String(Number(md));
                    if (y  && Number(y)  > 0) { s.year  = String(Number(y)); }
                    if (p  && Number(p)  > 0) s.part  = String(Number(p));
                } catch (e) { /* ignore */ }
            }
        },

        // ---- Searchable combobox state (per instance) -----------------
        openMake: false, queryMake: '',
        openModel: false, queryModel: '',
        openYear: false, queryYear: '',
        openPart: false, queryPart: '',

        closeAll() {
            this.openMake = this.openModel = this.openYear = this.openPart = false;
        },

        // ---- Per-field fetch helpers --------------------------------------
        // Each loadX() is called from the dropdown's @click. If the cached
        // result for that field is still valid (no other selection has
        // changed since), the call is a no-op.
        _fetchField(field) {
            const s = Alpine.store('vehicleCompatSel');
            const loadingKey = 'loading' + field.charAt(0).toUpperCase() + field.slice(1);
            const loadedKey  = 'loaded'  + field.charAt(0).toUpperCase() + field.slice(1);
            const optionsKey = 'options' + field.charAt(0).toUpperCase() + field.slice(1);
            if (s[loadingKey] || s[loadedKey]) return;
            s[loadingKey] = true;

            const params = new URLSearchParams();
            params.set('field', field);
            if (s.make)  params.set('make_id',  s.make);
            if (s.model) params.set('model_id', s.model);
            if (s.year)  params.set('year',     s.year);
            if (s.part)  params.set('part_id',  s.part);

            fetch(this.optionsUrl + '?' + params.toString(), { credentials: 'same-origin' })
                .then(r => r.ok ? r.json() : null)
                .then(data => {
                    if (data && Array.isArray(data.options)) {
                        s[optionsKey] = data.options;
                        s[loadedKey] = true;
                        // Backfill the current selection's label if we just
                        // got a list that includes it. Lets reload-with-URL
                        // resolve the names without a separate lookup.
                        const selId = (field === 'year')
                            ? Number(s.year)
                            : (field === 'make' ? s.make : (field === 'model' ? s.model : s.part));
                        if (selId) {
                            for (const opt of data.options) {
                                if (String(opt.id) === String(selId)) {
                                    if (field === 'make')  s.makeLabel  = opt.name;
                                    if (field === 'model') s.modelLabel = opt.name;
                                    if (field === 'part')  s.partLabel  = opt.name;
                                    break;
                                }
                            }
                        }
                    }
                })
                .catch(() => { /* network error — leave empty */ })
                .finally(() => { s[loadingKey] = false; });
        },

        ensureMakes()  { this._fetchField('make');  },
        ensureModels() { this._fetchField('model'); },
        ensureYears()  { this._fetchField('year');  },
        ensureParts()  { this._fetchField('part');  },

        // ---- Visible options getters (proxy + client-side text filter) ----
        // The list itself is whatever the server returned for the most recent
        // fetch; the search input on top of the panel does a local substring
        // filter so the user can type to narrow without another round-trip.
        get visibleMakes() {
            let list = Alpine.store('vehicleCompatSel').optionsMake;
            if (this.queryMake) {
                const q = this.queryMake.toLowerCase();
                list = list.filter(m => m.name.toLowerCase().includes(q));
            }
            return list;
        },
        get visibleModels() {
            let list = Alpine.store('vehicleCompatSel').optionsModel;
            if (this.queryModel) {
                const q = this.queryModel.toLowerCase();
                list = list.filter(m => m.name.toLowerCase().includes(q));
            }
            return list;
        },
        get visibleYears() {
            let list = Alpine.store('vehicleCompatSel').optionsYear.map(o => o.id);
            if (this.queryYear) {
                list = list.filter(y => String(y).includes(this.queryYear));
            }
            return list;
        },
        get visibleParts() {
            let list = Alpine.store('vehicleCompatSel').optionsPart;
            if (this.queryPart) {
                const q = this.queryPart.toLowerCase();
                list = list.filter(p => p.name.toLowerCase().includes(q));
            }
            return list;
        },

        // Invalidate the cached option lists for every field other than
        // `except`. Called after any pick so the next dropdown click refetches
        // with the new filter combination.
        _invalidateOthers(except) {
            const s = Alpine.store('vehicleCompatSel');
            if (except !== 'make')  { s.loadedMake  = false; s.optionsMake  = []; }
            if (except !== 'model') { s.loadedModel = false; s.optionsModel = []; }
            if (except !== 'year')  { s.loadedYear  = false; s.optionsYear  = []; }
            if (except !== 'part')  { s.loadedPart  = false; s.optionsPart  = []; }
        },

        // ---- Pick handlers ----------------------------------------
        pickMake(id, name) {
            this.selectedMake = String(id);
            this.selectedMakeLabel = name || '';
            this.queryMake = '';
            this.openMake = false;
            this._invalidateOthers('make');
        },
        pickModel(id, name) {
            this.selectedModel = String(id);
            this.selectedModelLabel = name || '';
            this.queryModel = '';
            this.openModel = false;
            this._invalidateOthers('model');
        },
        pickYear(y) {
            this.selectedYear = String(y);
            this.queryYear = '';
            this.openYear = false;
            this._invalidateOthers('year');
        },
        pickPart(id, name) {
            this.selectedPart = String(id);
            this.selectedPartLabel = name || '';
            this.queryPart = '';
            this.openPart = false;
            this._invalidateOthers('part');
        },
        clearField(name) {
            const s = Alpine.store('vehicleCompatSel');
            if (name === 'make')  { s.make = ''; s.makeLabel = ''; s.model = ''; s.modelLabel = ''; s.year = ''; this._invalidateOthers('make'); }
            if (name === 'model') { s.model = ''; s.modelLabel = ''; s.year = ''; this._invalidateOthers('model'); }
            if (name === 'year')  { s.year = ''; this._invalidateOthers('year'); }
            if (name === 'part')  { s.part = ''; s.partLabel = ''; this._invalidateOthers('part'); }
        },

        goToFindParts() {
            const base = carKeysPartsBaseUrl;
            const p = new URLSearchParams();
            if (this.selectedMake)  p.set('make_id',  this.selectedMake);
            if (this.selectedModel) p.set('model_id', this.selectedModel);
            if (this.selectedYear)  p.set('year',     this.selectedYear);
            if (this.selectedPart)  p.set('part_id',  this.selectedPart);
            const qs = p.toString();
            window.location.href = qs ? base + '?' + qs : base;
        }
    };
}
