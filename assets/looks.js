document.querySelectorAll('.ml-looks').forEach((section) => {
    const currency = JSON.parse(section.dataset.money);
    const currencySymbol = new DOMParser().parseFromString(currency.symbol, 'text/html').body.textContent;
    const price = (value) => {
        const parts = value.toFixed(currency.decimals).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, () => currency.thousand);
        const amount = parts.join(currency.decimal);
        return currency.format.replace(/%([12])\$s/g, (_, index) => index === '1' ? currencySymbol : amount)
            .replace(/&nbsp;/g, '\u00a0');
    };

    section.querySelectorAll('.ml-card').forEach((card) => {
        const dialog = card.querySelector('dialog');
        const form = dialog.querySelector('form');
        const open = card.querySelector('.ml-open');
        const add = form.querySelector('.ml-add');
        const message = form.querySelector('.ml-error');
        const rows = [...form.querySelectorAll('.ml-row')];
        let pending = false;

        function update() {
            const selection = {};
            let count = 0;
            let missing = false;
            let base = 0;
            let discounted = 0;

            rows.forEach((row) => {
                const checkbox = row.querySelector('.ml-select');
                const sizes = row.querySelector('.ml-size');
                const option = sizes ? sizes.querySelector('.ml-choice:checked') : row.querySelector('.ml-choice');
                const amounts = option || sizes;
                if (!amounts) return;
                if (sizes) sizes.disabled = !checkbox.checked || pending;
                row.classList.toggle('ml-excluded', !checkbox.checked);
                row.querySelector('.ml-line-price').textContent = price(Number(amounts.dataset.price));

                if (!checkbox.checked) return;
                count += 1;
                base += Number(amounts.dataset.price);
                discounted += Number(amounts.dataset.sale);
                if (!option) {
                    missing = true;
                } else {
                    selection[row.dataset.product] = Number(option.value);
                }
            });

            const complete = form.dataset.complete === '1' && count === rows.length;
            form.querySelector('.ml-discount').hidden = !complete;
            form.querySelector('[data-base]').textContent = price(base);
            form.querySelector('[data-discount]').textContent = '−' + price(Math.max(0, base - discounted));
            form.querySelector('[data-total]').textContent = price(complete ? discounted : base);
            add.disabled = pending || count === 0;
            return { selection, missing };
        }

        open.addEventListener('click', () => {
            dialog.showModal();
            dialog.querySelector('.ml-close').focus();
            update();
        });
        dialog.querySelector('.ml-close').addEventListener('click', () => {
            if (!pending) dialog.close();
        });
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog && !pending) dialog.close();
        });
        dialog.addEventListener('cancel', (event) => {
            if (pending) event.preventDefault();
        });
        dialog.addEventListener('close', () => {
            open.focus();
            message.hidden = true;
        });
        form.addEventListener('change', () => {
            message.hidden = true;
            update();
        });
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (pending) return;
            const { selection, missing } = update();
            if (missing) {
                message.textContent = 'Выберите размер каждой отмеченной вещи.';
                message.hidden = false;
                rows.map((row) => row.querySelector('.ml-size'))
                    .find((sizes) => sizes && !sizes.disabled && !sizes.querySelector('.ml-choice:checked'))
                    ?.querySelector('.ml-choice')?.focus();
                return;
            }
            if (!Object.keys(selection).length) return;

            pending = true;
            message.hidden = true;
            add.textContent = 'Добавляем…';
            rows.forEach((row) => {
                const checkbox = row.querySelector('.ml-select');
                if (row.querySelector('.ml-choice')) checkbox.disabled = true;
            });
            update();
            try {
                const response = await fetch(section.dataset.endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-MITKIN-Look-Request': '1' },
                    body: new URLSearchParams({
                        look: form.elements.look.value,
                        selection: JSON.stringify(selection)
                    })
                });
                const result = await response.json();
                if (!result.success) {
                    throw new Error(result.data?.message || 'Не удалось добавить образ.');
                }
                window.location.assign(section.dataset.cart);
            } catch (error) {
                message.textContent = error instanceof SyntaxError || error instanceof TypeError
                    ? 'Не удалось получить ответ. Откройте корзину, чтобы проверить, добавился ли образ.'
                    : error.message;
                message.hidden = false;
                pending = false;
                add.textContent = 'Добавить в корзину';
                rows.forEach((row) => {
                    if (row.querySelector('.ml-choice')) row.querySelector('.ml-select').disabled = false;
                });
                update();
            }
        });
    });
});
