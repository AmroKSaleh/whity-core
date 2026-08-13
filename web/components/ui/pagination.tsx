'use client';

/** The design system's Pagination with this app's translations. See ./dialog.tsx. */

import { useTranslation } from '@amroksaleh/features/i18n';
import { Pagination as BasePagination } from '@amroksaleh/ui/pagination';

export function Pagination(props: React.ComponentProps<typeof BasePagination>) {
  const t = useTranslation('common');

  return (
    <BasePagination
      entriesLabel={(total) =>
        total === 1
          ? t('ui.pagination.entry', '1 entry')
          : t('ui.pagination.entries', '{count} entries', { count: total })
      }
      pageLabel={(page, totalPages) =>
        t('ui.pagination.page', 'page {page} of {total}', { page, total: totalPages })
      }
      navLabel={t('ui.pagination.nav', 'Pagination')}
      previousLabel={t('ui.pagination.previous', 'Previous page')}
      nextLabel={t('ui.pagination.next', 'Next page')}
      {...props}
    />
  );
}
