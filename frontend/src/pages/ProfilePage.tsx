import { useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  App,
  Avatar,
  Button,
  Divider,
  Form,
  Input,
  Popconfirm,
  Select,
  Skeleton,
  Tag,
  Upload,
} from 'antd'
import {
  BadgeCheck,
  Building2,
  CalendarDays,
  Camera,
  Clock3,
  Globe2,
  Mail,
  MapPin,
  Phone,
  Save,
  ShieldCheck,
  UserRound,
} from 'lucide-react'
import { MountainBanner } from '../components/MountainBanner'
import { getApiErrorMessage } from '../api/apiErrors'
import { useAuth } from '../features/auth/useAuth'
import { getProfile, removeProfileAvatar, updateProfile, uploadProfileAvatar } from '../features/profile/profileApi'
import type { UpdateProfilePayload } from '../types/profile'
import { formatDateTime } from '../utils/dateTime'

type ProfileFormValues = Required<Pick<UpdateProfilePayload, 'name'>> & Omit<UpdateProfilePayload, 'name'>

function initials(name: string): string {
  return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part.charAt(0)).join('').toUpperCase()
}

function roleLabel(role: string | undefined): string {
  return (role ?? 'user').split('_').map((value) => value.charAt(0).toUpperCase() + value.slice(1)).join(' ')
}

function LinkedInMark({ size = 15 }: { size?: number }) {
  return (
    <svg aria-hidden="true" width={size} height={size} viewBox="0 0 24 24" fill="currentColor">
      <path d="M20.45 3H3.55C3.25 3 3 3.25 3 3.55v16.9c0 .3.25.55.55.55h16.9c.3 0 .55-.25.55-.55V3.55c0-.3-.25-.55-.55-.55ZM8.34 18.34H5.66V9.75h2.68v8.59ZM7 8.58A1.55 1.55 0 1 1 7 5.5a1.55 1.55 0 0 1 0 3.08Zm11.35 9.76h-2.67v-4.18c0-1 0-2.28-1.39-2.28-1.39 0-1.6 1.08-1.6 2.2v4.26h-2.67V9.75h2.56v1.17h.04c.36-.67 1.23-1.39 2.54-1.39 2.71 0 3.21 1.78 3.21 4.1v4.71Z" />
    </svg>
  )
}

const countryOptions = [
  { value: 'TN', label: 'Tunisia (TN)' },
  { value: 'DZ', label: 'Algeria (DZ)' },
  { value: 'EG', label: 'Egypt (EG)' },
  { value: 'FR', label: 'France (FR)' },
  { value: 'DE', label: 'Germany (DE)' },
  { value: 'IT', label: 'Italy (IT)' },
  { value: 'LY', label: 'Libya (LY)' },
  { value: 'MA', label: 'Morocco (MA)' },
  { value: 'SA', label: 'Saudi Arabia (SA)' },
  { value: 'AE', label: 'United Arab Emirates (AE)' },
  { value: 'GB', label: 'United Kingdom (GB)' },
  { value: 'US', label: 'United States (US)' },
]

