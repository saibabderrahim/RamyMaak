import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import type { ProductFamily } from '../../../shared/api/types'
import { applyServerErrors } from '../../../shared/forms'
import { Alert, Button, Card, ErrorAlert, Field, Input, Select, Spinner } from '../../../shared/ui'
import { type FamilyInput, useCreateFamily, useFamilies, useUpdateFamily } from './api'
import { type FamilyNode, familyOptionLabel, orderFamilies, selfAndDescendants } from './families'

type Editing = { mode: 'create' } | { mode: 'edit'; family: ProductFamily } | null

/**
 * Familles et gammes de produits (EF-PRD-01) : liste en arbre ; création et
 * modification avec la famille parente (ni elle-même ni une descendante).
 */
export function FamiliesPanel({ canManage }: { canManage: boolean }) {
  const { t } = useTranslation()
  const families = useFamilies()
  const [editing, setEditing] = useState<Editing>(null)
  const [saved, setSaved] = useState<'created' | 'updated' | null>(null)

  const list = families.data?.data ?? []
  const byId = new Map(list.map((family) => [family.id, family]))
  const nodes = orderFamilies(list)

  const open = (next: Editing) => {
    setSaved(null)
    setEditing(next)
  }

  return (
    <div className="flex flex-col gap-6">
      {saved && (
        <Alert tone="success">{saved === 'created' ? t('backoffice.products.families.created') : t('backoffice.products.families.updated')}</Alert>
      )}

      {canManage && editing && (
        <Card
          title={editing.mode === 'create' ? t('backoffice.products.families.create') : t('backoffice.products.families.edit', { code: editing.family.code })}
        >
          {editing.mode === 'create' ? (
            <CreateFamilyForm
              nodes={nodes}
              onCancel={() => setEditing(null)}
              onSaved={() => {
                setEditing(null)
                setSaved('created')
              }}
            />
          ) : (
            <EditFamilyForm
              key={editing.family.id}
              family={editing.family}
              nodes={nodes}
              excluded={selfAndDescendants(list, editing.family.id)}
              onCancel={() => setEditing(null)}
              onSaved={() => {
                setEditing(null)
                setSaved('updated')
              }}
            />
          )}
        </Card>
      )}

      <Card
        title={t('backoffice.products.families.title')}
        actions={
          canManage && editing?.mode !== 'create' ? (
            <Button variant="secondary" onClick={() => open({ mode: 'create' })}>
              {t('backoffice.products.families.create')}
            </Button>
          ) : undefined
        }
      >
        <ErrorAlert error={families.error} />
        {families.isPending && <Spinner />}
        {families.data && (
          <>
            <div className="relative overflow-x-auto">
              <table className="w-full min-w-[560px] text-start text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                  <tr>
                    <th className="py-2 pe-4 text-start font-medium">{t('backoffice.products.families.columns.code')}</th>
                    <th className="py-2 pe-4 text-start font-medium">{t('backoffice.products.families.columns.name')}</th>
                    <th className="py-2 pe-4 text-start font-medium">{t('backoffice.products.families.columns.parent')}</th>
                    <th className="py-2 pe-4 text-end font-medium">{t('backoffice.products.families.columns.sortOrder')}</th>
                    <th className="py-2 pe-4 text-end font-medium">{t('backoffice.products.families.columns.products')}</th>
                    {canManage && (
                      <th className="py-2 text-end font-medium">
                        <span className="sr-only">{t('backoffice.products.families.columns.actions')}</span>
                      </th>
                    )}
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {nodes.map(({ family, depth }) => (
                    <tr key={family.id} className="hover:bg-slate-50">
                      <td className="py-2 pe-4 font-mono text-xs">{family.code}</td>
                      <td className="py-2 pe-4" style={{ paddingInlineStart: `${depth * 1.25}rem` }}>
                        {depth > 0 && (
                          <span aria-hidden="true" className="me-1 text-slate-400">
                            └
                          </span>
                        )}
                        {family.name}
                      </td>
                      <td className="py-2 pe-4 text-slate-600">
                        {family.parent_id !== null ? (byId.get(family.parent_id)?.name ?? t('common.none')) : t('common.none')}
                      </td>
                      <td className="py-2 pe-4 text-end tabular-nums">{family.sort_order}</td>
                      <td className="py-2 pe-4 text-end tabular-nums">{family.products_count}</td>
                      {canManage && (
                        <td className="py-1 text-end">
                          <Button
                            variant="ghost"
                            aria-label={t('backoffice.products.families.editActionLabel', { name: family.name })}
                            onClick={() => open({ mode: 'edit', family })}
                          >
                            {t('backoffice.products.families.editAction')}
                          </Button>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {list.length === 0 && <p className="py-6 text-center text-sm text-slate-500">{t('backoffice.products.families.empty')}</p>}
          </>
        )}
      </Card>
    </div>
  )
}

type FormValues = { code: string; name: string; parent_id: string; sort_order: string }

const FIELDS = ['code', 'name', 'parent_id', 'sort_order'] as const

/** Le code n'est saisi qu'à la création : il est repris par l'import du catalogue. */
function useFamilySchema(editing: boolean) {
  const { t } = useTranslation()
  return z.object({
    code: editing
      ? z.string()
      : z
          .string()
          .trim()
          .toUpperCase()
          .regex(/^[A-Z0-9_-]{1,20}$/, t('backoffice.products.families.validation.code')),
    name: z.string().trim().min(1).max(255),
    parent_id: z
      .string()
      .transform((value) => (value === '' ? null : Number(value)))
      .pipe(z.number().int().positive().nullable()),
    sort_order: z
      .string()
      .trim()
      .transform((value) => (value === '' ? 0 : Number(value)))
      .pipe(z.number(t('backoffice.products.validation.sortOrder')).int(t('backoffice.products.validation.sortOrder')).min(0).max(1_000_000)),
  })
}

type FormProps = {
  nodes: FamilyNode[]
  onCancel: () => void
  onSaved: () => void
}

function CreateFamilyForm({ nodes, onCancel, onSaved }: FormProps) {
  const create = useCreateFamily()
  return (
    <FamilyForm
      nodes={nodes}
      excluded={new Set()}
      defaults={{ code: '', name: '', parent_id: '', sort_order: '0' }}
      submitting={create.isPending}
      error={create.error}
      onCancel={onCancel}
      onSubmit={async (values) => {
        await create.mutateAsync(values)
        onSaved()
      }}
    />
  )
}

function EditFamilyForm({ family, excluded, nodes, onCancel, onSaved }: FormProps & { family: ProductFamily; excluded: Set<number> }) {
  const update = useUpdateFamily(family.id)
  return (
    <FamilyForm
      editing
      nodes={nodes}
      excluded={excluded}
      defaults={{ code: family.code, name: family.name, parent_id: family.parent_id === null ? '' : String(family.parent_id), sort_order: String(family.sort_order) }}
      submitting={update.isPending}
      error={update.error}
      onCancel={onCancel}
      onSubmit={async ({ code: _code, ...values }) => {
        await update.mutateAsync(values)
        onSaved()
      }}
    />
  )
}

function FamilyForm({
  editing = false,
  nodes,
  excluded,
  defaults,
  submitting,
  error,
  onCancel,
  onSubmit,
}: {
  editing?: boolean
  nodes: FamilyNode[]
  excluded: Set<number>
  defaults: FormValues
  submitting: boolean
  error: unknown
  onCancel: () => void
  onSubmit: (values: FamilyInput) => Promise<void>
}) {
  const { t } = useTranslation()
  const schema = useFamilySchema(editing)
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<FormValues, unknown, FamilyInput>({ resolver: zodResolver(schema), defaultValues: defaults })

  const submit = handleSubmit(async (values) => {
    try {
      await onSubmit(values)
    } catch (submitError) {
      applyServerErrors(submitError, setError, FIELDS)
    }
  })

  const hasFieldErrors = Object.keys(errors).length > 0

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4">
      {!hasFieldErrors && <ErrorAlert error={error} />}
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t('backoffice.products.families.fields.code')} hint={t('backoffice.products.families.fields.codeHint')} error={errors.code?.message}>
          {(props) => <Input {...props} {...register('code')} disabled={editing} autoCapitalize="characters" spellCheck={false} />}
        </Field>
        <Field label={t('backoffice.products.families.fields.name')} error={errors.name?.message}>
          {(props) => <Input {...props} {...register('name')} />}
        </Field>
        <Field label={t('backoffice.products.families.fields.parent')} error={errors.parent_id?.message}>
          {(props) => (
            <Select {...props} {...register('parent_id')}>
              <option value="">{t('backoffice.products.families.fields.noParent')}</option>
              {nodes
                .filter((node) => !excluded.has(node.family.id))
                .map((node) => (
                  <option key={node.family.id} value={node.family.id}>
                    {familyOptionLabel(node)}
                  </option>
                ))}
            </Select>
          )}
        </Field>
        <Field label={t('backoffice.products.families.fields.sortOrder')} error={errors.sort_order?.message}>
          {(props) => <Input {...props} {...register('sort_order')} inputMode="numeric" />}
        </Field>
      </div>
      <div className="flex flex-wrap gap-2">
        <Button type="submit" disabled={submitting}>
          {submitting ? t('common.saving') : t('common.save')}
        </Button>
        <Button variant="secondary" onClick={onCancel}>
          {t('common.cancel')}
        </Button>
      </div>
    </form>
  )
}
