/* Form Guard — optional signals; no input content is collected. MIT License. */
(() => {
  'use strict';
  const states = new WeakMap();
  const initialize = form => {
    if (states.has(form) || !form.querySelector('[data-antispam-signals]')) return;
    const signals = () => {
      const js = form.elements.namedItem('as_js');
      const activity = form.elements.namedItem('as_interactions');
      const trap = form.elements.namedItem('as_trap');
      return js && activity && trap ? {js, activity, trap} : null;
    };
    if (!signals()) return;
    const state = {count: 0, last: -Infinity};
    const sync = () => {
      const controls = signals();
      if (!controls) return;
      controls.js.value = '1';
      controls.activity.value = String(state.count);
      controls.trap.value = '';
    };
    const interaction = (event, force = false) => {
      if (!event.isTrusted) return;
      const now = performance.now();
      if (force || now - state.last >= 300) {
        state.count = Math.min(99999, state.count + 1);
        state.last = now;
        sync();
      }
    };
    ['pointermove', 'pointerdown', 'keydown', 'click', 'touchstart', 'input', 'change'].forEach(type => {
      form.addEventListener(type, interaction, {passive: true});
    });
    // Capture : les champs sont prêts avant un gestionnaire AJAX ajouté par le site.
    form.addEventListener('submit', event => { interaction(event, true); sync(); }, true);
    states.set(form, {state, sync});
    sync();
  };
  const refresh = form => {
    initialize(form);
    const saved = states.get(form);
    if (!saved) return;
    saved.state.count = 0;
    saved.state.last = -Infinity;
    saved.sync();
  };
  window.FormGuard = Object.freeze({initialize, refresh});
  const init = () => document.querySelectorAll('form[data-antispam]').forEach(initialize);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
  else init();
})();
