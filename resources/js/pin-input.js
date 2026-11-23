/**
 * PIN fields ([data-pin-input]) keep digits only, so a pasted "55 26"
 * becomes 5526. The server checks the PIN either way.
 */
document.addEventListener('input', (event) => {
    const input = event.target.closest?.('[data-pin-input]');

    if (input) {
        input.value = input.value.replace(/\D/g, '').slice(0, 4);
    }
});
