import { readFile, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'

const siteUrl = process.env.EDTECHLIVE_SITE_URL?.trim()
const outputDirectory = resolve('dist')

if (!siteUrl) {
  console.warn('EDTECHLIVE_SITE_URL is not set; omitting the sitemap until a canonical HTTPS domain is configured.')
  process.exit(0)
}

let canonicalOrigin
try {
  const parsed = new URL(siteUrl)
  if (
    parsed.protocol !== 'https:' ||
    parsed.username ||
    parsed.password ||
    parsed.pathname !== '/' ||
    parsed.search ||
    parsed.hash ||
    /(?:^|\.)(?:example|invalid|localhost|test)$/.test(parsed.hostname)
  ) {
    throw new Error('Use a public HTTPS origin without a path, query, credentials, or reserved hostname.')
  }
  canonicalOrigin = parsed.origin
} catch (error) {
  console.error(`Invalid EDTECHLIVE_SITE_URL: ${error instanceof Error ? error.message : 'invalid URL'}`)
  process.exit(1)
}

const publicRoutes = [
  '/',
  '/about',
  '/features',
  '/courses',
  '/courses/python-foundations',
  '/courses/algebra-step-by-step',
  '/courses/living-world-stories',
  '/solutions/institutions',
  '/solutions/teachers',
  '/solutions/students',
  '/pricing',
  '/contact',
  '/privacy',
  '/terms',
  '/cookies',
  '/acceptable-use',
  '/refund-policy',
]

const urls = publicRoutes
  .map((route) => `  <url><loc>${canonicalOrigin}${route}</loc></url>`)
  .join('\n')
const sitemap = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls}\n</urlset>\n`
await writeFile(resolve(outputDirectory, 'sitemap.xml'), sitemap)

const robotsPath = resolve(outputDirectory, 'robots.txt')
const robots = await readFile(robotsPath, 'utf8')
const withoutSitemap = robots.replace(/^Sitemap:.*\r?\n?/gim, '').trimEnd()
await writeFile(robotsPath, `${withoutSitemap}\nSitemap: ${canonicalOrigin}/sitemap.xml\n`)

console.log(`Generated sitemap.xml for ${publicRoutes.length} public routes at ${canonicalOrigin}.`)
