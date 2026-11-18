import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { pathToFileURL } from 'node:url'

const base = resolve('dist')
const serverEntry = resolve('dist-server/entry-server.js')
const { render } = await import(pathToFileURL(serverEntry).href)
const template = await readFile(resolve(base, 'index.html'), 'utf8')
const routes = [
  ['/', 'edTechLive — Learn. Teach. Manage. Grow.', 'Bring academic management, teaching and learning together in one thoughtful platform.'],
  ['/about', 'About edTechLive — Education, connected', 'Learn about the edTechLive purpose: a thoughtful shared space for institutions, educators and learners.'],
  ['/features', 'Platform Features — edTechLive', 'Explore the academic ERP and learning capabilities planned for edTechLive.'],
  ['/courses', 'Explore Courses — edTechLive', 'Browse sample courses and see how learning discovery can work in edTechLive.'],
  ['/solutions/institutions', 'For Institutions — edTechLive', 'A clearer view of academic planning, people and learning for educational institutions.'],
  ['/solutions/teachers', 'For Teachers — edTechLive', 'Bring lesson planning, live classes, assignments and learner progress into one workspace.'],
  ['/solutions/students', 'For Students — edTechLive', 'Find classes, course materials, assignments and results in a connected learning space.'],
  ['/pricing', 'Plans and Pricing — edTechLive', 'Review provisional edTechLive plan concepts. Paid subscriptions are not active.'],
  ['/contact', 'Contact edTechLive — Start a Conversation', 'Share your education platform needs with the edTechLive team.'],
  ['/privacy', 'Privacy Notice — edTechLive', 'Read the working privacy draft for edTechLive. Legal review is required before launch.'],
  ['/terms', 'Terms and Conditions — edTechLive', 'Read the working terms draft for edTechLive. Legal review is required before launch.'],
  ['/cookies', 'Cookie Notice — edTechLive', 'Read the working cookie notice draft for edTechLive.'],
  ['/acceptable-use', 'Acceptable Use — edTechLive', 'Read the working acceptable-use draft for edTechLive.'],
  ['/refund-policy', 'Refund Information — edTechLive', 'No paid plans are active on edTechLive. Read draft refund information.'],
]

for (const [route, title, description] of routes) {
  const markup = render(route)
  const html = template
    .replace('<div id="root"></div>', `<div id="root">${markup}</div>`)
    .replace(/<title>[^<]*<\/title>/, `<title>${title}</title>`)
    .replace(/<meta name="description" content="[^"]*"\s*\/>/, `<meta name="description" content="${description}" />`)
    .replace(/<meta property="og:title" content="[^"]*"\s*\/>/, `<meta property="og:title" content="${title}" />`)
    .replace(/<meta property="og:description" content="[^"]*"\s*\/>/, `<meta property="og:description" content="${description}" />`)
  const file = route === '/' ? resolve(base, 'index.html') : resolve(base, `.${route}/index.html`)
  await mkdir(dirname(file), { recursive: true })
  await writeFile(file, html)
}

console.log(`Pre-rendered ${routes.length} public routes.`)
