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
}

export interface RoleWithPermissions extends Role {
  permissions: Permission[];
}
