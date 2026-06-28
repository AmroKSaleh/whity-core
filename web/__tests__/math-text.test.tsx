import React from 'react';
import { render } from '@testing-library/react';
import { MathText } from '@whity/ui/math-text';

describe('MathText', () => {
  it('renders a valid inline expression without throwing', () => {
    const { container } = render(<MathText expression="x^2" />);
    // KaTeX produces a span with class "katex"
    expect(container.querySelector('.katex')).not.toBeNull();
  });

  it('renders a block expression with display-mode class', () => {
    const { container } = render(<MathText expression="x^2" block />);
    const wrapper = container.firstChild as HTMLElement;
    expect(wrapper.classList.contains('block')).toBe(true);
    // KaTeX display mode produces a katex-display container
    expect(container.querySelector('.katex-display')).not.toBeNull();
  });

  it('renders an error span for invalid LaTeX without throwing (throwOnError: false)', () => {
    // "\frac{" has an unclosed brace — KaTeX emits a .katex-error span instead of throwing
    const { container } = render(<MathText expression="\frac{" />);
    expect(container.querySelector('.katex-error')).not.toBeNull();
  });
});
