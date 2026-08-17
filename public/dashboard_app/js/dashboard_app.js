(function () {
    var body = document.body;
    var token = body.dataset.token || '';

    var listViewEl = document.getElementById('cw-list-view');
    var detailViewEl = document.getElementById('cw-detail-view');
    var listEl = document.getElementById('cw-list');
    var countEl = document.getElementById('cw-count');
    var subtitleEl = document.getElementById('cw-subtitle');
    var searchEl = document.getElementById('cw-search');
    var statusEl = document.getElementById('cw-status');
    var currentIdentifier = null;
    var currentPhone = null;
    var requestSeq = 0;

    // --- i18n -----------------------------------------------------------
    // Language is 100% automatic, driven only by the Chatwoot account's own
    // configured language (Config::getAccountLocale() on the server,
    // confirmed working via GET /api/v1/accounts/{id}) — never by GLPI's own
    // language setting (a separate system) and with no manual switcher in
    // the panel. dashboard_app.php already resolved and rendered the static
    // labels (title, placeholders, etc.) server-side in the right language;
    // this dictionary is only needed here for content that's built
    // dynamically after the page loads (ticket count, "No technician", the
    // detail view, and so on).
    //
    // Values that come ready-made from the server elsewhere (ticket status
    // name, priority, solution status) are translated by GLPI at query time
    // — but those requests have no user session (same reason as always: no
    // GLPI cookie inside Chatwoot's iframe), so they always come back in
    // GLPI's default system language, no matter what's used here.
    var STRINGS = window.__cwDictionaries || { en: {}, pt_BR: {} };
    var currentLocale = body.dataset.locale === 'pt_BR' ? 'pt_BR' : 'en';
    var dict = STRINGS[currentLocale] || STRINGS.en || {};

    function t(key) {
        return (dict && dict[key]) || (STRINGS.en && STRINGS.en[key]) || key;
    }

    function esc(s) {
        var div = document.createElement('div');
        div.textContent = s == null ? '' : String(s);
        return div.innerHTML;
    }

    // Recursively looks for any of the given keys at any level of the object
    // received from Chatwoot — avoids relying on one fixed exact path, since
    // the exact payload structure can vary.
    function findValueByKeys(obj, keys, depth) {
        depth = depth || 0;
        if (depth > 6 || !obj || typeof obj !== 'object') return null;
        for (var i = 0; i < keys.length; i++) {
            if (typeof obj[keys[i]] === 'string' && obj[keys[i]]) return obj[keys[i]];
        }
        for (var key in obj) {
            if (!Object.prototype.hasOwnProperty.call(obj, key)) continue;
            var val = obj[key];
            if (val && typeof val === 'object') {
                var found = findValueByKeys(val, keys, depth + 1);
                if (found) return found;
            }
        }
        return null;
    }

    function findIdentifier(obj) {
        return findValueByKeys(obj, ['identifier']);
    }

    function findPhone(obj) {
        return findValueByKeys(obj, ['phone_number', 'phoneNumber', 'phone']);
    }

    function statusBadge(ticket) {
        return '<span class="cw-badge status-' + (ticket.status_id || '') + '">' + esc(ticket.status) + '</span>';
    }

    // SLA bar: percent/color already come computed from the server, based on
    // the real GLPI deadline (time_to_resolve). With no SLA set for the
    // ticket, the bar stays empty (gray) instead of showing a made-up number.
    function progressBar(ticket) {
        var pct = ticket.sla_percent === null || ticket.sla_percent === undefined ? 0 : ticket.sla_percent;
        var color = ticket.sla_color || 'none';
        var title = color === 'none' ? t('no_sla') : pct + t('pct_deadline');
        return '<div class="cw-progress-track" title="' + esc(title) + '">' +
            '<div class="cw-progress-fill sla-' + color + '" style="width:' + pct + '%"></div></div>';
    }

    function renderTickets(tickets) {
        if (!tickets.length) {
            countEl.style.display = 'none';
            listEl.innerHTML = '<div class="cw-empty">' + esc(t('no_tickets')) + '</div>';
            return;
        }
        countEl.style.display = 'block';
        countEl.textContent = tickets.length + ' ' + (tickets.length === 1 ? t('ticket_one') : t('ticket_other'));

        listEl.innerHTML = '';
        tickets.forEach(function (ticket) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cw-ticket';
            btn.setAttribute('data-status', ticket.status_id || '');
            btn.innerHTML =
                '<div class="cw-ticket-title">#' + ticket.id + ' — ' + esc(ticket.title) + '</div>' +
                '<div class="cw-ticket-line2">' +
                  '<span>&#128100; ' + esc(ticket.technician || t('no_technician')) + '</span>' +
                  '<span>&#128205; ' + esc(ticket.location || t('no_location')) + '</span>' +
                  '<span>&#128197; ' + esc(ticket.date_mod || ticket.date || '') + '</span>' +
                  '<span class="cw-col-status">' + statusBadge(ticket) + '</span>' +
                  '<span class="cw-col-sla">' + progressBar(ticket) + '</span>' +
                '</div>';
            btn.addEventListener('click', function () { showDetail(ticket.id); });
            listEl.appendChild(btn);
        });
    }

    function debounce(fn, delay) {
        var timer;
        return function () {
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(null, args); }, delay);
        };
    }

    // Always searches directly in GLPI (doesn't filter a pre-loaded list), so
    // it finds any ticket of the person, not just the most recent ones.
    function fetchTickets() {
        if (!currentIdentifier && !currentPhone) return;

        var seq = ++requestSeq;
        var params = new URLSearchParams();
        params.set('t', token);
        params.set('identifier', currentIdentifier || '');
        params.set('phone', currentPhone || '');
        params.set('q', searchEl.value.trim());
        params.set('status', statusEl.value);
        params.set('lang', currentLocale);

        listEl.innerHTML = '<div class="cw-empty">' + esc(t('loading')) + '</div>';
        countEl.style.display = 'none';

        fetch('/plugins/chatwoot/dashboard_app/dashboard_tickets.php?' + params.toString())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (seq !== requestSeq) return; // stale response, ignore
                if (!data.user_found) {
                    listEl.innerHTML = '<div class="cw-empty">' + esc(t('user_not_found')) + '</div>';
                    return;
                }
                subtitleEl.textContent = data.user_name || currentIdentifier;
                renderTickets(data.tickets || []);
            })
            .catch(function () {
                if (seq !== requestSeq) return;
                listEl.innerHTML = '<div class="cw-error">' + esc(t('error_fetch')) + '</div>';
            });
    }

    function backButtonHtml() {
        return '<button type="button" class="cw-back" id="cw-back-btn">&larr; ' + esc(t('back')) + '</button>';
    }

    function showDetail(ticketId) {
        listViewEl.style.display = 'none';
        detailViewEl.style.display = 'block';
        detailViewEl.innerHTML = '<div class="cw-empty">' + esc(t('loading')) + '</div>';

        var params = new URLSearchParams();
        params.set('t', token);
        params.set('identifier', currentIdentifier || '');
        params.set('phone', currentPhone || '');
        params.set('ticket_id', ticketId);
        params.set('lang', currentLocale);

        fetch('/plugins/chatwoot/dashboard_app/dashboard_ticket_detail.php?' + params.toString())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.found) {
                    detailViewEl.innerHTML = backButtonHtml() + '<div class="cw-error">' + esc(t('could_not_load')) + '</div>';
                    bindBack();
                    return;
                }
                renderDetail(data.ticket);
            })
            .catch(function () {
                detailViewEl.innerHTML = backButtonHtml() + '<div class="cw-error">' + esc(t('error_loading')) + '</div>';
                bindBack();
            });
    }

    function renderDetail(ticket) {
        var html = '';
        html += backButtonHtml();
        html += '<div class="cw-detail-title">#' + ticket.id + ' — ' + esc(ticket.title) + '</div>';
        html += progressBar(ticket);
        html += '<dl style="margin-top:.75rem;">';
        html += '<div class="cw-detail-row"><dt>' + esc(t('deadline_sla')) + '</dt><dd>' + (ticket.sla_percent === null ? esc(t('no_sla')) : ticket.sla_percent + '%') + '</dd></div>';
        html += '<div class="cw-detail-row"><dt>' + esc(t('status')) + '</dt><dd>' + statusBadge(ticket) + '</dd></div>';
        html += '<div class="cw-detail-row"><dt>' + esc(t('priority')) + '</dt><dd>' + esc(ticket.priority) + '</dd></div>';
        html += '<div class="cw-detail-row"><dt>' + esc(t('technician')) + '</dt><dd>' + esc(ticket.technician || t('no_technician_assigned')) + '</dd></div>';
        html += '<div class="cw-detail-row"><dt>' + esc(t('location')) + '</dt><dd>' + esc(ticket.location || t('not_set')) + '</dd></div>';
        html += '<div class="cw-detail-row"><dt>' + esc(t('opened_on')) + '</dt><dd>' + esc(ticket.date) + '</dd></div>';
        html += '<div class="cw-detail-row"><dt>' + esc(t('updated')) + '</dt><dd>' + esc(ticket.date_mod) + '</dd></div>';
        html += '</dl>';
        html += '<div class="cw-section-title">' + esc(t('description')) + '</div>';

        detailViewEl.innerHTML = html;

        // All content coming from GLPI (description, follow-ups, solution) is
        // inserted via textContent, never via innerHTML — the server already
        // converted it from HTML to plain text, and here we make sure none of
        // it is reinterpreted as HTML/script, even if it comes from an old ticket.
        var descEl = document.createElement('div');
        descEl.className = 'cw-detail-desc';
        descEl.textContent = ticket.description || t('no_description');
        detailViewEl.appendChild(descEl);

        var descImages = imageGallery(ticket.description_images, ticket.id);
        if (descImages) detailViewEl.appendChild(descImages);

        renderTimeline(ticket.timeline || [], ticket.id);
        renderAttachments(ticket.attachments || [], ticket.id);

        var link = document.createElement('a');
        link.className = 'cw-external';
        link.href = ticket.url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.innerHTML = esc(t('open_in_glpi')) + ' &#8599;';
        detailViewEl.appendChild(link);

        bindBack();
    }

    // Builds the document proxy URL (embedded image or attachment). The
    // server confirms again, on every call, that this document really
    // belongs to this ticket before serving any byte.
    function docUrl(ticketId, docId) {
        var params = new URLSearchParams();
        params.set('t', token);
        params.set('identifier', currentIdentifier || '');
        params.set('phone', currentPhone || '');
        params.set('ticket_id', ticketId);
        params.set('doc', docId);
        return '/plugins/chatwoot/dashboard_app/dashboard_document.php?' + params.toString();
    }

    function imageGallery(ids, ticketId) {
        if (!ids || !ids.length) return null;
        var wrap = document.createElement('div');
        wrap.className = 'cw-image-gallery';
        ids.forEach(function (id) {
            var img = document.createElement('img');
            img.className = 'cw-image-thumb';
            img.loading = 'lazy';
            img.src = docUrl(ticketId, id);
            img.addEventListener('click', function () { window.open(img.src, '_blank'); });
            wrap.appendChild(img);
        });
        return wrap;
    }

    function renderTimeline(items, ticketId) {
        var title = document.createElement('div');
        title.className = 'cw-section-title';
        title.textContent = t('follow_ups');
        detailViewEl.appendChild(title);

        if (!items.length) {
            var empty = document.createElement('div');
            empty.className = 'cw-empty';
            empty.style.padding = '.75rem 0';
            empty.textContent = t('no_follow_ups');
            detailViewEl.appendChild(empty);
            return;
        }

        items.forEach(function (item) {
            var box = document.createElement('div');
            var cls = 'cw-timeline-item';
            if (item.type === 'solution') {
                cls += ' is-solution';
                // status_id: 1 = Proposed, 2 = Refused, 3 = Accepted. A
                // refused solution isn't "success" — it doesn't make sense to
                // highlight it in green as if it were the ticket's final answer.
                if (item.status_id === 3) {
                    cls += ' solution-accepted';
                } else if (item.status_id === 2) {
                    cls += ' solution-refused';
                } else {
                    cls += ' solution-proposed';
                }
            } else {
                cls += item.is_requester ? ' from-requester' : ' from-staff';
            }
            box.className = cls;

            var head = document.createElement('div');
            head.className = 'cw-timeline-head';

            var who = document.createElement('span');
            who.textContent = item.author + (item.type === 'solution' ? ' ' + t('proposed_the_solution') : '');
            head.appendChild(who);

            var tags = document.createElement('span');
            if (item.type === 'solution') {
                var st = document.createElement('span');
                st.className = 'cw-timeline-tag';
                st.textContent = item.status;
                tags.appendChild(st);
            }
            if (item.source) {
                var src = document.createElement('span');
                src.className = 'cw-timeline-tag';
                src.textContent = item.source;
                src.style.marginLeft = '.3rem';
                tags.appendChild(src);
            }
            if (item.is_private) {
                var priv = document.createElement('span');
                priv.className = 'cw-timeline-tag';
                priv.textContent = t('internal');
                priv.style.marginLeft = '.3rem';
                tags.appendChild(priv);
            }
            var when = document.createElement('small');
            when.textContent = ' ' + item.date;
            tags.appendChild(when);
            head.appendChild(tags);

            var body2 = document.createElement('div');
            body2.className = 'cw-timeline-body';
            body2.textContent = item.content || '';

            box.appendChild(head);
            box.appendChild(body2);

            var imgs = imageGallery(item.images, ticketId);
            if (imgs) box.appendChild(imgs);

            detailViewEl.appendChild(box);
        });
    }

    function renderAttachments(files, ticketId) {
        var title = document.createElement('div');
        title.className = 'cw-section-title';
        title.textContent = t('attachments');
        detailViewEl.appendChild(title);

        if (!files.length) {
            var empty = document.createElement('div');
            empty.className = 'cw-empty';
            empty.style.padding = '.5rem 0 0';
            empty.textContent = t('no_attachments');
            detailViewEl.appendChild(empty);
            return;
        }

        files.forEach(function (f) {
            var row = document.createElement('div');
            row.className = 'cw-attachment';

            var icon = document.createElement('span');
            icon.innerHTML = '&#128206;';
            row.appendChild(icon);

            var name = document.createElement('a');
            name.className = 'cw-attachment-link';
            name.href = docUrl(ticketId, f.id);
            name.target = '_blank';
            name.rel = 'noopener';
            name.textContent = f.name;
            row.appendChild(name);

            if (f.size) {
                var size = document.createElement('small');
                size.textContent = f.size;
                row.appendChild(size);
            }

            detailViewEl.appendChild(row);
        });
    }

    function bindBack() {
        var btn = document.getElementById('cw-back-btn');
        if (btn) {
            btn.addEventListener('click', function () {
                detailViewEl.style.display = 'none';
                listViewEl.style.display = 'block';
            });
        }
    }

    var debouncedFetch = debounce(fetchTickets, 300);
    searchEl.addEventListener('input', debouncedFetch);
    statusEl.addEventListener('change', fetchTickets);

    window.addEventListener('message', function (event) {
        var data = event.data;
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch (e) { return; }
        }
        if (!data || typeof data !== 'object') return;

        window.__cwLastPayload = data;

        var identifier = findIdentifier(data);
        // Chatwoot sends several messages throughout the conversation (new
        // message, status change, etc.), and the identifier shows up in
        // practically all of them. Without this check, every single one
        // would reload the whole list and overwrite whatever was in the
        // search/filter.
        if (identifier && identifier !== currentIdentifier) {
            currentIdentifier = identifier;
            currentPhone = findPhone(data) || currentPhone;
            subtitleEl.textContent = identifier;
            searchEl.disabled = false;
            statusEl.disabled = false;
            fetchTickets();
        }
    });

    // Asks Chatwoot to send the current conversation/contact data.
    window.parent.postMessage('chatwoot-dashboard-app:fetch-info', '*');
})();
