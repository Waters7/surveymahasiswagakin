export function initLoginForm() {
    for (const button of document.querySelectorAll('[data-password-toggle]')) {
        const password = document.getElementById(button.getAttribute('aria-controls'));
        if (!password) continue;

        button.hidden = false;
        button.addEventListener('click', () => {
            const visible = password.type === 'password';
            password.type = visible ? 'text' : 'password';
            button.textContent = visible ? 'Sembunyikan' : 'Tampilkan';
            button.setAttribute('aria-label', visible ? 'Sembunyikan password' : 'Tampilkan password');
            button.setAttribute('aria-pressed', String(visible));
        });
    }
}
