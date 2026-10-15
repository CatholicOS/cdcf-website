import { beforeEach, describe, expect, it, vi } from 'vitest'

// generateMetadata for every previewable route: in a preview session it must
// describe the draft (addressed by id) and give no canonical URL; otherwise
// it keeps the published lookup.

const activePreviewId = vi.hoisted(() => vi.fn())
vi.mock('@/lib/wordpress/preview', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/wordpress/preview')>()),
  activePreviewId,
}))

const api = vi.hoisted(() => ({
  getPage: vi.fn(),
  getPagePreview: vi.fn(),
  getPostBySlug: vi.fn(),
  getPostPreview: vi.fn(),
  getProject: vi.fn(),
  getProjectPreview: vi.fn(),
  getAcademicCollaboration: vi.fn(),
  getAcademicCollaborationPreview: vi.fn(),
  getAuthorProfile: vi.fn(),
  getPosts: vi.fn(),
  getProjects: vi.fn(),
  getSponsors: vi.fn(),
  getChildPages: vi.fn(),
}))
vi.mock('@/lib/wordpress/api', () => api)

// Rendering-only dependencies of the page modules.
vi.mock('next-intl/server', () => ({
  setRequestLocale: vi.fn(),
  getTranslations: vi.fn(),
}))
vi.mock('@/src/i18n/navigation', () => ({ Link: () => null }))
for (const path of [
  '@/components/sections/PageRenderer',
  '@/components/blog/ShareButtons',
  '@/components/blog/DisqusComments',
  '@/components/blog/AuthorBio',
  '@/components/JsonLd',
  '@/components/projects/RepoLanguages',
  '@/components/sections/GovernanceSection',
  '@/components/sections/ProjectGrid',
]) {
  vi.doMock(path, () => ({ default: () => null }))
}

const draft = {
  title: 'Draft title',
  excerpt: '<p>Draft excerpt</p>',
  content: '<p>Draft body</p>',
  uri: '/draft/',
  featuredImage: null,
  collaborationFields: { collabDescription: 'Draft collaboration' },
}

const routes = [
  {
    name: 'page',
    load: () => import('./[[...slug]]/page'),
    params: { lang: 'en', slug: ['1593'] },
    type: 'page',
    slug: '1593',
    preview: api.getPagePreview,
    published: api.getPage,
  },
  {
    name: 'blog post',
    load: () => import('./blog/[slug]/page'),
    params: { lang: 'en', slug: '825' },
    type: 'post',
    slug: '825',
    preview: api.getPostPreview,
    published: api.getPostBySlug,
  },
  {
    name: 'project',
    load: () => import('./projects/[slug]/page'),
    params: { lang: 'en', slug: '42' },
    type: 'project',
    slug: '42',
    preview: api.getProjectPreview,
    published: api.getProject,
  },
  {
    name: 'academic collaboration',
    load: () => import('./academic-collaborations/[slug]/page'),
    params: { lang: 'en', slug: '1593' },
    type: 'acad_collab',
    slug: '1593',
    preview: api.getAcademicCollaborationPreview,
    published: api.getAcademicCollaboration,
  },
]

beforeEach(() => {
  vi.clearAllMocks()
})

describe.each(routes)('generateMetadata for a $name', (route) => {
  it('describes the draft in a preview session, with no canonical URL', async () => {
    activePreviewId.mockResolvedValue(7)
    route.preview.mockResolvedValue(draft)
    const { generateMetadata } = await route.load()

    const meta = await generateMetadata({
      params: Promise.resolve(route.params),
    } as never)

    expect(activePreviewId).toHaveBeenCalledWith(route.type, route.slug)
    expect(route.preview).toHaveBeenCalledWith(7)
    expect(route.published).not.toHaveBeenCalled()
    expect(meta.title).toBe('Draft title')
    expect(meta.alternates).toBeUndefined()
  })

  it('keeps the published lookup outside preview', async () => {
    activePreviewId.mockResolvedValue(null)
    route.published.mockResolvedValue({ ...draft, title: 'Published title' })
    const { generateMetadata } = await route.load()

    const meta = await generateMetadata({
      params: Promise.resolve(route.params),
    } as never)

    expect(route.preview).not.toHaveBeenCalled()
    expect(meta.title).toBe('Published title')
    expect(meta.alternates?.canonical).toBeTruthy()
  })
})
