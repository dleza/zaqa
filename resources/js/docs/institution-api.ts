// Swagger UI integration notes:
// - We intentionally load the UMD bundle as a static asset URL (instead of importing `swagger-ui` as an ES module)
//   to avoid React version/peer-dependency issues that can cause blank pages at runtime.
// - This page documents only institution-facing endpoints (see `resources/openapi/institution-api.yaml`).
// NOTE: We import the Swagger UI dist assets via a relative path into `node_modules` to bypass
// package "exports" restrictions on deep imports.
import swaggerBundleUrl from '../../../node_modules/swagger-ui/dist/swagger-ui-bundle.js?url'
import swaggerCssUrl from '../../../node_modules/swagger-ui/dist/swagger-ui.css?url'

function init() {
  const mount = document.querySelector('#swagger-ui')
  if (!mount) return

  const css = document.createElement('link')
  css.rel = 'stylesheet'
  css.href = swaggerCssUrl
  document.head.appendChild(css)

  const script = document.createElement('script')
  script.src = swaggerBundleUrl
  script.async = true
  script.onload = () => {
    const SwaggerUIBundle = (window as any).SwaggerUIBundle as any
    if (typeof SwaggerUIBundle !== 'function') {
      // eslint-disable-next-line no-console
      console.error('SwaggerUIBundle did not load as a function.')
      return
    }

    SwaggerUIBundle({
      dom_id: '#swagger-ui',
      url: '/docs/institution-api/openapi.yaml',
      docExpansion: 'list',
      deepLinking: true,
      persistAuthorization: true,
      displayRequestDuration: true,
      tryItOutEnabled: true,
    })
  }
  document.head.appendChild(script)
}

if (document.readyState === 'loading') {
  window.addEventListener('DOMContentLoaded', init, { once: true })
} else {
  init()
}
