import { redirect } from 'next/navigation';

export default function AdminDashboard() {
  redirect('/admin/stats');
}
