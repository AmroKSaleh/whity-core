# Admin Panel Documentation

**Status:** ✅ MVP Complete - Merged to Main  
**Last Updated:** May 17, 2026  
**Branch:** Merged from `feature/admin-panel`

## Overview

The Admin Panel is a comprehensive management interface for system administrators to oversee and manage all system resources. It provides intuitive dashboards, list views, and CRUD operations for users, roles, tenants, and system metrics.

## Features

### 1. Dashboard (Stats)
- **Route:** `/admin/stats`
- **Purpose:** At-a-glance system overview
- **Displays:**
  - Total Users count
  - Total Tenants count
  - Total Roles count
  - Active Sessions count
- **Data Source:** `/api/admin/stats` endpoint

### 2. Users Management
- **Route:** `/admin/users`
- **Capabilities:**
  - **List:** View all system users with columns: Name, Email, Role, Tenant, Created At
  - **Create:** Add new users with name, email, password, role, and tenant assignment
  - **Edit:** Modify user details (name, role, tenant) - email is read-only
  - **Delete:** Remove users from system with confirmation
- **Features:**
  - Sortable table columns (click header to sort)
  - Form validation (email format, password minimum 8 characters)
  - Toast notifications for success/error
  - API endpoints: GET/POST/PATCH/DELETE `/api/users`

### 3. Roles Management
- **Route:** `/admin/roles`
- **Capabilities:**
  - **List:** View all roles with columns: Name, Description, Permission Count
  - **Create:** Define new roles with description and assign permissions
  - **Edit:** Modify role details and permission assignments
  - **Delete:** Remove roles (with validation if assigned to users)
  - **View Permissions:** See all permissions assigned to a role
- **Features:**
  - Multi-select permission picker with search
  - Checkbox-based permission assignment
  - Permission descriptions visible in selector
  - Success/error notifications
  - API endpoints: GET/POST/PATCH/DELETE `/api/roles`, GET `/api/permissions`, GET `/api/roles/{id}/permissions`

### 4. Tenants Management
- **Route:** `/admin/tenants`
- **Capabilities:**
  - **List:** View all tenants with columns: Name, Slug, User Count, Created At
  - **Create:** Add new tenants with auto-generated URL slugs
  - **Edit:** Modify tenant name and slug
  - **Delete:** Remove tenants (with warning if has users)
- **Features:**
  - Automatic slug generation from name (e.g., "My Company" → "my-company")
  - Editable slug field for custom values
  - Slug validation (lowercase, hyphens, alphanumeric)
  - User count displayed in list
  - API endpoints: GET/POST/PATCH/DELETE `/api/tenants`

## Architecture

### Component Structure

```
app/(protected)/admin/
├── layout.tsx                 # Admin layout with sidebar
├── page.tsx                   # Redirect to /admin/stats
├── stats/
│   └── page.tsx              # Dashboard with stat cards
├── users/
│   ├── page.tsx              # Users list
│   ├── create-modal.tsx       # Create user form
│   ├── edit-modal.tsx         # Edit user form
│   └── delete-modal.tsx       # Delete confirmation
├── roles/
│   ├── page.tsx              # Roles list
│   ├── create-modal.tsx       # Create role form
│   ├── edit-modal.tsx         # Edit role form
│   ├── permissions-panel.tsx  # View permissions
│   ├── delete-modal.tsx       # Delete confirmation
│   ├── permission-checkbox.tsx # Permission multi-select
│   └── types.ts              # Role/Permission types
└── tenants/
    ├── page.tsx              # Tenants list
    ├── create-modal.tsx       # Create tenant form
    ├── edit-modal.tsx         # Edit tenant form
    └── delete-modal.tsx       # Delete confirmation

components/admin/
├── admin-sidebar.tsx         # Navigation sidebar
├── admin-header.tsx          # Page header with title/action
└── data-table.tsx            # Reusable sortable table
```

