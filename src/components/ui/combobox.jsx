import React from 'react';
import { Check, ChevronsUpDown } from 'lucide-react';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';

/**
 * Always-enabled Combobox.
 * - Ignores any disabled prop (kept for API compatibility).
 * - No shadcn <CommandItem> — options are plain clickable rows.
 * - String-normalizes values to avoid strict-equality mismatches.
 */
export function Combobox({
  options = [],
  value,
  onChange,
  placeholder = 'Select...',
  isMulti = false,
  // accepted but ignored (always interactive)
  disabled: _ignoredDisabledProp,
}) {
  const [open, setOpen] = React.useState(false);
  const [query, setQuery] = React.useState('');

  const toStr = (v) => (v == null ? '' : String(v));

  // Normalize selected values
  const normValueSingle = toStr(value);
  const normValueMulti = Array.isArray(value) ? value.map(toStr) : [];

  const isSelected = (optVal) => {
    const v = toStr(optVal);
    return isMulti ? normValueMulti.includes(v) : normValueSingle === v;
  };

  const handleSelect = (raw) => {
    const currentValue = toStr(raw);
    if (isMulti) {
      const set = new Set(normValueMulti);
      if (set.has(currentValue)) set.delete(currentValue);
      else set.add(currentValue);
      onChange?.(Array.from(set));
    } else {
      const next = normValueSingle === currentValue ? '' : currentValue;
      onChange?.(next);
      setOpen(false);
    }
  };

  const displayValue = () => {
    if (isMulti) {
      if (!normValueMulti.length) return placeholder;
      if (normValueMulti.length > 2) return `${normValueMulti.length} selected`;
      return options
        .filter((o) => normValueMulti.includes(toStr(o.value)))
        .map((o) => o.label ?? toStr(o.value))
        .join(', ');
    }
    const sel = options.find((o) => toStr(o.value) === normValueSingle);
    return sel ? sel.label : placeholder;
  };

  const filtered = options.filter((o) => {
    const q = query.trim().toLowerCase();
    if (!q) return true;
    const lab = String(o.label ?? o.value).toLowerCase();
    const val = toStr(o.value).toLowerCase();
    return lab.includes(q) || val.includes(q);
  });

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          className="w-full justify-between"
        >
          <span className="truncate">{displayValue()}</span>
          <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>

      <PopoverContent
        className="w-[var(--radix-popover-trigger-width)] z-[99999] p-0"
        onOpenAutoFocus={(e) => e.preventDefault()}
        sideOffset={4}
        align="start"
      >
        {/* Search input */}
        <div className="p-2 border-b">
          <input
            type="text"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search..."
            className="w-full outline-none text-sm px-2 py-1"
          />
        </div>

        {/* List */}
        <div className="max-h-60 overflow-auto py-1">
          {filtered.length === 0 ? (
            <div className="px-3 py-2 text-sm text-gray-500">No results found.</div>
          ) : (
            filtered.map((option) => {
              const optValStr = toStr(option.value);
              const selected = isSelected(optValStr);
              return (
                <button
                  key={optValStr}
                  type="button"
                  className={cn(
                    'w-full text-left px-3 py-2 text-sm flex items-center',
                    'hover:bg-gray-100 focus:bg-gray-100 focus:outline-none',
                  )}
                  onClick={() => handleSelect(optValStr)}
                >
                  <svg
                    className={cn('mr-2 h-4 w-4', selected ? 'opacity-100' : 'opacity-0')}
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="3"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    aria-hidden="true"
                  >
                    <polyline points="20 6 9 17 4 12" />
                  </svg>
                  {option.label ?? optValStr}
                </button>
              );
            })
          )}
        </div>
      </PopoverContent>
    </Popover>
  );
}