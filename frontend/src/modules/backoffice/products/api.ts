import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, request } from '../../../shared/api/client'
import type { ListResponse, Paginated, Product, ProductFamily, ProductPhoto, ProductPrice } from '../../../shared/api/types'
import { formData, postMultipart } from '../imports/multipart'

/* ---------- Produits (EF-PRD-01) ---------- */

export type ProductFilters = {
  search?: string
  family_id?: string
  is_active?: '' | '1' | '0'
  page?: number
}

const productsKey = ['backoffice', 'products'] as const
const familiesKey = ['backoffice', 'product-families'] as const
const productKey = (id: number) => [...productsKey, 'detail', id] as const
const pricesKey = (id: number) => [...productsKey, 'prices', id] as const

export function useProducts(filters: ProductFilters) {
  return useQuery({
    queryKey: [...productsKey, 'list', filters],
    queryFn: ({ signal }) =>
      api.get<Paginated<Product>>(
        '/products',
        {
          'filter[search]': filters.search,
          'filter[family_id]': filters.family_id,
          'filter[is_active]': filters.is_active,
          page: filters.page,
          per_page: 25,
        },
        signal,
      ),
    placeholderData: keepPreviousData,
  })
}

export function useProduct(id: number) {
  return useQuery({
    queryKey: productKey(id),
    queryFn: ({ signal }) => api.get<Product>(`/products/${id}`, undefined, signal),
    enabled: Number.isInteger(id) && id > 0,
  })
}

export type ProductInput = {
  sku: string
  name: string
  short_name: string | null
  family_id: number
  format: string | null
  sales_unit_label: string
  units_per_sales_unit: number
  sales_units_per_pallet: number | null
  ean: string | null
  vat_rate: number
  is_active: boolean
  is_new: boolean
  is_featured: boolean
  sort_order: number
}

/** Le code article ne change plus après la création (clé des fichiers Excel). */
export type ProductUpdate = Partial<Omit<ProductInput, 'sku'>>

export function useCreateProduct() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: ProductInput) => api.post<Product>('/products', input),
    onSuccess: (product) => {
      queryClient.setQueryData(productKey(product.id), product)
      void queryClient.invalidateQueries({ queryKey: [...productsKey, 'list'] })
      void queryClient.invalidateQueries({ queryKey: familiesKey })
    },
  })
}

export function useUpdateProduct(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: ProductUpdate) => api.patch<Product>(`/products/${id}`, input),
    onSuccess: (product) => {
      queryClient.setQueryData(productKey(id), product)
      void queryClient.invalidateQueries({ queryKey: [...productsKey, 'list'] })
      void queryClient.invalidateQueries({ queryKey: familiesKey })
    },
  })
}

/* ---------- Prix (EF-PRD-03, RG-P01) ---------- */

export function useProductPrices(id: number) {
  return useQuery({
    queryKey: pricesKey(id),
    queryFn: ({ signal }) => api.get<ListResponse<ProductPrice>>(`/products/${id}/prices`, undefined, signal),
  })
}

export type PriceInput = { price_ht: number; valid_from: string }

export function useAddPrice(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: PriceInput) => api.post<ProductPrice>(`/products/${id}/prices`, input),
    // Le prix en vigueur et le prochain prix de la fiche peuvent changer.
    onSuccess: () => queryClient.invalidateQueries({ queryKey: productsKey }),
  })
}

/* ---------- Photos (EF-PRD-02, §9.8) ---------- */

export function useUploadPhoto(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (photo: File) => postMultipart<ProductPhoto>(`/products/${id}/media`, formData({ photo })),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: productsKey }),
  })
}

function setPhotos(queryClient: ReturnType<typeof useQueryClient>, id: number, photos: (current: ProductPhoto[]) => ProductPhoto[]) {
  queryClient.setQueryData<Product>(productKey(id), (product) => (product ? { ...product, photos: photos(product.photos) } : product))
}

export function useMakeMainPhoto(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (mediaId: number) => api.patch<ListResponse<ProductPhoto>>(`/products/${id}/media/${mediaId}`, { main: true }),
    onSuccess: ({ data }) => {
      setPhotos(queryClient, id, () => data)
      void queryClient.invalidateQueries({ queryKey: [...productsKey, 'list'] })
    },
  })
}

export function useDeletePhoto(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (mediaId: number) => request<void>(`/products/${id}/media/${mediaId}`, { method: 'DELETE' }),
    onSuccess: (_, mediaId) => {
      setPhotos(queryClient, id, (photos) => photos.filter((photo) => photo.id !== mediaId))
      // La photo suivante peut devenir la principale : relecture de la fiche.
      void queryClient.invalidateQueries({ queryKey: productsKey })
    },
  })
}

/* ---------- Familles ---------- */

export function useFamilies() {
  return useQuery({
    queryKey: familiesKey,
    queryFn: ({ signal }) => api.get<ListResponse<ProductFamily>>('/product-families', undefined, signal),
    staleTime: 60_000,
  })
}

export type FamilyInput = { code: string; name: string; parent_id: number | null; sort_order: number }
export type FamilyUpdate = Omit<FamilyInput, 'code'>

export function useCreateFamily() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: FamilyInput) => api.post<ProductFamily>('/admin/product-families', input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: familiesKey }),
  })
}

export function useUpdateFamily(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: FamilyUpdate) => api.patch<ProductFamily>(`/admin/product-families/${id}`, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: familiesKey })
      // Le libellé de la famille est repris dans les fiches produits.
      void queryClient.invalidateQueries({ queryKey: productsKey })
    },
  })
}
