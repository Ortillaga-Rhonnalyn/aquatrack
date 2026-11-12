/** AquaTrack Order Management: dynamic product rows and estimate only. */
'use strict';
document.addEventListener('DOMContentLoaded', () => {
    const lines = document.getElementById('orderLines');
    const template = document.getElementById('orderLineTemplate');
    const add = document.getElementById('addOrderLine');
    const total = document.getElementById('orderEstimatedTotal');
    if (!lines || !template || !add || !total) return;

    const currency = new Intl.NumberFormat('en-PH', {
        style: 'currency', currency: 'PHP'
    });

    function refresh() {
        let amount = 0;
        lines.querySelectorAll('.order-line').forEach(row => {
            const select = row.querySelector('select');
            const quantity = Number(row.querySelector('input').value);
            const price = Number(select.selectedOptions[0]?.dataset.price || 0);
            if (Number.isFinite(price) && Number.isInteger(quantity) && quantity > 0) {
                amount += price * quantity;
            }
            row.querySelector('.line-remove').disabled = lines.children.length <= 1;
        });
        total.textContent = currency.format(amount);
        add.disabled = lines.children.length >= 15;
    }

    add.addEventListener('click', () => {
        if (lines.children.length >= 15) return;
        lines.append(template.content.cloneNode(true));
        refresh();
    });
    lines.addEventListener('input', refresh);
    lines.addEventListener('change', refresh);
    lines.addEventListener('click', event => {
        if (!event.target.closest('.line-remove') || lines.children.length <= 1) return;
        event.target.closest('.order-line').remove();
        refresh();
    });
    refresh();
});
