document.addEventListener('DOMContentLoaded', () => {
  const list = document.getElementById('reportList');
  const search = document.getElementById('reportSearch');
  const category = document.getElementById('reportCategory');
  const status = document.getElementById('reportStatus');
  const count = document.getElementById('reportCount');
  const empty = document.getElementById('reportNoResults');
  const clear = document.getElementById('clearReportFilters');

  if (!list || !search || !category ||
      !status || !count || !empty || !clear) return;

  const cards = [...list.querySelectorAll('article.simple-card')];

  const clean = (text) =>
    (text ?? '').replace(/\s+/g, ' ').trim();

  const data = cards.map((card) => {
    const tag = clean(
      card.querySelector('.eyebrow')?.textContent
    );

    const reportCategory = clean(tag.split('·').pop());

    const reportStatus = clean(
      card.querySelector('.simple-status')?.textContent
    ) || 'Open';

    const details = [
      ...card.querySelectorAll('p')
    ].slice(0, 2).map((p) => clean(p.textContent));

    const searchable = [
      tag,
      clean(card.querySelector('h3')?.textContent),
      ...details
    ].join(' ').toLocaleLowerCase();

    return {
      card,
      reportCategory,
      reportStatus,
      searchable
    };
  });

  const addOptions = (select, values) => {
    [...new Set(values.filter(Boolean))]
      .sort((a, b) => a.localeCompare(b))
      .forEach((value) =>
        select.add(new Option(value, value))
      );
  };

  addOptions(
    category,
    data.map((item) => item.reportCategory)
  );

  addOptions(
    status,
    data.map((item) => item.reportStatus)
  );

  const apply = () => {
    const query = clean(search.value).toLocaleLowerCase();
    let visible = 0;

    data.forEach(({
      card,
      reportCategory,
      reportStatus,
      searchable
    }) => {
      const show =
        searchable.includes(query) &&
        (!category.value ||
          category.value === reportCategory) &&
        (!status.value ||
          status.value === reportStatus);

      card.hidden = !show;

      if (show) visible += 1;
    });

    count.textContent =
      `${visible} of ${data.length} reports shown`;

    empty.hidden = !(
      visible === 0 && data.length > 0
    );
  };

  search.addEventListener('input', apply);
  category.addEventListener('change', apply);
  status.addEventListener('change', apply);

  clear.addEventListener('click', () => {
    search.value = '';
    category.value = '';
    status.value = '';

    apply();
    search.focus();
  });

  apply();
});
