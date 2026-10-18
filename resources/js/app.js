// Count-up for dashboard figures. The server renders the final value, so the
// page is correct without JavaScript; this only animates from zero once the
// card scrolls into view (skipped when the user prefers reduced motion).
const countUp = (el) => {
    const target = Number(el.dataset.countTo);
    const decimals = Number(el.dataset.decimals || 0);
    if (!Number.isFinite(target) || target === 0) return;

    const format = (n) => n.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    const duration = 1100;
    const start = performance.now();

    const tick = (now) => {
        const p = Math.min((now - start) / duration, 1);
        el.textContent = format(target * (1 - Math.pow(1 - p, 3)));
        if (p < 1) requestAnimationFrame(tick);
        else el.textContent = format(target);
    };
    requestAnimationFrame(tick);
};

const initCounters = () => {
    const els = document.querySelectorAll('[data-count-to]');
    if (!els.length || window.matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) return;

    const io = new IntersectionObserver((entries) => {
        entries.forEach((e) => {
            if (e.isIntersecting) {
                countUp(e.target);
                io.unobserve(e.target);
            }
        });
    }, { threshold: 0.4 });
    els.forEach((el) => io.observe(el));
};

// Sticky header gains a shadow once the page scrolls.
const initHeader = () => {
    const header = document.querySelector('[data-sticky-header]');
    if (!header) return;
    const update = () => header.classList.toggle('shadow-md', window.scrollY > 8);
    update();
    window.addEventListener('scroll', update, { passive: true });
};

document.addEventListener('DOMContentLoaded', () => {
    initCounters();
    initHeader();
});
