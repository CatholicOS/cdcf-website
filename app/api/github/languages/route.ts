import { NextRequest, NextResponse } from 'next/server'

const REPO_SEGMENT_PATTERN = /^[a-zA-Z0-9][a-zA-Z0-9._-]*$/

export async function GET(request: NextRequest) {
  const reposParam = request.nextUrl.searchParams.get('repos')
  if (!reposParam) {
    return NextResponse.json(
      { error: 'Missing "repos" query parameter' },
      { status: 400 }
    )
  }

  const repos = reposParam.split(',').filter(Boolean)
  if (repos.length === 0) {
    return NextResponse.json({})
  }

  // Validate all repo identifiers
  for (const repo of repos) {
    const [owner, name, extra] = repo.split('/')
    if (
      !owner ||
      !name ||
      extra ||
      !REPO_SEGMENT_PATTERN.test(owner) ||
      !REPO_SEGMENT_PATTERN.test(name) ||
      owner === '.' ||
      owner === '..' ||
      name === '.' ||
      name === '..'
    ) {
      return NextResponse.json(
        { error: `Invalid repo identifier: ${repo}` },
        { status: 400 }
      )
    }
  }

  // Cap at 10 repos per request to avoid abuse
  const capped = repos.slice(0, 10)

  const headers: Record<string, string> = {
    Accept: 'application/vnd.github.v3+json',
    'User-Agent': 'cdcf-website',
  }
  if (process.env.GITHUB_TOKEN) {
    headers.Authorization = `Bearer ${process.env.GITHUB_TOKEN}`
  }

  const results: Record<string, Record<string, number>> = {}

  await Promise.all(
    capped.map(async (repo) => {
      try {
        const [owner, name] = repo.split('/')
        const url = new URL('https://api.github.com/')
        url.pathname = [
          'repos',
          encodeURIComponent(owner),
          encodeURIComponent(name),
          'languages',
        ].join('/')
        const res = await fetch(url, {
          headers,
          signal: AbortSignal.timeout(8000),
          next: { revalidate: 3600 },
        })
        if (res.ok) {
          results[repo] = await res.json()
        }
      } catch {
        // Skip repos that fail
      }
    })
  )

  return NextResponse.json(results)
}
