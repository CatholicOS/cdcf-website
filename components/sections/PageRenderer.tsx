import { getTranslations } from 'next-intl/server'
import type { WPPage, WPPost, WPProject, WPSponsor } from '@/lib/wordpress/types'
import HeroBanner from './HeroBanner'
import StatsBar from './StatsBar'
import ProjectGrid from './ProjectGrid'
import BlogFeed from './BlogFeed'
import SponsorGrid from './SponsorGrid'
import CommunitySection from './CommunitySection'
import GovernanceSection from './GovernanceSection'
import CallToAction from './CallToAction'
import TextSection from './TextSection'

interface PageRendererProps {
  page: WPPage
  posts?: WPPost[]
  projects?: WPProject[]
  sponsors?: WPSponsor[]
}

export default async function PageRenderer({
  page,
  posts = [],
  projects = [],
  sponsors = [],
}: PageRendererProps) {
  const template = page.template?.templateName || 'Default'
  const t = await getTranslations('about')

  return (
    <>
      {/* Hero — shared across all templates (only if meaningful content exists) */}
      {page.hero &&
        (page.hero.heroTagline ||
          page.hero.heroSubtitle ||
          page.hero.heroPrimaryBtnLabel ||
          page.hero.heroSecondaryBtnLabel) && <HeroBanner hero={page.hero} />}

      {/* Template-specific sections */}
      {template === 'Home' && renderHome(page, projects, sponsors)}
      {template === 'About' && renderAbout(page, t)}
      {template === 'Projects' && renderProjects(page, projects)}
      {template === 'Community' && renderCommunity(page)}
      {template === 'Blog' && renderBlog(page, posts)}
      {template === 'Contact' && renderContact(page)}
      {template === 'Default' && renderDefault(page)}

      {/* CTA — shared across templates that use it */}
      {page.cta?.ctaHeading && <CallToAction cta={page.cta} />}
    </>
  )
}

function renderHome(page: WPPage, projects: WPProject[], sponsors: WPSponsor[]) {
  const home = page.homeFields
  return (
    <>
      {home?.stats?.nodes && home.stats.nodes.length > 0 && (
        <StatsBar
          stats={home.stats.nodes}
          bgColor={home.statsBgColor?.[0] || 'navy'}
        />
      )}

      {home?.featuredProjects?.nodes && home.featuredProjects.nodes.length > 0 && (
        <ProjectGrid projects={home.featuredProjects.nodes} />
      )}

      {projects.length === 0 && sponsors.length > 0 && (
        <SponsorGrid sponsors={sponsors} />
      )}

      {sponsors.length > 0 && (
        <SponsorGrid sponsors={sponsors} />
      )}
    </>
  )
}

function renderAbout(page: WPPage, t: (key: string) => string) {
  const about = page.aboutFields
  return (
    <>
      {page.content && (
        <TextSection heading="" body={page.content} />
      )}

      {about?.teamMembers?.nodes && about.teamMembers.nodes.length > 0 && (
        <GovernanceSection
          title={t('boardOfDirectors')}
          members={about.teamMembers.nodes}
          columns={Number(about.governanceColumns?.[0]) || 3}
        />
      )}

      {about?.ecclesialCouncil?.nodes && about.ecclesialCouncil.nodes.length > 0 && (
        <GovernanceSection
          title={t('ecclesialAdvisoryCouncil')}
          members={about.ecclesialCouncil.nodes}
          columns={Number(about.governanceColumns?.[0]) || 3}
        />
      )}

      {about?.technicalCouncil?.nodes && about.technicalCouncil.nodes.length > 0 && (
        <GovernanceSection
          title={t('technicalAdvisoryCouncil')}
          members={about.technicalCouncil.nodes}
          columns={Number(about.governanceColumns?.[0]) || 3}
        />
      )}
    </>
  )
}

function renderProjects(page: WPPage, projects: WPProject[]) {
  const settings = page.projectsPageFields
  return (
    <ProjectGrid
      projects={projects}
      columns={Number(settings?.gridColumns?.[0]) || 3}
    />
  )
}

function renderCommunity(page: WPPage) {
  const community = page.communityFields
  return (
    <>
      {community?.channels?.nodes && community.channels.nodes.length > 0 && (
        <CommunitySection channels={community.channels.nodes} />
      )}

      {community?.members?.nodes && community.members.nodes.length > 0 && (
        <GovernanceSection members={community.members.nodes} />
      )}
    </>
  )
}

function renderBlog(page: WPPage, posts: WPPost[]) {
  return <BlogFeed posts={posts} />
}

function renderContact(page: WPPage) {
  const contact = page.contactFields
  return (
    <>
      {(contact?.contactBody || page.content) && (
        <TextSection
          heading=""
          body={contact?.contactBody || page.content || ''}
        />
      )}
    </>
  )
}

function renderDefault(page: WPPage) {
  if (!page.content) return null
  return <TextSection heading="" body={page.content} />
}
