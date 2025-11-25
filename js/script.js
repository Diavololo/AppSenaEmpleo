// Animaciones basicas de aparicion para elementos con la clase .reveal
// Evita que la landing se vea "vacia" cuando no se ha aplicado la clase
// agregando la clase 'in' al entrar en el viewport.
function setupReveal() {
  const elements = document.querySelectorAll('.reveal');
  if (!elements.length) { return; }

  if (!('IntersectionObserver' in window)) {
    elements.forEach(el => el.classList.add('in'));
    return;
  }

  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('in');
        observer.unobserve(entry.target);
      }
    });
  }, { root: null, threshold: 0.15 });

  elements.forEach(el => observer.observe(el));
}

// Validacion instantanea y resaltado de campos para formularios marcados con data-validate="instant"
function setupInstantValidation() {
  const forms = document.querySelectorAll('form[data-validate="instant"]');
  if (!forms.length) { return; }

  const getLabel = (field) => {
    if (field.id) {
      const fromFor = field.form?.querySelector(`label[for="${field.id}"]`);
      if (fromFor) { return fromFor.textContent?.replace('*', '').trim() || 'Campo'; }
    }
    const wrapLabel = field.closest('.field')?.querySelector('label');
    return (wrapLabel?.textContent || field.name || 'Campo').replace('*', '').trim();
  };

  const normalize = (field) => {
    const mode = field.dataset.normalize;
    if (mode === 'digits') {
      field.value = field.value.replace(/\D+/g, '');
    } else if (mode === 'nit') {
      const cleaned = field.value.replace(/[^0-9-]+/g, '');
      const pieces = cleaned.split('-');
      const main = (pieces.shift() || '').slice(0, 16).replace(/\D+/g, '');
      const suffix = pieces.join('').slice(0, 2).replace(/\D+/g, '');
      field.value = suffix ? `${main}-${suffix}` : main;
    }
  };

  const showError = (field, message) => {
    const wrapper = field.closest('.field') || field.parentElement;
    if (!wrapper) { return; }
    let msg = wrapper.querySelector('.field-error');
    if (!msg) {
      msg = document.createElement('small');
      msg.className = 'field-error';
      wrapper.appendChild(msg);
    }
    msg.textContent = message;
    wrapper.classList.add('has-error');
    field.classList.add('is-invalid');
    field.setAttribute('aria-invalid', 'true');
  };

  const clearError = (field) => {
    const wrapper = field.closest('.field') || field.parentElement;
    if (wrapper) {
      wrapper.classList.remove('has-error');
      const msg = wrapper.querySelector('.field-error');
      if (msg) { msg.remove(); }
    }
    field.classList.remove('is-invalid');
    field.removeAttribute('aria-invalid');
  };

  const validateField = (field, { showRequired = true } = {}) => {
    if (field.disabled) { return true; }
    if (field.type === 'checkbox') {
      if (field.required && !field.checked) {
        if (showRequired) { showError(field, 'Este campo es obligatorio'); }
        return false;
      }
      clearError(field);
      return true;
    }

    normalize(field);
    const value = (field.value || '').trim();
    const label = getLabel(field);
    clearError(field);

    if (field.type === 'file') {
      if (field.required && (!field.files || field.files.length === 0)) {
        if (showRequired) { showError(field, `${label}: adjunta un archivo.`); }
        return false;
      }
      return true;
    }

    if (field.required && value === '') {
      if (showRequired) { showError(field, `${label}: requerido.`); }
      return false;
    }

    if (field.dataset.match) {
      const other = field.form?.querySelector(field.dataset.match);
      if (other && value !== (other.value || '').trim()) {
        showError(field, field.dataset.matchMessage || `${label}: no coincide.`);
        return false;
      }
    }

    if (field.pattern) {
      try {
        const regex = new RegExp(`^${field.pattern}$`);
        if (value !== '' && !regex.test(value)) {
          showError(field, field.dataset.patternMessage || `${label}: formato invalido.`);
          return false;
        }
      } catch (e) {
        // Patrones invalidos no deben romper la experiencia
      }
    }

    if (field.type === 'email' && value !== '' && !field.checkValidity()) {
      showError(field, `${label}: correo invalido.`);
      return false;
    }

    if (field.type === 'number' && value !== '') {
      const num = Number(value);
      if (Number.isNaN(num)) {
        showError(field, `${label}: solo numeros.`);
        return false;
      }
      if (field.min !== '' && num < Number(field.min)) {
        showError(field, `${label}: debe ser mayor o igual a ${field.min}.`);
        return false;
      }
      if (field.max !== '' && num > Number(field.max)) {
        showError(field, `${label}: debe ser menor o igual a ${field.max}.`);
        return false;
      }
    }

    return true;
  };

  forms.forEach((form) => {
    const fields = Array.from(form.querySelectorAll('input, select, textarea'));
    const attachField = (field) => {
      const onChange = () => validateField(field, { showRequired: false });
      field.addEventListener('input', onChange);
      field.addEventListener('blur', () => validateField(field, { showRequired: true }));
      field.addEventListener('change', onChange);
      if (field.type === 'file') {
        field.addEventListener('change', () => validateField(field, { showRequired: true }));
      }
    };

    fields.forEach(attachField);
    form.__attachValidation = attachField;

    form.addEventListener('submit', (event) => {
      let firstInvalid = null;
      const currentFields = Array.from(form.querySelectorAll('input, select, textarea'));
      currentFields.forEach((field) => {
        const ok = validateField(field, { showRequired: true });
        if (!ok && !firstInvalid) {
          firstInvalid = field;
        }
      });
      if (firstInvalid) {
        event.preventDefault();
        firstInvalid.focus({ preventScroll: false });
        firstInvalid.scrollIntoView({ block: 'center', behavior: 'smooth' });
      }
    });
  });
}

