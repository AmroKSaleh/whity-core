export interface OU {
  id: number;
  tenant_id: number;
  parent_id: number | null;
  name: string;
  slug: string;
  description?: string;
  created_at: string;
  children?: { id: number }[];
}
