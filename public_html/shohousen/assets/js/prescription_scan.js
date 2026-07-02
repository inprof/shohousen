// 処方箋読込画面専用JS
// 目的: スマホ/iPadの撮影画像を画面内プレビューし、必要に応じて送信用画像を縮小してから解析開始できるようにする。
(() => {
  const cameraInput = document.getElementById('prescriptionFile');
  const pickerInput = document.getElementById('prescriptionFilePicker');
  const openCameraButton = document.getElementById('openCameraButton');
  const openFilePickerButton = document.getElementById('openFilePickerButton');
  const preview = document.getElementById('scanPreview');
  const quality = document.getElementById('scanQuality');
  const button = document.getElementById('analyzeButton');
  const sourceType = document.getElementById('sourceType');
  if (!cameraInput || !pickerInput || !openCameraButton || !openFilePickerButton || !preview || !quality || !button || !sourceType) return;

  const MAX_LONG_SIDE = 2200;
  const JPEG_QUALITY = 0.84;

  const supportsFileReplacement = () => typeof DataTransfer !== 'undefined';

  const loadImage = (file) => new Promise((resolve, reject) => {
    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
      URL.revokeObjectURL(url);
      resolve(img);
    };
    img.onerror = () => {
      URL.revokeObjectURL(url);
      reject(new Error('画像プレビューの読み込みに失敗しました'));
    };
    img.src = url;
  });

  const canvasToBlob = (canvas, type, qualityValue) => new Promise((resolve) => {
    if (!canvas.toBlob) {
      resolve(null);
      return;
    }
    canvas.toBlob(resolve, type, qualityValue);
  });

  const shrinkImageIfNeeded = async (file) => {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
      return { file, resized: false, originalWidth: null, originalHeight: null, originalSize: file.size };
    }

    const img = await loadImage(file);
    const originalWidth = img.naturalWidth || img.width;
    const originalHeight = img.naturalHeight || img.height;
    const longSide = Math.max(originalWidth, originalHeight);

    if (longSide <= MAX_LONG_SIDE && file.size <= 5 * 1024 * 1024) {
      return { file, resized: false, originalWidth, originalHeight, originalSize: file.size };
    }

    const scale = Math.min(1, MAX_LONG_SIDE / longSide);
    const width = Math.max(1, Math.round(originalWidth * scale));
    const height = Math.max(1, Math.round(originalHeight * scale));
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d', { alpha: false });
    if (!ctx) {
      return { file, resized: false, originalWidth, originalHeight, originalSize: file.size };
    }
    ctx.drawImage(img, 0, 0, width, height);

    const blob = await canvasToBlob(canvas, 'image/jpeg', JPEG_QUALITY);
    if (!blob || blob.size <= 0 || blob.size >= file.size) {
      return { file, resized: false, originalWidth, originalHeight, originalSize: file.size };
    }

    const name = (file.name || 'prescription').replace(/\.[^.]+$/, '') + '_resized.jpg';
    const resizedFile = new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() });
    return { file: resizedFile, resized: true, originalWidth, originalHeight, originalSize: file.size };
  };

  const replaceMainInputFile = (file) => {
    if (!supportsFileReplacement()) return false;
    const data = new DataTransfer();
    data.items.add(file);
    cameraInput.files = data.files;
    return true;
  };

  const setPreview = (file, meta) => {
    const url = URL.createObjectURL(file);
    preview.onload = () => {
      const width = preview.naturalWidth;
      const height = preview.naturalHeight;
      const sizeMb = file.size / 1024 / 1024;
      const originalMb = (meta.originalSize || file.size) / 1024 / 1024;
      const warnings = [];
      if (width < 1000 || height < 1000) warnings.push('画像サイズが小さい可能性があります');
      if (meta.resized) warnings.push(`送信用に ${meta.originalWidth}×${meta.originalHeight}px / ${originalMb.toFixed(2)}MB から縮小しました`);
      if (sizeMb > 8) warnings.push('画像が大きいためアップロードに時間がかかる可能性があります');
      quality.textContent = `送信画像: ${width}×${height}px / ${sizeMb.toFixed(2)}MB${warnings.length ? ' / 注意: ' + warnings.join('、') : ' / 解析可能です'}`;
      button.disabled = false;
      URL.revokeObjectURL(url);
    };
    preview.src = url;
    preview.hidden = false;
  };

  const showFile = async (file, source) => {
    if (!file) return;
    button.disabled = true;
    sourceType.value = source;
    quality.textContent = '画像を確認しています...';

    try {
      const meta = await shrinkImageIfNeeded(file);
      const replaced = replaceMainInputFile(meta.file);
      if (!replaced && source === 'file') {
        quality.textContent = 'このブラウザでは選択ファイルの自動差し替えができません。カメラ側から再選択してください。';
      }
      setPreview(meta.file, meta);
    } catch (error) {
      quality.textContent = error && error.message ? error.message : '画像の確認に失敗しました。';
      button.disabled = true;
    }
  };

  openCameraButton.addEventListener('click', () => {
    sourceType.value = 'camera';
    cameraInput.click();
  });

  openFilePickerButton.addEventListener('click', () => {
    sourceType.value = 'file';
    pickerInput.click();
  });

  cameraInput.addEventListener('change', () => showFile(cameraInput.files && cameraInput.files[0], 'camera'));
  pickerInput.addEventListener('change', () => showFile(pickerInput.files && pickerInput.files[0], 'file'));
})();
