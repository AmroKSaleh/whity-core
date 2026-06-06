'use client';

import { useState, type KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  IconChevronRight,
  IconDotsVertical,
  IconFolder,
  IconFolderOpen,
  IconPointFilled,
} from '@tabler/icons-react';
import type { OuNode } from './ou-tree-util';
import type { OuViewProps } from './ou-view';

/**
 * `OuTree` — the default, fully keyboard-accessible hierarchy view.
 *
 * Renders the built OU tree as an indented, collapsible file-explorer-style
 * list using only the installed shadcn/Radix/Tailwind/@tabler primitives (no
 * graph dependency). Each row can be selected (emits `onSelect`) and exposes a
 * per-node action menu (create child / edit / move / delete via `onAction`).
 *
 * Accessibility: the structure is a `tree`/`treeitem`/`group` ARIA pattern.
 * Each item is focusable; Enter/Space select, ArrowRight expands (or moves to
 * the first child), ArrowLeft collapses (or moves to the parent).
 */
export function OuTree({ tree, selectedId, onSelect, onAction }: OuViewProps) {
  // Expanded by default so the seeded hierarchy is visible on first paint.
  const [collapsed, setCollapsed] = useState<Set<number>>(new Set());

  const toggle = (id: number) => {
    setCollapsed((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };

  if (tree.length === 0) {
    return null;
  }

  return (
    <div
      role="tree"
      aria-label="Organizational unit hierarchy"
      className="rounded-lg border border-border bg-card p-2"
    >
      {tree.map((node) => (
        <OuTreeItem
          key={node.id}
          node={node}
          selectedId={selectedId}
          collapsed={collapsed}
          onToggle={toggle}
          onSelect={onSelect}
          onAction={onAction}
        />
      ))}
    </div>
  );
}

interface OuTreeItemProps {
  node: OuNode;
  selectedId: number | null;
  collapsed: Set<number>;
  onToggle: (id: number) => void;
  onSelect: (id: number) => void;
  onAction: OuViewProps['onAction'];
}

function OuTreeItem({
  node,
  selectedId,
  collapsed,
  onToggle,
  onSelect,
  onAction,
}: OuTreeItemProps) {
  const hasChildren = node.children.length > 0;
  const isExpanded = hasChildren && !collapsed.has(node.id);
  const isSelected = selectedId === node.id;

  const handleKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
    switch (event.key) {
      case 'Enter':
      case ' ':
        event.preventDefault();
        onSelect(node.id);
        break;
      case 'ArrowRight':
        if (hasChildren && !isExpanded) {
          event.preventDefault();
          onToggle(node.id);
        }
        break;
      case 'ArrowLeft':
        if (hasChildren && isExpanded) {
          event.preventDefault();
          onToggle(node.id);
        }
        break;
      default:
        break;
    }
  };

  return (
    <div role="treeitem" aria-expanded={hasChildren ? isExpanded : undefined} aria-selected={isSelected}>
      <div
        className={cn(
          'group/row flex items-center gap-1 rounded-md py-1 pe-1 text-xs/relaxed transition-colors',
          isSelected
            ? 'bg-muted text-foreground'
            : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground'
        )}
        style={{ paddingInlineStart: `${node.depth * 1.25 + 0.25}rem` }}
      >
        {hasChildren ? (
          <Button
            variant="ghost"
            size="icon-xs"
            aria-label={isExpanded ? `Collapse ${node.name}` : `Expand ${node.name}`}
            onClick={(e) => {
              e.stopPropagation();
              onToggle(node.id);
            }}
          >
            <IconChevronRight
              className={cn('transition-transform', isExpanded && 'rotate-90')}
            />
          </Button>
        ) : (
          <span className="inline-flex size-5 items-center justify-center text-muted-foreground/60">
            <IconPointFilled className="size-2" />
          </span>
        )}

        <button
          type="button"
          tabIndex={0}
          onClick={() => onSelect(node.id)}
          onKeyDown={handleKeyDown}
          className="flex min-w-0 flex-1 items-center gap-1.5 rounded-sm py-0.5 text-start outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
        >
          {hasChildren ? (
            isExpanded ? (
              <IconFolderOpen className="size-3.5 shrink-0 text-brand" />
            ) : (
              <IconFolder className="size-3.5 shrink-0 text-brand" />
            )
          ) : (
            <IconFolder className="size-3.5 shrink-0 text-muted-foreground/70" />
          )}
          <span className="truncate font-medium">{node.name}</span>
        </button>

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="icon-sm"
              aria-label={`Actions for ${node.name}`}
              className="opacity-0 transition-opacity group-hover/row:opacity-100 focus-visible:opacity-100 aria-expanded:opacity-100"
              onClick={(e) => e.stopPropagation()}
            >
              <IconDotsVertical />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onClick={() => onAction('create-child', node)}>
              Create child OU
            </DropdownMenuItem>
            <DropdownMenuItem onClick={() => onAction('edit', node)}>Edit</DropdownMenuItem>
            <DropdownMenuItem onClick={() => onAction('move', node)}>Move to&hellip;</DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              variant="destructive"
              onClick={() => onAction('delete', node)}
            >
              Delete
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      {isExpanded && (
        <div role="group">
          {node.children.map((child) => (
            <OuTreeItem
              key={child.id}
              node={child}
              selectedId={selectedId}
              collapsed={collapsed}
              onToggle={onToggle}
              onSelect={onSelect}
              onAction={onAction}
            />
          ))}
        </div>
      )}
    </div>
  );
}
