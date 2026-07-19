// jest.setup.js
import '@testing-library/jest-dom';

// Polyfill ResizeObserver — jsdom has no layout engine so it never implements
// this, but Radix's popover-family primitives (Tooltip/Popover/.../Arrow) use
// it internally for size measurement via @radix-ui/react-use-size. A no-op
// stub is sufficient: tests assert on ARIA wiring and DOM presence, never on
// measured pixel sizes.
if (typeof global.ResizeObserver === 'undefined') {
  global.ResizeObserver = class ResizeObserver {
    observe() {}
    unobserve() {}
    disconnect() {}
  };
}

// Polyfill Response for node environment
if (typeof global.Response === 'undefined') {
  global.Response = class Response {
    constructor(body, init = {}) {
      this.body = body;
      this.status = init.status || 200;
      this.headers = new Map(Object.entries(init.headers || {}));
      this.ok = this.status >= 200 && this.status < 300;
    }

    async json() {
      return JSON.parse(this.body);
    }

    async text() {
      return this.body;
    }

    clone() {
      return new global.Response(this.body, {
        status: this.status,
        headers: Object.fromEntries(this.headers),
      });
    }
  };
}
