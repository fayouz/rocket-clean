// Manifest of the public cleaning page: installed from /m/<token>, the app opens that cleaning (no account needed).
export default defineEventHandler((event) => {
  const token = getRouterParam(event, 'token') ?? ''
  if (!/^[\w.-]{1,512}$/.test(token)) throw createError({ statusCode: 404 })
  setResponseHeader(event, 'X-Robots-Tag', 'noindex')
  setResponseHeader(event, 'Referrer-Policy', 'no-referrer')
  return sendManifest(event, `/m/${token}`)
})
