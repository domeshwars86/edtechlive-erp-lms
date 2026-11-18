import { spawn } from 'node:child_process'
import { existsSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { apiDirectory, assertSafeLocalApiEnvironment, rootDirectory } from './setup-local-api.mjs'

const apiDatabase = path.join(apiDirectory, 'database', 'local-dev.sqlite')
const viteCli = path.join(rootDirectory, 'node_modules', 'vite', 'bin', 'vite.js')
const apiPort = Number(process.env.EDTECHLIVE_API_PORT || 8001)
const webPort = Number(process.env.EDTECHLIVE_WEB_PORT || 4173)
const children = []
let stopping = false

for (const [name, port] of [['EDTECHLIVE_API_PORT', apiPort], ['EDTECHLIVE_WEB_PORT', webPort]]) {
  if (!Number.isInteger(port) || port < 1024 || port > 65535) {
    process.stderr.write(`${name} must be an integer between 1024 and 65535.\n`)
    process.exit(1)
  }
}

function stop(code = 0) {
  if (stopping) return
  stopping = true
  for (const child of children) if (!child.killed) child.kill('SIGTERM')
  const timeout = setTimeout(() => process.exit(code), 2500)
  timeout.unref()
}

process.on('SIGINT', () => stop(0))
process.on('SIGTERM', () => stop(0))

try {
  assertSafeLocalApiEnvironment()
  if (!existsSync(apiDatabase)) throw new Error('Local database is missing. Run `npm run setup:local` first.')
  if (!existsSync(viteCli)) throw new Error('Web dependencies are missing. Run `npm install` in the repository root first.')

  const api = spawn(process.env.PHP_BINARY || 'php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${apiPort}`], {
    cwd: apiDirectory,
    env: {
      ...process.env, APP_ENV: 'local', DB_CONNECTION: 'sqlite', DB_DATABASE: apiDatabase, DB_URL: '',
      SESSION_DRIVER: 'file', CACHE_STORE: 'file', QUEUE_CONNECTION: 'sync',
    },
    stdio: 'inherit',
  })
  children.push(api)
  api.on('error', error => { process.stderr.write(`Laravel startup failed: ${error.message}\n`); stop(1) })
  api.on('exit', code => { if (!stopping) { process.stderr.write(`Laravel exited (${code ?? 'signal'}).\n`); stop(code ?? 1) } })

  const web = spawn(process.execPath, [viteCli, '--host=127.0.0.1', `--port=${webPort}`, '--strictPort'], {
    cwd: rootDirectory,
    env: { ...process.env, VITE_API_PROXY_TARGET: `http://127.0.0.1:${apiPort}` },
    stdio: 'inherit',
  })
  children.push(web)
  web.on('error', error => { process.stderr.write(`Vite startup failed: ${error.message}\n`); stop(1) })
  web.on('exit', code => { if (!stopping) { process.stderr.write(`Vite exited (${code ?? 'signal'}).\n`); stop(code ?? 1) } })
  process.stdout.write(`Starting the Laravel API at http://127.0.0.1:${apiPort} and website at http://127.0.0.1:${webPort}. Press Ctrl+C to stop both servers.\n`)
} catch (error) {
  process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`)
  stop(1)
}

