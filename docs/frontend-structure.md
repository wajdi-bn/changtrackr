# Frontend Structure

Recommended structure:

```text
src/
  api/          API clients and axios setup
  app/          app providers, theme, query client
  assets/       static assets
  components/   shared UI components
  features/     domain modules
  hooks/        shared React hooks
  layouts/      application layouts
  pages/        route pages
  routes/       router definitions
  types/        shared TypeScript types
  utils/        formatters and helpers
```

Suggested feature modules:

- `auth`
- `organizations`
- `users`
- `stations`
- `connectors`
- `map`
- `alerts`
- `interventions`
- `sessions`
- `payments`
- `reports`
- `settings`
- `ai-assistant`

Implemented foundation:

- `src/api/httpClient.ts`: central Axios client using `VITE_API_URL`.
- `src/features/auth`: login API, auth provider, protected route and role menu config.
- `src/layouts/AppLayout.tsx`: Ant Design dashboard shell inspired by the prototype layout.
- `src/pages/LoginPage.tsx`: real backend login with seeded demo accounts.
