/**
 * Stamp card: tap a slot to see its details (stamp number, date, reward).
 * Hover and keyboard focus already show them through CSS; this adds tap,
 * which touch screens need, and closes the bubble on an outside tap or Esc.
 * The card is complete without this script.
 *
 * The product stickers can be picked at, like real ones: pressing one lifts
 * it off the card (it grows, straightens a little and its shadow spreads),
 * letting go presses it back down with a small squash. If the finger moves
 * off into a scroll or a card drag, the sticker settles back unpressed.
 */
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

if (!reduceMotion.matches && 'IntersectionObserver' in window) {
    const entrance = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (entry.intersectionRatio >= 0.5) {
                entry.target.classList.add('is-in-view');
                entrance.unobserve(entry.target);
            }
        }
    }, { threshold: 0.5 });

    document.querySelectorAll('.stamp-slot.is-filled').forEach((slot) => entrance.observe(slot));
}

/** Pixels a held finger may move before the press counts as a scroll or drag. */
const HOLD_SLOP = 8;

/** The sticker being held: { slot, pointerId, x, y }. */
let held = null;

function closeTips(except = null) {
    document.querySelectorAll('.stamp-slot.is-open').forEach((slot) => {
        if (slot !== except) {
            slot.classList.remove('is-open');
        }
    });
}

const stickerOf = (slot) => slot.querySelector('.stamp-slot__sticker');

const tiltOf = (slot) => parseFloat(slot.style.getPropertyValue('--tilt')) || 0;

/**
 * Animates the sticker from wherever it is now (mid-lift, hovered, still
 * being placed) to the given keyframes, replacing the previous hand move.
 * Without a last keyframe it ends on the sticker's own CSS.
 */
function moveSticker(slot, keyframes, options) {
    const sticker = stickerOf(slot);
    const now = getComputedStyle(sticker);
    const from = { transform: now.transform, filter: now.filter };

    sticker.getAnimations().forEach((animation) => {
        if (animation.id === 'sticker-hand') {
            animation.cancel();
        }
    });

    const resolved = keyframes.map(({ shadow, ...frame }) => ({
        ...frame,
        // The shadow follows the product's outline: a drop-shadow filter.
        ...(shadow ? { filter: now.getPropertyValue(shadow).trim() } : {}),
    }));

    sticker.animate([from, ...resolved], options).id = 'sticker-hand';
}

function lift(slot) {
    moveSticker(slot, [{ transform: `translateY(-3px) rotate(${tiltOf(slot) * 0.4}deg) scale(1.1)`, shadow: '--sticker-shadow-held' }], {
        duration: 180,
        easing: 'cubic-bezier(0.2, 0.8, 0.3, 1)',
        fill: 'forwards',
    });
}

function pressDown(slot) {
    moveSticker(slot, [{ transform: `rotate(${tiltOf(slot)}deg) scale(0.92)`, shadow: '--sticker-shadow-flat', offset: 0.4 }], {
        duration: 380,
        easing: 'cubic-bezier(0.3, 0.6, 0.35, 1)',
    });
}

function settle(slot) {
    moveSticker(slot, [], { duration: 200, easing: 'ease-out' });
}

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-stamp-card] .stamp-slot__button');

    if (!button) {
        closeTips();

        return;
    }

    const slot = button.closest('.stamp-slot');
    closeTips(slot);
    slot.classList.toggle('is-open');

    // Enter or Space on a sticker presses it too (a pointer already did).
    if (event.detail === 0 && slot.classList.contains('is-filled') && !reduceMotion.matches) {
        pressDown(slot);
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeTips();
    }
});

document.addEventListener('pointerdown', (event) => {
    const slot = event.target.closest('[data-stamp-card] .stamp-slot.is-filled');

    if (!slot || !event.isPrimary || event.button !== 0 || reduceMotion.matches) {
        return;
    }

    held = { slot, pointerId: event.pointerId, x: event.clientX, y: event.clientY };
    lift(slot);
});

document.addEventListener('pointermove', (event) => {
    if (held === null || event.pointerId !== held.pointerId) {
        return;
    }

    if (Math.hypot(event.clientX - held.x, event.clientY - held.y) > HOLD_SLOP) {
        settle(held.slot);
        held = null;
    }
});

function letGo(event) {
    if (held === null || event.pointerId !== held.pointerId) {
        return;
    }

    const { slot } = held;
    held = null;

    if (event.type === 'pointerup') {
        pressDown(slot);
    } else {
        settle(slot);
    }
}

document.addEventListener('pointerup', letGo);
document.addEventListener('pointercancel', letGo);
