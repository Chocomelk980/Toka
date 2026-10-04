(() => {
    'use strict';

    // Only the group preview uses browser storage. Users and logs come from PHP/MySQL.
    const storageKey = 'toka.admin.preview.v1';
    const initialState = () => ({
        version: 1,
        groups: [
            { id: 'Toka-771-234', name: "Tambayan Savings ’26", status: 'Frozen', votes: 5, members: 7, reason: 'Pot Disbursement Shortage/Delay' },
            { id: 'Toka-445-991', name: 'Sunrise Paluwagan', status: 'Frozen', votes: 3, members: 7, reason: 'Unverified Share' },
            { id: 'Toka-301-101', name: 'Barkada Savings', status: 'Active' },
            { id: 'Toka-301-102', name: 'Family Fund', status: 'Active' },
            { id: 'Toka-301-103', name: 'Weekend Savers', status: 'Active' },
            { id: 'Toka-301-104', name: 'Community Circle', status: 'Active' },
            { id: 'Toka-301-105', name: 'Office Savings', status: 'Active' },
            { id: 'Toka-201-101', name: 'Holiday Fund', status: 'Completed' },
            { id: 'Toka-201-102', name: 'School Savings', status: 'Completed' }
        ]
    });
    let state = initialState();
    const byId = id => document.getElementById(id);
    const dialog = byId('admin-confirm');
    const search = byId('user-search');
    const auditSearch = byId('audit-search');
    const userRows = Array.from(byId('user-roster').children);
    const auditRows = Array.from(byId('audit-rows').children);
    const auditPageSize = 10;
    let auditPage = 1;
    let pending = null;
    let trigger = null;
    let toastTimer;

    function storageNotice(message) {
        byId('storage-notice').textContent = message;
        byId('storage-notice').hidden = false;
    }

    // Restore only editable fields onto known fixtures; discard malformed storage.
    try {
        const saved = localStorage.getItem(storageKey);
        if (saved) {
            const parsed = JSON.parse(saved);
            if (parsed.version !== 1 || !Array.isArray(parsed.groups)) throw new Error('Invalid preview');
            const restored = initialState();
            restored.groups.forEach(group => {
                const record = parsed.groups.find(item => item && item.id === group.id);
                if (!record || !['Active', 'Completed', 'Frozen', 'Dissolved'].includes(record.status)) throw new Error('Invalid group');
                if (group.status === 'Frozen') group.status = record.status === 'Completed' ? 'Frozen' : record.status;
            });
            state = restored;
        }
    } catch (error) {
        storageNotice('Saved group preview data could not be loaded. The original sample groups are shown.');
    }

    function save() {
        try {
            localStorage.setItem(storageKey, JSON.stringify(state));
            byId('storage-notice').hidden = true;
        } catch (error) {
            storageNotice('Browser storage is unavailable. Changes will last only until you leave or refresh this page.');
        }
    }

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function actionButton(label, action, id, name, secondary = false) {
        const button = element('button', `admin-button${secondary ? ' secondary' : ''}`, label);
        button.type = 'button';
        button.dataset.action = action;
        button.dataset.id = id;
        button.setAttribute('aria-label', `${label} ${name}`);
        return button;
    }

    function renderOverview() {
        const rows = byId('dispute-rows');
        rows.replaceChildren();
        state.groups.filter(group => group.status === 'Frozen').forEach(group => {
            const row = element('tr');
            const identity = element('td');
            identity.append(element('span', 'group-name', group.name), element('small', '', group.id));
            const votes = element('td', 'vote-count', `${group.votes}/${group.members}`);
            votes.title = `${group.votes} votes to freeze out of ${group.members} members`;
            const status = element('td');
            status.append(element('span', 'status-pill', group.status));
            const actions = element('td');
            const buttons = element('div', 'dispute-actions');
            buttons.append(actionButton('Force-Unfreeze', 'unfreeze', group.id, group.name, true), actionButton('Force-Dissolve', 'dissolve', group.id, group.name));
            actions.append(buttons);
            row.append(identity, votes, status, element('td', 'dispute-reason', group.reason), actions);
            rows.append(row);
        });
        byId('disputes-empty').hidden = rows.children.length > 0;
    }

    function renderUsers() {
        if (byId('user-roster').dataset.available !== 'true') return;
        const query = search.value.trim().toLocaleLowerCase();
        let visibleCount = 0;
        userRows.forEach(row => {
            row.hidden = !row.dataset.search.toLocaleLowerCase().includes(query);
            if (!row.hidden) visibleCount++;
        });
        byId('users-empty').hidden = visibleCount > 0;
        const bannedCount = userRows.filter(row => row.dataset.status === 'Banned').length;
        byId('roster-count').textContent = `${visibleCount} of ${userRows.length} users · ${bannedCount} banned`;
    }

    function renderAudit() {
        if (byId('audit-rows').dataset.available !== 'true') return;
        const query = auditSearch.value.trim().toLocaleLowerCase();
        const matchingRows = auditRows.filter(row => row.dataset.search.toLocaleLowerCase().includes(query));
        const pageCount = Math.ceil(matchingRows.length / auditPageSize);
        auditPage = Math.max(1, Math.min(auditPage, pageCount || 1));
        const startIndex = (auditPage - 1) * auditPageSize;
        const endIndex = Math.min(startIndex + auditPageSize, matchingRows.length);
        auditRows.forEach(row => { row.hidden = true; });
        matchingRows.slice(startIndex, endIndex).forEach(row => { row.hidden = false; });

        const pagination = byId('audit-pagination');
        pagination.hidden = pageCount <= 1;
        byId('audit-page-status').textContent = `Showing ${matchingRows.length ? startIndex + 1 : 0}–${endIndex} of ${matchingRows.length} logs · Page ${auditPage} of ${pageCount || 1}`;
        byId('audit-prev').disabled = auditPage <= 1;
        byId('audit-next').disabled = auditPage >= pageCount;

        const pageNumbers = byId('audit-page-numbers');
        pageNumbers.replaceChildren();
        let pages;
        if (pageCount <= 7) {
            pages = Array.from({ length: pageCount }, (_, index) => index + 1);
        } else {
            const included = new Set([1, pageCount]);
            for (let page = Math.max(2, auditPage - 1); page <= Math.min(pageCount - 1, auditPage + 1); page++) included.add(page);
            pages = Array.from(included).sort((left, right) => left - right);
        }
        let previousPage = 0;
        pages.forEach(page => {
            if (page - previousPage > 1) pageNumbers.append(element('span', 'audit-page-ellipsis', '…'));
            const button = element('button', 'audit-page-number', String(page));
            button.type = 'button';
            button.dataset.page = String(page);
            button.setAttribute('aria-label', `Page ${page}`);
            if (page === auditPage) {
                button.setAttribute('aria-current', 'page');
            }
            pageNumbers.append(button);
            previousPage = page;
        });
        byId('audit-empty').hidden = matchingRows.length > 0;
        byId('audit-count').textContent = matchingRows.length
            ? `Showing audit records ${startIndex + 1}–${endIndex} of ${matchingRows.length}.`
            : 'No matching audit records.';
    }

    function changeAuditPage(page, focusControl) {
        auditPage = page;
        renderAudit();
        if (focusControl === 'number') {
            const currentButton = Array.from(byId('audit-page-numbers').querySelectorAll('button[data-page]'))
                .find(button => Number(button.dataset.page) === auditPage);
            currentButton?.focus();
        } else {
            byId(focusControl).focus();
        }
    }

    function announce(message) {
        clearTimeout(toastTimer);
        byId('admin-feedback').textContent = message;
        toastTimer = setTimeout(() => { byId('admin-feedback').textContent = ''; }, 6000);
    }

    function confirmAction(action, id, button) {
        if (button.disabled) return;
        const group = state.groups.find(item => item.id === id);
        const userRow = userRows.find(row => row.dataset.userId === id);
        const messages = {
            ban: userRow && [`Ban ${userRow.dataset.name}?`, 'Are you sure you want to ban this user?', 'Ban user'],
            unban: userRow && [`Unban ${userRow.dataset.name}?`, 'Are you sure you want to unban this user? They will be able to log in again.', 'Unban user'],
            unfreeze: group && ['Force-unfreeze group?', `Return ${group.name} to Active and remove it from the intervention desk in this preview?`, 'Force-unfreeze'],
            dissolve: group && ['Force-dissolve group?', `Mark ${group.name} as Dissolved and remove it from the intervention desk in this preview? Its history is retained. Use Reset preview to restore the sample group.`, 'Force-dissolve'],
            reset: ['Reset group preview?', 'Restore all sample groups? This clears your saved group preview decisions in this browser.', 'Reset group preview']
        };
        if (!messages[action]) return;
        pending = { action, id, status: userRow?.dataset.status };
        trigger = button;
        [byId('confirm-title').textContent, byId('confirm-description').textContent, byId('confirm-action').textContent] = messages[action];
        dialog.showModal();
    }

    document.addEventListener('click', event => {
        const button = event.target.closest('button[data-action]');
        if (button) confirmAction(button.dataset.action, button.dataset.id, button);
    });
    byId('reset-preview').addEventListener('click', event => confirmAction('reset', '', event.currentTarget));
    byId('cancel-action').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { pending = null; });
    byId('confirm-form').addEventListener('submit', event => {
        event.preventDefault();
        if (!pending) return;
        const { action, id } = pending;
        if (action === 'ban' || action === 'unban') {
            byId('moderation-action').value = action;
            byId('moderation-user').value = id;
            byId('moderation-status').value = pending.status;
            byId('confirm-action').disabled = true;
            byId('moderation-form').submit();
            return;
        }
        let message = '';
        if (action === 'reset') {
            state = initialState();
            search.value = '';
            message = 'Group preview reset. All sample groups have been restored.';
        } else {
            const group = state.groups.find(item => item.id === id);
            if (group.status !== 'Frozen') { dialog.close(); return; }
            group.status = action === 'unfreeze' ? 'Active' : 'Dissolved';
            message = `${group.name} has been ${action === 'unfreeze' ? 'unfrozen' : 'dissolved'} in the preview.`;
        }
        save();
        dialog.close();
        renderOverview();
        renderUsers();
        // Keep keyboard focus useful after the original action button is replaced.
        const replacement = Array.from(document.querySelectorAll('button[data-id]')).find(button => button.dataset.id === id);
        if (replacement) replacement.focus();
        else if (action === 'reset') trigger.focus();
        else byId('dispute-heading').focus();
        announce(message);
    });
    search.addEventListener('input', renderUsers);
    auditSearch.addEventListener('input', () => { auditPage = 1; renderAudit(); });
    byId('audit-prev').addEventListener('click', () => changeAuditPage(auditPage - 1, 'audit-prev'));
    byId('audit-next').addEventListener('click', () => changeAuditPage(auditPage + 1, 'audit-next'));
    byId('audit-page-numbers').addEventListener('click', event => {
        const button = event.target.closest('button[data-page]');
        if (button) changeAuditPage(Number(button.dataset.page), 'number');
    });
    byId('clear-search').addEventListener('click', () => { search.value = ''; renderUsers(); search.focus(); });
    renderOverview();
    renderUsers();
    renderAudit();
    if (byId('admin-feedback').textContent.trim()) announce(byId('admin-feedback').textContent);
    // A back/forward navigation may restore the page with its submit button disabled.
    window.addEventListener('pageshow', () => { byId('confirm-action').disabled = false; });
})();
