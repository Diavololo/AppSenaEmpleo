// Animaciones básicas de aparición para elementos con la clase .reveal
// Evita que la landing se vea "vacía" cuando no se ha aplicado la clase
// agregando la clase 'in' al entrar en el viewport.

document.addEventListener('DOMContentLoaded', () => {
  const elements = document.querySelectorAll('.reveal');

  // Si el navegador no soporta IntersectionObserver, mostramos todo de inmediato
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
  }, {
    root: null,
    threshold: 0.15,
  });

  elements.forEach(el => observer.observe(el));
});