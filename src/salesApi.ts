export function csrfToken(): string {
  return decodeURIComponent(document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '')
}

export async function salesRequest(path: string, tenantId: string, method = 'GET', body?: unknown) {
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Tenant-ID': tenantId }
  if (method !== 'GET') headers['X-XSRF-TOKEN'] = csrfToken()
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  const response = await fetch(`/api/v1${path}`, {
    method, credentials: 'include', headers,
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  })
  const result = response.status === 204 ? null : await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(result?.message ?? `Request failed (${response.status}).`)
  return result
}

export function humanize(value: unknown): string {
  if (typeof value !== 'string' || !value) return 'Not available'
  return value.toLowerCase().split(/[_\s]+/).map((part) => part.charAt(0).toUpperCase() + part.slice(1)).join(' ')
}

export function unwrap<T = any>(response: any): T[] {
  if (Array.isArray(response)) return response
  if (Array.isArray(response?.data)) return response.data
  if (Array.isArray(response?.data?.data)) return response.data.data
  return []
}
