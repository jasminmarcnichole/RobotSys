const tones = {
    success: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    danger: 'bg-red-50 text-red-700 ring-red-200',
    warning: 'bg-amber-50 text-amber-800 ring-amber-200',
    secondary: 'bg-zinc-100 text-zinc-600 ring-zinc-200',
    info: 'bg-blue-50 text-blue-700 ring-blue-200',
};

export function setText(id, value) {
    const element = document.getElementById(id);
    if (element) {
        element.textContent = value ?? '--';
    }
}

export function setPill(element, tone, label) {
    if (!element) {
        return;
    }
    element.className = `inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset ${tones[tone] ?? tones.secondary}`;
    const labelTarget = element.querySelector?.('[data-status-label]');
    if (labelTarget) {
        labelTarget.textContent = label;
    } else {
        element.textContent = label;
    }
}

export function setMessage(element, message, tone = 'secondary') {
    if (!element) {
        return;
    }
    element.className = `rounded-xl px-3.5 py-2.5 text-sm leading-5 ring-1 ring-inset ${tones[tone] ?? tones.secondary}`;
    element.textContent = message;
}

export async function requestJson(url, options = {}) {
    if (navigator.onLine === false) {
        throw new Error('You are offline. Reconnect before requesting or saving data.');
    }
    const response = await fetch(url, {
        cache: 'no-store',
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            ...options.headers,
        },
        signal: options.signal ?? AbortSignal.timeout(15000),
    });
    const data = await response.json().catch(() => null);
    if (!response.ok) {
        const firstError = Object.values(data?.errors ?? {}).flat()[0];
        throw new Error([401, 419].includes(response.status)
            ? 'Your session has expired. Sign in again.'
            : firstError ?? data?.message ?? 'The request could not be completed. Try again.');
    }
    return data;
}
