/**
 * Stamp card: tap a slot to see its details (stamp number, date, reward).
 * Hover and keyboard focus already show them through CSS; this adds tap,
 * which touch screens need, and closes the bubble on an outside tap or Esc.
 * The card is complete without this script.
 */
function closeTips(except = null) {
    document.querySelectorAll('.stamp-slot.is-open').forEach((slot) => {
        if (slot !== except) {
            slot.classList.remove('is-open');
        }
    });
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
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeTips();
    }
});
