/**
 * Draws each reward voucher's QR (the signed code in data-voucher-qr, see
 * App\Services\Loyalty\RewardVoucher) as an SVG inside its figure. The
 * cashier scans it with the POS camera: the customer's name and number fill
 * in and the reward goes in the order. Black on white with a quiet zone,
 * which is what scanners want.
 *
 * On the customer's own page ([data-voucher-refresh]) the codes change every
 * minute: the new ones are fetched when the slot ends, and each voucher
 * shows how long its QR has left. A failed fetch is tried again shortly; a
 * session that has ended asks for the card to be opened again.
 */
import QRCode from 'qrcode';

/** Seconds before trying again after a failed fetch. */
const RETRY_SECONDS = 5;

const drawVoucherQr = async (figure) => {
    const code = figure.dataset.voucherQr;
    if (!code) {
        return;
    }

    try {
        const svg = await QRCode.toString(code, {
            type: 'svg',
            errorCorrectionLevel: 'M',
            margin: 2,
            color: { dark: '#000000', light: '#ffffff' },
        });
        // A newer code may have come in while this one was drawn.
        if (figure.dataset.voucherQr !== code) {
            return;
        }
        figure.querySelector('svg')?.remove();
        figure.querySelector('.reward-voucher__code')?.remove();
        figure.insertAdjacentHTML('afterbegin', svg);
        figure.querySelector('svg')?.setAttribute('aria-hidden', 'true');
    } catch {
        // Can't draw it: show the code itself, a scanner gun still reads it.
        figure.querySelector('svg')?.remove();
        const fallback = figure.querySelector('.reward-voucher__code') ?? document.createElement('span');
        fallback.className = 'reward-voucher__code';
        fallback.textContent = code;
        figure.append(fallback);
    }
};

const figures = [...document.querySelectorAll('[data-voucher-qr]')];
figures.forEach(drawVoucherQr);

const refresher = document.querySelector('[data-voucher-refresh]');

if (refresher && figures.length > 0) {
    const url = refresher.dataset.voucherRefresh;
    const timers = [...document.querySelectorAll('[data-voucher-timer]')];
    let deadline = Date.now() + (Number(refresher.dataset.voucherRefreshIn) || 60) * 1000;
    let fetching = false;
    let stopped = false;

    const say = (text) => timers.forEach((timer) => {
        timer.textContent = text;
    });

    const refresh = async () => {
        fetching = true;
        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (response.status === 401 || response.status === 403 || response.status === 419) {
                stopped = true;
                say('Buka ulang halaman ini untuk QR baru.');

                return;
            }
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const { vouchers = [], refresh_in: refreshIn } = await response.json();
            vouchers.forEach(({ card_number: cardNumber, stamps, code }) => {
                const figure = figures.find(
                    (candidate) => candidate.dataset.voucherCard === cardNumber
                        && candidate.dataset.voucherStamps === String(stamps),
                );
                if (figure && figure.dataset.voucherQr !== code) {
                    figure.dataset.voucherQr = code;
                    drawVoucherQr(figure);
                }
            });
            deadline = Date.now() + (Number(refreshIn) || 60) * 1000;
        } catch {
            deadline = Date.now() + RETRY_SECONDS * 1000;
        } finally {
            fetching = false;
        }
    };

    const tick = () => {
        if (stopped) {
            return;
        }
        const left = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        if (left === 0) {
            if (!fetching) {
                refresh().then(tick);
            }

            return;
        }
        say(`QR berganti dalam ${left} detik`);
    };

    tick();
    window.setInterval(tick, 1000);
    // Back from another app or tab: catch up at once.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            tick();
        }
    });
}
