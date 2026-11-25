/**
 * Draws each reward voucher's QR (the signed code in data-voucher-qr, see
 * App\Services\Loyalty\RewardVoucher) as an SVG inside its figure. The
 * cashier scans it with the POS camera: the customer's name and number fill
 * in and the reward goes in the order. Black on white with a quiet zone,
 * which is what scanners want. The codes never change, so each QR is
 * drawn once.
 */
import QRCode from 'qrcode';

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

document.querySelectorAll('[data-voucher-qr]').forEach(drawVoucherQr);
