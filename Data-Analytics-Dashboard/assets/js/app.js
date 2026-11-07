/* Shared UI behaviour: mobile sidebar toggle */
(() => {
    const app = document.getElementById('app');
    const btn = document.getElementById('menuBtn');
    const scrim = document.getElementById('scrim');
    if (!app || !btn) return;

    const close = () => app.classList.remove('nav-open');
    btn.addEventListener('click', () => app.classList.toggle('nav-open'));
    scrim.addEventListener('click', close);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
})();
