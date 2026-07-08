import {
  AlertTriangle,
  BarChart3,
  Building2,
  ClipboardList,
  CreditCard,
  Gauge,
  LayoutDashboard,
  ListChecks,
  Map,
  PlugZap,
  ReceiptText,
  Search,
  Settings,
  ShieldCheck,
  Tags,
  Users,
  Wrench,
} from 'lucide-react'
import type { ReactNode } from 'react'
import type { UserRole } from '../../types/auth'

export interface RoleNavItem {
  icon: ReactNode
  path: string
  label: string
}

export interface RoleConfig {
  label: string
  shortLabel: string
  defaultPath: string
  navItems: RoleNavItem[]
}

const iconSize = 18

export const roleConfigs: Record<UserRole, RoleConfig> = {
  super_admin: {
    label: 'Super Administrator',
    shortLabel: 'Super Admin',
    defaultPath: '/admin-home',
    navItems: [
      { icon: <LayoutDashboard size={iconSize} />, path: '/admin-home', label: 'Admin Home' },
      { icon: <Building2 size={iconSize} />, path: '/organizations', label: 'Organizations' },
      { icon: <Users size={iconSize} />, path: '/admin-users', label: 'Users' },
      { icon: <ShieldCheck size={iconSize} />, path: '/roles-permissions', label: 'Roles & Permissions' },
      { icon: <ClipboardList size={iconSize} />, path: '/audit-logs', label: 'Audit Logs' },
      { icon: <Gauge size={iconSize} />, path: '/integrations', label: 'Integrations' },
      { icon: <Settings size={iconSize} />, path: '/system-settings', label: 'System Settings' },
    ],
  },
  admin: {
    label: 'Organization Administrator',
    shortLabel: 'Admin',
    defaultPath: '/overview',
    navItems: [
      { icon: <LayoutDashboard size={iconSize} />, path: '/overview', label: 'Organization Overview' },
      { icon: <Users size={iconSize} />, path: '/users', label: 'Users' },
      { icon: <Tags size={iconSize} />, path: '/tariffs', label: 'Tariffs & Pricing' },
      { icon: <BarChart3 size={iconSize} />, path: '/analytics-reports', label: 'Analytics & Reports' },
    ],
  },
  operator: {
    label: 'Network Operator',
    shortLabel: 'Operator',
    defaultPath: '/overview',
    navItems: [
      { icon: <LayoutDashboard size={iconSize} />, path: '/overview', label: 'Overview' },
      { icon: <PlugZap size={iconSize} />, path: '/stations', label: 'Stations' },
      { icon: <Map size={iconSize} />, path: '/map', label: 'Map' },
      { icon: <AlertTriangle size={iconSize} />, path: '/alerts', label: 'Alerts' },
      { icon: <ReceiptText size={iconSize} />, path: '/sessions', label: 'Sessions' },
      { icon: <CreditCard size={iconSize} />, path: '/payments', label: 'Payments' },
      { icon: <BarChart3 size={iconSize} />, path: '/reports', label: 'Reports' },
    ],
  },
  technician: {
    label: 'Field Technician',
    shortLabel: 'Technician',
    defaultPath: '/overview',
    navItems: [
      { icon: <LayoutDashboard size={iconSize} />, path: '/overview', label: 'Overview' },
      { icon: <AlertTriangle size={iconSize} />, path: '/assigned-alerts', label: 'My Alerts' },
      { icon: <Wrench size={iconSize} />, path: '/my-interventions', label: 'My Interventions' },
      { icon: <PlugZap size={iconSize} />, path: '/stations', label: 'Stations' },
      { icon: <Map size={iconSize} />, path: '/map', label: 'Map' },
      { icon: <ClipboardList size={iconSize} />, path: '/maintenance-reports', label: 'Maintenance Reports' },
    ],
  },
  client: {
    label: 'Driver',
    shortLabel: 'Client',
    defaultPath: '/overview',
    navItems: [
      { icon: <LayoutDashboard size={iconSize} />, path: '/overview', label: 'Overview' },
      { icon: <Search size={iconSize} />, path: '/find-station', label: 'Find Station' },
      { icon: <ReceiptText size={iconSize} />, path: '/my-sessions', label: 'My Sessions' },
      { icon: <ListChecks size={iconSize} />, path: '/vehicles', label: 'My Vehicles' },
      { icon: <CreditCard size={iconSize} />, path: '/payments', label: 'Payments & Invoices' },
    ],
  },
}

export function getRoleConfig(role: UserRole | null): RoleConfig {
  return role ? roleConfigs[role] : roleConfigs.operator
}
