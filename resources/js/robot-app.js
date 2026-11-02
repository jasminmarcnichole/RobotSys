let installPrompt = null;
const installButtons = [...document.querySelectorAll('[data-app-install]')];
const standalone = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    installPrompt = event;
    installButtons.forEach((button) => { button.hidden = standalone(); });
});

installButtons.forEach((button) => button.addEventListener('click', async () => {
    if (!installPrompt) return;
    const prompt = installPrompt;
    installPrompt = null;
    installButtons.forEach((item) => { item.hidden = true; });
    try {
        await prompt.prompt();
        await prompt.userChoice;
    } catch {
        // The browser may withdraw an installation prompt after navigation.
    }
}));

window.addEventListener('appinstalled', () => {
    installPrompt = null;
    installButtons.forEach((button) => { button.hidden = true; });
});

function networkStatus() {
    document.querySelectorAll('[data-app-offline]').forEach((element) => { element.hidden = navigator.onLine; });
}

window.addEventListener('online', networkStatus);
window.addEventListener('offline', networkStatus);
networkStatus();

if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' }).catch(() => {});
    });
}
