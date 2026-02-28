// ─── Shared types ────────────────────────────────────────────────────

export interface WPImage {
  sourceUrl: string
  altText: string
  mediaDetails?: {
    width: number
    height: number
  }
}

export interface WPFeaturedImage {
  node: WPImage
}

// ─── Hero ACF fields ─────────────────────────────────────────────────

export interface HeroFields {
  heroBgStyle: string[] | null
  heroBgColor: string | null
  heroShowLogo: boolean
  heroAlignment: string[] | null
  heroTagline: string | null
  heroSubtitle: string | null
  heroBackgroundImage: { node: WPImage } | null
  heroPrimaryBtnLabel: string | null
  heroPrimaryBtnUrl: string | null
  heroSecondaryBtnLabel: string | null
  heroSecondaryBtnUrl: string | null
}

// ─── CTA ACF fields ─────────────────────────────────────────────────

export interface CTAFields {
  ctaStyle: string[] | null
  ctaHeading: string | null
  ctaDescription: string | null
  ctaPrimaryBtnLabel: string | null
  ctaPrimaryBtnUrl: string | null
  ctaSecondaryBtnLabel: string | null
  ctaSecondaryBtnUrl: string | null
}

// ─── CPT types ───────────────────────────────────────────────────────

export interface WPProject {
  title: string
  slug: string
  content: string | null
  excerpt: string | null
  featuredImage: WPFeaturedImage | null
  projectFields: {
    projectStatus: string[] | null
    projectRepoUrl: string | null
    projectUrl: string | null
    projectLicense: string | null
    projectCategory: string | null
  }
}

export interface WPTeamMember {
  title: string
  content: string | null
  featuredImage: WPFeaturedImage | null
  teamMemberFields: {
    memberRole: string | null
    memberTitle: string | null
    memberLinkedinUrl: string | null
    memberGithubUrl: string | null
  }
}

export interface WPSponsor {
  title: string
  featuredImage: WPFeaturedImage | null
  sponsorFields: {
    sponsorTier: string[] | null
    sponsorUrl: string | null
  }
}

export interface WPCommunityChannel {
  title: string
  channelFields: {
    channelIcon: string | null
    channelUrl: string | null
    channelDescription: string | null
  }
}

export interface WPStatItem {
  title: string
  statFields: {
    statIcon: string | null
    statNumber: string | null
    statLabel: string | null
  }
}

// ─── Page types ──────────────────────────────────────────────────────

export interface WPPost {
  title: string
  slug: string
  date: string
  content: string | null
  excerpt: string | null
  featuredImage: WPFeaturedImage | null
  author: {
    node: {
      name: string
    }
  }
  tags: {
    nodes: { name: string }[]
  }
}

export interface WPPage {
  title: string
  slug: string
  content: string | null
  template: {
    templateName: string
  }
  hero: HeroFields | null
  cta: CTAFields | null
  homeFields: {
    featuredProjects: { nodes: WPProject[] } | null
    stats: { nodes: WPStatItem[] } | null
    statsBgColor: string[] | null
  } | null
  aboutFields: {
    teamMembers: { nodes: WPTeamMember[] } | null
    ecclesialCouncil: { nodes: WPTeamMember[] } | null
    technicalCouncil: { nodes: WPTeamMember[] } | null
    governanceColumns: string[] | null
  } | null
  projectsPageFields: {
    showFilters: boolean | null
    gridColumns: string[] | null
  } | null
  communityFields: {
    channels: { nodes: WPCommunityChannel[] } | null
    members: { nodes: WPTeamMember[] } | null
  } | null
  blogFields: {
    maxPosts: number | null
  } | null
  contactFields: {
    contactBody: string | null
  } | null
}
