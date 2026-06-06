/**
 * Types for the audit-log admin page (WC-34).
 *
 * Mirror the public contract returned by `GET /api/audit-logs`
 * ({@see \Whity\Api\AuditLogApiHandler::toPublicEntry}).
 */

export interface AuditLogEntry {
  id: number;
  tenantId: number | null;
  actorUserId: number | null;
  action: string;
  targetType: string | null;
  targetId: number | null;
  metadata: Record<string, unknown>;
  ipAddress: string | null;
  createdAt: string | null;
}

export interface AuditLogPagination {
  page: number;
  perPage: number;
  total: number;
  totalPages: number;
}

export interface AuditLogResponse {
  data: AuditLogEntry[];
  pagination: AuditLogPagination;
}

/**
 * Active filter state for the audit-log listing. Empty strings mean "no filter".
 */
export interface AuditLogFilters {
  action: string;
  targetType: string;
  actor: string;
  from: string;
  to: string;
}
