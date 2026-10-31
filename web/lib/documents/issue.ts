/**
 * Where the editor opens a template or a letter to issue a document from it
 * (editor M4c): `?issue=1`, with the record to issue for when it is known.
 */
export function issueHref(id: string | number, record?: string | null): string {
  return `/admin/document-sources/${id}?issue=1${record ? `&record=${encodeURIComponent(record)}` : ''}`;
}
