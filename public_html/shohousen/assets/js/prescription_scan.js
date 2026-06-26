// 処方箋読込画面専用JS
// 目的: スマホ/iPadの撮影画像をプレビューし、最低限の画像サイズを確認してから解析開始できるようにする。
(() => {
  const cameraInput = document.getElementById('prescriptionFile');
  const pickerInput = document.getElementById('prescriptionFilePicker');
  const preview = document.getElementById('scanPreview');
  const quality = document.getElementById('scanQuality');
  const button = document.getElementById('analyzeButton');
  const sourceType = document.getElementById('sourceType');
  if (!cameraInput || !pickerInput || !preview || !quality || !button || !sourceType) return;

  const showFile = (file, source) => {
    if (!file) return;
    sourceType.value = source;

    const url = URL.createObjectURL(file);
    preview.onload = () => {
      const width = preview.naturalWidth;
      const height = preview.naturalHeight;
      const sizeMb = file.size / 1024 / 1024;
      const warnings = [];
      if (width < 1000 || height < 1000) warnings.push('画像サイズが小さい可能性があります');
      if (sizeMb > 8) warnings.push('画像が大きいためアップロードに時間がかかる可能性があります');
      quality.textContent = `画像: ${width}×${height}px / ${sizeMb.toFixed(2)}MB${warnings.length ? ' / 注意: ' + warnings.join('、') : ' / 解析可能です'}`;
      button.disabled = false;
      URL.revokeObjectURL(url);
    };
    preview.src = url;
    preview.hidden = false;
  };

  cameraInput.addEventListener('change', () => showFile(cameraInput.files && cameraInput.files[0], 'camera'));
  pickerInput.addEventListener('change', () => {
    const file = pickerInput.files && pickerInput.files[0];
    if (!file) return;
    const data = new DataTransfer();
    data.items.add(file);
    cameraInput.files = data.files;
    showFile(file, 'file');
  });
})();
