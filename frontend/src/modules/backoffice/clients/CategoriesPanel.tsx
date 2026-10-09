import { zodResolver } from '@hookform/resolvers/zod'
import type { TFunction } from 'i18next'
import { useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import type { ClientCategory } from '../../../shared/api/types'
import { applyServerErrors } from '../../../shared/forms'
import { Alert, Button, Card, ErrorAlert, Field, Input, Spinner } from '../../../shared/ui'
import { DataTable, Dialog, DialogActions, EmptyState, TableHead, Th } from '../shared/ui'
import { type CategoryInput, useCategories, useCreateCategory, useUpdateCategory } from './api'

function makeSchema(t: TFunction) {
  return z.object({
    code: z
      .string()
      .trim()
      .toUpperCase()
      .regex(/^[A-Z0-9_-]{1,20}$/, t('backoffice.clients.categories.codeFormat')),
    name: z.string().trim().min(1).max(255),
  })
}

type FormInput = z.input<ReturnType<typeof makeSchema>>

const FIELDS = ['code', 'name'] as const

type CategoryFormProps = {
  category?: ClientCategory
  submitting: boolean
  error: unknown
  onSubmit: (values: CategoryInput) => Promise<unknown>
  onCancel: () => void
}

/** Catégorie de clients : code saisi à la création seulement, libellé unique. */
function CategoryForm({ category, submitting, error, onSubmit, onCancel }: CategoryFormProps) {
  const { t } = useTranslation()
  const schema = useMemo(() => makeSchema(t), [t])
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<FormInput, unknown, CategoryInput>({
    resolver: zodResolver(schema),
    defaultValues: { code: category?.code ?? '', name: category?.name ?? '' },
  })

  const submit = handleSubmit(async (values) => {
    try {
      await onSubmit(values)
    } catch (submitError) {
      applyServerErrors(submitError, setError, FIELDS)
    }
  })

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {Object.keys(errors).length === 0 && <ErrorAlert error={error} />}
      <Field
        label={t('backoffice.clients.categories.fields.code')}
        hint={category ? undefined : t('backoffice.clients.categories.fields.codeHint')}
        error={errors.code?.message}
      >
        {(props) => (
          <Input
            {...props}
            {...register('code')}
            readOnly={Boolean(category)}
            className={category ? 'bg-slate-100' : 'uppercase'}
            autoCapitalize="characters"
            spellCheck={false}
          />
        )}
      </Field>
      <Field label={t('backoffice.clients.categories.fields.name')} error={errors.name?.message}>
        {(props) => <Input {...props} {...register('name')} />}
      </Field>
      <DialogActions submitting={submitting} onCancel={onCancel} />
    </form>
  )
}

function CreateCategoryDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation()
  const createCategory = useCreateCategory()
  return (
    <Dialog open title={t('backoffice.clients.categories.create')} onClose={onClose}>
      <CategoryForm
        submitting={createCategory.isPending}
        error={createCategory.error}
        onCancel={onClose}
        onSubmit={async (values) => {
          await createCategory.mutateAsync(values)
          onDone()
        }}
      />
    </Dialog>
  )
}

function EditCategoryDialog({ category, onClose, onDone }: { category: ClientCategory; onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation()
  const updateCategory = useUpdateCategory(category.id)
  return (
    <Dialog open title={t('backoffice.clients.categories.edit', { code: category.code })} onClose={onClose}>
      <CategoryForm
        category={category}
        submitting={updateCategory.isPending}
        error={updateCategory.error}
        onCancel={onClose}
        onSubmit={async ({ name }) => {
          await updateCategory.mutateAsync({ name })
          onDone()
        }}
      />
    </Dialog>
  )
}

/** Onglet « Catégories » des clients : lecture pour le personnel, création et modification avec client.manage. */
export function CategoriesPanel({ manage }: { manage: boolean }) {
  const { t } = useTranslation()
  const categories = useCategories()
  const [dialog, setDialog] = useState<'create' | ClientCategory | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const open = (target: 'create' | ClientCategory) => {
    setNotice(null)
    setDialog(target)
  }
  const done = (message: string) => {
    setDialog(null)
    setNotice(message)
  }

  return (
    <Card
      title={t('backoffice.clients.tabs.categories')}
      actions={manage ? <Button onClick={() => open('create')}>{t('backoffice.clients.categories.create')}</Button> : undefined}
    >
      <p className="mb-4 text-sm text-slate-600">{t('backoffice.clients.categories.intro')}</p>
      {notice && (
        <Alert tone="success" className="mb-4">
          {notice}
        </Alert>
      )}
      <ErrorAlert error={categories.error} />
      {categories.isPending && <Spinner />}

      {categories.data && (
        <>
          <DataTable label={t('backoffice.clients.tabs.categories')} className="min-w-[480px]">
            <TableHead>
              <Th>{t('backoffice.clients.categories.columns.code')}</Th>
              <Th>{t('backoffice.clients.categories.columns.name')}</Th>
              <Th className="text-end">{t('backoffice.clients.categories.columns.clients')}</Th>
              {manage && (
                <Th>
                  <span className="sr-only">{t('backoffice.common.actions')}</span>
                </Th>
              )}
            </TableHead>
            <tbody className="divide-y divide-slate-100">
              {categories.data.map((category) => (
                <tr key={category.id} className="hover:bg-slate-50">
                  <td className="py-2 pe-4 font-medium">{category.code}</td>
                  <td className="py-2 pe-4">{category.name}</td>
                  <td className="py-2 pe-4 text-end tabular-nums">{category.clients_count}</td>
                  {manage && (
                    <td className="py-1 text-end">
                      <Button variant="ghost" aria-label={t('backoffice.clients.categories.edit', { code: category.code })} onClick={() => open(category)}>
                        {t('backoffice.common.edit')}
                      </Button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </DataTable>
          {categories.data.length === 0 && <EmptyState>{t('backoffice.clients.categories.empty')}</EmptyState>}
        </>
      )}

      {dialog === 'create' && <CreateCategoryDialog onClose={() => setDialog(null)} onDone={() => done(t('backoffice.clients.categories.created'))} />}
      {dialog !== null && dialog !== 'create' && (
        <EditCategoryDialog category={dialog} onClose={() => setDialog(null)} onDone={() => done(t('backoffice.clients.categories.updated'))} />
      )}
    </Card>
  )
}
