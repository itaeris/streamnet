let node = null;
let owner = null;
let timer = 0;
let lastHide = 0;

function ensureNode() {
    if (node) {
        return node;
    }

    node = document.createElement('div');
    node.setAttribute('role', 'tooltip');
    node.className = 'pointer-events-none fixed top-0 left-0 z-[100] max-w-64 rounded-md bg-tooltip px-2 py-1 text-[11px] leading-4 font-medium text-paper shadow-md ring-1 ring-line opacity-0 transition-opacity duration-100';
    document.body.appendChild(node);

    return node;
}

function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

function place(el) {
    const tip = ensureNode();
    const rect = el.getBoundingClientRect();
    const box = tip.getBoundingClientRect();
    const gap = 6;
    let left;
    let top;

    if (el.__tip.side === 'right') {
        left = rect.right + gap;
        top = rect.top + (rect.height - box.height) / 2;
    } else {
        left = rect.left + (rect.width - box.width) / 2;
        top = rect.bottom + gap;
        if (top + box.height > window.innerHeight - 4) {
            top = rect.top - gap - box.height;
        }
    }

    tip.style.left = `${clamp(left, 4, window.innerWidth - box.width - 4)}px`;
    tip.style.top = `${clamp(top, 4, window.innerHeight - box.height - 4)}px`;
}

function show(el) {
    const text = el.__tip?.text;
    if (!text || !el.isConnected) {
        return;
    }

    const tip = ensureNode();
    tip.textContent = text;
    owner = el;
    place(el);
    tip.style.opacity = '1';
}

function hide() {
    window.clearTimeout(timer);
    if (owner) {
        lastHide = Date.now();
    }
    owner = null;
    if (node) {
        node.style.opacity = '0';
    }
}

function iconOnly(el) {
    return [...el.childNodes].every((child) => {
        if (child.nodeType === Node.TEXT_NODE) {
            return !child.textContent.trim();
        }

        return child.nodeType !== Node.ELEMENT_NODE || child.classList.contains('material-symbols-outlined');
    });
}

function label(el) {
    if (el.__tip.text && iconOnly(el)) {
        el.setAttribute('aria-label', el.__tip.text);
    } else if (el.__tip.labelled) {
        el.removeAttribute('aria-label');
    }
    el.__tip.labelled = el.hasAttribute('aria-label');
}

function onEnter(event) {
    const el = event.currentTarget;
    window.clearTimeout(timer);
    const delay = Date.now() - lastHide < 400 ? 0 : 350;
    timer = window.setTimeout(() => show(el), delay);
}

if (typeof window !== 'undefined') {
    window.addEventListener('scroll', hide, true);
    window.addEventListener('blur', hide);
}

export const tooltip = {
    mounted(el, binding) {
        el.__tip = { text: binding.value ? String(binding.value) : '', side: binding.arg || 'bottom', labelled: false };
        label(el);
        el.addEventListener('mouseenter', onEnter);
        el.addEventListener('mouseleave', hide);
        el.addEventListener('pointerdown', hide);
    },
    updated(el, binding) {
        el.__tip.text = binding.value ? String(binding.value) : '';
        el.__tip.side = binding.arg || 'bottom';
        label(el);

        if (owner !== el) {
            return;
        }

        if (el.__tip.text) {
            show(el);
        } else {
            hide();
        }
    },
    beforeUnmount(el) {
        el.removeEventListener('mouseenter', onEnter);
        el.removeEventListener('mouseleave', hide);
        el.removeEventListener('pointerdown', hide);
        if (owner === el) {
            hide();
        }
    },
};
