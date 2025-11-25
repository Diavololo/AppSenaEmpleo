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

    fields.forEach((field) => {
      const onChange = () => validateField(field, { showRequired: false });
      field.addEventListener('input', onChange);
      field.addEventListener('blur', () => validateField(field, { showRequired: true }));
      field.addEventListener('change', onChange);
      if (field.type === 'file') {
        field.addEventListener('change', () => validateField(field, { showRequired: true }));
      }
    });

    form.addEventListener('submit', (event) => {
      let firstInvalid = null;
      fields.forEach((field) => {
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

// Inicializacion
window.addEventListener('DOMContentLoaded', () => {
  setupReveal();
  setupInstantValidation();
});

