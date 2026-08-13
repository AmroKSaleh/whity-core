'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useTranslation } from '@amroksaleh/features/i18n';

export default function Home() {
  const router = useRouter();
  const { isLoading, isAuthenticated } = useAuth();
  const t = useTranslation('common');

  useEffect(() => {
    if (!isLoading) {
      if (isAuthenticated()) {
        router.push('/dashboard');
      } else {
        router.push('/login');
      }
    }
  }, [isLoading, isAuthenticated, router]);

  return (
    <div className="flex items-center justify-center min-h-screen">
      <p>{t('home.redirecting', 'Redirecting...')}</p>
    </div>
  );
}
