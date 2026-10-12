export function initLoginForm() {
    const button = document.querySelector('[data-password-toggle]');
    const password = document.getElementById('password');

    if (!button || !password) return;

    button.hidden = false;
    button.addEventListener('click', () => {
        const visible = password.type === 'password';
        password.type = visible ? 'text' : 'password';
        button.textContent = visible ? 'Sembunyikan' : 'Tampilkan';
        button.setAttribute('aria-label', visible ? 'Sembunyikan password' : 'Tampilkan password');
        button.setAttribute('aria-pressed', String(visible));
    });
}
