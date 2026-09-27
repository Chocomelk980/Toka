(() => {
    'use strict';

    // Deliberately separate from real accounts until admin authentication is connected.
    const storageKey = 'toka.admin.preview.v1';
    const initialState = () => ({
        version: 1,
        groups: [
            { id: 'Toka-771-234', name: "Tambayan Savings ’26", status: 'Frozen', votes: 5, members: 7, reason: 'Pot Disbursement Shortage/Delay', volume: 42000 },
            { id: 'Toka-445-991', name: 'Sunrise Paluwagan', status: 'Frozen', votes: 3, members: 7, reason: 'Unverified Share', volume: 21000 },
            { id: 'Toka-301-101', name: 'Barkada Savings', status: 'Active', volume: 24000 },
            { id: 'Toka-301-102', name: 'Family Fund', status: 'Active', volume: 18000 },
            { id: 'Toka-301-103', name: 'Weekend Savers', status: 'Active', volume: 12000 },
            { id: 'Toka-301-104', name: 'Community Circle', status: 'Active', volume: 9000 },
            { id: 'Toka-301-105', name: 'Office Savings', status: 'Active', volume: 7000 },
            { id: 'Toka-201-101', name: 'Holiday Fund', status: 'Completed', volume: 30000 },
            { id: 'Toka-201-102', name: 'School Savings', status: 'Completed', volume: 20000 }
        ],
        users: [
            { id: 'USR-001', name: 'Han Ukangpera', groups: 4, trust: 100, banned: false },
            { id: 'USR-002', name: 'Cardin Santos', groups: 2, trust: 100, banned: false },
            { id: 'USR-003', name: 'James Cruz', groups: 1, trust: 85, banned: false },
            { id: 'USR-004', name: 'Rosa Maliwanag', groups: 2, trust: 100, banned: false },
            { id: 'USR-005', name: 'Lito Reyes', groups: 1, trust: 88, banned: true },
            { id: 'USR-006', name: 'Alex Rivera', groups: 1, trust: 100, banned: false },
            { id: 'USR-007', name: 'Josephine Tan', groups: 1, trust: 100, banned: false },
            { id: 'USR-008', name: 'Luciel Pinil', groups: 3, trust: 100, banned: false }
        ]
    });
    let state = initialState();
    const byId = id => document.getElementById(id);
    const dialog = byId('admin-confirm');
    const search = byId('user-search');
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
            if (parsed.version !== 1 || !Array.isArray(parsed.groups) || !Array.isArray(parsed.users)) throw new Error('Invalid preview');
            const restored = initialState();
            restored.groups.forEach(group => {
                const record = parsed.groups.find(item => item && item.id === group.id);
                if (!record || !['Active', 'Completed', 'Frozen', 'Dissolved'].includes(record.status)) throw new Error('Invalid group');
                if (group.status === 'Frozen') group.status = record.status === 'Completed' ? 'Frozen' : record.status;
            });
            restored.users.forEach(user => {
                const record = parsed.users.find(item => item && item.id === user.id);
                if (!record || typeof record.banned !== 'boolean') throw new Error('Invalid user');
                user.banned = record.banned;
            });
            state = restored;
        }
    } catch (error) {
        storageNotice('Saved preview data could not be loaded. The original sample data is shown.');
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
        byId('active-count').textContent = state.groups.filter(group => group.status === 'Active').length;
        byId('completed-count').textContent = state.groups.filter(group => group.status === 'Completed').length;
        byId('flagged-count').textContent = state.groups.filter(group => group.status === 'Frozen').length;
        // Historical verified volume remains unchanged by moderation decisions.
        byId('volume-total').textContent = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(state.groups.reduce((sum, group) => sum + group.volume, 0));
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
        const query = search.value.trim().toLocaleLowerCase();
        const users = state.users.filter(user => `${user.name} ${user.id}`.toLocaleLowerCase().includes(query));
        const roster = byId('user-roster');
        roster.replaceChildren();
        users.forEach(user => {
            const row = element('li', `user-row${user.banned ? ' is-banned' : ''}`);
            const initials = user.name.split(/\s+/).map(part => part[0]).slice(0, 2).join('');
            const avatar = element('span', 'user-avatar', initials);
            avatar.setAttribute('aria-hidden', 'true');
            const description = element('div', 'user-description');
            const name = element('div', 'user-name');
            name.append(element('strong', '', user.name));
            if (user.banned) name.append(element('span', 'status-pill', 'Banned'));
            description.title = user.id;
            description.append(name, element('span', 'sr-only', `User ID ${user.id}. `), element('span', 'user-meta', `${user.groups} ${user.groups === 1 ? 'group' : 'groups'} · Trust: ${user.trust}`));
            row.append(avatar, description, actionButton(user.banned ? 'Unban' : 'Ban', user.banned ? 'unban' : 'ban', user.id, user.name, user.banned));
            roster.append(row);
        });
        byId('users-empty').hidden = users.length > 0;
        const bannedCount = state.users.filter(user => user.banned).length;
        byId('roster-count').textContent = `${users.length} of ${state.users.length} users · ${bannedCount} banned`;
    }

    function announce(message) {
        clearTimeout(toastTimer);
        byId('admin-feedback').textContent = message;
        toastTimer = setTimeout(() => { byId('admin-feedback').textContent = ''; }, 6000);
    }

    function confirmAction(action, id, button) {
        const user = state.users.find(item => item.id === id);
        const group = state.groups.find(item => item.id === id);
        const messages = {
            ban: user && ['Ban user?', `Mark ${user.name} as banned in this preview? You can unban them at any time.`, 'Ban user'],
            unban: user && ['Unban user?', `Restore ${user.name} to active status in this preview?`, 'Unban user'],
            unfreeze: group && ['Force-unfreeze group?', `Return ${group.name} to Active and remove it from the intervention desk in this preview?`, 'Force-unfreeze'],
            dissolve: group && ['Force-dissolve group?', `Mark ${group.name} as Dissolved and remove it from the intervention desk in this preview? Its history is retained. Use Reset preview to restore the sample group.`, 'Force-dissolve'],
            reset: ['Reset preview?', 'Restore all sample groups and users? This clears your saved preview decisions in this browser.', 'Reset preview']
        };
        if (!messages[action]) return;
        pending = { action, id };
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
        let message = '';
        if (action === 'reset') {
            state = initialState();
            search.value = '';
            message = 'Preview reset. All sample groups and users have been restored.';
        } else if (action === 'ban' || action === 'unban') {
            const user = state.users.find(item => item.id === id);
            user.banned = action === 'ban';
            message = `${user.name} has been ${user.banned ? 'banned' : 'unbanned'} in the preview.`;
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
    byId('clear-search').addEventListener('click', () => { search.value = ''; renderUsers(); search.focus(); });
    renderOverview();
    renderUsers();
})();
