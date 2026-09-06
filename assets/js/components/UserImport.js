/**
 * Drives the two pieces of the user import UI which need live data: the list of
 * recent imports on the index, and the paged preview of an uploaded CSV.
 *
 * The admin bundle is loaded on every admin page, so each half no-ops unless its
 * container is in the DOM.
 */
class UserImport {

    /**
     * Construct UserImport
     * @param {Object} adminController The admin controller
     */
    constructor(adminController) {

        this.adminController = adminController;

        /**
         * How often, in ms, to poll while something might change
         * @type {Number}
         */
        this.pollActive = 2500;

        /**
         * How often, in ms, to poll when everything has settled
         * @type {Number}
         */
        this.pollIdle = 30000;

        /**
         * How many page links either side of the current page the paginator
         * shows; matches the `num_links` the admin pagination partial configures
         * @type {Number}
         */
        this.numLinks = 5;

        this.endpoint = window.SITE_URL + 'api/auth/import';

        this.dom = {
            list: document.getElementById('user-import-list'),
            listBody: document.getElementById('user-import-list-body'),
            preview: document.getElementById('user-import-preview'),
            previewBody: document.getElementById('user-import-preview-body')
        };

        this.imports = [];
        this.listTimeout = null;

        /**
         * The shared modal, created on first use. Null means "not yet asked for",
         * false means "asked for and unavailable".
         * @type {Object|Boolean|null}
         */
        this.modal = null;

        /**
         * Null until the first page of the preview has loaded; this is what
         * distinguishes the first load from a page change
         * @type {Number|null}
         */
        this.previewPage = null;

        /**
         * The IDs of imports whose delete request is in flight, consulted when
         * the actions cell renders so that the button's busy state survives a
         * poll landing mid-request
         * @type {Set}
         */
        this.deleting = new Set();

        /**
         * Whether a confirmation is on screen. The modal is shared, so a second
         * confirm() would reassign its onHide and leave the first promise
         * unsettled.
         * @type {Boolean}
         */
        this.confirming = false;

        this.bindConfirmForms();
        this.bindDelete();
        this.bindDetails();

        if (this.dom.list && this.dom.listBody) {
            this.adminController.log('Constructing user import list');
            this.loadList();
        }

        if (this.dom.preview && this.dom.previewBody) {
            this.adminController.log('Constructing user import preview');
            this.bindPreview();

            /**
             * When the CSV has gone the server has already said so in the table,
             * and the API could only 404; asking would just stack a modal on top
             * of the flash error which is already on the page.
             */
            if (!this.dom.preview.dataset.csvMissing) {
                this.loadPreview(1);
            }
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Fetches a URL and returns the decoded API envelope
     *
     * On failure the rejection carries the response's status, and the API's own
     * message where it got far enough to render one: the poll only needs to
     * know that something went wrong, but a delete the user asked for has to be
     * able to say what.
     *
     * @param {String} url     The URL to fetch
     * @param {Object} options Any fetch options to apply, e.g. {method: 'DELETE'}
     * @return {Promise} Resolves with the parsed response body
     */
    request(url, options) {

        let settings = Object.assign({method: 'GET', credentials: 'same-origin'}, options || {});

        //  Merged over the caller's, so a caller cannot drop what the API needs
        settings.headers = Object.assign({}, settings.headers, {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        });

        return fetch(url, settings)
            .then((response) => response.text().then((body) => {

                let payload = null;

                /**
                 * In production an unhandled exception is re-thrown to the
                 * global error handler, so a 500 arrives as an HTML page rather
                 * than an envelope; the status is then all we have to go on.
                 */
                try {
                    payload = body ? JSON.parse(body) : null;
                } catch (e) {
                    payload = null;
                }

                if (!response.ok) {
                    let error = new Error(
                        (payload && payload.error) || `Request failed with status ${response.status}`
                    );
                    error.status = response.status;
                    throw error;
                }

                return payload || {};
            }));
    }

    // --------------------------------------------------------------------------

    /**
     * Loads, and schedules the next load of, the list of recent imports
     * @return {void}
     */
    loadList() {

        /**
         * The preview page runs the same class but has no list, and the delete
         * handlers resync unconditionally; without this a failed delete there
         * would reach renderList() and fall over on a null container.
         */
        if (!this.dom.list || !this.dom.listBody) {
            return;
        }

        clearTimeout(this.listTimeout);

        this.request(this.endpoint)
            .then((response) => {

                let imports = response.data || [];

                this.renderList(imports);

                //  Poll quickly while something is in flight, slowly otherwise
                let isBusy = imports.some((item) => {
                    return ['PENDING', 'VALIDATING', 'RUNNING'].indexOf(item.status) !== -1;
                });

                this.listTimeout = setTimeout(() => {
                    this.loadList();
                }, isBusy ? this.pollActive : this.pollIdle);
            })
            .catch((error) => {

                /**
                 * This path polls every few seconds, so it must never raise a
                 * modal; one flaky response would otherwise become an unclosable
                 * stream of dialogs. The next poll will recover on its own.
                 */
                this.adminController.warn('Failed to load user imports', error);

                this.listTimeout = setTimeout(() => {
                    this.loadList();
                }, this.pollIdle);
            });
    }

    // --------------------------------------------------------------------------

    /**
     * Reconciles the rendered rows against the response
     * @param {Array} imports The imports as returned by the API
     * @return {void}
     */
    renderList(imports) {

        let responseIds = imports.map((item) => item.id);

        for (let i = 0; i < imports.length; i++) {
            let existing = this.imports.find((x) => x.id === imports[i].id);
            if (existing) {
                /**
                 * The stored item is what the delete confirmation reads its
                 * status and counts from, so it has to keep up; `dom` survives
                 * because the response never carries one.
                 */
                Object.assign(existing, imports[i]);
                this.updateRow(existing.dom, imports[i]);
            } else {
                this.addRow(imports[i]);
            }
        }

        //  Reverse loop so items can be spliced out as we go
        for (let i = this.imports.length - 1; i >= 0; i--) {
            if (responseIds.indexOf(this.imports[i].id) === -1) {
                if (this.imports[i].dom && this.imports[i].dom.parentNode) {
                    this.imports[i].dom.parentNode.removeChild(this.imports[i].dom);
                }
                this.imports.splice(i, 1);
            }
        }

        /**
         * Revealed on the first successful response and never hidden again, so an
         * admin with no imports sees the empty state rather than nothing at all.
         */
        this.dom.list.classList.remove('hidden');
        this.toggleListEmptyRow();
    }

    // --------------------------------------------------------------------------

    /**
     * Adds or removes the list's empty state row
     * @return {void}
     */
    toggleListEmptyRow() {

        let row = this.dom.listBody.querySelector('tr.user-import-list-empty');

        if (this.imports.length) {
            if (row) {
                row.parentNode.removeChild(row);
            }
            return;
        }

        if (!row) {
            row = document.createElement('tr');
            row.classList.add('user-import-list-empty');
            row.appendChild(this.createCell(
                'no-data',
                'No imports found',
                this.countColumns(this.dom.list)
            ));
            this.dom.listBody.appendChild(row);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Drops an import from the list, and from the reconciliation set
     *
     * Splicing `imports` is the point: leave the entry behind and the next
     * poll's find() matches it, updateRow() writes into a detached node, the
     * row never comes back, and the empty state never appears either.
     *
     * @param {Number} id The import to remove
     * @return {void}
     */
    removeImport(id) {

        let index = this.imports.findIndex((x) => x.id === id);
        if (index === -1) {
            return;
        }

        let row = this.imports[index].dom;
        if (row && row.parentNode) {
            row.parentNode.removeChild(row);
        }

        this.imports.splice(index, 1);
        this.toggleListEmptyRow();
    }

    // --------------------------------------------------------------------------

    /**
     * Adds a row for an import which was not previously rendered
     * @param {Object} item The import
     * @return {void}
     */
    addRow(item) {

        let tr = document.createElement('tr');

        tr.appendChild(this.createCell(['field', 'field--id'], this.getIdCellHtml(item)));
        tr.appendChild(this.createCell(['field', 'field--file'], this.getFileCellHtml(item)));
        tr.appendChild(this.createCell(['field', 'field--status'], this.getStatusCellHtml(item)));
        tr.appendChild(this.createCell(['field', 'field--progress'], this.getProgressCellHtml(item)));
        tr.appendChild(this.createCell(['field', 'field--requested', 'datetime'], this.getRequestedCellHtml(item)));
        tr.appendChild(this.createCell(['field', 'field--finished', 'datetime'], this.getFinishedCellHtml(item)));

        /**
         * Left genuinely empty when there is nothing to do, so that admin's
         * `td.actions:empty:before` supplies the "No Actions" label
         */
        tr.appendChild(this.createCell('actions', this.getActionsCellHtml(item)));

        item.dom = tr;
        this.imports.push(item);
        this.dom.listBody.appendChild(tr);
    }

    // --------------------------------------------------------------------------

    /**
     * Updates the cells of an already rendered import
     * @param {Object} dom  The row element
     * @param {Object} item The import
     * @return {void}
     */
    updateRow(dom, item) {

        this.setCellHtml(dom, 'td.field--status', this.getStatusCellHtml(item));
        this.setCellHtml(dom, 'td.field--progress', this.getProgressCellHtml(item));
        this.setCellHtml(dom, 'td.field--finished', this.getFinishedCellHtml(item));
        this.setCellHtml(dom, 'td.actions', this.getActionsCellHtml(item));
    }

    // --------------------------------------------------------------------------

    /**
     * Sets a cell's contents, if the cell exists
     *
     * Guarded because this runs inside the poll loop; a renamed class should cost
     * one stale cell, not a stream of errors every few seconds.
     *
     * @param {HTMLElement} row      The row
     * @param {String}      selector The cell's selector
     * @param {String}      html     The cell's contents
     * @return {void}
     */
    setCellHtml(row, selector, html) {
        let cell = row.querySelector(selector);
        if (cell) {
            cell.innerHTML = html;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * The ID cell's contents
     * @param {Object} item The import
     * @return {String} The cell's HTML
     */
    getIdCellHtml(item) {
        return this.escape(item.id);
    }

    // --------------------------------------------------------------------------

    /**
     * The file cell's contents
     * @param {Object} item The import
     * @return {String} The cell's HTML
     */
    getFileCellHtml(item) {
        let name = item.source && item.source.filename ? item.source.filename : 'CSV';
        return `<a href="${item.urls.preview}">${this.escape(name)}</a>`;
    }

    // --------------------------------------------------------------------------

    /**
     * The status cell's contents
     *
     * The badge carries the colour; the cell itself is left untinted, so a row
     * no longer changes colour wholesale. admin's `td small { display: block }`
     * puts the error underneath without any help from us.
     *
     * @param {Object} item The import
     * @return {String} The cell's HTML
     */
    getStatusCellHtml(item) {

        let html = `<span class="badge ${this.getStatusVariant(item)}">` +
            this.escape(item.status) +
            '</span>';

        /**
         * The summary line only - the stored error is deliberately multi-line
         * (see Processor::composeError()) and the whole thing here would turn
         * every failed row into a wall of text. The Details button has the rest.
         */
        if (item.error) {
            html += `<small>${this.escape(item.error_summary || item.error)}</small>`;
        }

        return html;
    }

    // --------------------------------------------------------------------------

    /**
     * The badge modifier for an import's status
     *
     * Mapped rather than derived: the return goes straight into a `class`
     * attribute, and a lookup is what guarantees it can only ever be one of the
     * five modifiers the stylesheet declares - including for a status case
     * shipped after this bundle was last built.
     *
     * @param {Object} item The import
     * @return {String} The modifier class
     */
    getStatusVariant(item) {
        return {
            DRAFT: 'badge--default',
            PENDING: 'badge--info',
            VALIDATING: 'badge--info',
            RUNNING: 'badge--info',
            COMPLETE: 'badge--success',
            PARTIAL: 'badge--warning',
            FAILED: 'badge--danger'
        }[item.status] || 'badge--default';
    }

    // --------------------------------------------------------------------------

    /**
     * The progress cell's contents
     * @param {Object} item The import
     * @return {String} The cell's HTML
     */
    getProgressCellHtml(item) {

        let progress = item.progress || {};
        let total = progress.row_count || 0;

        if (!total) {
            return '<span class="text-muted">&mdash;</span>';
        }

        let cursor = item.status === 'VALIDATING'
            ? progress.validated_count
            : progress.processed_count;

        let html = `${this.numberFormat(cursor)} of ${this.numberFormat(total)} (${progress.percent}%)`;

        /**
         * Warnings get their own line rather than being folded into the errors:
         * an errored row has no account and a warned one does, so they are not
         * the same number to anybody reading this.
         */
        if (progress.error_count) {
            html += `<small>${this.numberFormat(progress.error_count)} errored</small>`;
        }

        if (progress.warning_count) {
            html += `<small>${this.numberFormat(progress.warning_count)} warned</small>`;
        }

        return html;
    }

    // --------------------------------------------------------------------------

    /**
     * The requested cell's contents
     * @param {Object} item The import
     * @return {String} The cell's HTML
     */
    getRequestedCellHtml(item) {
        let when = item.created ? item.created.formatted : '';
        let who = item.user ? item.user.name : null;
        return who ? `${when}<small>${this.escape(who)}</small>` : when;
    }

    // --------------------------------------------------------------------------

    /**
     * The finished cell's contents
     * @param {Object} item The import
     * @return {String} The cell's HTML
     */
    getFinishedCellHtml(item) {
        return item.finished
            ? item.finished.formatted
            : '<span class="text-muted">&mdash;</span>';
    }

    // --------------------------------------------------------------------------

    /**
     * The actions cell's contents
     * @param {Object} item The import
     * @return {String} The cell's HTML
     */
    getActionsCellHtml(item) {

        let buttons = [];

        if (item.status === 'DRAFT') {
            buttons.push(`<a href="${item.urls.preview}" class="btn btn-xs btn-primary">Review</a>`);
        }

        if (this.hasDetails(item)) {
            buttons.push('<button type="button" class="btn btn-xs btn-default js-user-import-details" ' +
                `data-import-id="${item.id}">Details</button>`);
        }

        if (item.log && item.log.url) {
            buttons.push(`<a href="${item.log.url}" class="btn btn-xs btn-default">Log</a>`);
        }

        if (this.isDeletable(item)) {
            /**
             * Rendered busy while the request is in flight, because the poll
             * re-renders this cell out from under the button - every 2.5s while
             * something is active, and every 30s when nothing is, which is the
             * case that actually bites.
             */
            buttons.push(this.deleting.has(item.id)
                ? '<button type="button" class="btn btn-xs btn-danger" disabled>' +
                '<span class="user-import-spinner" aria-hidden="true"></span>' +
                '</button>'
                : '<button type="button" class="btn btn-xs btn-danger js-user-import-delete" ' +
                `data-import-id="${item.id}" data-delete-url="${item.urls.delete}">Delete</button>`
            );
        }

        return buttons.join(' ');
    }

    // --------------------------------------------------------------------------

    /**
     * Whether an import can be deleted
     *
     * Mirrors Status::isDeletable(). The server checks again and answers 409 if
     * this is out of date, which it will be for up to one poll.
     *
     * @param {Object} item The import
     * @return {Boolean} Whether it can be deleted
     */
    isDeletable(item) {
        return ['VALIDATING', 'RUNNING'].indexOf(item.status) === -1;
    }

    // --------------------------------------------------------------------------

    /**
     * Whether an import has anything worth opening a modal for
     *
     * Either a stored reason, or per-row failures or warnings to list. A clean
     * import has none of them and gets no button, which is what keeps the list
     * uncluttered.
     *
     * @param {Object} item The import
     * @return {Boolean} Whether there is detail to show
     */
    hasDetails(item) {
        let progress = item.progress || {};
        return Boolean(item.error) ||
            Boolean(progress.error_count) ||
            Boolean(progress.warning_count);
    }

    // --------------------------------------------------------------------------

    /**
     * Wires up the preview's paging
     * @return {void}
     */
    bindPreview() {

        this.dom.previewPagingTop = this.dom.preview.querySelector('[data-paging="top"]');
        this.dom.previewPagingBottom = this.dom.preview.querySelector('[data-paging="bottom"]');

        /**
         * Delegated, because both paginators are re-rendered on every response;
         * listeners bound to the buttons themselves would go stale immediately.
         */
        this.dom.preview
            .addEventListener('click', (event) => {

                let link = event.target.closest('a[data-page]');
                if (!link) {
                    return;
                }

                event.preventDefault();

                //  Anchors cannot be disabled, so the busy state is the guard
                if (this.dom.preview.getAttribute('aria-busy') === 'true') {
                    return;
                }

                this.loadPreview(parseInt(link.dataset.page, 10), link);
            });
    }

    // --------------------------------------------------------------------------

    /**
     * Loads a page of the CSV preview
     * @param {Number}      page    The page to load
     * @param {HTMLElement} trigger The button which asked for it, if any
     * @return {void}
     */
    loadPreview(page, trigger) {

        let id = this.dom.preview.dataset.importId;
        let isFirstLoad = this.previewPage === null;

        /**
         * On the first load the server has already rendered a spinner row, so
         * there is nothing to dim and no button to put a spinner on.
         */
        if (!isFirstLoad) {
            this.setPreviewBusy(true, trigger);
        }

        this.request(`${this.endpoint}/${id}/rows?page=${page}`)
            .then((response) => {

                this.previewPage = page;
                this.renderPreview(response.data || [], response.meta || {});

                //  The first load is already at the top of the page; only a page change moves
                if (!isFirstLoad) {
                    this.scrollToPreview();
                }
            })
            .catch((error) => {

                this.adminController.warn('Failed to load user import preview', error);

                /**
                 * On a page change the rows on screen are still valid, so they
                 * stay; losing what you were reading because page seven timed out
                 * is worse than the error itself.
                 */
                if (isFirstLoad) {
                    this.renderPreviewMessage('The preview could not be loaded');
                }

                this.alert(
                    'The preview could not be loaded',
                    'Something went wrong fetching this page of the CSV. Please reload the page to try again.'
                );
            })
            .finally(() => {
                this.setPreviewBusy(false);
            });
    }

    // --------------------------------------------------------------------------

    /**
     * Toggles the preview's busy state
     *
     * `aria-busy` doubles as the styling hook, so no class has to be invented; it
     * is also what stops the paging links being followed, since an anchor has no
     * disabled state.
     *
     * @param {Boolean}     isBusy  Whether a request is in flight
     * @param {HTMLElement} trigger The link which asked for it, if any
     * @return {void}
     */
    setPreviewBusy(isBusy, trigger) {

        this.dom.preview.setAttribute('aria-busy', isBusy ? 'true' : 'false');

        if (isBusy) {

            if (trigger) {
                this.previewTrigger = {el: trigger, label: trigger.innerHTML};
                trigger.innerHTML = '<span class="user-import-spinner" role="status" aria-hidden="true"></span>';
            }

        } else if (this.previewTrigger) {

            /**
             * A successful load re-renders the paginator, taking the link with
             * it; the link is only still around when the request failed, and then
             * it must not be left spinning.
             */
            if (this.previewTrigger.el.isConnected) {
                this.previewTrigger.el.innerHTML = this.previewTrigger.label;
            }

            this.previewTrigger = null;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Renders a page of the CSV preview
     * @param {Array}  rows The rows to render
     * @param {Object} meta The response's meta data
     * @return {void}
     */
    renderPreview(rows, meta) {

        let header = meta.header || [];
        let pagination = meta.pagination || {};

        if (!rows.length) {
            this.renderPreviewMessage('There is nothing to preview');

        } else {

            this.dom.previewBody.innerHTML = '';

            for (let i = 0; i < rows.length; i++) {

                let tr = document.createElement('tr');
                tr.appendChild(this.createCell(['field', 'field--line'], String(rows[i].line)));

                for (let j = 0; j < header.length; j++) {
                    let value = rows[i].data[header[j]];
                    tr.appendChild(this.createCell(
                        this.fieldClass(header[j]).split(' '),
                        value === null || value === '' || typeof value === 'undefined'
                            ? '<span class="text-muted">&mdash;</span>'
                            : this.escape(String(value))
                    ));
                }

                this.dom.previewBody.appendChild(tr);
            }
        }

        this.renderPaginator(this.dom.previewPagingTop, pagination);
        this.renderPaginator(this.dom.previewPagingBottom, pagination);
    }

    // --------------------------------------------------------------------------

    /**
     * Replaces the preview's rows with a single full width message
     * @param {String} message The message to show
     * @return {void}
     */
    renderPreviewMessage(message) {

        let tr = document.createElement('tr');
        tr.appendChild(this.createCell(
            'no-data',
            this.escape(message),
            this.countColumns(this.dom.preview)
        ));

        this.dom.previewBody.innerHTML = '';
        this.dom.previewBody.appendChild(tr);
    }

    // --------------------------------------------------------------------------

    /**
     * Renders a paginator into a container
     *
     * This mirrors the markup produced by module-admin's
     * `admin/views/_components/pagination.php`, which is the source of truth, with
     * the same `num_links: 5` / `use_page_numbers: true` configuration. The
     * partial cannot be used directly because paging happens over the API with no
     * page load, so the whole paginator is redrawn from each response instead.
     *
     * The links must be anchors: admin styles them as
     * `.pagination ul li.page a`, so a button would come out unstyled.
     *
     * @param {HTMLElement} container  The element to render into
     * @param {Object}      pagination The `meta.pagination` object from the API
     * @return {void}
     */
    renderPaginator(container, pagination) {

        if (!container) {
            return;
        }

        let page = pagination.page || 1;
        let perPage = pagination.per_page || 0;
        let total = pagination.total || 0;
        let pages = perPage ? Math.ceil(total / perPage) : 0;

        //  "Records X to Y of Z", arithmetic lifted from the partial
        let start = (page * perPage) - perPage;
        let end = start + perPage;
        if (total > 0) {
            start++;
        }
        if (end > total) {
            end = total;
        }

        let items = [];

        //  Like CodeIgniter, render no links at all for an empty or single page
        if (pages > 1) {

            if (page > this.numLinks + 1) {
                items.push(this.paginatorItem('First', 1, 'first'));
            }

            if (page !== 1) {
                items.push(this.paginatorItem('&lsaquo;', page - 1, 'previous'));
            }

            let from = Math.max(1, page - this.numLinks);
            let to = Math.min(pages, page + this.numLinks);

            for (let i = from; i <= to; i++) {
                items.push(i === page
                    ? `<li class="page current"><span class="current">${i}</span></li>`
                    : this.paginatorItem(String(i), i));
            }

            if (page < pages) {
                items.push(this.paginatorItem('&rsaquo;', page + 1, 'next'));
            }

            if (page + this.numLinks < pages) {
                items.push(this.paginatorItem('Last', pages, 'last'));
            }
        }

        container.innerHTML = [
            '<div class="pagination clearfix">',
            `<small>Records ${start} to ${end} of ${this.numberFormat(total)}</small>`,
            items.length ? `<ul>${items.join('')}</ul>` : '',
            '<div style="clear:both"></div>',
            '</div>'
        ].join('');
    }

    // --------------------------------------------------------------------------

    /**
     * A single clickable paginator item
     * @param {String} label    The item's label; may contain entities
     * @param {Number} page     The page the item navigates to
     * @param {String} modifier An extra class for the item, if any
     * @return {String} The item's HTML
     */
    paginatorItem(label, page, modifier) {
        return `<li class="page${modifier ? ' ' + modifier : ''}">` +
            `<a href="#" data-page="${page}">${label}</a>` +
            '</li>';
    }

    // --------------------------------------------------------------------------

    /**
     * Brings the top of the preview back into view
     *
     * Paging from the bottom paginator replaces the rows above the viewport, so
     * without this the page you just asked for is off-screen and has to be
     * scrolled to by hand.
     *
     * @return {void}
     */
    scrollToPreview() {

        //  Measured rather than hard coded; admin's header is fixed and overlaps
        let header = document.querySelector('body > .header');
        let offset = (header ? header.offsetHeight : 0) + 8;

        let top = this.dom.preview.getBoundingClientRect().top + window.scrollY - offset;

        let reduceMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        window.scrollTo({
            top: Math.max(0, top),
            behavior: reduceMotion ? 'auto' : 'smooth'
        });
    }

    // --------------------------------------------------------------------------

    /**
     * Creates a table cell
     * @param {String|Array} classes The classes to apply
     * @param {String}       html    The cell's contents
     * @param {Number}       colspan The number of columns to span, if any
     * @return {Object} The cell element
     */
    createCell(classes, html, colspan) {

        let cell = document.createElement('td');

        if (Array.isArray(classes)) {
            cell.classList.add(...classes);
        } else {
            cell.classList.add(classes);
        }

        if (colspan) {
            cell.setAttribute('colspan', colspan);
        }

        if (html) {
            cell.innerHTML = html;
        }

        return cell;
    }

    // --------------------------------------------------------------------------

    /**
     * The number of columns in a container's table
     * @param {HTMLElement} container The element containing the table
     * @return {Number} The column count
     */
    countColumns(container) {
        return container.querySelectorAll('thead th').length;
    }

    // --------------------------------------------------------------------------

    /**
     * Normalises a column label into a cell class, as module-admin does
     * @param {String} label The column's label
     * @return {String} The cell's classes
     */
    fieldClass(label) {
        return 'field field--' + String(label)
            .toLowerCase()
            .replace(/[^a-z0-9 \-_]/g, '')
            .replace(/[ _]/g, '-');
    }

    // --------------------------------------------------------------------------

    /**
     * Formats a number as PHP's number_format() does
     *
     * Deliberately not toLocaleString(); number_format() is not locale aware, so
     * this is what actually matches the markup we are mirroring.
     *
     * @param {Number} value The value to format
     * @return {String} The formatted value
     */
    numberFormat(value) {
        return String(value || 0).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the shared modal, creating it on first use
     * @return {Object|null} The modal instance, or null if admin's Modal is unavailable
     */
    getModal() {

        if (this.modal === null) {
            try {
                this.modal = this.adminController.getInstance('Modal', 'nails/module-admin').create();
            } catch (e) {
                this.adminController.warn('Admin\'s Modal component is unavailable', e);
                this.modal = false;
            }
        }

        return this.modal || null;
    }

    // --------------------------------------------------------------------------

    /**
     * Shows a message in a modal
     * @param {String} title   The modal's title
     * @param {String} message The message to show
     * @return {void}
     */
    alert(title, message) {

        let modal = this.getModal();

        if (!modal) {
            window.alert(`${title}\n\n${message}`);
            return;
        }

        let p = document.createElement('p');
        p.innerText = message;

        //  Clear anything a previous confirm() left on the shared instance
        modal.onHide(() => {
        });

        modal
            .setTitle(this.escape(title))
            .setBody(p)
            .clearActions()
            .addAction('Close', ['btn-primary'], (event, instance) => instance.hide())
            .show();
    }

    // --------------------------------------------------------------------------

    /**
     * Asks the user to confirm an action
     * @param {String}  title   The modal's title
     * @param {String}  message The question to ask
     * @param {String}  action  The confirming action's label
     * @param {Boolean} warn    Whether to present the question as a warning
     * @return {Promise} Resolves with whether the action was confirmed
     */
    confirm(title, message, action, warn) {

        return new Promise((resolve) => {

            let modal = this.getModal();

            if (!modal) {
                //  Better an ugly confirmation than a button which silently does nothing
                resolve(window.confirm(message));
                return;
            }

            let settled = false;
            let settle = (confirmed) => {
                if (settled) {
                    return;
                }
                settled = true;
                resolve(confirmed);
            };

            let p = document.createElement('p');
            p.innerText = message;

            let body = p;

            if (warn) {

                /**
                 * Wrapped in an alert so the question reads as a warning at a
                 * glance, rather than as the same paragraph every other
                 * confirmation shows. Following Notes::showError() in
                 * module-admin, which hands setBody() an `.alert` of its own -
                 * the message still goes in through innerText, the alert is the
                 * only thing being added.
                 */
                body = document.createElement('div');
                body.classList.add('alert', 'alert-warning');

                /**
                 * Inline rather than a rule in this module's stylesheet: the
                 * admin bundle is global, so `.modal__body > .alert` would
                 * reach every other admin modal too - module-admin's own alerts
                 * included - to spare this one the 20px it does not need.
                 */
                body.style.marginBottom = '0';

                body.appendChild(p);
            }

            //  Escape to close is built into the modal, and counts as a cancellation
            modal.onHide(() => settle(false));

            modal
                .setTitle(this.escape(title))
                .setBody(body)
                .clearActions()
                .addAction(this.escape(action), ['btn-primary'], (event, instance) => {
                    settle(true);
                    instance.hide();
                })
                .addAction('Cancel', ['btn-danger'], (event, instance) => {
                    settle(false);
                    instance.hide();
                })
                .show();
        });
    }

    // --------------------------------------------------------------------------

    /**
     * Routes the submission of flagged forms through a modal confirmation
     *
     * The listener is on the form rather than the button so that the Delete
     * button, which is adopted into the floating controls by the `form`
     * attribute, keeps working.
     *
     * @return {void}
     */
    bindConfirmForms() {

        document
            .querySelectorAll('form.js-user-import-confirm')
            .forEach((form) => {
                form.addEventListener('submit', (event) => {

                    event.preventDefault();

                    /**
                     * Held for the whole chain, not just one modal: the shared
                     * instance has a single onHide slot, so a Delete
                     * confirmation opening over this one would leave this
                     * promise unsettled.
                     */
                    this.confirming = true;

                    this
                        .confirmForm(form)
                        .then((confirmed) => {
                            if (confirmed) {
                                /**
                                 * Called off the prototype in case a control is
                                 * ever named "submit", which would shadow it.
                                 * Note this does not fire the submit event, so it
                                 * cannot loop back into this handler.
                                 */
                                HTMLFormElement.prototype.submit.call(form);
                            }
                        })
                        .finally(() => {
                            this.confirming = false;
                        });
                });
            });
    }

    // --------------------------------------------------------------------------

    /**
     * Confirms a form's submission, warning about it first if it needs it
     *
     * A form which names a `data-warn-field` checkbox, and has it unticked,
     * gets a warning before the confirmation; Continue is an implied tick, and
     * chains straight on into that confirmation. Anything else - no such field,
     * or a box already ticked - goes straight there, exactly as every other
     * flagged form does.
     *
     * The two run in sequence over the one shared modal, which keeps them clear
     * of module-admin's stacking, and there is nothing to see in between: the
     * hide and the re-show both land in the same task.
     *
     * @param {HTMLFormElement} form The form being submitted
     * @return {Promise} Resolves with whether the submission was confirmed
     */
    confirmForm(form) {

        let checkbox = this.getWarnCheckbox(form);

        //  A local, so the regular confirmation is written once for both routes
        let confirmSubmit = () => this.confirm(
            form.dataset.confirmTitle || 'Are you sure?',
            form.dataset.confirmBody || 'Please confirm you\'d like to continue with this action.',
            form.dataset.confirmAction || 'OK'
        );

        if (!checkbox || checkbox.checked) {
            return confirmSubmit();
        }

        return this
            .confirm(
                form.dataset.warnTitle || 'Are you sure?',
                form.dataset.warnBody || 'Please confirm you\'d like to continue with this action.',
                form.dataset.warnAction || 'Continue',
                true
            )
            .then((acknowledged) => {

                if (!acknowledged) {
                    return false;
                }

                /**
                 * The real checkbox, rather than a hidden input, so the value
                 * reaches the server exactly as a tick would and the page shows
                 * what was agreed to. It stays ticked if the confirmation is
                 * then cancelled - the decision was made, and a second Import
                 * click should not have to make it again.
                 */
                checkbox.checked = true;

                return confirmSubmit();
            });
    }

    // --------------------------------------------------------------------------

    /**
     * Resolves the checkbox a form wants warning about, if it names one
     *
     * Looked up through `form.elements` rather than interpolated into a
     * selector, so a server-authored name never becomes part of one.
     *
     * @param {HTMLFormElement} form The form being submitted
     * @return {HTMLElement|null} The checkbox, or null if the form has no such field
     */
    getWarnCheckbox(form) {

        let name = form.dataset.warnField;

        if (!name) {
            return null;
        }

        let field = form.elements[name];

        //  A repeated name comes back as a RadioNodeList, which has no `type`
        return field && field.type === 'checkbox' ? field : null;
    }

    // --------------------------------------------------------------------------

    /**
     * Wires up the Details buttons on both the list and the preview
     *
     * Delegated from the document for the same two reasons bindDelete() is; see
     * the note there.
     *
     * @return {void}
     */
    bindDetails() {

        document.addEventListener('click', (event) => {

            let button = event.target.closest
                ? event.target.closest('button.js-user-import-details')
                : null;

            if (button) {
                event.preventDefault();
                this.showDetails(parseInt(button.dataset.importId, 10));
            }
        });
    }

    // --------------------------------------------------------------------------

    /**
     * Shows what went wrong with an import
     *
     * The modal opens straight away with a spinner rather than after the
     * requests land, so a click always does something visible; the body is
     * replaced in place once the detail arrives.
     *
     * @param {Number} id The import's ID
     * @return {void}
     */
    showDetails(id) {

        let modal = this.getModal();
        let cached = this.imports.find((item) => item.id === id);

        if (!modal) {
            window.alert(cached && cached.error
                ? cached.error
                : 'The details for this import are unavailable.');
            return;
        }

        let loading = document.createElement('p');
        let spinner = document.createElement('span');
        spinner.className = 'user-import-spinner';
        spinner.setAttribute('aria-hidden', 'true');
        loading.appendChild(spinner);
        loading.appendChild(document.createTextNode(' Loading the details\u2026'));

        //  Clear anything a previous confirm() left on the shared instance
        modal.onHide(() => {
        });

        modal
            .setTitle(`Import #${id}`)
            .setBody(loading)
            .clearActions()
            .addAction('Close', ['btn-primary'], (event, instance) => instance.hide())
            .show();

        Promise
            .all([
                this.request(`${this.endpoint}/${id}`),
                this.request(`${this.endpoint}/${id}/items?status=ERROR,WARNING`)
            ])
            .then(([job, items]) => {

                let item = job.data || {};

                modal
                    .setBody(this.buildDetailsBody(item, items.data || [], items.meta || {}))
                    .clearActions();

                if (item.log && item.log.url) {
                    modal.addAction('Download the full log', ['btn-default'], () => {
                        window.location = item.log.url;
                    });
                }

                modal.addAction('Close', ['btn-primary'], (event, instance) => instance.hide());
            })
            .catch((error) => {

                this.adminController.warn('Failed to load user import details', error);

                let p = document.createElement('p');
                p.innerText = error.message || 'The details for this import could not be loaded.';

                modal.setBody(p);
            });
    }

    // --------------------------------------------------------------------------

    /**
     * Builds the details modal's body
     *
     * Everything goes in via textContent rather than innerHTML. Both blocks
     * carry values lifted straight out of the uploaded CSV, so there is no
     * escaping decision to get wrong here.
     *
     * @param {Object} item The import, as returned by the API
     * @param {Array}  rows The import's failing and warned rows
     * @param {Object} meta The rows response's meta
     * @return {DocumentFragment} The body
     */
    buildDetailsBody(item, rows, meta) {

        let fragment = document.createDocumentFragment();

        if (item.error) {
            let log = document.createElement('pre');
            log.className = 'user-import-details__log';
            log.textContent = item.error;
            fragment.appendChild(log);
        }

        if (rows.length) {

            let total = (meta.pagination || {}).total || rows.length;

            if (total > rows.length) {
                let note = document.createElement('p');
                note.className = 'text-muted';
                note.textContent = 'Showing the first ' + this.numberFormat(rows.length) +
                    ' of ' + this.numberFormat(total) + ' affected rows; the log has every one.';
                fragment.appendChild(note);
            }

            /**
             * Each line carries its status, because the two the request asks for
             * do not mean the same thing: an ERROR row has no account, a WARNING
             * row has one which needs finishing by hand.
             */
            let list = document.createElement('pre');
            list.className = 'user-import-details__rows';
            list.textContent = rows
                .map((row) => `Line ${row.line} [${row.status}]: ` +
                    `${row.message || '(no reason was recorded)'}`)
                .join('\n');
            fragment.appendChild(list);
        }

        /**
         * A bad header or a missing CSV fails before a single row is recorded,
         * so an empty modal is a real outcome and needs saying out loud.
         */
        if (!fragment.childNodes.length) {
            let p = document.createElement('p');
            p.textContent = 'No further detail was recorded for this import.';
            fragment.appendChild(p);
        }

        return fragment;
    }

    // --------------------------------------------------------------------------

    /**
     * Wires up the Delete buttons on both the list and the preview
     *
     * Delegated from the document rather than from a container, for two
     * reasons: the list's buttons are re-rendered on every poll, and the
     * preview's button is rendered by admin's floating controls, which sit
     * outside `#user-import-preview` - so a listener scoped the way
     * bindPreview() scopes its own would never see it. The class is specific
     * enough that this costs nothing on the admin pages which have neither.
     *
     * @return {void}
     */
    bindDelete() {

        document.addEventListener('click', (event) => {

            let button = event.target.closest
                ? event.target.closest('button.js-user-import-delete')
                : null;

            if (button) {
                event.preventDefault();
                this.deleteImport(button);
            }
        });
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes an import, once the user has confirmed it
     *
     * @param {HTMLElement} button The Delete button which was clicked
     * @return {void}
     */
    deleteImport(button) {

        let id = parseInt(button.dataset.importId, 10);

        //  A confirmation is already up, or this one is already on its way out
        if (this.confirming || this.deleting.has(id)) {
            return;
        }

        /**
         * The list knows the item; the preview page does not, and hands the two
         * things the copy turns on over as attributes instead.
         */
        let item    = this.imports.find((x) => x.id === id);
        let status  = item ? item.status : button.dataset.status;

        //  A warned row has an account too, so it is one of the accounts which stays
        let created = item
            ? (((item.progress || {}).success_count || 0) + ((item.progress || {}).warning_count || 0))
            : parseInt(button.dataset.createdCount || '0', 10);

        let label = button.innerHTML;

        this.confirming = true;

        this
            .confirm(
                'Delete this import?',
                this.getDeleteConfirmBody(status, created),
                'Delete'
            )
            .then((confirmed) => {

                this.confirming = false;

                if (!confirmed) {
                    return;
                }

                /**
                 * The clicked button is styled here for immediate feedback, and
                 * the id is recorded so that getActionsCellHtml() reproduces
                 * that state if the poll re-renders the cell mid-request.
                 */
                this.deleting.add(id);
                button.disabled = true;
                button.innerHTML = '<span class="user-import-spinner" aria-hidden="true"></span>';

                return this
                    .request(button.dataset.deleteUrl, {method: 'DELETE'})
                    .then(() => this.onDeleted(id, button))
                    .catch((error) => this.onDeleteFailed(id, error))
                    .finally(() => {

                        /**
                         * In `finally`, never in `then`: an id left in the set
                         * by a failed request would disable that row's button
                         * on every subsequent poll, with no way back but a
                         * reload.
                         */
                        this.deleting.delete(id);

                        //  Only still here if the delete failed; success took the row, or the page
                        if (button.isConnected) {
                            button.disabled = false;
                            button.innerHTML = label;
                        }
                    });
            });
    }

    // --------------------------------------------------------------------------

    /**
     * Drops a deleted import, and resyncs
     *
     * The row goes now rather than on the next poll; at pollIdle that would be
     * half a minute of a row which is already gone. loadList() then reconciles
     * against the server, and clears the pending timer as it goes.
     *
     * @param {Number}      id     The import which was deleted
     * @param {HTMLElement} button The button which asked
     * @return {void}
     */
    onDeleted(id, button) {

        this.removeImport(id);

        //  The preview is a page about something which no longer exists
        if (button.dataset.redirect) {
            window.location = button.dataset.redirect;
            return;
        }

        this.loadList();
    }

    // --------------------------------------------------------------------------

    /**
     * Reports a delete which did not happen
     *
     * loadList()'s refusal to raise a modal does not apply here: that is about
     * not stacking dialogs every few seconds, and this is a one-off the user
     * asked for.
     *
     * @param {Number} id    The import which was not deleted
     * @param {Error}  error The rejection, carrying .status where we got that far
     * @return {void}
     */
    onDeleteFailed(id, error) {

        this.adminController.warn('Failed to delete user import', error);

        /**
         * Already gone - most likely deleted in another tab. There is nothing
         * to apologise for; the row disappearing is the whole message.
         */
        if (error.status === 404) {
            this.removeImport(id);
            this.loadList();
            return;
        }

        this.alert(
            'The import could not be deleted',
            error.status === 409
                ? 'This import has started running since the page was loaded, so it can no longer be deleted.'
                : error.message
        );

        //  Whatever the server thinks, the list should agree with it
        this.loadList();
    }

    // --------------------------------------------------------------------------

    /**
     * The body of the delete confirmation
     *
     * What matters is not the status but whether accounts exist: deleting the
     * job never deletes the users it created, and a FAILED job may well have
     * created some before it stopped - `user_import_item.user_id` is
     * ON DELETE SET NULL precisely so that it cannot reach them. Saying
     * otherwise invites an admin to read "Delete" as "undo".
     *
     * @param {String} status  The import's status
     * @param {Number} created How many accounts it created
     * @return {String} The message
     */
    getDeleteConfirmBody(status, created) {

        if (created) {
            return 'The uploaded CSV and its log will be deleted. The ' +
                `${this.numberFormat(created)} user ` +
                `${created === 1 ? 'account' : 'accounts'} this import created ` +
                'will NOT be deleted. This cannot be undone.';
        }

        if (status === 'DRAFT' || status === 'PENDING') {
            return 'The uploaded CSV will be deleted and no users will be created. This cannot be undone.';
        }

        return 'The uploaded CSV and its log will be deleted. ' +
            'This import created no user accounts. This cannot be undone.';
    }

    // --------------------------------------------------------------------------

    /**
     * Escapes a value for insertion into the DOM
     * @param {String} value The value to escape
     * @return {String} The escaped value
     */
    escape(value) {
        let div = document.createElement('div');
        div.appendChild(document.createTextNode(value));
        return div.innerHTML;
    }
}

export default UserImport;
