document.addEventListener('DOMContentLoaded', () => {
  const adminLink = document.querySelector(
    '.sidebar a[href="admin.php?view=lost"]'
  );

  const studentLink = document.querySelector(
    '.sidebar a[href="my-reports.php"]'
  );

  const link = adminLink ?? studentLink;

  if (!link) return;

  const badge = document.createElement('span');

  badge.hidden = true;
  badge.setAttribute('aria-live', 'polite');

  badge.style.cssText = `
    margin-left:8px;
    padding:2px 7px;
    border-radius:20px;
    background:#721923;
    color:#fff;
    font-size:11px;
    font-weight:700;
  `;

  link.appendChild(badge);

  const update = async () => {
    try {
      const response = await fetch(
        'notification-count.php',
        {
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json'
          },
          cache: 'no-store'
        }
      );

      if (!response.ok) return;

      const result = await response.json();
      const number = Number(result.count);

      if (!Number.isInteger(number) || number < 0) {
        return;
      }

      badge.hidden = number === 0;
      badge.textContent = number > 0
        ? String(number)
        : '';

      badge.title = adminLink
        ? `${number} acknowledged notices awaiting onsite return`
        : `${number} notices awaiting acknowledgment`;

    } catch (error) {
      // The original navigation keeps working
      // even if the API is unavailable.
    }
  };

  update();

  window.setInterval(update, 45000);
});
