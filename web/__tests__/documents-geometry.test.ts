import { snapMove, type Box } from '@/lib/documents/geometry';

const PAGE = { widthMm: 100, heightMm: 100 };
const THRESHOLD = 1.5;

describe('snapMove', () => {
  it('snaps the left edge to the page left when close', () => {
    const moving: Box = { x: 0.8, y: 50, w: 20, h: 10 };
    const res = snapMove(moving, [], PAGE, THRESHOLD);
    expect(res.x).toBeCloseTo(0, 5);
    expect(res.vGuides).toContain(0);
  });

  it('snaps the element centre to the page centre', () => {
    // Page centre is x=50; a 20mm-wide box is centred when x=40.
    const moving: Box = { x: 39.2, y: 10, w: 20, h: 10 };
    const res = snapMove(moving, [], PAGE, THRESHOLD);
    expect(res.x).toBeCloseTo(40, 5);
    expect(res.vGuides).toContain(50);
  });

  it('snaps the right edge to the page right', () => {
    // Right edge at page width (100) means x = 100 - w = 80.
    const moving: Box = { x: 79.3, y: 10, w: 20, h: 10 };
    const res = snapMove(moving, [], PAGE, THRESHOLD);
    expect(res.x).toBeCloseTo(80, 5);
    expect(res.vGuides).toContain(100);
  });

  it('aligns a moving element to another element’s left edge', () => {
    const other: Box = { x: 30, y: 5, w: 15, h: 8 };
    const moving: Box = { x: 30.9, y: 60, w: 20, h: 10 };
    const res = snapMove(moving, [other], PAGE, THRESHOLD);
    expect(res.x).toBeCloseTo(30, 5);
    expect(res.vGuides).toContain(30);
  });

  it('snaps both axes independently', () => {
    const res = snapMove({ x: 0.5, y: 0.6, w: 10, h: 10 }, [], PAGE, THRESHOLD);
    expect(res.x).toBeCloseTo(0, 5);
    expect(res.y).toBeCloseTo(0, 5);
    expect(res.vGuides).toContain(0);
    expect(res.hGuides).toContain(0);
  });

  it('leaves position unchanged and reports no guides when nothing is within threshold', () => {
    const moving: Box = { x: 42, y: 42, w: 6, h: 6 };
    const res = snapMove(moving, [], PAGE, THRESHOLD);
    expect(res.x).toBe(42);
    expect(res.y).toBe(42);
    expect(res.vGuides).toEqual([]);
    expect(res.hGuides).toEqual([]);
  });

  it('chooses the nearest target when several are in range', () => {
    // Left edge (x=0.4 → target 0, delta -0.4) beats centre (target 50, far).
    const moving: Box = { x: 0.4, y: 10, w: 4, h: 4 };
    const res = snapMove(moving, [], PAGE, THRESHOLD);
    expect(res.x).toBeCloseTo(0, 5);
  });
});