export function ProfilePage() {
  const { message } = App.useApp()
  const queryClient = useQueryClient()
  const { updateCurrentUser } = useAuth()
  const [form] = Form.useForm<ProfileFormValues>()
  const profileQuery = useQuery({ queryKey: ['profile'], queryFn: getProfile })
  const saveMutation = useMutation({
    mutationFn: updateProfile,
    onSuccess: async (profile) => {
      updateCurrentUser(profile.user)
      await queryClient.invalidateQueries({ queryKey: ['profile'] })
      void message.success('Profile information saved.')
    },
    onError: (error) => void message.error(getApiErrorMessage(error, 'Profile information could not be saved.')),
  })
  const avatarMutation = useMutation({
    mutationFn: uploadProfileAvatar,
    onSuccess: async (profile) => {
      updateCurrentUser(profile.user)
      await queryClient.invalidateQueries({ queryKey: ['profile'] })
      void message.success('Profile photo updated.')
    },
    onError: (error) => void message.error(getApiErrorMessage(error, 'Profile photo could not be updated.')),
  })
  const removeAvatarMutation = useMutation({
    mutationFn: removeProfileAvatar,
    onSuccess: async (profile) => {
      updateCurrentUser(profile.user)
      await queryClient.invalidateQueries({ queryKey: ['profile'] })
      void message.success('Profile photo removed.')
    },
    onError: (error) => void message.error(getApiErrorMessage(error, 'Profile photo could not be removed.')),
  })

  const profile = profileQuery.data

  useEffect(() => {
    if (!profile) return
    form.setFieldsValue({
      ...profile.personal,
      ...profile.address,
      ...profile.professional_links,
    })
  }, [form, profile])

  if (profileQuery.isLoading || !profile) {
    return <div className="profile-page"><Skeleton active paragraph={{ rows: 14 }} /></div>
  }

  const { user, metadata } = profile
  const role = user.roles[0]
  const accessScope = role === 'super_admin'
    ? 'Platform-wide access'
    : role === 'client'
      ? 'Driver account'
      : user.organization?.name ?? 'Organization assignment missing'
  const uploadBusy = avatarMutation.isPending || removeAvatarMutation.isPending

  return (
    <div className="profile-page">
      <MountainBanner
        color="purple"
        breadcrumb={['Account', 'Profile']}
        title="My profile"
        subtitle="Keep your contact details current while your access and organization assignment remain protected."
      />

      <section className="profile-identity">
        <div className="profile-avatar-wrap">
          <Avatar size={96} src={user.avatar_url ?? undefined}>{!user.avatar_url ? initials(user.name) : null}</Avatar>
          <Upload
            accept="image/jpeg,image/png,image/webp"
            showUploadList={false}
            disabled={uploadBusy}
            beforeUpload={(file) => {
              if (file.size > 2 * 1024 * 1024) {
                void message.error('Choose an image smaller than 2 MB.')
                return Upload.LIST_IGNORE
              }
              avatarMutation.mutate(file)
              return Upload.LIST_IGNORE
            }}
          >
            <Button className="profile-avatar-action" shape="circle" aria-label="Change profile photo" icon={<Camera size={16} />} loading={avatarMutation.isPending} />
          </Upload>
        </div>
        <div className="profile-identity-copy">
          <div className="profile-title-row"><h2>{user.name}</h2><Tag color="green">{user.status}</Tag></div>
          <p>{profile.personal.job_title || roleLabel(role)} · {accessScope}</p>
          <span><Mail size={15} /> {user.email}</span>
        </div>
        <div className="profile-identity-actions">
          {user.avatar_url && <Popconfirm title="Remove this profile photo?" okText="Remove" onConfirm={() => removeAvatarMutation.mutate()}><Button disabled={uploadBusy}>Remove photo</Button></Popconfirm>}
          <span>JPG, PNG or WebP · 2 MB maximum</span>
        </div>
      </section>

      <Form form={form} layout="vertical" onFinish={(values) => saveMutation.mutate(values)} className="profile-form">
        <div className="profile-layout">
          <section className="profile-editor-section">
            <div className="profile-section-heading"><span><UserRound size={19} /></span><div><h2>Personal information</h2><p>Information used for your account identity and direct contact.</p></div></div>
            <div className="profile-form-grid two">
              <Form.Item name="name" label="Full name" rules={[{ required: true, message: 'Your name is required.' }]}><Input /></Form.Item>
              <Form.Item name="phone" label="Phone number"><Input type="tel" inputMode="tel" prefix={<Phone size={15} />} placeholder="+216 20 000 000" /></Form.Item>
              <Form.Item name="job_title" label="Professional title"><Input placeholder="e.g. Network operations specialist" /></Form.Item>
            </div>
            <Form.Item name="bio" label="Professional summary"><Input.TextArea rows={4} maxLength={500} showCount placeholder="A short professional description for internal collaboration." /></Form.Item>

            <Divider />
            <div className="profile-section-heading"><span><MapPin size={19} /></span><div><h2>Address</h2><p>Useful for account records and local operational coordination.</p></div></div>
            <div className="profile-form-grid two">
              <Form.Item name="address_line_1" label="Address line 1"><Input /></Form.Item>
              <Form.Item name="address_line_2" label="Address line 2"><Input /></Form.Item>
              <Form.Item name="city" label="City"><Input /></Form.Item>
              <Form.Item name="region" label="Region / governorate"><Input /></Form.Item>
              <Form.Item name="postal_code" label="Postal code"><Input /></Form.Item>
              <Form.Item name="country_code" label="Country"><Select showSearch optionFilterProp="label" options={countryOptions} placeholder="Select a country" /></Form.Item>
            </div>

            <Divider />
            <div className="profile-section-heading"><span><Globe2 size={19} /></span><div><h2>Professional links</h2><p>Optional business-facing links only. Personal social profiles are not requested.</p></div></div>
            <div className="profile-form-grid two">
              <Form.Item name="linkedin_url" label="LinkedIn"><Input type="url" prefix={<LinkedInMark />} placeholder="https://www.linkedin.com/in/..." /></Form.Item>
              <Form.Item name="website_url" label="Professional website"><Input type="url" prefix={<Globe2 size={15} />} placeholder="https://..." /></Form.Item>
            </div>
            <div className="profile-savebar"><span>Changes are recorded in the platform audit log.</span><Button type="primary" htmlType="submit" icon={<Save size={16} />} loading={saveMutation.isPending}>Save profile</Button></div>
          </section>

          <aside className="profile-metadata-panel">
            <div className="profile-section-heading"><span><ShieldCheck size={19} /></span><div><h2>Account context</h2><p>Managed by your platform permissions.</p></div></div>
            <dl className="profile-metadata-list">
              <div><dt><Mail size={15} /> Email</dt><dd>{user.email}</dd></div>
              <div><dt><BadgeCheck size={15} /> Verification</dt><dd>{metadata.email_verified_at ? 'Verified' : 'Pending verification'}</dd></div>
              <div><dt><UserRound size={15} /> Role</dt><dd>{roleLabel(role)}</dd></div>
              {role === 'super_admin' && <div><dt><ShieldCheck size={15} /> Access scope</dt><dd>Platform-wide access</dd></div>}
              {role === 'client' && <div><dt><UserRound size={15} /> Account type</dt><dd>Driver account</dd></div>}
              {!['super_admin', 'client'].includes(role ?? '') && <><div><dt><Building2 size={15} /> Organization</dt><dd>{user.organization?.name ?? 'Not assigned'}</dd></div>{user.team && <div><dt><Building2 size={15} /> Team</dt><dd>{user.team}</dd></div>}</>}
              <div><dt><Clock3 size={15} /> Last sign-in</dt><dd>{formatDateTime(metadata.last_login_at, user.timezone)}</dd></div>
              <div><dt><CalendarDays size={15} /> Account created</dt><dd>{formatDateTime(metadata.account_created_at, user.timezone)}</dd></div>
              <div><dt><ShieldCheck size={15} /> Sign-in methods</dt><dd>{[metadata.local_password_configured ? 'Password' : null, ...metadata.sign_in_providers.map((provider) => provider.charAt(0).toUpperCase() + provider.slice(1))].filter(Boolean).join(' · ') || 'Not recorded'}</dd></div>
            </dl>
            <p className="profile-metadata-note">To protect access control, your role, organization, team and account status are maintained by the authorized administrator.</p>
          </aside>
        </div>
      </Form>
    </div>
  )
}
