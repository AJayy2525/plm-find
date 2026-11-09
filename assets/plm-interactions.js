document.addEventListener('DOMContentLoaded', () => {
  'use strict';

  const forms = [...document.querySelectorAll('form')];

  forms.forEach((form) => {

    // FEATURE 1: Validate existing required text fields.

    const textFields = [
      ...form.querySelectorAll(
        'input[required], textarea[required]'
      )
    ].filter((field) =>
      field.tagName === 'TEXTAREA' ||
      ['text', 'search', 'email', 'tel', 'url']
        .includes(field.type)
    );

    const validateField = (field) => {
      field.setCustomValidity('');

      if (
        field.value.length > 0 &&
        field.value.trim().length === 0
      ) {
        field.setCustomValidity(
          'Please enter a value, not just spaces.'
        );
      }
    };

    textFields.forEach((field) => {
      field.addEventListener(
        'input',
        () => validateField(field)
      );
    });

    // FEATURE 2: Validate selected image files.

    const photoInputs = [
      ...form.querySelectorAll('input[type="file"]')
    ].filter((input) =>
      /image/i.test(input.accept) ||
      /photo|image|picture/i.test(input.name)
    );

    const validatePhoto = (input) => {
      input.setCustomValidity('');

      const file = input.files?.[0];

      if (
        file &&
        file.type &&
        !file.type.startsWith('image/')
      ) {
        input.setCustomValidity(
          'Please select an image file.'
        );
      }
    };

    photoInputs.forEach((input) => {
      input.addEventListener('change', () => {
        validatePhoto(input);

        if (!input.checkValidity()) {
          input.reportValidity();
        }
      });
    });

    // FEATURE 3: Prevent accidental double submissions.

    form.addEventListener('submit', (event) => {

      textFields.forEach(validateField);
      photoInputs.forEach(validatePhoto);

      if (!form.checkValidity()) {
        event.preventDefault();
        form.reportValidity();
        return;
      }

      if (form.dataset.plmSubmitting === 'yes') {
        event.preventDefault();
        return;
      }

      form.dataset.plmSubmitting = 'yes';

      // Safety fallback if the page stays open.
      window.setTimeout(() => {
        delete form.dataset.plmSubmitting;
      }, 12000);
    });
  });

  // FEATURE 4: Fetch notification counts.
  // Updates only the browser tab title,
  // never the website layout.

  const originalTitle = document.title.replace(
    /^\(\d+\)\s*/,
    ''
  );

  const checkNotifications = async () => {
    try {

      const response = await fetch(
        'notification-count.php',
        {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {
            Accept: 'application/json'
          }
        }
      );

      if (!response.ok) return;

      const data = await response.json();
      const count = Number(data.count);

      if (
        !Number.isSafeInteger(count) ||
        count < 0
      ) {
        return;
      }

      document.title = count > 0
        ? `(${count}) ${originalTitle}`
        : originalTitle;

    } catch {
      // Existing website actions still work
      // even if the API is unavailable.
    }
  };

  // First check when the page opens.
  checkNotifications();

  // Check periodically while the tab is active.
  window.setInterval(() => {
    if (!document.hidden) {
      checkNotifications();
    }
  }, 45000);

  // Check again when the student/admin returns.
  document.addEventListener(
    'visibilitychange',
    () => {
      if (!document.hidden) {
        checkNotifications();
      }
    }
  );
});
