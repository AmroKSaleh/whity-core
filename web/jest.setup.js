// jest.setup.js
import '@testing-library/jest-dom';
import { TextEncoder, TextDecoder } from 'node:util';

import { assertWorkspaceResolution } from './jest.workspace-guard';

// Before anything else: prove the workspace packages under test come from THIS
// checkout. Running from a worktree with no node_modules of its own resolves
// them to the main checkout instead, which silently unbinds every jest.mock()
// of a workspace package and presents the result as four broken product suites
// (#840). Fail here, with the cause named, rather than there.
assertWorkspaceResolution();

// jsdom ships no TextEncoder/TextDecoder; react-qr-code (rendered by the 2FA
// setup wizard) needs TextEncoder to encode the QR payload.
if (typeof global.TextEncoder === 'undefined') {
  global.TextEncoder = TextEncoder;
}
if (typeof global.TextDecoder === 'undefined') {
  global.TextDecoder = TextDecoder;
}

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

// Polyfill the Pointer Events API surface Radix's Select uses. jsdom implements
// no pointer capture at all, and Radix calls `hasPointerCapture` DIRECTLY (not
// optionally), so a test that opens a Select throws before it can assert
// anything. The user record page (#882) picks a role and an organisational unit
// through Select, and testing "which values does Save actually send" means
// operating those pickers rather than trusting them.
//
// `scrollIntoView` is the same class of gap: jsdom has no layout, and Radix
// scrolls the highlighted item into view when the listbox opens.
if (typeof Element !== 'undefined') {
  if (!Element.prototype.hasPointerCapture) {
    Element.prototype.hasPointerCapture = function hasPointerCapture() {
      return false;
    };
  }
  if (!Element.prototype.setPointerCapture) {
    Element.prototype.setPointerCapture = function setPointerCapture() {};
  }
  if (!Element.prototype.releasePointerCapture) {
    Element.prototype.releasePointerCapture = function releasePointerCapture() {};
  }
  if (!Element.prototype.scrollIntoView) {
    Element.prototype.scrollIntoView = function scrollIntoView() {};
  }
}
