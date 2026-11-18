import { renderToString } from 'react-dom/server'
import { StaticRouter } from 'react-router-dom'
import App from './App'
import { AuthProvider } from './auth/AuthContext'

export function render(location: string) {
  return renderToString(<StaticRouter location={location}><AuthProvider><App /></AuthProvider></StaticRouter>)
}
