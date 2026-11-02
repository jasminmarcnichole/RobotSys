const loginForm = document.querySelector('[data-login-form]');

if (loginForm) {
    const password = loginForm.querySelector('#password');
    const toggle = loginForm.querySelector('[data-password-toggle]');
    const submit = loginForm.querySelector('[data-login-submit]');
    const label = loginForm.querySelector('[data-login-label]');

    toggle?.classList.remove('hidden');
    toggle?.addEventListener('click', () => {
        const visible = password.type === 'password';
        password.type = visible ? 'text' : 'password';
        toggle.textContent = visible ? 'Hide' : 'Show';
        toggle.setAttribute('aria-pressed', String(visible));
        toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
    });

    loginForm.addEventListener('submit', () => {
        submit.disabled = true;
        label.textContent = 'Signing in…';
    });

    window.addEventListener('pageshow', () => {
        submit.disabled = false;
        label.textContent = 'Sign in to workspace';
        password.type = 'password';
        toggle.textContent = 'Show';
        toggle.setAttribute('aria-pressed', 'false');
        toggle.setAttribute('aria-label', 'Show password');
    });
}
