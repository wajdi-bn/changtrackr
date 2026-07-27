import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { AutoComplete, Empty, Input, Spin } from 'antd'
import type { AutoCompleteProps } from 'antd'
import {
  AlertTriangle,
  Building2,
  CreditCard,
  PlugZap,
  ReceiptText,
  Search,
  UserRound,
  Wrench,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import type { GlobalSearchResult, GlobalSearchResultType } from '../../types/globalSearch'
import type { UserRole } from '../../types/auth'
import { globalSearch } from './globalSearchApi'

const placeholders: Record<UserRole, string> = {
  super_admin: 'Search organizations, users, stations',
  admin: 'Search users, stations, alerts, sessions',
  operator: 'Search stations, alerts, sessions',
  technician: 'Search stations and assigned work',
  client: 'Search stations, sessions, payments',
}

export function GlobalSearch({ role }: { role: UserRole | null }) {
  const navigate = useNavigate()
  const [query, setQuery] = useState('')
  const [debouncedQuery, setDebouncedQuery] = useState('')
  const [open, setOpen] = useState(false)

  useEffect(() => {
    const timeout = window.setTimeout(() => setDebouncedQuery(query.trim()), 220)
    return () => window.clearTimeout(timeout)
  }, [query])

  const searchQuery = useQuery({
    queryKey: ['global-search', debouncedQuery],
    queryFn: () => globalSearch(debouncedQuery),
    enabled: debouncedQuery.length >= 2,
    staleTime: 30_000,
  })
  const options = useMemo<AutoCompleteProps['options']>(() => {
    const grouped = new Map<string, GlobalSearchResult[]>()
    for (const result of searchQuery.data?.data ?? []) {
      grouped.set(result.group, [...(grouped.get(result.group) ?? []), result])
    }

    return Array.from(grouped.entries()).map(([group, results]) => ({
      label: <span className="global-search-group"><strong>{group}</strong><small>{results.length}</small></span>,
      options: results.map((result) => ({
        key: `${result.type}:${result.id}`,
        value: result.url,
        label: <SearchResult result={result} />,
      })),
    }))
  }, [searchQuery.data?.data])

  const noResults = debouncedQuery.length >= 2 && !searchQuery.isFetching && options?.length === 0

  return <AutoComplete
    className="global-search"
    value={query}
    options={options}
    open={open && debouncedQuery.length >= 2}
    filterOption={false}
    onOpenChange={setOpen}
    onSearch={(value) => {
      setQuery(value)
      setOpen(true)
    }}
    onSelect={(url) => {
      setQuery('')
      setOpen(false)
      navigate(url)
    }}
    notFoundContent={searchQuery.isFetching
      ? <div className="global-search-state"><Spin size="small" /> Searching workspace</div>
      : noResults
        ? <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="No accessible result" />
        : null}
    classNames={{ popup: { root: 'global-search-dropdown' } }}
  >
    <Input
      allowClear
      aria-label="Search workspace"
      prefix={<Search size={16} />}
      placeholder={placeholders[role ?? 'operator']}
      onFocus={() => setOpen(true)}
    />
  </AutoComplete>
}

function SearchResult({ result }: { result: GlobalSearchResult }) {
  return <div className="global-search-result">
    <span className={`global-search-result-icon ${result.type}`}>{resultIcon(result.type)}</span>
    <span className="global-search-result-copy">
      <strong>{result.title}</strong>
      <small>{result.subtitle}</small>
    </span>
    {result.status && <span className={`global-search-status ${normalizeStatus(result.status)}`}>{result.status.replaceAll('-', ' ')}</span>}
  </div>
}

function resultIcon(type: GlobalSearchResultType) {
  const props = { size: 16 }
  if (type === 'organization') return <Building2 {...props} />
  if (type === 'user') return <UserRound {...props} />
  if (type === 'station') return <PlugZap {...props} />
  if (type === 'alert') return <AlertTriangle {...props} />
  if (type === 'intervention') return <Wrench {...props} />
  if (type === 'session') return <ReceiptText {...props} />
  return <CreditCard {...props} />
}

function normalizeStatus(status: string): string {
  return status.toLowerCase().replaceAll('_', '-')
}
