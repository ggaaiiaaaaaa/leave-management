// Add the session token to same-origin write requests made by the dashboards.
(() => {
  const token = document.querySelector('meta[name="csrf-token"]')?.content;
  const originalFetch = window.fetch.bind(window);
  window.fetch = (input, init = {}) => {
    const method = (init.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
    if (token && method !== 'GET' && method !== 'HEAD') {
      const url = new URL(input instanceof Request ? input.url : input, location.href);
      if (url.origin === location.origin) {
        init = { ...init, headers: new Headers(init.headers || (input instanceof Request ? input.headers : undefined)) };
        init.headers.set('X-CSRF-Token', token);
      }
    }
    return originalFetch(input, init);
  };
})();
