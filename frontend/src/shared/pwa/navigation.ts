/**
 * Adresses que le service worker ne remplace jamais par l'application
 * (index.html) : l'API, les points techniques du serveur et les photos
 * produits servies par nginx (§9.8), ouvertes par exemple dans un nouvel onglet.
 */
export const NAVIGATION_DENYLIST: RegExp[] = [/^\/api\//, /^\/sanctum\//, /^\/broadcasting\//, /^\/app\//, /^\/up$/, /^\/media\//]

export function isAppNavigation(pathname: string): boolean {
  return !NAVIGATION_DENYLIST.some((pattern) => pattern.test(pathname))
}
