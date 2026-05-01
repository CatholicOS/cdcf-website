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
    projectLeads(first: 50) {
      nodes {
        ... on TeamMember {
          ${TEAM_MEMBER_FIELDS}
        }
      }
    }
  }
  projectRepoUrls
  projectTags {
    nodes {
      name
    }
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

const ACADEMIC_COLLABORATION_CARD_FIELDS = `
  title
  slug
  featuredImage {
    node {
      ${IMAGE_FRAGMENT}
    }
  }
  collaborationFields {
    collabUniversity
    collabDepartment
    collabLocation
    collabDescription
  }
`

const ACADEMIC_COLLABORATION_FIELDS = `
  title
  slug
  content
  featuredImage {
    node {
      ${IMAGE_FRAGMENT}
    }
  }
  collaborationFields {
    collabUniversity
    collabDepartment
    collabLocation
    collabDescription
    collabWebsiteUrl
    collabProjects(first: 50) {
      nodes {
        ... on Project {
          ${PROJECT_FIELDS}
        }
      }
    }
    collabGovernance(first: 50) {
      nodes {
        ... on TeamMember {
          ${TEAM_MEMBER_FIELDS}
        }
      }
    }
  }
`

const COMMUNITY_PROJECT_FIELDS = `
  title
  slug
  content
  excerpt
  featuredImage {
    node {
      ${IMAGE_FRAGMENT}
    }
  }
  communityProjectFields {
    projectCategory
    projectUrl
    projectGithubUrl
  }
  projectTags {
    nodes {
      name
    }
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
        databaseId
        title
        slug
        content
        template {
          templateName
        }
        ${HERO_FRAGMENT}
        ${CTA_FRAGMENT}
        aboutFields {
          teamMembers(first: 50) {
            nodes {
              ... on TeamMember {
                ${TEAM_MEMBER_FIELDS}
              }
            }
          }
          ecclesialCouncil(first: 50) {
            nodes {
              ... on TeamMember {
                ${TEAM_MEMBER_FIELDS}
              }
            }
          }
          technicalCouncil(first: 50) {
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
          communityProjects(first: 50) {
            nodes {
              ... on CommunityProject {
                ${COMMUNITY_PROJECT_FIELDS}
              }
            }
          }
        }
        communityFields {
          channels(first: 50) {
            nodes {
              ... on CommunityChannel {
                ${CHANNEL_FIELDS}
              }
            }
          }
          localGroups(first: 50) {
            nodes {
              ... on LocalGroup {
                ${LOCAL_GROUP_FIELDS}
              }
            }
          }
          members(first: 50) {
            nodes {
              ... on TeamMember {
                ${TEAM_MEMBER_FIELDS}
              }
            }
          }
          academicCollaborations(first: 50) {
            nodes {
              ... on AcademicCollaboration {
                ${ACADEMIC_COLLABORATION_CARD_FIELDS}
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

// ─── Academic Collaboration detail query ────────────────────────────

export const GET_ACADEMIC_COLLABORATION_BY_SLUG = `
  query GetAcademicCollaboration($slug: ID!, $language: LanguageCodeEnum!) {
    academicCollaboration(id: $slug, idType: SLUG) {
      translation(language: $language) {
        ${ACADEMIC_COLLABORATION_FIELDS}
      }
    }
  }
`

// ─── Child pages query (Governance TOC) ─────────────────────────────

export const GET_CHILD_PAGES = `
  query GetChildPages($parentId: ID!, $language: LanguageCodeFilterEnum) {
    pages(where: { parent: $parentId, language: $language, orderby: { field: MENU_ORDER, order: ASC } }, first: 50) {
      nodes {
        title
        slug
        uri
        modified
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
        translations {
          language { code }
          uri
        }
      }
    }
  }
`

