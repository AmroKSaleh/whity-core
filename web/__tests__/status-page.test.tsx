/** Smoke: the public status page renders component states from the API payload. */
import { render, screen, waitFor } from '@testing-library/react';
import StatusPage from '@/app/status/page';

const PAYLOAD = {
  status: 'degraded',
  components: [
    { key: 'api', name: 'API', status: 'operational', uptime: 99.9, checked_at: new Date().toISOString() },
    { key: 'web', name: 'Web application', status: 'down', uptime: 33.333, checked_at: new Date().toISOString() },
  ],
  incidents: [
    { component: 'web', status: 'down', started_at: new Date().toISOString(), ended_at: new Date().toISOString(), minutes: 12 },
  ],
  window_days: 90,
  generated_at: new Date().toISOString(),
};

describe('public status page', () => {
  afterEach(() => jest.restoreAllMocks());

  it('renders the overall banner, each component and incidents', async () => {
    global.fetch = jest.fn().mockResolvedValue({ ok: true, json: async () => PAYLOAD }) as unknown as typeof fetch;
    render(<StatusPage />);
    await waitFor(() => expect(screen.getByText('Some systems degraded')).toBeInTheDocument());
    expect(screen.getByText('Web application')).toBeInTheDocument();
    expect(screen.getByText('99.90%')).toBeInTheDocument();
    expect(screen.getByText(/down for 12 min/)).toBeInTheDocument();
  });

  it('says so plainly when the status API cannot be reached', async () => {
    global.fetch = jest.fn().mockRejectedValue(new Error('offline')) as unknown as typeof fetch;
    render(<StatusPage />);
    await waitFor(() =>
      expect(screen.getByText(/could not be reached/i)).toBeInTheDocument()
    );
    expect(screen.getByText('Status unavailable')).toBeInTheDocument();
  });
});
