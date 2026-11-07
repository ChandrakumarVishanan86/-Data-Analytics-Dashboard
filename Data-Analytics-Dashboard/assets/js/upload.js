/* Upload page: drag & drop, file name preview, submit state */
(() => {
    const zone = document.getElementById('dropzone');
    const input = document.getElementById('csv');
    const title = document.getElementById('dzTitle');
    const sub = document.getElementById('dzSub');
    const form = document.getElementById('uploadForm');
    const btn = document.getElementById('uploadBtn');
    if (!zone || !input) return;

    const MAX = 5 * 1024 * 1024;

    function showFile() {
        const f = input.files && input.files[0];
        if (!f) return;
        title.textContent = f.name;
        const kb = f.size / 1024;
        sub.textContent = (kb > 1024 ? (kb / 1024).toFixed(1) + ' MB' : Math.max(1, Math.round(kb)) + ' KB') +
            (f.size > MAX ? ' — too large (max 5 MB)' : ' · ready to import');
        zone.classList.add('has-file');
    }

    input.addEventListener('change', showFile);

    ['dragenter', 'dragover'].forEach((ev) =>
        zone.addEventListener(ev, (e) => { e.preventDefault(); zone.classList.add('drag'); }));
    ['dragleave', 'drop'].forEach((ev) =>
        zone.addEventListener(ev, (e) => { e.preventDefault(); zone.classList.remove('drag'); }));

    zone.addEventListener('drop', (e) => {
        if (e.dataTransfer && e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            showFile();
        }
    });

    form.addEventListener('submit', () => {
        btn.disabled = true;
        btn.textContent = 'Importing…';
    });
})();
