'use client';

import { useState } from 'react';
import { Button } from '@amroksaleh/ui/button';
import { IconChevronDown } from '@tabler/icons-react';
import type { Permission } from './types';

interface PermissionCheckboxProps {
  permissions: Permission[];
  selectedIds: number[];
  onChange: (selectedIds: number[]) => void;
}

export function PermissionCheckbox({
  permissions,
  selectedIds,
  onChange,
}: PermissionCheckboxProps) {
  const [isOpen, setIsOpen] = useState(false);

  const handleToggle = (id: number) => {
    if (selectedIds.includes(id)) {
      onChange(selectedIds.filter(sid => sid !== id));
    } else {
      onChange([...selectedIds, id]);
    }
  };

  const handleSelectAll = () => {
    if (selectedIds.length === permissions.length) {
      onChange([]);
    } else {
      onChange(permissions.map(p => p.id));
    }
  };

  const selectedCount = selectedIds.length;
  const totalCount = permissions.length;

  return (
    <div className="relative w-full">
      <Button
        type="button"
        variant="outline"
        onClick={() => setIsOpen(!isOpen)}
        className="w-full justify-between text-left font-normal"
      >
        <span className="truncate">
          {selectedCount === 0
            ? 'Select permissions...'
            : `${selectedCount} permission${selectedCount !== 1 ? 's' : ''} selected`}
        </span>
        <IconChevronDown
          size={16}
          className={`transition-transform ${isOpen ? 'rotate-180' : ''}`}
        />
      </Button>

      {isOpen && (
        <div className="absolute top-full left-0 right-0 z-50 mt-1 border border-border rounded-md bg-popover shadow-lg">
          <div className="max-h-64 overflow-y-auto">
            <div className="sticky top-0 border-b border-border bg-popover p-2">
              <button
                type="button"
                onClick={handleSelectAll}
                className="w-full text-left px-2 py-2 text-sm font-medium text-foreground hover:bg-muted rounded"
              >
                {selectedCount === totalCount ? 'Deselect All' : 'Select All'}
              </button>
            </div>

            <div className="p-2 space-y-2">
              {permissions.length === 0 ? (
                <div className="px-2 py-4 text-sm text-muted-foreground text-center">
                  No permissions available
                </div>
              ) : (
                permissions.map(permission => (
                  <label
                    key={permission.id}
                    className="flex items-start gap-2 p-2 rounded hover:bg-muted cursor-pointer"
                  >
                    <input
                      type="checkbox"
                      checked={selectedIds.includes(permission.id)}
                      onChange={() => handleToggle(permission.id)}
                      className="mt-1 w-4 h-4 rounded border-border text-primary focus:ring-2 focus:ring-ring"
                    />
                    <div className="flex-1 min-w-0">
                      <div className="text-sm font-medium text-foreground">
                        {permission.name}
                      </div>
                      <div className="text-xs text-muted-foreground">
                        {permission.description}
                      </div>
                    </div>
                  </label>
                ))
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
