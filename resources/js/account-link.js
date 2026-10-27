export function accountLink(fragment) {
    const params = new URLSearchParams(fragment.replace(/^#/, ''));
    const token = params.get('token') ?? '';
    return /^[a-zA-Z0-9]{64}$/.test(token) ? { token, email: params.get('email') ?? '' } : null;
}

export function initializeAccountLink(form, location, history) {
    const field = form.querySelector('[data-link-token]');
    const link = accountLink(location.hash);
    // Remove the secret before any later navigation, referrer or history entry.
    if (location.hash) history.replaceState(null, '', location.pathname + location.search);
    if (link) {
        field.value = link.token;
        if (form.dataset.accountLink === 'reset') form.elements.email.value = link.email;
    }
    if (! /^[a-zA-Z0-9]{64}$/.test(field.value)) {
        form.querySelector('[data-link-error]').hidden = false;
        form.querySelector('[type="submit"]').disabled = true;
    }
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-account-link]').forEach(form => initializeAccountLink(form, window.location, window.history));
}
