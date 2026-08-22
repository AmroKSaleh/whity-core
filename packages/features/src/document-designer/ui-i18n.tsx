import { useTranslation } from '../i18n';
import { Input as BaseInput } from '@amroksaleh/ui/input';
import { DialogContent as BaseDialogContent } from '@amroksaleh/ui/dialog';

/**
 * The design-system Input and DialogContent with their copy supplied.
 *
 * `@amroksaleh/ui` takes its user-facing copy as props with English defaults,
 * because it is published standalone and must not depend on the i18n feature
 * (#758). Web solves that with app-level wrappers in `web/components/ui/`, and
 * the designer imported those two — the one web coupling that is neither
 * persistence nor toast. A package cannot reach into `web/`, so the same
 * wrapping is done here, over the same base components.
 *
 * Only these two need it. `Dialog`, `DialogHeader`, `DialogTitle` and
 * `DialogDescription` take no copy props and are re-exported untouched.
 *
 * `useTranslation` is safe to call with no `<LanguageProvider>` mounted: it
 * optional-chains its context and returns the fallback, which is exactly what
 * the desktop client relies on (see the i18n note in this slice's README
 * section of the plan). So this renders translated on web and English on
 * desktop, with no per-client wiring.
 */

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

export function DialogContent(props: React.ComponentProps<typeof BaseDialogContent>) {
  const t = useTranslation('common');
  return <BaseDialogContent closeLabel={t('ui.dialog.close', 'Close')} {...props} />;
}

export {
  Dialog,
  DialogTrigger,
  DialogPortal,
  DialogClose,
  DialogOverlay,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@amroksaleh/ui/dialog';
