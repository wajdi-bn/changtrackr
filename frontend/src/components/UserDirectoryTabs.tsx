import { BriefcaseBusiness, UsersRound } from 'lucide-react'
import { useLocation, useNavigate } from 'react-router-dom'

const tabs = [
  { path: '/users/employees', label: 'Employees', icon: BriefcaseBusiness },
  { path: '/users/customers', label: 'Customers', icon: UsersRound },
]

export function UserDirectoryTabs() {
  const location = useLocation()
  const navigate = useNavigate()

  return <nav className="user-directory-tabs" aria-label="User directory sections">
    {tabs.map((tab) => {
      const Icon = tab.icon
      return <button key={tab.path} type="button" className={location.pathname === tab.path ? 'active' : ''} onClick={() => navigate(tab.path)}>
        <Icon size={15} />
        {tab.label}
      </button>
    })}
  </nav>
}
