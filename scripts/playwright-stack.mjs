import { spawn, spawnSync } from 'node:child_process'
import { existsSync, mkdirSync, mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const apiDirectory = path.join(root, 'apps', 'api')
const viteCli = path.join(root, 'node_modules', 'vite', 'bin', 'vite.js')
const apiPort = 4189
const webPort = 4188
const temporaryDirectory = mkdtempSync(path.join(tmpdir(), 'edtechlive-playwright-'))
const sessionsDirectory = path.join(temporaryDirectory, 'sessions')
const databasePath = path.join(temporaryDirectory, 'api.sqlite')
const apiUrl = `http://127.0.0.1:${apiPort}`
const webUrl = `http://127.0.0.1:${webPort}`
const apiEnvironment = {
  ...process.env,
  APP_ENV: 'local',
  APP_DEBUG: 'false',
  APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
  APP_URL: apiUrl,
  FRONTEND_URL: webUrl,
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: databasePath,
  DB_URL: '',
  CACHE_STORE: 'array',
  QUEUE_CONNECTION: 'sync',
  SESSION_DRIVER: 'file',
  SESSION_FILES: sessionsDirectory,
  SESSION_COOKIE: 'edtechlive_playwright_session',
  SESSION_DOMAIN: '',
  MAIL_MAILER: 'array',
  SANCTUM_STATEFUL_DOMAINS: `${new URL(webUrl).host}`,
}
const children = []
let stopping = false

function cleanup() {
  const tempRoot = path.resolve(tmpdir())
  const target = path.resolve(temporaryDirectory)
  if (!target.startsWith(`${tempRoot}${path.sep}`) || !path.basename(target).startsWith('edtechlive-playwright-')) {
    throw new Error('Refusing to remove a path outside this Playwright temporary directory.')
  }
  rmSync(target, { recursive: true, force: true })
}

function stop(code = 0) {
  if (stopping) return
  stopping = true
  for (const child of children) if (!child.killed) child.kill('SIGTERM')
  setTimeout(() => {
    cleanup()
    process.exit(code)
  }, 750)
}

async function waitForApi() {
  const deadline = Date.now() + 30_000
  while (Date.now() < deadline) {
    try {
      const response = await fetch(`${apiUrl}/api/v1/health/ready`)
      if (response.ok) return
    } catch {
      // Laravel is still starting.
    }
    await new Promise(resolve => setTimeout(resolve, 250))
  }
  throw new Error('The isolated Laravel API did not become ready in 30 seconds.')
}

function start(command, args, options) {
  const child = spawn(command, args, { ...options, stdio: 'inherit' })
  children.push(child)
  child.on('error', error => {
    process.stderr.write(`${command} could not start: ${error.message}\n`)
    stop(1)
  })
  child.on('exit', code => {
    if (!stopping) {
      process.stderr.write(`${command} exited (${code ?? 'signal'}).\n`)
      stop(code ?? 1)
    }
  })
  return child
}

process.on('SIGINT', () => stop(0))
process.on('SIGTERM', () => stop(0))

try {
  if (!existsSync(viteCli)) throw new Error('Web dependencies are missing; run `npm install` first.')
  mkdirSync(sessionsDirectory, { recursive: true })

  const migration = spawnSync(process.env.PHP_BINARY || 'php', ['artisan', 'migrate', '--force'], {
    cwd: apiDirectory,
    env: apiEnvironment,
    stdio: 'inherit',
  })
  if (migration.error) throw migration.error
  if (migration.status !== 0) throw new Error(`Isolated API migration failed (${migration.status ?? 'signal'}).`)

  start(process.env.PHP_BINARY || 'php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${apiPort}`], {
    cwd: apiDirectory,
    env: apiEnvironment,
  })
  await waitForApi()
  start(process.execPath, [viteCli, '--host=127.0.0.1', `--port=${webPort}`, '--strictPort'], {
    cwd: root,
    env: { ...process.env, VITE_API_PROXY_TARGET: apiUrl },
  })
  process.stdout.write(`Playwright uses an isolated SQLite database. API: ${apiUrl}; web: ${webUrl}.\n`)
} catch (error) {
  process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`)
  stop(1)
}
