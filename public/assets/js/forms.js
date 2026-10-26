/* Формы: «глазик» у полей пароля и оформление телефона. Подключается во всех шаблонах. */
(function () {
  'use strict';
  var EYE = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

  document.querySelectorAll('input[type=password]').forEach(function (input) {
    if (input.closest('.pw-wrap')) return;
    var wrap = document.createElement('div');
    wrap.className = 'pw-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-eye';
    btn.setAttribute('aria-label', 'Показать пароль');
    btn.innerHTML = EYE;
    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.innerHTML = show ? EYE_OFF : EYE;
      btn.setAttribute('aria-label', show ? 'Скрыть пароль' : 'Показать пароль');
      input.focus();
    });
    wrap.appendChild(btn);
  });

  // Телефон: приводим к виду +7 900 123-45-67 при выходе из поля
  document.querySelectorAll('input[type=tel]').forEach(function (input) {
    input.addEventListener('blur', function () {
      var d = input.value.replace(/\D/g, '');
      if (d.length === 11 && d[0] === '8') d = '7' + d.slice(1);
      if (d.length === 10 && d[0] === '9') d = '7' + d;
      if (d.length === 11 && d[0] === '7') {
        input.value = '+7 ' + d.slice(1, 4) + ' ' + d.slice(4, 7) + '-' + d.slice(7, 9) + '-' + d.slice(9, 11);
      }
    });
  });

  // Выбор файла: показываем имя выбранного файла
  document.querySelectorAll('.file-pick input[type=file]').forEach(function (input) {
    input.addEventListener('change', function () {
      var label = input.parentNode, span = label.querySelector('span');
      var name = input.files && input.files[0] ? input.files[0].name : '';
      span.textContent = name || span.getAttribute('data-empty');
      label.classList.toggle('has-file', !!name);
    });
  });
})();
