'use client';

/** The design system's Input with this app's translations. See ./dialog.tsx. */

import { useTranslation } from '@amroksaleh/features/i18n';
import { Input as BaseInput } from '@amroksaleh/ui/input';

export type { InputProps } from '@amroksaleh/ui/input';

export function Input(props: React.ComponentProps<typeof BaseInput>) {
  const t = useTranslation('common');

  return (
    <BaseInput
      showPasswordLabel={t('ui.input.showPassword', 'Show password')}
      hidePasswordLabel={t('ui.input.hidePassword', 'Hide password')}
      decrementLabel={t('ui.input.decrement', 'Decrease value')}
      incrementLabel={t('ui.input.increment', 'Increase value')}
      tooltipTriggerLabel={t('ui.tooltipTrigger', 'More information')}
      multipleFilesHint={t('ui.input.uploadMultiple', 'Upload multiple files')}
      singleFileHint={t('ui.input.uploadSingle', 'Upload single file')}
      {...props}
    />
  );
}
