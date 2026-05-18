// jest.setup.js
import '@testing-library/jest-dom';

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
