/**
 * Makes the @testing-library/jest-dom matcher types (toBeInTheDocument,
 * toHaveTextContent, ...) visible to `tsc --noEmit`. The matchers themselves
 * are registered at runtime by jest.setup.js, which tsc never reads.
 */
import '@testing-library/jest-dom';
