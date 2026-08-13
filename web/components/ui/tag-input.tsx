'use client';

/** The design system's TagInput with this app's translations. See ./dialog.tsx. */

import { useTranslation } from '@amroksaleh/features/i18n';
import { TagInput as BaseTagInput } from '@amroksaleh/ui/tag-input';

export type { TagInputProps, TagOption } from '@amroksaleh/ui/tag-input';

export function TagInput(props: React.ComponentProps<typeof BaseTagInput>) {
  const t = useTranslation('common');

  return (
    <BaseTagInput
      emptyLabel={t('ui.tagInput.empty', 'No tags selected')}
      // The tag's name is an argument, not a suffix, so a translation can place
      // it wherever its grammar needs.
      removeLabel={(label) => t('ui.tagInput.remove', 'Remove {tag}', { tag: label })}
      {...props}
    />
  );
}
