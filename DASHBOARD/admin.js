(() => {
    'use strict';

    const byId = id => document.getElementById(id);
    const dialog = byId('admin-confirm');
    const banReasonField = byId('ban-reason-field');
    const banReasonInput = byId('ban-reason-input');
    const search = byId('user-search');
    const auditSearch = byId('audit-search');
    const userRows = Array.from(byId('user-roster')?.children || []);
    const auditRows = Array.from(byId('audit-rows')?.children || []);
    const disputeRows = Array.from(byId('dispute-rows')?.children || []);
    const pageSize = 10;
    const userPageSize = 7;
    let auditPage = 1;
    let userPage = 1;
    let pending = null;
    let trigger = null;
    let toastTimer;
    const userPagination = createPaginationNav(byId('user-roster'), 'User roster pages');

    function createPaginationNav(anchor, label) {
        if (!anchor) return null;
        const nav = document.createElement('nav');
        nav.className = 'admin-pagination';
        nav.setAttribute('aria-label', label);
        nav.hidden = true;
        const panelHeading = anchor.closest('.admin-panel')?.querySelector('.audit-heading, .panel-heading');
        if (panelHeading) panelHeading.append(nav);
        else anchor.before(nav);
        return nav;
    }

    function paginateRows(rows, matchingRows, requestedPage, rowsPerPage = pageSize) {
        const pageCount = Math.ceil(matchingRows.length / rowsPerPage);
        const currentPage = Math.max(1, Math.min(requestedPage, pageCount || 1));
        const startIndex = (currentPage - 1) * rowsPerPage;
        const endIndex = Math.min(startIndex + rowsPerPage, matchingRows.length);
        rows.forEach(row => { row.hidden = true; });
        matchingRows.slice(startIndex, endIndex).forEach(row => { row.hidden = false; });
        return { currentPage, pageCount, startIndex, endIndex };
    }

    function renderPagination(nav, page, onChange, { showSinglePage = false, maxPageNumbers = 7 } = {}) {
        if (!nav) return;
        const { currentPage, pageCount } = page;
        nav.hidden = !showSinglePage && pageCount <= 1;
        let controls = nav.querySelector('.pagination-controls');
        let status = nav.querySelector('.sr-only');
        if (!controls) {
            controls = document.createElement('div');
            controls.className = 'pagination-controls';
            status = document.createElement('span');
            status.className = 'sr-only';
            status.setAttribute('role', 'status');
            status.setAttribute('aria-live', 'polite');
            status.setAttribute('aria-atomic', 'true');
            nav.append(controls, status);
        }
        status.textContent = `Page ${currentPage} of ${pageCount || 1}`;
        controls.replaceChildren();
        if (!showSinglePage && pageCount <= 1) return;

        function addButton(destination, text, key, disabled = false) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'page-control';
            button.dataset.pageControl = key;
            button.disabled = disabled;
            if (key === 'prev' || key === 'next') {
                button.classList.add('page-arrow');
                button.setAttribute('aria-label', key === 'prev' ? 'Previous page' : 'Next page');
                const arrow = document.createElement('span');
                arrow.setAttribute('aria-hidden', 'true');
                arrow.textContent = text;
                button.append(arrow);
            } else {
                button.textContent = text;
                button.setAttribute('aria-label', `Page ${destination}`);
                if (destination === currentPage) button.setAttribute('aria-current', 'page');
            }
            button.addEventListener('click', () => {
                onChange(destination);
                const replacement = nav.querySelector(`[data-page-control="${key}"]`);
                const focusTarget = replacement && !replacement.disabled
                    ? replacement : nav.querySelector('[aria-current="page"]');
                focusTarget?.focus({ preventScroll: true });
            });
            controls.append(button);
        }

        addButton(currentPage - 1, '‹', 'prev', currentPage <= 1);
        const pageNumbers = [];
        if (pageCount === 0) {
            pageNumbers.push(1);
        } else if (pageCount <= maxPageNumbers) {
            for (let number = 1; number <= pageCount; number++) pageNumbers.push(number);
        } else if (maxPageNumbers === 6) {
            const start = Math.max(1, Math.min(currentPage - 2, pageCount - maxPageNumbers + 1));
            for (let number = start; number < start + maxPageNumbers; number++) pageNumbers.push(number);
        } else {
            pageNumbers.push(1);
            const start = Math.max(2, Math.min(currentPage - 1, pageCount - 2));
            const end = Math.min(pageCount - 1, Math.max(currentPage + 1, 3));
            for (let number = start; number <= end; number++) pageNumbers.push(number);
            pageNumbers.push(pageCount);
        }
        let previousPage = 0;
        pageNumbers.forEach(number => {
            if (number - previousPage === 2) {
                addButton(previousPage + 1, String(previousPage + 1), String(previousPage + 1));
            } else if (number - previousPage > 2) {
                const ellipsis = document.createElement('span');
                ellipsis.className = 'page-ellipsis';
                ellipsis.setAttribute('aria-hidden', 'true');
                ellipsis.textContent = '…';
                controls.append(ellipsis);
            }
            addButton(number, String(number), String(number));
            previousPage = number;
        });
        addButton(currentPage + 1, '›', 'next', currentPage >= pageCount);
    }

    function paginateTable(tbody, anchor, label) {
        if (!tbody || !anchor || tbody.dataset.available === 'false') return;
        const rows = Array.from(tbody.children).filter(row => !row.querySelector('.table-empty'));
        const nav = createPaginationNav(anchor, label);
        function render(requestedPage) {
            const page = paginateRows(rows, rows, requestedPage);
            renderPagination(nav, page, render, { showSinglePage: true, maxPageNumbers: 6 });
        }
        render(1);
    }

    function renderUsers() {
        const roster = byId('user-roster');
        if (!roster || roster.dataset.available !== 'true') return;
        const query = search ? search.value.trim().toLocaleLowerCase() : '';
        const matchingRows = userRows.filter(row => row.dataset.search.toLocaleLowerCase().includes(query));
        const visibleCount = matchingRows.length;
        const page = paginateRows(userRows, matchingRows, userPage, userPageSize);
        userPage = page.currentPage;
        renderPagination(userPagination, page, requestedPage => {
            userPage = requestedPage;
            renderUsers();
        }, { showSinglePage: true, maxPageNumbers: 6 });
        const usersEmpty = byId('users-empty');
        if (usersEmpty) usersEmpty.hidden = visibleCount > 0;
        const bannedCount = userRows.filter(row => row.dataset.status === 'Banned').length;
        const rosterCount = byId('roster-count');
        if (rosterCount) {
            rosterCount.textContent = `${visibleCount} of ${userRows.length} users · ${bannedCount} banned`;
        }
    }

    function renderAudit() {
        const auditTbody = byId('audit-rows');
        if (!auditTbody || auditTbody.dataset.available !== 'true') return;
        const query = auditSearch ? auditSearch.value.trim().toLocaleLowerCase() : '';
        const matchingRows = auditRows.filter(row => row.dataset.search.toLocaleLowerCase().includes(query));
        const page = paginateRows(auditRows, matchingRows, auditPage);
        const { startIndex, endIndex } = page;
        auditPage = page.currentPage;
        renderPagination(byId('audit-pagination'), page, requestedPage => {
            auditPage = requestedPage;
            renderAudit();
        });

        const auditEmpty = byId('audit-empty');
        if (auditEmpty) auditEmpty.hidden = matchingRows.length > 0;
        const auditCount = byId('audit-count');
        if (auditCount) {
            auditCount.textContent = matchingRows.length
                ? `Showing audit records ${startIndex + 1}-${endIndex} of ${matchingRows.length}.`
                : 'No matching audit records.';
        }
    }

    function announce(message) {
        clearTimeout(toastTimer);
        const feedback = byId('admin-feedback');
        if (!feedback) return;
        feedback.textContent = message;
        toastTimer = setTimeout(() => { feedback.textContent = ''; }, 6000);
    }

    function confirmAction(action, id, button) {
        if (!button || button.disabled || !dialog) return;
        const userRow = userRows.find(row => row.dataset.userId === id);
        const disputeRow = disputeRows.find(row => row.dataset.groupId === id);
        const groupName = button.dataset.name || disputeRow?.dataset.name || 'this group';

        const messages = {
            ban: userRow && [`Ban ${userRow.dataset.name}?`, 'Provide a reason for this ban. The user will see it when they try to sign in.', 'Ban user'],
            unban: userRow && [`Unban ${userRow.dataset.name}?`, 'Are you sure you want to unban this user? They will be able to log in again.', 'Unban user'],
            unfreeze: (disputeRow || button.dataset.action === 'unfreeze') && [
                `Force-unfreeze ${groupName}?`,
                `Return ${groupName} to Active status and resume regular operations?`,
                'Force-unfreeze'
            ],
            dissolve: (disputeRow || button.dataset.action === 'dissolve') && [
                `Force-dissolve ${groupName}?`,
                `Permanently dissolve ${groupName}? Its history and records will be retained in audit logs.`,
                'Force-dissolve'
            ]
        };
        if (!messages[action]) return;

        const status = userRow ? userRow.dataset.status : (disputeRow ? disputeRow.dataset.status : 'Frozen');
        pending = { action, id, status };
        trigger = button;
        [byId('confirm-title').textContent, byId('confirm-description').textContent, byId('confirm-action').textContent] = messages[action];
        if (banReasonField) banReasonField.hidden = action !== 'ban';
        if (banReasonInput) {
            banReasonInput.value = '';
            banReasonInput.setCustomValidity('');
        }
        dialog.showModal();
        if (action === 'ban') banReasonInput?.focus();
    }

    document.addEventListener('click', event => {
        const button = event.target.closest('button[data-action]');
        if (button) confirmAction(button.dataset.action, button.dataset.id, button);
    });

    byId('cancel-action')?.addEventListener('click', () => dialog?.close());
    dialog?.addEventListener('close', () => { pending = null; });

    byId('confirm-form')?.addEventListener('submit', event => {
        event.preventDefault();
        if (!pending) return;
        const { action, id, status } = pending;
        if (action === 'ban' || action === 'unban') {
            const reason = action === 'ban' ? (banReasonInput?.value.trim() || '') : '';
            if (action === 'ban' && !reason) {
                banReasonInput?.setCustomValidity('Enter a reason for the ban.');
                banReasonInput?.reportValidity();
                return;
            }
            byId('moderation-action').value = action;
            byId('moderation-user').value = id;
            byId('moderation-group').value = '';
            byId('moderation-status').value = status;
            byId('moderation-ban-reason').value = reason;
            byId('confirm-action').disabled = true;
            byId('moderation-form').submit();
            return;
        }
        if (action === 'unfreeze' || action === 'dissolve') {
            byId('moderation-action').value = action;
            byId('moderation-user').value = '';
            byId('moderation-group').value = id;
            byId('moderation-status').value = status;
            byId('confirm-action').disabled = true;
            byId('moderation-form').submit();
            return;
        }
    });

    banReasonInput?.addEventListener('input', () => banReasonInput.setCustomValidity(''));

    if (search) search.addEventListener('input', () => { userPage = 1; renderUsers(); });
    if (auditSearch) auditSearch.addEventListener('input', () => { auditPage = 1; renderAudit(); });
    byId('clear-search')?.addEventListener('click', () => {
        if (search) { search.value = ''; userPage = 1; renderUsers(); search.focus(); }
    });

    paginateTable(byId('dispute-rows'), document.querySelector('.dispute-scroll'), 'Dispute pages');
    document.querySelectorAll('.admin-panel[id^="section-"]').forEach(panel => {
        const heading = panel.querySelector('h2')?.textContent.trim() || 'Records';
        paginateTable(panel.querySelector('tbody'), panel.querySelector('.audit-scroll'), `${heading} pages`);
    });
    renderUsers();
    renderAudit();

    const initialFeedback = byId('admin-feedback')?.textContent.trim();
    if (initialFeedback) announce(initialFeedback);

    window.addEventListener('pageshow', () => {
        const confirmBtn = byId('confirm-action');
        if (confirmBtn) confirmBtn.disabled = false;
    });
})();
