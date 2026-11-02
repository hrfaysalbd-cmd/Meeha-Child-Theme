// Round 18 — category tree helpers shared by the category picker.
//
// The backend's GET /categories is a flat list; since round 18 every item
// also carries `parent` (0 = top level) and `count` (published posts). Older
// backends send neither, so everything here treats a missing parent as top
// level and a missing count as 0 — the picker then degrades to a flat,
// searchable list instead of breaking.

export interface CategoryLike {
  id: number
  name: string
  slug?: string
  parent?: number
  count?: number
  is_default?: boolean
}

export interface CategoryNode<C extends CategoryLike = CategoryLike> {
  cat: C
  children: CategoryNode<C>[]
  depth: number
  /** Sum of this category's and all descendants' post counts. */
  total: number
}

export interface CategoryTree<C extends CategoryLike = CategoryLike> {
  roots: CategoryNode<C>[]
  byId: Map<number, CategoryNode<C>>
}

const byPopularity = <C extends CategoryLike>(a: CategoryNode<C>, b: CategoryNode<C>) =>
  b.total - a.total || a.cat.name.localeCompare(b.cat.name, 'bn')

export function buildCategoryTree<C extends CategoryLike>(list: C[]): CategoryTree<C> {
  const byId = new Map<number, CategoryNode<C>>()
  for (const cat of list) byId.set(cat.id, { cat, children: [], depth: 0, total: cat.count ?? 0 })

  const roots: CategoryNode<C>[] = []
  for (const node of byId.values()) {
    const parent = node.cat.parent ? byId.get(node.cat.parent) : undefined
    // A parent pointing at itself or at a missing term is treated as top level.
    if (parent && parent !== node) parent.children.push(node)
    else roots.push(node)
  }

  // Depths and subtree totals; `seen` guards against a corrupt parent loop.
  const seen = new Set<number>()
  const walk = (node: CategoryNode<C>, depth: number): number => {
    if (seen.has(node.cat.id)) return 0
    seen.add(node.cat.id)
    node.depth = depth
    node.total = (node.cat.count ?? 0) + node.children.reduce((sum, c) => sum + walk(c, depth + 1), 0)
    node.children.sort(byPopularity)
    return node.total
  }
  roots.forEach((r) => walk(r, 0))
  // The "Uncategorized"/default category goes last among the main ones.
  roots.sort((a, b) => Number(!!a.cat.is_default) - Number(!!b.cat.is_default) || byPopularity(a, b))

  return { roots, byId }
}

/** Ancestors first, the category itself last. */
export function categoryPath<C extends CategoryLike>(tree: CategoryTree<C>, id: number): C[] {
  const path: C[] = []
  let node = tree.byId.get(id)
  const guard = new Set<number>()
  while (node && !guard.has(node.cat.id)) {
    guard.add(node.cat.id)
    path.unshift(node.cat)
    node = node.cat.parent ? tree.byId.get(node.cat.parent) : undefined
  }
  return path
}

export const pathLabel = (path: CategoryLike[]) => path.map((c) => c.name).join(' › ')

// Search: case-insensitive and ignores spaces/ZWJ/ZWNJ. NFC also unifies
// the two ways of typing য়/ড়/ঢ় (one code point, or letter + nukta), which
// phone keyboards mix freely.
export function normalizeForSearch(s: string) {
  return s
    .normalize('NFC')
    .toLowerCase()
    .replace(/[\u200c\u200d\s\-_.]/g, '')
}

export interface CategorySearchHit<C extends CategoryLike = CategoryLike> {
  cat: C
  path: C[]
  /** Lower is better. */
  score: number
}

export function searchCategories<C extends CategoryLike>(tree: CategoryTree<C>, query: string, limit = 30): CategorySearchHit<C>[] {
  const q = normalizeForSearch(query)
  if (!q) return []
  const hits: CategorySearchHit<C>[] = []
  for (const node of tree.byId.values()) {
    const name = normalizeForSearch(node.cat.name)
    const slug = (node.cat.slug ?? '').toLowerCase()
    let score = -1
    if (name === q) score = 0
    else if (name.startsWith(q)) score = 1
    else if (name.includes(q)) score = 2
    else if (slug.includes(q)) score = 3
    else {
      // A parent's name matches ("খেলা" finds "খেলা › ক্রিকেট") — ranked below direct hits.
      const path = categoryPath(tree, node.cat.id)
      if (path.slice(0, -1).some((p) => normalizeForSearch(p.name).includes(q))) score = 4
    }
    if (score >= 0) hits.push({ cat: node.cat, path: categoryPath(tree, node.cat.id), score })
  }
  hits.sort((a, b) => a.score - b.score || a.path.length - b.path.length || (tree.byId.get(b.cat.id)!.total - tree.byId.get(a.cat.id)!.total))
  return hits.slice(0, limit)
}

// Recently used categories, per app (localStorage; failures are ignored —
// private mode or blocked storage just means no "recent" row).
export function readRecentCategories(key: string): number[] {
  try {
    const raw = localStorage.getItem(key)
    const parsed = raw ? JSON.parse(raw) : []
    return Array.isArray(parsed) ? parsed.filter((n) => typeof n === 'number').slice(0, 8) : []
  } catch {
    return []
  }
}

export function rememberCategory(key: string, id: number) {
  if (!id) return
  try {
    const next = [id, ...readRecentCategories(key).filter((n) => n !== id)].slice(0, 8)
    localStorage.setItem(key, JSON.stringify(next))
  } catch {
    // ignore
  }
}
