/**
 * Example usage and test data for DataTable component
 * This demonstrates how to use the reusable data table component
 */

import { DataTable, Column } from './data-table';
import { Button } from '@/components/ui/button';

// Example 1: User Management Table
interface User {
  id: string;
  name: string;
  email: string;
  role: string;
  status: string;
  createdAt: string;
}

const userColumns: Column<User>[] = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'email', label: 'Email', sortable: true },
  { key: 'role', label: 'Role', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'createdAt', label: 'Created', sortable: true },
];

const sampleUsers: User[] = [
  {
    id: '1',
    name: 'Alice Johnson',
    email: 'alice@example.com',
    role: 'Admin',
    status: 'Active',
    createdAt: '2024-01-15',
  },
  {
    id: '2',
    name: 'Bob Smith',
    email: 'bob@example.com',
    role: 'User',
    status: 'Active',
    createdAt: '2024-02-20',
  },
  {
    id: '3',
    name: 'Carol White',
    email: 'carol@example.com',
    role: 'Editor',
    status: 'Inactive',
    createdAt: '2024-01-10',
  },
];

function UserTableExample() {
  return (
    <DataTable<User>
      columns={userColumns}
      data={sampleUsers}
      rowActions={() => (
        <div className="flex gap-2">
          <Button variant="outline" size="sm">
            Edit
          </Button>
          <Button variant="destructive" size="sm">
            Delete
          </Button>
        </div>
      )}
    />
  );
}

// Example 2: Role Management Table
interface Role {
  id: string;
  name: string;
  description: string;
  permissionCount: number;
  userCount: number;
}

const roleColumns: Column<Role>[] = [
  { key: 'name', label: 'Role Name', sortable: true },
  { key: 'description', label: 'Description', sortable: true },
  { key: 'permissionCount', label: 'Permissions', sortable: true },
  { key: 'userCount', label: 'Users', sortable: true },
];

const sampleRoles: Role[] = [
  {
    id: '1',
    name: 'Admin',
    description: 'Full system access',
    permissionCount: 50,
    userCount: 2,
  },
  {
    id: '2',
    name: 'Editor',
    description: 'Can edit content',
    permissionCount: 15,
    userCount: 5,
  },
  {
    id: '3',
    name: 'Viewer',
    description: 'Read-only access',
    permissionCount: 3,
    userCount: 20,
  },
];

function RoleTableExample() {
  return (
    <DataTable<Role>
      columns={roleColumns}
      data={sampleRoles}
      rowActions={() => (
        <div className="flex gap-2">
          <Button variant="outline" size="sm">
            Edit
          </Button>
          <Button variant="destructive" size="sm">
            Delete
          </Button>
        </div>
      )}
    />
  );
}

// Example 3: Tenant Management Table
interface Tenant {
  id: string;
  name: string;
  domain: string;
  status: string;
  userCount: number;
  createdAt: string;
}

const tenantColumns: Column<Tenant>[] = [
  { key: 'name', label: 'Tenant Name', sortable: true },
  { key: 'domain', label: 'Domain', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'userCount', label: 'Users', sortable: true },
  { key: 'createdAt', label: 'Created', sortable: true },
];

const sampleTenants: Tenant[] = [
  {
    id: '1',
    name: 'Acme Corp',
    domain: 'acme.example.com',
    status: 'Active',
    userCount: 45,
    createdAt: '2023-06-15',
  },
  {
    id: '2',
    name: 'Tech Startup',
    domain: 'techstartup.example.com',
    status: 'Active',
    userCount: 12,
    createdAt: '2024-01-20',
  },
  {
    id: '3',
    name: 'Design Studio',
    domain: 'designstudio.example.com',
    status: 'Inactive',
    userCount: 8,
    createdAt: '2023-11-10',
  },
];

function TenantTableExample() {
  return (
    <DataTable<Tenant>
      columns={tenantColumns}
      data={sampleTenants}
      rowActions={() => (
        <div className="flex gap-2">
          <Button variant="outline" size="sm">
            Edit
          </Button>
          <Button variant="destructive" size="sm">
            Delete
          </Button>
        </div>
      )}
    />
  );
}

// Export all examples for reference
export { UserTableExample, RoleTableExample, TenantTableExample };
export { sampleUsers, sampleRoles, sampleTenants };
