import { getTranslations } from 'next-intl/server'
import type { WPPage, WPPost, WPProject, WPSponsor } from '@/lib/wordpress/types'
import { getStats } from '@/lib/stats'
import HeroBanner from './HeroBanner'
import StatsBar from './StatsBar'
import ProjectGrid from './ProjectGrid'
import BlogFeed from './BlogFeed'
import SponsorGrid from './SponsorGrid'
import CommunitySection from './CommunitySection'
import LocalGroupsSection from './LocalGroupsSection'
import AcademicCollaborationsSection from './AcademicCollaborationsSection'
import GovernanceSection from './GovernanceSection'
import CommunityProjectsSection from './CommunityProjectsSection'
import CallToAction from './CallToAction'
import TextSection from './TextSection'

interface PageRendererProps {
  page: WPPage
  posts?: WPPost[]
  projects?: WPProject[]
  sponsors?: WPSponsor[]
  isLogoSymbolism?: boolean
  fishExplanationHtml?: string
}

export default async function PageRenderer({
  page,
  posts = [],
  projects = [],
  sponsors = [],
  isLogoSymbolism,
  fishExplanationHtml,
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
      {template === 'Home' && (await renderHome(page, sponsors))}
      {template === 'About' && renderAbout(page, t)}
      {template === 'Projects' && renderProjects(page, projects, await getTranslations('projects'))}
      {template === 'Community' && renderCommunity(page, await getTranslations('community'))}
      {template === 'Blog' && renderBlog(page, posts)}
      {template === 'Contact' && renderContact(page)}
      {template === 'Default' && renderDefault(page, isLogoSymbolism, fishExplanationHtml)}

      {/* CTA — shared across templates that use it */}
      {page.cta?.ctaHeading && <CallToAction cta={page.cta} />}
    </>
  )
}

async function renderHome(_page: WPPage, sponsors: WPSponsor[]) {
  const stats = await getStats()
  return (
    <>
      <StatsBar stats={stats} />

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
          id="board-of-directors"
          title={t('boardOfDirectors')}
          members={about.teamMembers.nodes}
          columns={Number(about.governanceColumns?.[0]) || 3}
        />
      )}

      {about?.ecclesialCouncil?.nodes && about.ecclesialCouncil.nodes.length > 0 && (
        <GovernanceSection
          id="ecclesial-advisory-council"
          title={t('ecclesialAdvisoryCouncil')}
          members={about.ecclesialCouncil.nodes}
          columns={Number(about.governanceColumns?.[0]) || 3}
        />
      )}

      {about?.technicalCouncil?.nodes && about.technicalCouncil.nodes.length > 0 && (
        <GovernanceSection
          id="technical-advisory-council"
          title={t('technicalAdvisoryCouncil')}
          members={about.technicalCouncil.nodes}
          columns={Number(about.governanceColumns?.[0]) || 3}
        />
      )}
    </>
  )
}

function renderProjects(page: WPPage, projects: WPProject[], t: (key: string) => string) {
  const settings = page.projectsPageFields
  const communityProjects = settings?.communityProjects?.nodes ?? []
  return (
    <>
      <ProjectGrid
        id="cdcf-projects"
        projects={projects}
        columns={Number(settings?.gridColumns?.[0]) || 3}
        submitButtonLabel={t('submitButton')}
      />
      <CommunityProjectsSection
        id="community-projects"
        projects={communityProjects}
        heading={t('communityProjectsHeading')}
        intro={t('communityProjectsIntro')}
        columns={Number(settings?.gridColumns?.[0]) || 3}
        referButtonLabel={t('referButton')}
      />
    </>
  )
}

function renderCommunity(page: WPPage, t: (key: string) => string) {
  const community = page.communityFields
  const academicCollaborations = community?.academicCollaborations?.nodes ?? []
  return (
    <>
      {community?.channels?.nodes && community.channels.nodes.length > 0 && (
        <CommunitySection
          id="online-communities"
          channels={community.channels.nodes}
          heading={t('onlineHeading')}
          intro={t('onlineIntro')}
        />
      )}

      {community?.localGroups?.nodes && community.localGroups.nodes.length > 0 && (
        <LocalGroupsSection
          id="local-groups"
          groups={community.localGroups.nodes}
          heading={t('localGroupsHeading')}
          intro={t('localGroupsIntro')}
          referButtonLabel={t('referButton')}
        />
      )}

      {academicCollaborations.length > 0 && (
        <AcademicCollaborationsSection
          id="academic-collaborations"
          collaborations={academicCollaborations}
          heading={t('academicCollaborationsHeading')}
          intro={t('academicCollaborationsIntro')}
        />
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

function renderDefault(page: WPPage, isLogoSymbolism?: boolean, fishExplanationHtml?: string) {
  if (!page.content) return null
  return (
    <TextSection
      heading=""
      body={page.content}
      width="full"
      isLogoSymbolism={isLogoSymbolism}
      fishExplanationHtml={fishExplanationHtml}
    />
  )
}