### Authentication & Authorization

- All admin routes are protected via `(protected)` layout
- Requires valid JWT token in localStorage (`whity_auth_token`)
- Auth context checks token validity and expiry
- Automatic redirect to login for unauthenticated users
- User role stored in JWT payload for future role-based access control

### Data Flow

1. **Page Load** → Fetch data from API (GET)
2. **User Action** → Form submission (POST/PATCH/DELETE)
3. **API Call** → Authenticated request via Auth Context's `apiClient`
4. **Response Handling**:
   - Success: Show toast, close modal, refetch list
   - Error: Show error toast with message, keep modal open
5. **State Update** → DataTable refreshes with new data

### State Management

- **Local Component State:** React `useState` for forms, modals, loading states
- **Auth State:** Auth Context provides `token`, `user`, `isLoading`, `isAuthenticated()`
- **No Global Store:** Simple approach suitable for MVP
- **API Client:** Auth Context's `apiClient` method handles Bearer token injection

## API Integration

### Endpoints Used

**Stats:**
- `GET /api/admin/stats` - Fetch system metrics

**Users:**
- `GET /api/users` - List all users
- `POST /api/users` - Create new user
- `PATCH /api/users/{id}` - Update user
- `DELETE /api/users/{id}` - Delete user

**Roles:**
- `GET /api/roles` - List all roles
- `POST /api/roles` - Create new role
- `PATCH /api/roles/{id}` - Update role with permissions
- `DELETE /api/roles/{id}` - Delete role
- `GET /api/permissions` - List available permissions
- `GET /api/roles/{id}/permissions` - Get role's permissions

**Tenants:**
- `GET /api/tenants` - List all tenants
- `POST /api/tenants` - Create new tenant
- `PATCH /api/tenants/{id}` - Update tenant
- `DELETE /api/tenants/{id}` - Delete tenant

### Request/Response Format

**Request:**
- Method: HTTP verb (GET, POST, PATCH, DELETE)
- Headers: `Authorization: Bearer {token}`, `Content-Type: application/json`
- Body: JSON payload for POST/PATCH (name, email, etc.)

**Response:**
- Success (200): Returns created/updated entity or list
- Error (4xx/5xx): Error message in response body
- Toast notifications display API messages to user

## Styling & UI

- **Framework:** Tailwind CSS
- **Components:** shadcn/ui (Dialog, Button, Input, Label, Select, DropdownMenu, etc.)
- **Icons:** Tabler Icons (matching sidebar navigation)
- **Color Scheme:** Uses design system slate colors (slate-900 for sidebar, slate-50 for backgrounds)
- **Responsive:** Mobile-friendly layouts with proper spacing
- **Dark Mode:** Partial support (background colors prepared)

## User Guide

### Accessing Admin Panel

1. Navigate to `http://localhost:3000/login`
2. Login with admin credentials
3. Navigate to `http://localhost:3000/admin` or click Dashboard in sidebar
4. Use navigation links to access different sections

### Creating a User

1. Go to `/admin/users`
2. Click "Create User" button
3. Fill in form:
   - Name (required)
   - Email (required, must be valid format)
   - Password (required, minimum 8 characters)
   - Role (required, dropdown)
   - Tenant (required, enter tenant ID)
4. Click "Create User"
5. User appears in table

### Managing Roles

1. Go to `/admin/roles`
2. Click "Create Role" to add new role
3. Select permissions by clicking checkboxes
4. Click "View Permissions" to see assigned permissions
5. Click "Edit" to modify name, description, or permissions
6. Click "Delete" to remove (warning if users assigned)

### Creating a Tenant

1. Go to `/admin/tenants`
2. Click "Create Tenant" button
3. Enter tenant name
4. Slug auto-generates (e.g., "Acme Corp" → "acme-corp")
5. Edit slug if needed for custom value
6. Click "Create Tenant"

## Known Issues & Limitations

