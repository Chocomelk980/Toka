(() => {
    'use strict';

    const byId = id => document.getElementById(id);
    const dialog = byId('admin-confirm');
    const search = byId('user-search');
    const auditSearch = byId('audit-search');
    const userRows = Array.from(byId('user-roster')?.children || []);
    const auditRows = Array.from(byId('audit-rows')?.children || []);
    const disputeRows = Array.from(byId('dispute-rows')?.children || []);
    const auditPageSize = 10;
    let auditPage = 1;
    let pending = null;
    let trigger = null;
    let toastTimer;

    function renderUsers() {
        const roster = byId('user-roster');
        if (!roster || roster.dataset.available !== 'true') return;
        const query = search ? search.value.trim().toLocaleLowerCase() : '';
        let visibleCount = 0;
        userRows.forEach(row => {
            row.hidden = !row.dataset.search.toLocaleLowerCase().includes(query);
            if (!row.hidden) visibleCount++;
        });
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
        const pageCount = Math.ceil(matchingRows.length / auditPageSize);
        auditPage = Math.max(1, Math.min(auditPage, pageCount || 1));
        const startIndex = (auditPage - 1) * auditPageSize;
        const endIndex = Math.min(startIndex + auditPageSize, matchingRows.length);
        auditRows.forEach(row => { row.hidden = true; });
        matchingRows.slice(startIndex, endIndex).forEach(row => { row.hidden = false; });

        const pagination = byId('audit-pagination');
        if (pagination) pagination.hidden = pageCount <= 1;
        const pageStatus = byId('audit-page-status');
        if (pageStatus) pageStatus.textContent = `Page ${auditPage} of ${pageCount || 1}`;
        const prevBtn = byId('audit-prev');
        if (prevBtn) prevBtn.disabled = auditPage <= 1;
        const nextBtn = byId('audit-next');
        if (nextBtn) nextBtn.disabled = auditPage >= pageCount;

        const auditEmpty = byId('audit-empty');
        if (auditEmpty) auditEmpty.hidden = matchingRows.length > 0;
        const auditCount = byId('audit-count');
        if (auditCount) {
            auditCount.textContent = matchingRows.length
                ? `Showing audit records ${startIndex + 1}-${endIndex} of ${matchingRows.length}.`
                : 'No matching audit records.';
        }
    }

    function changeAuditPage(page, focusControl) {
        auditPage = page;
        renderAudit();
        byId(focusControl)?.focus();
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
            ban: userRow && [`Ban ${userRow.dataset.name}?`, 'Are you sure you want to ban this user?', 'Ban user'],
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
        dialog.showModal();
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
            byId('moderation-action').value = action;
            byId('moderation-user').value = id;
            byId('moderation-group').value = '';
            byId('moderation-status').value = status;
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

    if (search) search.addEventListener('input', renderUsers);
    if (auditSearch) auditSearch.addEventListener('input', () => { auditPage = 1; renderAudit(); });
    byId('audit-prev')?.addEventListener('click', () => changeAuditPage(auditPage - 1, 'audit-prev'));
    byId('audit-next')?.addEventListener('click', () => changeAuditPage(auditPage + 1, 'audit-next'));
    byId('clear-search')?.addEventListener('click', () => {
        if (search) { search.value = ''; renderUsers(); search.focus(); }
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
