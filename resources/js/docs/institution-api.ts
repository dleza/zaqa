import SwaggerUI from 'swagger-ui'
import 'swagger-ui/dist/swagger-ui.css'

SwaggerUI({
  dom_id: '#swagger-ui',
  url: '/docs/institution-api/openapi.yaml',
  docExpansion: 'list',
  deepLinking: true,
  persistAuthorization: true,
  displayRequestDuration: true,
  tryItOutEnabled: true,
})

