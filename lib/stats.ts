import { unstable_cache } from 'next/cache'
import { getProjects } from './wordpress/api'
import { locales } from '@/src/i18n/routing'

export interface SiteStats {
  projects: number
  contributors: number | null
  languages: number
}

const REPO_PATTERN = /github\.com\/([a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+)/

function extractRepoSlug(url: string): string | null {
  const match = url.match(REPO_PATTERN)
  if (!match) return null
  // Strip trailing .git if present
  return match[1].replace(/\.git$/, '')
}

interface GitHubContributor {
  login: string
}

async function fetchContributors(
  repo: string,
  headers: Record<string, string>
): Promise<string[]> {
  const logins: string[] = []
  let page = 1

  while (true) {
    const res = await fetch(
      `https://api.github.com/repos/${repo}/contributors?per_page=100&page=${page}`,
      { headers, next: { revalidate: 172800 } }
    )
    if (!res.ok) break

    const data: GitHubContributor[] = await res.json()
    if (data.length === 0) break

    for (const c of data) {
      if (c.login) logins.push(c.login)
    }

    if (data.length < 100) break
    page++
  }

  return logins
}

async function computeStats(): Promise<SiteStats> {
  // 1. Count active/incubating projects
  const projects = await getProjects('en')
  const activeProjects = projects.filter((p) => {
    const status = p.projectFields?.projectStatus?.[0]
    return status === 'incubating' || status === 'active'
  })

  // 2. Collect unique GitHub repos
  const repoSlugs = new Set<string>()
  for (const project of activeProjects) {
    if (project.projectRepoUrls) {
      for (const url of project.projectRepoUrls) {
        const slug = extractRepoSlug(url)
        if (slug) repoSlugs.add(slug)
      }
    }
    const singleUrl = project.projectFields?.projectRepoUrl
    if (singleUrl) {
      const slug = extractRepoSlug(singleUrl)
      if (slug) repoSlugs.add(slug)
    }
  }

  // 3. Fetch contributors from GitHub
  let contributors: number | null = null
  try {
    const headers: Record<string, string> = {
      Accept: 'application/vnd.github.v3+json',
      'User-Agent': 'cdcf-website',
    }
    if (process.env.GITHUB_TOKEN) {
      headers.Authorization = `Bearer ${process.env.GITHUB_TOKEN}`
    }

    const allLogins = new Set<string>()
    await Promise.all(
      Array.from(repoSlugs).map(async (repo) => {
        try {
          const logins = await fetchContributors(repo, headers)
          for (const login of logins) {
            allLogins.add(login)
          }
        } catch {
          // Skip repos that fail
        }
      })
    )
    contributors = allLogins.size
  } catch {
    // GitHub API failure — contributors will be null
  }

  return {
    projects: activeProjects.length,
    contributors,
    languages: locales.length,
  }
}

export const getStats = unstable_cache(computeStats, ['site-stats'], {
  revalidate: 172800,
  tags: ['site-stats'],
})
