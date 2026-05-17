import { AdminSidebar } from '@/components/admin/admin-sidebar';

export default function AdminLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <div className="flex min-h-screen bg-slate-50 dark:bg-slate-950">
      {/* Sidebar */}
      <AdminSidebar />

      {/* Main Content */}
      <main className="ml-64 flex-1 flex flex-col">
        <div className="flex-1 overflow-auto">
          <div className="p-8">{children}</div>
        </div>
      </main>
    </div>
  );
}
