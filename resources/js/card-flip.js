/**
 * Every stamp card has its QRs printed on the back: first the customer QR
 * (name and number for the cashier), then a voucher per ready reward. The
 * card turns with the cursor or a finger: a click / tap on the front (not on
 * a stamp or a button) or a sideways drag shows the customer QR; TUKAR shows
 * that reward's voucher; a tap on the back or Esc turns it back. A drag
 * follows the pointer, let go and the card falls to the nearer side (or the
 * other side, after a quick flick). Keyboards and screen readers get the
 * "Tunjukkan QR" and "Balik ke depan kartu" buttons, hidden until focused.
 *
 * The turn is --flip on [data-card-flipper], in degrees: 0 is the front,
 * ±180 the back. Without this script the back is shown under the card
 * (app.css, scripting: none).
 */
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

/** Sideways pixels before a press becomes a drag; less stays a tap. */
const DRAG_THRESHOLD = 8;

/** Degrees a card must be dragged to fall to the other side when let go. */
const TURN_THRESHOLD = 60;

/** Pixels per millisecond that count as a flick, whatever the angle. */
const FLICK_SPEED = 0.5;

/** data-voucher of the customer QR (RewardVoucher::MEMBER). */
const MEMBER_VOUCHER = '0';

/** On the front, a click on these does its own thing instead of turning the card. */
const OWN_CLICK = 'button, a, input, select, textarea, label, .stamp-slot';

