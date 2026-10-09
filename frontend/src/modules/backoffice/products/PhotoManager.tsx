import { type ChangeEvent, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError } from '../../../shared/api/client'
import type { Product } from '../../../shared/api/types'
import { Alert, Badge, Button, Card, ErrorAlert } from '../../../shared/ui'
import { ConfirmDialog } from '../shared/ui'
import { useDeletePhoto, useMakeMainPhoto, useUploadPhoto } from './api'

/** 1 principale + 4 secondaires (EF-PRD-02). */
const MAX_PHOTOS = 5
const MAX_BYTES = 5 * 1024 * 1024
const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp']

/** Message de validation du serveur sur le champ `photo`, sinon null. */
function photoFieldError(error: unknown): string | null {
  return error instanceof ApiError ? (error.fieldErrors.photo?.[0] ?? null) : null
}

/**
 * Photos du produit (EF-PRD-02, §9.8) : envoi (JPEG, PNG ou WebP, 5 Mo),
 * photo principale, suppression après confirmation. Le serveur contrôle le
 * type réel, ré-encode l'image et produit les variantes WebP ; les images
 * se chargent à la demande, emplacement réservé.
 */
export function PhotoManager({ product, canManage }: { product: Product; canManage: boolean }) {
  const { t } = useTranslation()
  const upload = useUploadPhoto(product.id)
  const makeMain = useMakeMainPhoto(product.id)
  const remove = useDeletePhoto(product.id)
  const [localError, setLocalError] = useState<string | null>(null)
  const [deleting, setDeleting] = useState<{ id: number; index: number } | null>(null)

  const full = product.photos.length >= MAX_PHOTOS
  const busy = upload.isPending || makeMain.isPending || remove.isPending

  const onFile = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    // Le même fichier peut être choisi de nouveau après une erreur.
    event.target.value = ''
    if (!file) return

    upload.reset()
    if (!ACCEPTED_TYPES.includes(file.type)) return setLocalError(t('backoffice.products.photos.invalidType'))
    if (file.size > MAX_BYTES) return setLocalError(t('backoffice.products.photos.tooBig'))
    setLocalError(null)
    upload.mutate(file)
  }

  const setMain = (photoId: number) => {
    upload.reset()
    setLocalError(null)
    makeMain.mutate(photoId)
  }

  const askDelete = (id: number, index: number) => {
    upload.reset()
    remove.reset()
    setLocalError(null)
    setDeleting({ id, index })
  }

  const confirmDelete = () => {
    if (deleting) remove.mutate(deleting.id, { onSuccess: () => setDeleting(null) })
  }

  const uploadFieldError = photoFieldError(upload.error)
  const actionError = upload.error && !uploadFieldError ? upload.error : makeMain.error

  return (
    <Card title={t('backoffice.products.photos.title')}>
      <div className="flex flex-col gap-4">
        {product.photos.length === 0 ? (
          <p className="text-sm text-slate-500">{t('backoffice.products.photos.empty')}</p>
        ) : (
          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            {product.photos.map((photo, index) => (
              <li key={photo.id} className="flex flex-col gap-2 rounded-lg border border-slate-200 p-2" data-testid={`photo-${photo.id}`}>
                <a
                  href={photo.urls.large}
                  target="_blank"
                  rel="noopener noreferrer"
                  aria-label={t('backoffice.products.photos.open', { index: index + 1 })}
                  className="block rounded focus-visible:outline-2 focus-visible:outline-brand-600"
                >
                  <img
                    src={photo.urls.thumb}
                    srcSet={`${photo.urls.thumb} 200w, ${photo.urls.medium} 600w`}
                    sizes="(min-width: 640px) 200px, 45vw"
                    alt={t('backoffice.products.photos.alt', { index: index + 1, name: product.name })}
                    width={200}
                    height={200}
                    loading="lazy"
                    decoding="async"
                    className="aspect-square h-auto w-full rounded bg-slate-50 object-contain"
                  />
                </a>
                {photo.is_main && (
                  <span className="self-start">
                    <Badge tone="brand">{t('backoffice.products.photos.main')}</Badge>
                  </span>
                )}
                {canManage && !photo.is_main && (
                  <Button
                    variant="secondary"
                    className="px-2 text-xs"
                    aria-label={t('backoffice.products.photos.makeMainLabel', { index: index + 1 })}
                    disabled={busy}
                    onClick={() => setMain(photo.id)}
                  >
                    {t('backoffice.products.photos.makeMain')}
                  </Button>
                )}
                {canManage && (
                  <Button
                    variant="ghost"
                    className="px-2 text-xs text-red-700"
                    aria-label={t('backoffice.products.photos.deleteLabel', { index: index + 1 })}
                    disabled={busy}
                    onClick={() => askDelete(photo.id, index + 1)}
                  >
                    {t('backoffice.products.photos.delete')}
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}

        {canManage && (
          <div className="flex flex-col gap-2">
            {upload.isSuccess && <Alert tone="success">{t('backoffice.products.photos.uploaded')}</Alert>}
            <ErrorAlert error={actionError} />
            {(localError ?? uploadFieldError) && (
              <p role="alert" className="text-sm text-red-700">
                {localError ?? uploadFieldError}
              </p>
            )}
            {full ? (
              <Alert tone="warning">{t('backoffice.products.photos.limit')}</Alert>
            ) : (
              <label
                className={`inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 self-start rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-brand-600 ${
                  busy ? 'cursor-not-allowed opacity-60' : ''
                }`}
              >
                <input type="file" accept={ACCEPTED_TYPES.join(',')} className="sr-only" disabled={busy} onChange={onFile} />
                {upload.isPending ? t('backoffice.products.photos.uploading') : t('backoffice.products.photos.add')}
              </label>
            )}
            <p className="text-xs text-slate-500">{t('backoffice.products.photos.hint')}</p>
          </div>
        )}
      </div>

      <ConfirmDialog
        open={deleting !== null}
        title={t('backoffice.products.photos.deleteTitle', { index: deleting?.index ?? 0 })}
        confirmLabel={t('backoffice.products.photos.delete')}
        tone="danger"
        pending={remove.isPending}
        error={remove.error}
        onConfirm={confirmDelete}
        onCancel={() => setDeleting(null)}
      >
        <p>{t('backoffice.products.photos.deleteConfirm')}</p>
      </ConfirmDialog>
    </Card>
  )
}
