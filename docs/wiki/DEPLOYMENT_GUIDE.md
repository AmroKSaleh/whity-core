# Deployment Guide

This guide describes the atomic deployment system and rollback procedures for Whity Core.

## Overview

Whity Core uses an atomic deployment system to ensure zero-downtime updates and safe rollbacks. The system is tenant-isolated, meaning deployments can be applied and rolled back for specific tenants without affecting others.

## Deployment Process

The deployment process follows these steps:

1. **Staging**: New code is uploaded/staged in a temporary directory on the server.
2. **Apply**: The `DeploymentManager` is triggered via the API.
   - A new version directory is created for the tenant in `storage/deployments/{tenant_id}/{version}`.
   - State is tracked as `pending` in the database.
   - Schema migrations (if any) are executed within a database transaction.
   - Files are moved atomically into the version directory.
   - State is updated to `applied`.
3. **Atomic Swap**: The application (FrankenPHP) loads the latest `applied` version for the tenant.

## Rollback Procedures

### Automatic Rollback
If a deployment fails during the `apply` phase (e.g., a migration fails), the system automatically:
- Rolls back the database transaction.
- Deletes any temporary files.
- Marks the deployment as `failed`.

### Manual Rollback
If a deployed version has issues, an administrator can trigger a rollback to the previous version:
- **API Endpoint**: `POST /api/deployments/rollback`
- The system identifies the previous `applied` version and restores it as the current active version.
- Database state is updated to reflect the rollback.

## API Reference

### Apply Deployment
`POST /api/deployments/apply`
Payload:
```json
{
  "version": "v1.1.0",
  "source_path": "/path/to/staged/code"
}
```

### Rollback Deployment
`POST /api/deployments/rollback`
(No payload required, uses tenant context)

### Deployment Status
`GET /api/deployments/status`
Returns the recent deployment history for the tenant.

### Migration Rollback
`POST /api/migrations/rollback`
Payload:
```json
{
  "migration_name": "006_create_deployment_tables"
}
```

## Safety Guarantees

- **Atomicity**: Deployments use database transactions and atomic filesystem operations.
- **Isolation**: Tenant A's deployment operations never affect Tenant B.
- **Data Integrity**: Migrations are rolled back automatically on failure.