function setupDynamicRepeater() {
  const buttons = document.querySelectorAll('[data-add-row]');
  if (!buttons.length) { return; }

  const ensureCounter = (target) => {
    if (!target.dataset.counter) {
      target.dataset.counter = target.querySelectorAll('[data-index]').length.toString();
    }
  };

  const reindexCollection = (target) => {
    const items = Array.from(target.querySelectorAll('[data-index]'));
    items.forEach((item, idx) => {
      item.dataset.index = idx.toString();
      const legend = item.querySelector('legend');
      if (legend && legend.dataset.numLabel) {
        legend.textContent = `${legend.dataset.numLabel} ${idx + 1}`;
      } else if (legend) {
        legend.textContent = legend.textContent.replace(/\d+$/, String(idx + 1));
      }
      item.querySelectorAll('[data-num-label]').forEach((el) => {
        const base = el.dataset.numLabel || '';
        el.textContent = `${base} ${idx + 1}`;
      });
      item.querySelectorAll('[id]').forEach((el) => {
        const newId = el.id.replace(/_\d+$/, `_${idx}`);
        if (newId !== el.id) {
          const labels = Array.from((target.closest('form') || document).querySelectorAll(`label[for="${el.id}"]`));
          labels.forEach((lbl) => lbl.setAttribute('for', newId));
          el.id = newId;
        }
      });
      item.querySelectorAll('label[for]').forEach((lbl) => {
        const newFor = lbl.getAttribute('for')?.replace(/_\d+$/, `_${idx}`);
        if (newFor) { lbl.setAttribute('for', newFor); }
      });
      // Oculta botones de eliminar en el primer elemento
      item.querySelectorAll('[data-remove-row]').forEach((btn) => {
        btn.style.display = idx === 0 ? 'none' : '';
      });
    });
  };

  buttons.forEach((btn) => {
    btn.addEventListener('click', () => {
      const tmplSel = btn.getAttribute('data-add-row');
      const targetSel = btn.getAttribute('data-target');
      const tmpl = tmplSel ? document.querySelector(tmplSel) : null;
      const target = targetSel ? document.querySelector(targetSel) : null;
      if (!tmpl || !target) { return; }

      ensureCounter(target);
      const nextIndex = Number(target.dataset.counter || '0');
      target.dataset.counter = (nextIndex + 1).toString();
      const html = tmpl.innerHTML.replace(/__INDEX__/g, String(nextIndex)).replace(/__NUM__/g, String(nextIndex + 1));
      const wrapper = document.createElement('div');
      wrapper.innerHTML = html.trim();
      const node = wrapper.firstElementChild;
      if (!node) { return; }
      target.appendChild(node);

      const form = btn.closest('form');
      if (form && form.__attachValidation) {
        node.querySelectorAll('input, select, textarea').forEach((el) => form.__attachValidation(el));
      }
      // Limpia posibles estados previos
      node.querySelectorAll('.field-error').forEach((el) => el.remove());
      node.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
      node.querySelectorAll('.has-error').forEach((el) => el.classList.remove('has-error'));
      reindexCollection(target);
      target.dataset.counter = target.querySelectorAll('[data-index]').length.toString();
    });
  });

  // Reindex inicial en contenedores existentes
  document.querySelectorAll('[data-collection]').forEach((container) => {
    ensureCounter(container);
    const currentCount = container.querySelectorAll('[data-index]').length;
    container.dataset.counter = currentCount.toString();
    const renumber = () => {
      reindexCollection(container);
      container.dataset.counter = container.querySelectorAll('[data-index]').length.toString();
    };
    container.__renumber = renumber;
    renumber();
  });

  // Expose reindexer
  window.__reindexCollection = (target) => {
    reindexCollection(target);
    target.dataset.counter = target.querySelectorAll('[data-index]').length.toString();
  };
}

function setupRemoveRow() {
  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-remove-row]');
    if (!btn) { return; }
    const selector = btn.getAttribute('data-remove-row');
    const item = selector ? btn.closest(selector) : null;
    if (!item) { return; }
    const container = item.closest('[data-collection]');
    const siblings = container ? container.querySelectorAll(selector) : [];
    if (siblings.length <= 1) { return; } // deja al menos uno
    item.remove();
    if (container && container.__renumber) {
      container.__renumber();
    } else if (container) {
      window.__reindexCollection?.(container);
    }
  });
}

// Inicializacion
window.addEventListener('DOMContentLoaded', () => {
  setupReveal();
  setupInstantValidation();
  setupDynamicRepeater();
  setupRemoveRow();
});

