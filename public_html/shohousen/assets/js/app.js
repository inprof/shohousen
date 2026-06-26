document.addEventListener('change', (event) => {
  const input = event.target;
  if (input.matches('input[type="file"]')) {
    const card = input.closest('.file-card');
    const name = input.files && input.files[0] ? input.files[0].name : '';
    if (card && name) {
      card.querySelector('em').textContent = `選択中：${name}`;
    }
  }
});
