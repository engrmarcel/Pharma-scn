// Public landing page: flowing particle network behind the hero, scroll reveals, header state.
// Everything degrades gracefully: content is server-rendered and visible without JavaScript,
// and motion is reduced to a still frame when the visitor prefers reduced motion.
import './app.js';

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Particles drift left to right in gentle streams (goods moving down the supply chain);
 * nearby particles link up like partners on the network; a few larger gold particles are
 * hubs. The pointer gently pulls particles towards it.
 */
function initParticles(canvas) {
    const ctx = canvas.getContext('2d');
    const density = Number(canvas.dataset.density || 90);
    const colors = ['rgba(125, 211, 252, 0.85)', 'rgba(255, 255, 255, 0.75)', 'rgba(237, 196, 93, 0.95)'];
    let particles = [];
    let width = 0;
    let height = 0;
    let running = false;
    let frame = null;
    const pointer = { x: null, y: null };

    const resize = () => {
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        width = canvas.clientWidth;
        height = canvas.clientHeight;
        canvas.width = width * dpr;
        canvas.height = height * dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        const count = Math.round(density * Math.min(1.4, (width * height) / (1440 * 800)));
        particles = Array.from({ length: Math.max(24, count) }, () => spawn(Math.random() * width));
    };

    const spawn = (x) => {
        const hub = Math.random() < 0.08;
        return {
            x,
            y: Math.random() * height,
            r: hub ? 3 + Math.random() * 2 : 1 + Math.random() * 1.6,
            vx: 0.15 + Math.random() * 0.55,
            phase: Math.random() * Math.PI * 2,
            amp: 0.15 + Math.random() * 0.35,
            color: hub ? colors[2] : colors[Math.random() < 0.6 ? 0 : 1],
            hub,
        };
    };

    const linkDistance = () => Math.min(140, Math.max(90, width / 12));

    const draw = (t) => {
        ctx.clearRect(0, 0, width, height);
        const max = linkDistance();
        for (let i = 0; i < particles.length; i++) {
            const a = particles[i];
            for (let j = i + 1; j < particles.length; j++) {
                const b = particles[j];
                const dx = a.x - b.x;
                const dy = a.y - b.y;
                const d = dx * dx + dy * dy;
                if (d < max * max) {
                    const alpha = (1 - Math.sqrt(d) / max) * (a.hub || b.hub ? 0.7 : 0.38);
                    ctx.strokeStyle = a.hub || b.hub ? `rgba(237, 196, 93, ${alpha})` : `rgba(148, 197, 255, ${alpha})`;
                    ctx.lineWidth = a.hub || b.hub ? 0.9 : 0.6;
                    ctx.beginPath();
                    ctx.moveTo(a.x, a.y);
                    ctx.lineTo(b.x, b.y);
                    ctx.stroke();
                }
            }
        }
        for (const p of particles) {
            if (p.hub) {
                ctx.beginPath();
                ctx.fillStyle = 'rgba(237, 196, 93, 0.12)';
                ctx.arc(p.x, p.y, p.r * 4 + Math.sin(t / 600 + p.phase) * 2, 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.beginPath();
            ctx.fillStyle = p.color;
            ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
            ctx.fill();
        }
    };

    const step = (t) => {
        for (const p of particles) {
            p.x += p.vx;
            p.y += Math.sin(t / 1400 + p.phase) * p.amp;
            if (pointer.x !== null) {
                const dx = pointer.x - p.x;
                const dy = pointer.y - p.y;
                const d = Math.hypot(dx, dy);
                if (d < 160 && d > 1) {
                    p.x += (dx / d) * 0.35;
                    p.y += (dy / d) * 0.35;
                }
            }
            if (p.x > width + 10) Object.assign(p, spawn(-10));
            if (p.y < -10) p.y = height + 10;
            if (p.y > height + 10) p.y = -10;
        }
        draw(t);
        frame = requestAnimationFrame(step);
    };

    const start = () => {
        if (running || reducedMotion) return;
        running = true;
        frame = requestAnimationFrame(step);
    };
    const stop = () => {
        running = false;
        if (frame) cancelAnimationFrame(frame);
    };

    resize();
    draw(0);
    window.addEventListener('resize', () => { resize(); draw(0); });
    canvas.parentElement.addEventListener('pointermove', (e) => {
        const rect = canvas.getBoundingClientRect();
        pointer.x = e.clientX - rect.left;
        pointer.y = e.clientY - rect.top;
    });
    canvas.parentElement.addEventListener('pointerleave', () => { pointer.x = null; pointer.y = null; });
    document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));
    // Only animate while the hero is on screen.
    if ('IntersectionObserver' in window) {
        new IntersectionObserver(([entry]) => (entry.isIntersecting ? start() : stop())).observe(canvas);
    } else {
        start();
    }
}

function initReveal() {
    const items = document.querySelectorAll('[data-reveal]');
    if (!items.length || reducedMotion || !('IntersectionObserver' in window)) return;
    document.documentElement.classList.add('reveal-ready');
    const io = new IntersectionObserver((entries) => {
        entries.forEach((e) => {
            if (e.isIntersecting) {
                e.target.classList.add('is-visible');
                io.unobserve(e.target);
            }
        });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
    items.forEach((el) => io.observe(el));
}

function initHeader() {
    const header = document.querySelector('[data-landing-header]');
    if (!header) return;
    const update = () => header.classList.toggle('is-scrolled', window.scrollY > 40);
    update();
    window.addEventListener('scroll', update, { passive: true });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('canvas[data-particles]').forEach(initParticles);
    initReveal();
    initHeader();
});
