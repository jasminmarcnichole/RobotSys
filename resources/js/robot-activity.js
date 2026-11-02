const dialog = document.getElementById('evidenceDialog');

if (dialog) {
    const image = document.getElementById('evidenceImage');
    const imageStatus = document.getElementById('evidenceImageStatus');
    const original = document.getElementById('evidenceOriginal');
    let trigger = null;

    image.addEventListener('load', () => {
        imageStatus.hidden = true;
    });
    image.addEventListener('error', () => {
        if (!dialog.open) return;
        imageStatus.hidden = false;
        imageStatus.textContent = 'The evidence image is unavailable.';
    });

    document.querySelectorAll('[data-evidence]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const evidence = JSON.parse(link.dataset.evidence);
            event.preventDefault();
            trigger = link;
            document.getElementById('evidenceTitle').textContent = `Detection evidence #${evidence.id}`;
            dialog.querySelectorAll('[data-evidence-field]').forEach((field) => {
                field.textContent = evidence[field.dataset.evidenceField] ?? '--';
            });
            imageStatus.hidden = false;
            imageStatus.textContent = 'Loading image...';
            image.alt = evidence.object === '--' ? 'Detection snapshot' : evidence.object;
            original.href = link.href;
            dialog.showModal();
            image.src = link.href;
        });
    });

    dialog.addEventListener('close', () => {
        image.removeAttribute('src');
        trigger?.focus({ preventScroll: true });
    });
    dialog.addEventListener('click', (event) => {
        const bounds = dialog.getBoundingClientRect();
        if (event.target === dialog && (event.clientX < bounds.left || event.clientX > bounds.right
            || event.clientY < bounds.top || event.clientY > bounds.bottom)) {
            dialog.close();
        }
    });
}