function setUpCard(card) {
    const flipper = card.querySelector('[data-card-flipper]');
    const front = card.querySelector('[data-card-front]');
    const back = card.querySelector('[data-card-back]');
    const vouchers = [...back.querySelectorAll('[data-voucher]')];
    const claimButtons = [...card.querySelectorAll('[data-card-flip]')];

    let angle = 0;
    let shownVoucher = vouchers[0].dataset.voucher;
    let turn = 0;
    let drag = null;
    let swallowClick = false;

    const backIsUp = () => Math.abs(angle) % 360 === 180;

    const rotate = (degrees) => flipper.style.setProperty('--flip', `${degrees}deg`);

    function showVoucher(stamps) {
        shownVoucher = stamps;
        vouchers.forEach((voucher) => {
            voucher.hidden = voucher.dataset.voucher !== stamps;
        });
    }

    /** Turned from the front by hand: the customer QR, not the last reward. */
    function showMemberVoucher() {
        if (vouchers.some((voucher) => voucher.dataset.voucher === MEMBER_VOUCHER)) {
            showVoucher(MEMBER_VOUCHER);
        }
    }

    /** Runs once the turn now starting has finished. */
    function afterTurn(callback) {
        const thisTurn = turn;

        if (reduceMotion.matches) {
            callback();

            return;
        }

        const done = (event) => {
            if (event.target !== flipper) {
                return;
            }
            flipper.removeEventListener('transitionend', done);
            if (thisTurn === turn) {
                callback();
            }
        };
        flipper.addEventListener('transitionend', done);
    }

    function turnTo(target, { moveFocus = false } = {}) {
        angle = target;
        turn++;
        rotate(angle);

        const backUp = backIsUp();
        front.inert = backUp;
        back.inert = !backUp;
        claimButtons.forEach((button) => {
            button.setAttribute('aria-expanded', String(backUp && button.dataset.cardFlip === shownVoucher));
        });
        card.querySelectorAll('.stamp-slot.is-open').forEach((slot) => slot.classList.remove('is-open'));

        afterTurn(() => {
            // Once all the way round, back to 0 without a visible turn.
            if (Math.abs(angle) === 360) {
                card.classList.add('is-dragging');
                angle = 0;
                rotate(0);
                flipper.getBoundingClientRect();
                card.classList.remove('is-dragging');
            }
        });

        if (!moveFocus) {
            return;
        }

        if (backUp) {
            const voucher = back.querySelector(`[data-voucher="${shownVoucher}"]`);
            voucher.querySelector('[data-voucher-title]').focus({ preventScroll: true });
            afterTurn(() => {
                voucher.querySelector('.reward-voucher__qr').scrollIntoView({
                    block: 'nearest',
                    behavior: reduceMotion.matches ? 'auto' : 'smooth',
                });
            });
        } else {
            claimButtons.find((button) => button.dataset.cardFlip === shownVoucher)?.focus({ preventScroll: true });
        }
    }

    /** Back to the front, turning back the way the card came. */
    const turnToFront = (options) => turnTo(angle > 0 ? angle - 180 : angle + 180, options);

    // Capture phase: a click that ends a drag must not reach the slot or
    // button under the finger (nor stamp-card.js on the document).
    card.addEventListener(
        'click',
        (event) => {
            if (swallowClick) {
                swallowClick = false;
                event.preventDefault();
                event.stopPropagation();

                return;
            }

            const claim = event.target.closest('[data-card-flip]');

            if (claim) {
                showVoucher(claim.dataset.cardFlip);
                turnTo(angle - 180, { moveFocus: true });
            } else if (backIsUp() && event.target.closest('[data-card-back]')) {
                turnToFront({ moveFocus: true });
            } else if (
                !backIsUp()
                && event.target.closest('[data-card-front]')
                && !event.target.closest(OWN_CLICK)
                // A click beside an open stamp tip only closes the tip.
                && !card.querySelector('.stamp-slot.is-open')
            ) {
                showMemberVoucher();
                turnTo(angle - 180);
            }
        },
        { capture: true },
    );

    card.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && backIsUp()) {
            turnToFront({ moveFocus: true });
        }
    });

    // Turning by hand. Vertical moves stay page scrolls (touch-action: pan-y).
    card.addEventListener('pointerdown', (event) => {
        swallowClick = false;

        if (!event.isPrimary || event.button !== 0 || reduceMotion.matches) {
            return;
        }

        drag = { id: event.pointerId, startX: event.clientX, startY: event.clientY, from: angle, moving: false };
        drag.lastX = event.clientX;
        drag.lastTime = event.timeStamp;
        drag.speed = 0;
    });

    card.addEventListener('pointermove', (event) => {
        if (drag === null || event.pointerId !== drag.id) {
            return;
        }

        const dx = event.clientX - drag.startX;
        const dy = event.clientY - drag.startY;

        if (!drag.moving) {
            if (Math.abs(dy) > DRAG_THRESHOLD && Math.abs(dy) >= Math.abs(dx)) {
                drag = null;

                return;
            }
            if (Math.abs(dx) <= DRAG_THRESHOLD) {
                return;
            }
            drag.moving = true;
            if (!backIsUp()) {
                showMemberVoucher();
            }
            card.setPointerCapture(event.pointerId);
            card.classList.add('is-dragging');
            card.querySelectorAll('.stamp-slot.is-open').forEach((slot) => slot.classList.remove('is-open'));
            window.getSelection()?.removeAllRanges();
        }

        const elapsed = event.timeStamp - drag.lastTime;
        if (elapsed > 0) {
            drag.speed = (event.clientX - drag.lastX) / elapsed;
        }
        drag.lastX = event.clientX;
        drag.lastTime = event.timeStamp;

        rotate(drag.from + draggedDegrees(dx));
    });

    function draggedDegrees(dx) {
        return Math.max(-180, Math.min(180, (dx / card.offsetWidth) * 180));
    }

    function letGo(event) {
        if (drag === null || event.pointerId !== drag.id) {
            return;
        }

        const { moving, from, startX, lastX, speed } = drag;
        drag = null;

        if (!moving) {
            return;
        }

        card.classList.remove('is-dragging');

        if (event.type === 'pointercancel') {
            turnTo(from);

            return;
        }

        swallowClick = true;

        const dragged = draggedDegrees(lastX - startX);
        const flicked = Math.abs(speed) > FLICK_SPEED && Math.sign(speed) === Math.sign(dragged);

        turnTo(Math.abs(dragged) > TURN_THRESHOLD || flicked ? from + 180 * Math.sign(dragged) : from);
    }

    card.addEventListener('pointerup', letGo);
    card.addEventListener('pointercancel', letGo);

    // A mouse drag must not pick up the sticker or QR images.
    card.addEventListener('dragstart', (event) => event.preventDefault());
}

document.querySelectorAll('[data-card-flipper]').forEach((flipper) => {
    setUpCard(flipper.closest('[data-stamp-card]'));
});
