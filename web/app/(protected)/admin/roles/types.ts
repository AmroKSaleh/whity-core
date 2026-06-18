export interface Permission {
  id: number;
  name: string;
  description: string;
}

export interface Role {
  id: number;
  name: string;
  description: string;
  createdAt: string;
  permissionCount?: number;
  /**
   * Whether the current tenant may update/delete this role (computed
   * server-side, mirroring the WC-110 write guard). A global NULL-tenant base
   * role is visible but NOT manageable by a regular tenant — only the SYSTEM
   * tenant may manage it. The roles admin gates Edit/Delete on this flag so a
   * non-system tenant never fires a PATCH/DELETE that would 404 (WC-222).
   */
  manageable: boolean;
}

export interface RoleWithPermissions extends Role {
  permissions: Permission[];
}
