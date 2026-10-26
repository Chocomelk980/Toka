(() => {
    const storageKey = 'toka_tab_auth';
    const parameter = 'toka_tab';
    const currentUrl = new URL(window.location.href);
    const tokenFromUrl = currentUrl.searchParams.get(parameter);
    let token = '';

    try {
        token = tokenFromUrl || sessionStorage.getItem(storageKey) || '';
        if (token) sessionStorage.setItem(storageKey, token);
    } catch (error) {
        window.location.replace('../LOGIN_PAGE/index.php?tab_auth_required=1');
        return;
    }

    if (!token) {
        window.location.replace('../LOGIN_PAGE/index.php?tab_auth_required=1');
        return;
    }

    if (tokenFromUrl) {
        currentUrl.searchParams.delete(parameter);
        history.replaceState(history.state, '', currentUrl.href);
    }

    const withTabAuthToken = url => {
        const destination = new URL(url, window.location.href);
        if (destination.origin === window.location.origin) {
            destination.searchParams.set(parameter, token);
        }
        return destination.href;
    };
    window.tokaTabAuthUrl = withTabAuthToken;
    window.tokaTabNavigate = url => window.location.assign(withTabAuthToken(url));
    window.tokaTabReload = () => window.location.replace(withTabAuthToken(window.location.href));

    document.querySelectorAll('a[href]').forEach(anchor => {
        const href = anchor.getAttribute('href') || '';
        if (!href || href.startsWith('#') || href.startsWith('javascript:')
            || anchor.hasAttribute('download') || anchor.target.toLowerCase() === '_blank') return;
        try {
            const destination = new URL(href, window.location.href);
            if (destination.origin !== window.location.origin) return;
            anchor.href = withTabAuthToken(destination.href);
        } catch (error) {
            // Ignore malformed or non-navigation links.
        }
    });

    document.querySelectorAll('form').forEach(form => {
        if ((form.getAttribute('method') || 'get').toLowerCase() === 'dialog'
            || form.target.toLowerCase() === '_blank') return;
        addTokenField(form);
    });

    function addTokenField(form) {
        if ((form.getAttribute('method') || 'get').toLowerCase() === 'dialog'
            || form.target.toLowerCase() === '_blank') return;
        let field = form.querySelector('input[name="toka_tab"]');
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = parameter;
            form.appendChild(field);
        }
        field.value = token;
    }

    document.addEventListener('submit', event => addTokenField(event.target), true);
    const nativeSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
        addTokenField(this);
        nativeSubmit.call(this);
    };

    const logoutDialog = document.createElement('dialog');
    logoutDialog.className = 'logout-confirm-dialog';
    logoutDialog.setAttribute('aria-labelledby', 'logout-confirm-title');
    logoutDialog.innerHTML = `
        <h2 id="logout-confirm-title">Log out?</h2>
        <p>Are you sure you want to log out?</p>
        <div class="logout-confirm-actions">
            <button type="button" class="logout-confirm-no">No</button>
            <button type="button" class="logout-confirm-yes">Yes</button>
        </div>
    `;
    document.body.appendChild(logoutDialog);

    let logoutDestination = '';
    const cancelLogout = () => {
        logoutDestination = '';
        logoutDialog.close();
    };

    logoutDialog.querySelector('.logout-confirm-no').addEventListener('click', cancelLogout);
    logoutDialog.querySelector('.logout-confirm-yes').addEventListener('click', () => {
        if (logoutDestination) window.location.assign(logoutDestination);
    });
    logoutDialog.addEventListener('click', event => {
        if (event.target === logoutDialog) cancelLogout();
    });

    document.addEventListener('click', event => {
        if (!(event.target instanceof Element)) return;
        const logoutLink = event.target.closest('a[href]');
        if (!logoutLink || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        let destination;
        try {
            destination = new URL(logoutLink.href, window.location.href);
        } catch (error) {
            return;
        }
        if (destination.origin !== window.location.origin || !destination.pathname.endsWith('/logout.php')) return;

        event.preventDefault();
        logoutDestination = destination.href;
        if (!logoutDialog.open) logoutDialog.showModal();
    });
})();
