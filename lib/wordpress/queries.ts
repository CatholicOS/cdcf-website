// ─── Shared fragments ────────────────────────────────────────────────

const IMAGE_FRAGMENT = `
  sourceUrl
  altText
  mediaDetails {
    width
    height
  }
`

const HERO_FRAGMENT = `
  hero {
    heroBgStyle
    heroBgColor
    heroShowLogo
    heroAlignment
    heroTagline
    heroSubtitle
    heroBackgroundImage {
      node {
        ${IMAGE_FRAGMENT}
      }
    }
    heroPrimaryBtnLabel
    heroPrimaryBtnUrl
    heroSecondaryBtnLabel
    heroSecondaryBtnUrl
  }
`

const CTA_FRAGMENT = `
  cta {
    ctaStyle
    ctaHeading
    ctaDescription
    ctaPrimaryBtnLabel
    ctaPrimaryBtnUrl
    ctaSecondaryBtnLabel
    ctaSecondaryBtnUrl
  }
`

const PROJECT_FIELDS = `
  title
  slug
  content
  excerpt
  featuredImage {
    node {
      ${IMAGE_FRAGMENT}
    }
  }
  projectFields {
    projectStatus
    projectRepoUrl
    projectUrl
    projectLicense
    projectCategory
  }
`

const TEAM_MEMBER_FIELDS = `
  title
  content
  featuredImage {
    node {
      ${IMAGE_FRAGMENT}
    }
  }
  teamMemberFields {
    memberRole
    memberTitle
    memberLinkedinUrl
    memberGithubUrl
  }
`

const STAT_ITEM_FIELDS = `
  title
  statFields {
    statIcon
    statNumber
    statLabel
  }
`

const CHANNEL_FIELDS = `
  title
  channelFields {
    channelIcon
    channelUrl
    channelDescription
  }
`

const LOCAL_GROUP_FIELDS = `
  title
  localGroupFields {
    groupLocation
    groupUrl
    groupDescription
  }
`

const SPONSOR_FIELDS = `
  title
  featuredImage {
    node {
      ${IMAGE_FRAGMENT}
    }
  }
  sponsorFields {
    sponsorTier
    sponsorUrl
  }
`

// ─── Page query ──────────────────────────────────────────────────────

export const GET_PAGE_BY_SLUG = `
  query GetPage($slug: ID!, $language: LanguageCodeEnum!) {
    page(id: $slug, idType: URI) {
      title
      slug
      content
      template {
        templateName
      }
      translation(language: $language) {
        title
        slug
        content
        template {
          templateName
        }
        ${HERO_FRAGMENT}
        ${CTA_FRAGMENT}
        homeFields {
          featuredProjects {
            nodes {
              ... on Project {
                ${PROJECT_FIELDS}
              }
            }
          }
          stats {
            nodes {
              ... on StatItem {
                ${STAT_ITEM_FIELDS}
              }
            }
          }
          statsBgColor
        }
        aboutFields {
          teamMembers {
            nodes {
              ... on TeamMember {
                ${TEAM_MEMBER_FIELDS}
              }
            }
          }
          ecclesialCouncil {
            nodes {
              ... on TeamMember {
                ${TEAM_MEMBER_FIELDS}
              }
            }
          }
          technicalCouncil {
            nodes {
              ... on TeamMember {
                ${TEAM_MEMBER_FIELDS}
              }
            }
          }
          governanceColumns
        }
        projectsPageFields {
          showFilters
          gridColumns
        }
        communityFields {
          channels {
            nodes {
              ... on CommunityChannel {
                ${CHANNEL_FIELDS}
              }
            }
          }
          localGroups {
            nodes {
              ... on LocalGroup {
                ${LOCAL_GROUP_FIELDS}
              }
            }
          }
          members {
            nodes {
              ... on TeamMember {
                ${TEAM_MEMBER_FIELDS}
              }
            }
          }
        }
        blogFields {
          maxPosts
        }
        contactFields {
          contactBody
        }
      }
    }
  }
`

// ─── Posts query ─────────────────────────────────────────────────────

export const GET_POSTS = `
  query GetPosts($language: LanguageCodeFilterEnum, $first: Int = 10) {
    posts(where: { language: $language }, first: $first) {
      nodes {
        title
        slug
        date
        content
        excerpt
        featuredImage {
          node {
            ${IMAGE_FRAGMENT}
          }
        }
        author {
          node {
            name
          }
        }
        tags {
          nodes {
            name
          }
        }
        postSettings {
          hideFromBlog
        }
      }
    }
  }
`

// ─── Projects queries ───────────────────────────────────────────────

export const GET_PROJECTS = `
  query GetProjects($language: LanguageCodeFilterEnum) {
    projects(where: { language: $language }, first: 100) {
      nodes {
        ${PROJECT_FIELDS}
      }
    }
  }
`

export const GET_PROJECT_BY_SLUG = `
  query GetProject($slug: ID!, $language: LanguageCodeEnum!) {
    project(id: $slug, idType: SLUG) {
      translation(language: $language) {
        ${PROJECT_FIELDS}
      }
    }
  }
`

// ─── Sponsors query ──────────────────────────────────────────────────

export const GET_SPONSORS = `
  query GetSponsors($language: LanguageCodeFilterEnum) {
    sponsors(where: { language: $language }, first: 100) {
      nodes {
        ${SPONSOR_FIELDS}
      }
    }
  }
`

// ─── Single post by slug ────────────────────────────────────────────

export const GET_POST_BY_SLUG = `
  query GetPostBySlug($slug: ID!, $language: LanguageCodeEnum!) {
    post(id: $slug, idType: SLUG) {
      translation(language: $language) {
        title
        slug
        date
        content
        excerpt
        featuredImage {
          node {
            ${IMAGE_FRAGMENT}
          }
        }
        author {
          node {
            name
          }
        }
        tags {
          nodes {
            name
          }
        }
      }
    }
  }
`

// ─── All pages query (sitemap) ──────────────────────────────────────

export const GET_ALL_PAGES = `
  query GetAllPages($language: LanguageCodeFilterEnum) {
    pages(where: { language: $language }, first: 100) {
      nodes {
        slug
        uri
        modified
      }
    }
  }
`

// ─── Stat Items query ────────────────────────────────────────────────

export const GET_STAT_ITEMS = `
  query GetStatItems($language: LanguageCodeFilterEnum) {
    statItems(where: { language: $language }, first: 20) {
      nodes {
        ${STAT_ITEM_FIELDS}
      }
    }
  }
`
