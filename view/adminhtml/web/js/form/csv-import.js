/**
 * Vehicle Compatibility toolbar — sits above the dynamicRows component.
 *  - "Import CSV" reads a CSV file, posts to admin endpoint, gets back parsed
 *    rows, and injects them into the dynamicRows record list.
 *  - "Delete X Selected" removes every row whose `selected` checkbox is ticked.
 *  - "Select all" toggles the selected flag on every row at once.
 */
define([
    'uiComponent',
    'uiRegistry',
    'jquery',
    'underscore',
    'ko',
    'Magento_Ui/js/modal/alert'
], function (Component, registry, $, _, ko, alertModal) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'ETechFlow_ProductFitmentFinder/csv-import',
            importUrl: '',
            formKey: '',           /* server-injected admin form key (CSRF) */
            dynamicRowsName: '',   /* full UI name of the dynamicRows component */
            savedRows: [],         /* server-rendered saved fitment rows to paint on load */
            tracks: {
                status:        true,
                rowsCount:     true,
                selectedCount: true,
                allSelected:   true
            }
        },

        initialize: function () {
            this._super();
            this.status        = '';
            this.rowsCount     = 0;
            this.selectedCount = 0;
            this.allSelected   = false;

            var self = this;
            setTimeout(function () { self._bindToDynamicRows(); }, 50);
            return this;
        },

        _bindToDynamicRows: function () {
            var self = this;
            var attempts = 0;
            (function poll() {
                var dr = registry.get(self.dynamicRowsName);
                if (dr && dr.recordData) {
                    self._dr = dr;
                    self._paintSavedRows();
                    /* Recompute counts whenever recordData changes */
                    dr.recordData.subscribe(function () { self._refreshCounts(); });
                    self._refreshCounts();
                    return;
                }
                if (++attempts < 60) setTimeout(poll, 100);
            })();
        },

        /**
         * Paint the server-rendered saved rows into the dynamicRows on load.
         * Magento's dataScope binding leaves a nested/"doubled" shape at
         * data.product.vehicle_compat_data on reopen, so recordData never links
         * to the saved rows and the grid comes up empty. We push them through
         * recordData() directly — the exact path a CSV import already uses — but
         * ONLY when the grid genuinely has no rows, so we never clobber a live
         * edit or double up if Magento ever loads them itself.
         */
        _paintSavedRows: function () {
            if (!this._dr || !this.savedRows || !this.savedRows.length) return;
            var current = this._dr.recordData() || [];
            /* Paint whenever the grid holds fewer rows than the DB actually has:
               covers both the empty case and the malformed "doubled"/partial
               shape. Runs once at form-open, before any edit, so it can't
               clobber in-progress work. */
            if (current.length >= this.savedRows.length) return;
            /* Fresh copies + sequential record_id so dynamicRows keys them cleanly */
            var rows = this.savedRows.map(function (r, i) {
                return {
                    make_id:    r.make_id,
                    make_name:  r.make_name,
                    model_id:   r.model_id,
                    model_name: r.model_name,
                    years:      (r.years || []).slice(),
                    selected:   0,
                    record_id:  i
                };
            });
            this._dr.recordData(rows);
            try { this._dr.reload(); } catch (e) { /* ignore */ }
        },

        _refreshCounts: function () {
            if (!this._dr) return;
            var records = this._dr.recordData() || [];
            this.rowsCount = records.length;
            var sel = records.filter(function (r) { return r && (r.selected === 1 || r.selected === '1' || r.selected === true); });
            this.selectedCount = sel.length;
            this.allSelected   = (records.length > 0 && sel.length === records.length);
        },

        triggerFileInput: function (data, ev) {
            var $input = $(ev.currentTarget).siblings('input[type="file"]');
            $input.val('').trigger('click');
        },

        onFileChange: function (data, ev) {
            var file = ev.target.files && ev.target.files[0];
            if (!file) return;
            this._uploadCsv(file);
        },

        _uploadCsv: function (file) {
            var self = this;
            this.status = 'Uploading ' + file.name + '…';

            var fd = new FormData();
            fd.append('csv', file);
            fd.append('form_key', this.formKey || window.FORM_KEY || '');

            $.ajax({
                url: this.importUrl,
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                dataType: 'json'
            }).done(function (res) {
                if (res.error) {
                    alertModal({ title: 'CSV import failed', content: res.error });
                    self.status = '';
                    return;
                }
                self._injectRows(res.rows || []);
                self.status = 'Imported ' + (res.rows || []).length + ' vehicle row(s)'
                    + (res.createdMakes  ? ' (+' + res.createdMakes  + ' new Makes)' : '')
                    + (res.createdModels ? ' (+' + res.createdModels + ' new Models)' : '')
                    + (res.rowsSkipped   ? ' — skipped ' + res.rowsSkipped + ' bad row(s).' : '.');
            }).fail(function (xhr) {
                /* Log the raw body so a 200-but-unparseable response (stray PHP
                   output, or an HTML redirect from a rejected form key) is visible. */
                console.error('[PFF CSV import] status', xhr.status,
                    '\nresponse:', (xhr.responseText || '').slice(0, 1000));
                alertModal({
                    title: 'Upload failed',
                    content: 'Server returned status ' + xhr.status
                        + '. See the browser console for the server response.'
                });
                self.status = '';
            });
        },

        /** Merge new rows into dynamicRows; existing (make_id, model_id) pairs get year-merged */
        _injectRows: function (newRows) {
            if (!this._dr || !newRows.length) return;
            var existing = (this._dr.recordData() || []).slice();
            var indexByKey = {};
            existing.forEach(function (r, i) {
                if (r && r.make_id && r.model_id) indexByKey[r.make_id + '|' + r.model_id] = i;
            });

            newRows.forEach(function (nr) {
                var key = nr.make_id + '|' + nr.model_id;
                if (indexByKey[key] !== undefined) {
                    var idx = indexByKey[key];
                    var cur = existing[idx];
                    var curYears = (cur.years || []).map(function (y) { return parseInt(y, 10); });
                    nr.years.forEach(function (y) { if (curYears.indexOf(y) < 0) curYears.push(y); });
                    curYears.sort(function (a, b) { return a - b; });
                    cur.years = curYears;
                } else {
                    existing.push({
                        make_id:    nr.make_id,
                        make_name:  nr.make_name,
                        model_id:   nr.model_id,
                        model_name: nr.model_name,
                        years:      nr.years,
                        selected:   0,
                        record_id:  existing.length
                    });
                }
            });

            this._dr.recordData(existing);
            /* Force the dynamicRows to actually rebuild its visible children list */
            try { this._dr.reload(); } catch (e) { /* ignore */ }
            this._refreshCounts();
        },

        deleteSelected: function () {
            if (!this._dr) return;
            var records = (this._dr.recordData() || []).filter(function (r) {
                return !(r && (r.selected === 1 || r.selected === '1' || r.selected === true));
            });
            this._dr.recordData(records);
            try { this._dr.reload(); } catch (e) {}
            this._refreshCounts();
        },

        selectAll: function () {
            if (!this._dr) return;
            var records = (this._dr.recordData() || []).slice();
            var anyUnselected = records.some(function (r) {
                return !(r && (r.selected === 1 || r.selected === '1' || r.selected === true));
            });
            var newVal = anyUnselected ? 1 : 0;
            records.forEach(function (r) { if (r) r.selected = newVal; });
            this._dr.recordData(records);
            try { this._dr.reload(); } catch (e) {}
            this._refreshCounts();
        },

        downloadSample: function () {
            var csv =
                "Make,Model,Year\n" +
                "BMW,3 Series,2010\n" +
                "BMW,3 Series,2011\n" +
                "BMW,3 Series,2012\n" +
                "BMW,X5,2015\n" +
                "Audi,A3,2008\n" +
                "Audi,A3,2009\n";
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
            var url  = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url; a.download = 'vehicle-compat-sample.csv';
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
            URL.revokeObjectURL(url);
            return false;
        }
    });
});
