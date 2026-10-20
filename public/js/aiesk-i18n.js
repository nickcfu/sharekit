/** Shared UI strings use the site's WordPress gettext catalogue. */
(function () {
  'use strict';
  const strings = window.aieskI18nData || {};
  window.aieskI18n = {
    t(key, values = {}) {
      const value = Object.prototype.hasOwnProperty.call(strings, key) ? String(strings[key]) : String(key);
      return value.replace(/\{(\w+)\}/g, (match, name) => Object.prototype.hasOwnProperty.call(values, name) ? String(values[name]) : match);
    }
  };
})();
