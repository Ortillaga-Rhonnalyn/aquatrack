'use strict';
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('form:has(#inventoryAction)');
  const action = document.getElementById('inventoryAction');
  const id = document.getElementById('inventoryEditId');
  const save = document.getElementById('inventorySave');
  const reset = document.getElementById('inventoryReset');
  document.querySelectorAll('.inventory-edit-btn').forEach(button => button.addEventListener('click', () => {
    action.value = 'inventory_edit';
    id.value = button.dataset.id;
    form.elements.item_name.value = button.dataset.name;
    form.elements.item_type.value = button.dataset.type;
    form.elements.unit.value = button.dataset.unit;
    form.elements.reorder_level.value = button.dataset.reorder;
    save.textContent = 'Save Changes';
    reset.hidden = false;
    form.scrollIntoView({behavior:'smooth',block:'center'});
  }));
  reset?.addEventListener('click', () => {
    form.reset(); action.value='inventory_create'; id.value='';
    save.textContent='Add Inventory Item'; reset.hidden=true;
  });
  const type = document.getElementById('movementType');
  const label = document.getElementById('movementQuantityLabel');
  const quantity = document.querySelector('.inventory-movement-form [name=quantity]');
  type?.addEventListener('change', () => {
    const adjust = type.value === 'stock_adjust';
    label.textContent = adjust ? 'New Exact Stock' : 'Quantity';
    quantity.min = adjust ? '0' : '1';
  });
});
