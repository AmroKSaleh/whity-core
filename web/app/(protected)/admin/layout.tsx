'use client';

import { AdminNavigationRegistrar } from './admin-navigation-registrar';

export default function AdminLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <>
      <AdminNavigationRegistrar />
      {children}
    </>
  );
}
