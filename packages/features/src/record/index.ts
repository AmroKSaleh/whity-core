/**
 * The record-page shell (#882) — the Whity standard for editing one record.
 *
 * Consumed today by `RoleRecordScreen` and `UserRecordScreen`; a third screen
 * writes the same five things and nothing else:
 *
 * ```tsx
 * // 1. The record's FIELDS — what the server says it IS. A permission flag
 * //    here is a compile error (see RecordFields / #895).
 * interface TenantRecordFields { name: string; plan: string; suspended: boolean }
 *
 * // 2. The facts, at MODULE scope — a pure projection of the record and the
 * //    dictionary, with no `can` in reach.
 * const tenantFacts: RecordFactsFn<TenantRecordFields> = (tenant, t) => ({
 *   title: tenant.name,
 *   badges: tenant.suspended
 *     ? [{ key: 'suspended', label: t('tenants.record.suspended', 'Suspended'), tone: 'danger' }]
 *     : [],
 *   stats: [{ key: 'plan', label: t('tenants.record.stat.plan', 'Plan'), value: tenant.plan }],
 * });
 *
 * export function TenantRecordScreen({ adapter, tenantId, can, t, onBack }: Props) {
 *   // 3. The record and its collections, one hook each.
 *   const record = useRecordResource(() => adapter.getTenant(tenantId), [tenantId], t('…'));
 *   const users  = useRecordResource(() => adapter.listTenantUsers(tenantId), [tenantId], t('…'));
 *
 *   if (record.status === 'loading') return <RecordPageSkeleton back={back} />;
 *   if (record.status !== 'ready')   return <RecordPageError back={back} … />;
 *
 *   // 4. The gates, in the order they should be explained.
 *   const access = resolveAccess([
 *     { allowed: can(TENANTS_WRITE), reason: t('…') },
 *     { allowed: record.value.manageable, reason: t('…') },
 *   ]);
 *
 *   // 5. The shell.
 *   return (
 *     <RecordPageShell
 *       testId="tenant-record"
 *       fields={toTenantFields(record.value)}
 *       facts={tenantFacts}
 *       t={t}
 *       access={access}
 *       back={back}
 *       actions={<SaveBar … />}
 *       main={{ editor: <TenantForm … />, readOnly: <TenantSummary … /> }}
 *       side={
 *         <RecordCollectionPanel title={t('…')} testId="tenant-record-users"
 *                                resource={users} emptyLabel={t('…')}>
 *           {(items) => <RecordList>{items.map(…)}</RecordList>}
 *         </RecordCollectionPanel>
 *       }
 *     />
 *   );
 * }
 * ```
 */

export { RecordPageShell, RecordPageSkeleton, RecordPageError } from './record-page-shell';
export {
  RecordCollectionPanel,
  RecordList,
  RecordListItem,
  RecordTimeline,
  RecordTimelineItem,
} from './record-panel';
export { resolveAccess } from './access';
export { formatRecordDate, formatRecordDateTime } from './format';
export { useRecordResource } from './use-record-resource';
export type {
  CallerFlagInRecordFields,
  RecordAccess,
  RecordBack,
  RecordBadge,
  RecordFact,
  RecordFactValue,
  RecordFactsFn,
  RecordFields,
  RecordGate,
  RecordMain,
  RecordPageShellProps,
  RecordProjection,
  RecordResource,
  RecordStatement,
  RecordTone,
  RecordTranslate,
} from './types';
export type { Transport, TransportResponse } from './transport';
