document.addEventListener('DOMContentLoaded', () => {
  const inputs = document.querySelectorAll('input[type="file"]');

  inputs.forEach((input) => {
    const identifyingText =
      `${input.name} ${input.id} ${input.accept}`;

    if (
      input.multiple ||
      !/photo|image|picture/i.test(identifyingText)
    ) return;

    const wrapper = document.createElement('figure');
    wrapper.hidden = true;
    wrapper.style.cssText = 'margin:12px 0;';

    const image = document.createElement('img');
    image.alt = 'Preview of the selected item photo';
    image.style.cssText = `
      display:block;
      max-width:220px;
      width:100%;
      max-height:200px;
      object-fit:contain;
      border-radius:10px;
      border:1px solid #ddd;
    `;

    const caption = document.createElement('figcaption');
    caption.style.cssText =
      'font-size:12px;margin-top:6px;color:#666;overflow-wrap:anywhere;';

    wrapper.append(image, caption);
    input.insertAdjacentElement('afterend', wrapper);

    let previewUrl = null;

    const reset = () => {
      if (previewUrl) URL.revokeObjectURL(previewUrl);
      previewUrl = null;
      image.removeAttribute('src');
      wrapper.hidden = true;
    };

    input.addEventListener('change', () => {
      reset();

      const file = input.files?.[0];
      if (!file) return;

      if (!file.type.startsWith('image/')) {
        image.hidden = true;
        caption.textContent =
          'This file is not an image. Please choose a photo.';
      } else {
        previewUrl = URL.createObjectURL(file);
        image.src = previewUrl;
        image.hidden = false;
        caption.textContent = `Selected photo: ${file.name}`;
      }

      wrapper.hidden = false;
    });

    window.addEventListener('pagehide', reset);
  });
});