### Current Limitations
- No bulk operations (select multiple items for batch delete)
- No advanced search or filtering
- No export to CSV
- No audit logging of admin actions
- Permission UI simplified (no hierarchical permission groups)
- No rate limiting on API calls

### To Be Implemented (Phase 2+)
- Admin audit log
- Advanced filtering and search
- Bulk operations
- CSV export
- API rate limiting
- Permission hierarchy
- Role templates
- User invitation workflow

## Testing

### Manual Testing Checklist
- [ ] Login and access admin panel
- [ ] Navigate sidebar links (all highlight properly)
- [ ] View stats on dashboard
- [ ] Create user (test validation)
- [ ] Edit user (verify email disabled)
- [ ] Delete user (test confirmation)
- [ ] Create role (select permissions)
- [ ] View role permissions
- [ ] Edit role (change permissions)
- [ ] Delete role (test in-use warning)
- [ ] Create tenant (test slug auto-generation)
- [ ] Edit tenant slug
- [ ] Delete tenant

### Automated Testing
- Unit tests for components (forms, validation)
- Integration tests for API calls
- E2E tests for complete workflows (Playwright)
- Tests in `/web/__tests__/admin/`

## Development

### Building & Running

```bash
# Development server
cd web && npm run dev

# Build for production
npm run build

# Run tests
npm run test

# Type checking
npm run type-check
```

### Adding New Admin Features

1. Create feature branch from main
2. Add components in `app/(protected)/admin/[feature]/`
3. Use DataTable for lists, modals for forms
4. Integrate with Auth Context's apiClient
5. Add toast notifications for feedback
6. Test thoroughly before merging
7. Update this documentation

### Code Patterns

**DataTable Usage:**
```tsx
<DataTable
  columns={columns}
  data={data}
  isLoading={loading}
  rowActions={(item) => <DropdownMenu>...</DropdownMenu>}
/>
```

**API Calls:**
```tsx
const response = await apiClient?.get('/api/endpoint');
if (response?.ok) {
  const data = await response.json();
  // Handle success
}
```

**Toast Notifications:**
```tsx
const { toast } = useToast();
toast({
  title: 'Success',
  description: 'Item created successfully',
});
```

## Performance Considerations

- List pages fetch data on mount only (no real-time updates)
- DataTable sorts client-side (suitable for small datasets)
- Modal forms don't refetch until submission
- Sidebar is static (no reactive updates)
- Loading states prevent duplicate submissions

### Future Optimizations
- Server-side pagination for large datasets
- API caching with React Query
- Real-time updates with WebSocket
- Lazy loading for heavy components
- Image optimization for avatars

## Security

- JWT tokens validated on both client and server
- API calls require Bearer token authentication
- Protected routes prevent unauthorized access
- Form validation prevents malicious input
- CORS configured for frontend-to-API communication

### Best Practices
- Never expose sensitive data in logs
- Validate all user input before API submission
- Use HTTPS in production
- Implement rate limiting on backend
- Regular security audits recommended

## Troubleshooting

**Issue: Admin pages redirect to login**
- Solution: Ensure you're logged in and token is in localStorage
- Check: Open DevTools → Application → localStorage → look for `whity_auth_token`

**Issue: Sidebar not showing**
- Solution: Check browser console for errors
- Verify: Admin layout is properly rendered

**Issue: API calls failing**
- Solution: Verify backend is running on port 8000
- Check: Network tab in DevTools for error responses
- Verify: Bearer token is being sent in Authorization header

**Issue: Forms not submitting**
- Solution: Check form validation errors
- Verify: All required fields are filled
- Check: Network requests in DevTools

## References

- [Next.js Documentation](https://nextjs.org/docs)
- [shadcn/ui Components](https://ui.shadcn.com/)
- [Tailwind CSS](https://tailwindcss.com/docs)
- [JWT Authentication](https://jwt.io/introduction)
