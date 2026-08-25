import { useTranslation } from '@amroksaleh/features/i18n';
import type { DocumentCollection } from './types';

/**
 * WHY THERE IS MORE THAN ONE EMPTY STATE
 * --------------------------------------
 * #987 built a rail whose whole point is that "this folder has nothing in it"
 * and "this folder cannot be computed here" never look alike. That distinction
 * is made in the rail — absent folders are not rendered, unavailable ones are
 * disabled with their reason — and it is trivially undone one pane to the right,
 * by answering every zero-row page with the same sentence.
 *
 * Because a zero-row page has several causes and they are not interchangeable:
 *
 *   - The FOLDER matched nothing. Honest, and the one #756 is about: say so
 *     rather than showing placeholder rows.
 *   - The SEARCH matched nothing. The folder may be full. "No documents in this
 *     folder" is then simply false, and the reader's next move is to clear the
 *     search rather than to conclude the library is empty.
 *   - A COLLECTION holds documents the caller may no longer read. This is the
 *     one that cannot be guessed from the page alone, and it is a fact the API
 *     already hands over: `item_count` counts FILED rows, and reading through a
 *     collection re-applies visibility, so `item_count: 3` with zero rows is not
 *     a contradiction — it is the only place the difference between "you filed
 *     nothing" and "what you filed is no longer yours to read" is visible. Left
 *     unsaid, somebody watches their own collection empty itself and files a bug
 *     about data loss.
 *   - The PAGE is past the end, because something was deleted while the browser
 *     sat open. Rare, and it renders identically to an empty folder unless the
 *     total is consulted.
 *
 * Returned as data rather than JSX so the table and the grid render the SAME
 * sentence — the two layouts having drifted apart is the failure this file's
 * existence prevents. The searched-for text is deliberately NOT interpolated
 * into any of these strings: it is tenant data in an unknown script, and an
 * Arabic term spliced into an English sentence needs a bidi isolate a plain
 * string cannot carry. The toolbar shows the term once, in a `dir="auto"` chip,
 * next to the control that clears it.
 */
export interface LibraryEmptyStateInput {
  /** The selected view key, so the starred folder can speak for itself. */
  viewKey: string;
  /** The open collection, when the open folder is one. */
  collection: DocumentCollection | null;
  /** Whether the caller has a starred collection at all — it is created on first star. */
  starredCollectionExists: boolean;
  /** Whether a search term is currently APPLIED (not merely typed). */
  searchApplied: boolean;
  /** The server's total for these criteria. Zero rows with a positive total means a stale page. */
  total: number;
}

export interface LibraryEmptyState {
  title: string;
  description: string;
  /** True only for the stale-page case, which the caller answers with a control. */
  pastTheEnd?: boolean;
}

/**
 * The hook the screen uses — and the reason this file binds `useTranslation`
 * itself rather than only taking `t` as a parameter.
 *
 * FOUND WHILE BUILDING THIS, AND WORTH READING BEFORE WRITING ANOTHER HELPER
 * -------------------------------------------------------------------------
 * The first draft of this file imported `useTranslation` as a TYPE only and
 * exported nothing but the pure function below. It typechecked, it lint-passed,
 * `i18n:extract` ran clean, and the catalogue drift guard said OK — while every
 * one of these strings was missing from the catalogue and would have shipped as
 * raw English in Arabic. `TranslationKeyExtractor::scanSource()` returns early
 * when a file binds no domain (`$bindings['names'] === []`), so a file whose
 * `t()` calls it cannot attribute is skipped rather than reported. The catalogue
 * lost four keys that WERE there before and gained none of the new ones, and
 * nothing failed.
 *
 * Binding the domain here fixes it for this file: the extractor then has exactly
 * one domain and the pure function's `t()` calls inherit it, the same way
 * `viewLabel` inherits `view-rail.tsx`'s. The guard's blind spot is reported in
 * the PR rather than patched here — changing what it accepts affects all 338
 * scanned files and belongs in its own change.
 */
export function useLibraryEmptyState(input: LibraryEmptyStateInput): LibraryEmptyState {
  // `const t = …`, spelled out. Passing `useTranslation('documents')` straight
  // into the call below is identical at runtime and invisible to the extractor:
  // it binds a domain to a NAME, so an unnamed inline call leaves the file with
  // no bindings and every `t()` in it unattributed — which is how the first
  // draft of this file passed the drift guard while contributing nothing.
  const t = useTranslation('documents');

  return libraryEmptyState(t, input);
}

/**
 * The decision itself, as a pure function of `t` and the inputs — so it can be
 * asserted against directly, without rendering a screen to find out which
 * sentence a zero-row page produces.
 */
export function libraryEmptyState(
  t: ReturnType<typeof useTranslation>,
  input: LibraryEmptyStateInput
): LibraryEmptyState {
  const { viewKey, collection, starredCollectionExists, searchApplied, total } = input;

  // Checked first: every other message below asserts something about the FOLDER,
  // and none of them is true when the folder has rows the caller is simply not
  // looking at.
  if (total > 0) {
    return {
      title: t('organizer.empty.pastEndTitle', 'This page is past the end of the folder'),
      description: t(
        'organizer.empty.pastEndHelp',
        'The folder has documents, but fewer than it did when this page was opened. Go back to the first page.'
      ),
      pastTheEnd: true,
    };
  }

  if (searchApplied) {
    return {
      title: t('organizer.empty.searchTitle', 'Nothing in this folder matches your search'),
      description: t(
        'organizer.empty.searchHelp',
        'The search looks at document titles only. Clear it to see everything the folder holds.'
      ),
    };
  }

  // The one an empty page cannot express on its own. `item_count` is the number
  // of FILED rows; reading through a collection re-applies visibility, so a
  // positive count with no rows means the documents are still filed and no
  // longer readable — not that the collection emptied itself.
  if (collection !== null && (collection.item_count ?? 0) > 0) {
    return {
      title: t(
        'organizer.empty.filedButUnreadableTitle',
        'Everything filed here has stopped being visible to you'
      ),
      description: t(
        'organizer.empty.filedButUnreadableHelp',
        'A collection holds pointers, and what you may read is checked again on every visit. Nothing has been removed from your collection — you can no longer read it. Ask whoever owns the documents if that is unexpected.'
      ),
    };
  }

  if (collection !== null) {
    return {
      title: t('organizer.empty.collectionTitle', 'Nothing is filed here yet'),
      description: t(
        'organizer.empty.collectionHelp',
        "Use a document's menu to add it to this collection. Only you can see your collections."
      ),
    };
  }

  // The starred folder before anything has been starred. The collection is
  // created on first use, so its absence is the honest signal — distinct from a
  // starred collection that exists and has been emptied.
  if (viewKey === 'starred' && !starredCollectionExists) {
    return {
      title: t('organizer.empty.starredTitle', 'You have not starred anything yet'),
      description: t(
        'organizer.empty.starredHelp',
        'Use the star beside a document to keep it here. Only you can see it.'
      ),
    };
  }

  return {
    title: t('organizer.empty.title', 'No documents in this folder'),
    description: t(
      'organizer.empty.help',
      'This folder is a query over what documents record. Nothing matched it — no document is hidden from it.'
    ),
  };
}
