import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { Alert, Card, PageHeader } from '../../shared/ui'
import { useCreateUser } from './api'
import { TemporaryPasswordNotice } from './components'
import { UserForm } from './UserForm'

export default function UserCreatePage() {
  const { t } = useTranslation()
  const createUser = useCreateUser()

  const created = createUser.data

  return (
    <>
      <PageHeader title={t('admin.users.create')} actions={<Link to=".." relative="path" className="text-sm text-brand-700 hover:underline">{t('common.back')}</Link>} />
      <Card>
        {created ? (
          <div className="flex flex-col gap-4">
            <Alert tone="success">{t('admin.users.created')}</Alert>
            <TemporaryPasswordNotice user={created.user} password={created.temporary_password} />
            <Link to={`../${created.user.id}`} relative="path" className="self-start text-sm font-medium text-brand-700 hover:underline">
              {created.user.username}
            </Link>
          </div>
        ) : (
          <UserForm
            submitting={createUser.isPending}
            error={createUser.error}
            onSubmit={(values) => createUser.mutateAsync({ ...values, client_id: values.role === 'client' ? values.client_id : null })}
          />
        )}
      </Card>
    </>
  )
}
