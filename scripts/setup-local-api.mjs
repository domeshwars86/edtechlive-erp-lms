import { spawnSync } from 'node:child_process'
import { closeSync, existsSync, lstatSync, openSync, readFileSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

export const rootDirectory = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
export const apiDirectory = path.join(rootDirectory, 'apps', 'api')
export const localDatabase = path.join(apiDirectory, 'database', 'local-dev.sqlite')
const envFile = path.join(apiDirectory, '.env')

function parseEnv(contents) {
  return Object.fromEntries(contents.split(/\r?\n/).flatMap(line => {
    const match = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/)
    if (!match) return []
    return [[match[1], match[2].replace(/^(['"])(.*)\1$/, '$2')]]
  }))
}

export function assertSafeLocalApiEnvironment() {
  if (!existsSync(envFile)) throw new Error('API .env is missing. Run `npm run setup:local` first.')
  const config = parseEnv(readFileSync(envFile, 'utf8'))
  if (config.APP_ENV !== 'local') throw new Error('Local startup requires APP_ENV=local in apps/api/.env.')
  if (config.DB_CONNECTION !== 'sqlite') throw new Error('Local startup requires DB_CONNECTION=sqlite; it will not run against a server database.')
  if (config.DB_DATABASE !== 'database/local-dev.sqlite') throw new Error('Local startup requires DB_DATABASE=database/local-dev.sqlite to protect other databases.')
  if (config.DB_URL) throw new Error('Clear DB_URL in apps/api/.env; local startup requires the isolated SQLite file.')
  if (process.env.DB_URL) throw new Error('Clear the inherited DB_URL environment variable before local startup.')
  if (lstatSync(path.join(apiDirectory, 'database')).isSymbolicLink()) throw new Error('The API database directory cannot be a symbolic link for local setup.')
  if (existsSync(localDatabase) && lstatSync(localDatabase).isSymbolicLink()) throw new Error('The local SQLite database cannot be a symbolic link.')
  if (!existsSync(path.join(apiDirectory, 'vendor', 'autoload.php'))) throw new Error('Laravel dependencies are missing. Run `composer install` in apps/api first.')
  return config
}

function runPhp(args, env) {
  const result = spawnSync(process.env.PHP_BINARY || 'php', args, {
    cwd: apiDirectory,
    env: { ...process.env, ...env },
    stdio: 'inherit',
  })
  if (result.error) throw result.error
  if (result.status !== 0) process.exit(result.status ?? 1)
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    if (!existsSync(path.join(apiDirectory, 'vendor', 'autoload.php'))) {
      throw new Error('Laravel dependencies are missing. Run `composer install` in apps/api first.')
    }

    if (!existsSync(envFile)) {
      let template = readFileSync(path.join(apiDirectory, '.env.example'), 'utf8')
      template = template
        .replace(/^DB_CONNECTION=.*$/m, 'DB_CONNECTION=sqlite')
        .replace(/^DB_DATABASE=.*$/m, 'DB_DATABASE=database/local-dev.sqlite')
        .replace(/^SESSION_DRIVER=.*$/m, 'SESSION_DRIVER=file')
        .replace(/^QUEUE_CONNECTION=.*$/m, 'QUEUE_CONNECTION=sync')
        .replace(/^CACHE_STORE=.*$/m, 'CACHE_STORE=file')
        .replace(/^MAIL_MAILER=.*$/m, 'MAIL_MAILER=log')
      writeFileSync(envFile, template, { flag: 'wx' })
      process.stdout.write('Created apps/api/.env for local SQLite development.\n')
    }

    const config = assertSafeLocalApiEnvironment()
    if (!config.APP_KEY) {
      runPhp(['artisan', 'key:generate', '--force'], {
        APP_ENV: 'local', DB_CONNECTION: 'sqlite', DB_DATABASE: localDatabase, DB_URL: '',
      })
    }

    if (!existsSync(localDatabase)) {
      const file = openSync(localDatabase, 'wx')
      closeSync(file)
      process.stdout.write('Created apps/api/database/local-dev.sqlite.\n')
    }

    runPhp(['artisan', 'migrate', '--force'], {
      APP_ENV: 'local', DB_CONNECTION: 'sqlite', DB_DATABASE: localDatabase, DB_URL: '',
      SESSION_DRIVER: 'file', CACHE_STORE: 'file', QUEUE_CONNECTION: 'sync',
    })
    process.stdout.write('Local API is ready. Run `npm run dev:stack` to start the API and website together.\n')
  } catch (error) {
    process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`)
    process.exit(1)
  }
}
