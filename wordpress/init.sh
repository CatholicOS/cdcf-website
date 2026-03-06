#!/bin/sh
set -e

echo "=== CDCF WordPress Initialization ==="

# ── Wait for WordPress files (extracted by the wordpress container) ──

echo "Waiting for WordPress files..."
while [ ! -f /var/www/html/wp-includes/version.php ]; do
  sleep 3
done

# ── Wait for database ──

echo "Waiting for database..."
tries=0
until wp db check --allow-root --quiet 2>/dev/null; do
  tries=$((tries + 1))
  if [ $tries -ge 30 ]; then
    echo "ERROR: Database not ready after 90 seconds."
    exit 1
  fi
  sleep 3
done

# ── Idempotency check ──

if wp core is-installed --allow-root 2>/dev/null; then
  echo "WordPress is already installed. Skipping."
  exit 0
fi

# ── Install WordPress core ──

echo "Installing WordPress core..."
wp core install \
  --url="${WP_URL:-http://localhost}" \
  --title="Catholic Digital Commons Foundation" \
  --admin_user="${WP_ADMIN_USER:-admin}" \
  --admin_password="${WP_ADMIN_PASSWORD:-admin}" \
  --admin_email="${WP_ADMIN_EMAIL:-admin@cdcf.dev}" \
  --skip-email \
  --allow-root

wp rewrite structure '/%postname%/' --hard --allow-root

# Enable public GraphQL introspection (needed for schema tools and debugging)
wp option update graphql_general_settings '{"public_introspection_enabled":"on"}' --format=json --allow-root 2>/dev/null || true

# ── Install Plugins ──

echo "Installing plugins..."

# WordPress.org plugins
wp plugin install wp-graphql --activate --allow-root
wp plugin install advanced-custom-fields --activate --allow-root
wp plugin install polylang --activate --allow-root

# WPGraphQL for ACF (now distributed via WordPress.org since monorepo migration)
wp plugin install wpgraphql-acf --activate --allow-root

# GitHub-hosted plugins

echo "Installing WPGraphQL Polylang..."
wp plugin install \
  "https://github.com/valu-digital/wp-graphql-polylang/archive/refs/heads/master.zip" \
  --activate --allow-root 2>&1 \
  || echo "  NOTE: WPGraphQL Polylang auto-install failed — install manually from GitHub."

echo "Installing Redis Queue..."
wp plugin install \
  "https://github.com/soderlind/redis-queue/releases/latest/download/redis-queue.zip" \
  --activate --allow-root 2>&1 \
  || echo "  NOTE: Redis Queue auto-install failed — Redis translations will fall back to WP Cron."

echo "Activating CDCF Redis Translations..."
wp plugin activate cdcf-redis-translations --allow-root 2>&1 \
  || echo "  NOTE: cdcf-redis-translations activation failed."

# ── Activate Theme ──

echo "Activating theme..."
wp theme activate cdcf-headless --allow-root

# ── Configure Polylang Languages ──

echo "Configuring languages..."
# Use Polylang PHP API directly (wp pll commands require wizard completion)
wp eval "
if ( ! function_exists('PLL') ) { echo 'Polylang not loaded, skipping languages.'; return; }
\$langs = [
    ['name' => 'English',    'slug' => 'en', 'locale' => 'en_US', 'flag' => 'us'],
    ['name' => 'Italiano',   'slug' => 'it', 'locale' => 'it_IT', 'flag' => 'it'],
    ['name' => 'Español',    'slug' => 'es', 'locale' => 'es_ES', 'flag' => 'es'],
    ['name' => 'Français',   'slug' => 'fr', 'locale' => 'fr_FR', 'flag' => 'fr'],
    ['name' => 'Português',  'slug' => 'pt', 'locale' => 'pt_BR', 'flag' => 'br'],
    ['name' => 'Deutsch',    'slug' => 'de', 'locale' => 'de_DE', 'flag' => 'de'],
];
\$order = 0;
foreach ( \$langs as \$lang ) {
    \$result = PLL()->model->add_language( array_merge( \$lang, [
        'rtl'        => 0,
        'term_group' => \$order++,
    ]));
    if ( is_wp_error(\$result) ) {
        echo \$lang['name'] . ': ' . \$result->get_error_message() . PHP_EOL;
    } else {
        echo \$lang['name'] . ' created.' . PHP_EOL;
    }
}
" --allow-root 2>/dev/null || echo "  Language setup skipped."

# ── Create Pages ──

echo "Creating pages..."

HOME_ID=$(wp post create --post_type=page --post_title="Home" --post_name="home" --post_status=publish --porcelain --allow-root)
wp post meta update "$HOME_ID" _wp_page_template templates/home.php --allow-root

ABOUT_ID=$(wp post create --post_type=page --post_title="About" --post_name="about" --post_status=publish --porcelain --allow-root)
wp post meta update "$ABOUT_ID" _wp_page_template templates/about.php --allow-root

PROJECTS_ID=$(wp post create --post_type=page --post_title="Projects" --post_name="projects" --post_status=publish --porcelain --allow-root)
wp post meta update "$PROJECTS_ID" _wp_page_template templates/projects.php --allow-root

COMMUNITY_ID=$(wp post create --post_type=page --post_title="Community" --post_name="community" --post_status=publish --porcelain --allow-root)
wp post meta update "$COMMUNITY_ID" _wp_page_template templates/community.php --allow-root

BLOG_ID=$(wp post create --post_type=page --post_title="Blog" --post_name="blog" --post_status=publish --porcelain --allow-root)
wp post meta update "$BLOG_ID" _wp_page_template templates/blog.php --allow-root

CONTACT_ID=$(wp post create --post_type=page --post_title="Contact" --post_name="contact" --post_status=publish --porcelain --allow-root)
wp post meta update "$CONTACT_ID" _wp_page_template templates/contact.php --allow-root

# Static front page settings
wp option update show_on_front page --allow-root
wp option update page_on_front "$HOME_ID" --allow-root
# Note: Do NOT set page_for_posts — it strips the page's URI in WPGraphQL,
# making it unqueryable. The Next.js frontend fetches posts via GraphQL independently.

# ── Seed ACF Content ──

echo "Seeding page content..."

wp eval "
if (!function_exists('update_field')) { echo 'ACF not loaded, skipping field seeding.'; return; }

// ── Home page ──
update_field('hero_bg_style',           'gradient',                                         ${HOME_ID});
update_field('hero_show_logo',          true,                                               ${HOME_ID});
update_field('hero_alignment',          'center',                                           ${HOME_ID});
update_field('hero_tagline',            'Catholic Digital Commons Foundation',               ${HOME_ID});
update_field('hero_subtitle',           '<p>Building open-source tools and fostering collaboration for the Catholic digital community.</p>', ${HOME_ID});
update_field('hero_primary_btn_label',  'Explore Projects',                                 ${HOME_ID});
update_field('hero_primary_btn_url',    '/projects',                                        ${HOME_ID});
update_field('hero_secondary_btn_label','Join the Community',                               ${HOME_ID});
update_field('hero_secondary_btn_url',  '/community',                                      ${HOME_ID});
update_field('cta_style',              'banner',                                            ${HOME_ID});
update_field('cta_heading',            'Join Us in Building the Catholic Digital Commons',  ${HOME_ID});
update_field('cta_description',        '<p>Whether you are a developer, designer, translator, or simply passionate about the Church\\'s digital mission, there is a place for you.</p>', ${HOME_ID});
update_field('cta_primary_btn_label',  'Get Involved',                                     ${HOME_ID});
update_field('cta_primary_btn_url',    '/community',                                       ${HOME_ID});
update_field('stats_bg_color',         'navy',                                              ${HOME_ID});

// ── About page ──
update_field('hero_bg_style',          'gradient',  ${ABOUT_ID});
update_field('hero_show_logo',         false,       ${ABOUT_ID});
update_field('hero_alignment',         'center',    ${ABOUT_ID});
update_field('hero_tagline',           'About CDCF', ${ABOUT_ID});
update_field('hero_subtitle',          '<p>Learn about our mission, governance, and the people behind the Catholic Digital Commons Foundation.</p>', ${ABOUT_ID});
update_field('cta_style',             'card',       ${ABOUT_ID});
update_field('cta_heading',           'Want to Contribute?', ${ABOUT_ID});
update_field('cta_description',       '<p>We welcome contributions from developers, translators, designers, and anyone passionate about Catholic digital tools.</p>', ${ABOUT_ID});
update_field('cta_primary_btn_label', 'See Open Issues', ${ABOUT_ID});
update_field('cta_primary_btn_url',   'https://github.com/CatholicOS-org', ${ABOUT_ID});

// ── Projects page ──
update_field('hero_bg_style',  'gradient',       ${PROJECTS_ID});
update_field('hero_show_logo', false,            ${PROJECTS_ID});
update_field('hero_alignment', 'center',         ${PROJECTS_ID});
update_field('hero_tagline',   'Our Projects',   ${PROJECTS_ID});
update_field('hero_subtitle',  '<p>Explore open-source projects built by and for the Catholic community.</p>', ${PROJECTS_ID});
update_field('show_filters',   true,             ${PROJECTS_ID});
update_field('grid_columns',   '3',              ${PROJECTS_ID});
update_field('cta_style',             'banner',  ${PROJECTS_ID});
update_field('cta_heading',           'Have a Project Idea?', ${PROJECTS_ID});
update_field('cta_description',       '<p>We are always looking for new projects that serve the Catholic community. Submit your proposal or contribute to an existing project.</p>', ${PROJECTS_ID});
update_field('cta_primary_btn_label', 'Propose a Project', ${PROJECTS_ID});
update_field('cta_primary_btn_url',   '/contact', ${PROJECTS_ID});

// ── Community page ──
update_field('hero_bg_style',  'gradient',       ${COMMUNITY_ID});
update_field('hero_show_logo', false,            ${COMMUNITY_ID});
update_field('hero_alignment', 'center',         ${COMMUNITY_ID});
update_field('hero_tagline',   'Community',      ${COMMUNITY_ID});
update_field('hero_subtitle',  '<p>Connect with Catholic developers, designers, and digital missionaries from around the world.</p>', ${COMMUNITY_ID});
update_field('cta_style',             'banner',  ${COMMUNITY_ID});
update_field('cta_heading',           'Stay Connected', ${COMMUNITY_ID});
update_field('cta_description',       '<p>Follow us on social media and join our mailing list for updates on projects, events, and opportunities.</p>', ${COMMUNITY_ID});
update_field('cta_primary_btn_label', 'Subscribe', ${COMMUNITY_ID});
update_field('cta_primary_btn_url',   '/contact',  ${COMMUNITY_ID});

// ── Blog page ──
update_field('hero_bg_style',  'gradient',  ${BLOG_ID});
update_field('hero_show_logo', false,       ${BLOG_ID});
update_field('hero_alignment', 'center',    ${BLOG_ID});
update_field('hero_tagline',   'Blog',      ${BLOG_ID});
update_field('hero_subtitle',  '<p>News, updates, and reflections from the Catholic Digital Commons Foundation.</p>', ${BLOG_ID});
update_field('max_posts',      6,           ${BLOG_ID});

// ── Contact page ──
update_field('hero_bg_style',  'gradient',     ${CONTACT_ID});
update_field('hero_show_logo', false,          ${CONTACT_ID});
update_field('hero_alignment', 'center',       ${CONTACT_ID});
update_field('hero_tagline',   'Contact Us',   ${CONTACT_ID});
update_field('hero_subtitle',  '<p>Get in touch with the Catholic Digital Commons Foundation.</p>', ${CONTACT_ID});
update_field('contact_body',   '<h2>Get in Touch</h2><p>We would love to hear from you. Whether you have questions, suggestions, or want to contribute, reach out to us.</p><p>Email: <a href=\"mailto:info@catholicdigitalcommons.org\">info@catholicdigitalcommons.org</a></p>', ${CONTACT_ID});
update_field('cta_style',             'inline', ${CONTACT_ID});
update_field('cta_heading',           'Prefer to Chat?', ${CONTACT_ID});
update_field('cta_description',       '<p>Join our community channels for real-time conversation.</p>', ${CONTACT_ID});
update_field('cta_primary_btn_label', 'Join the Community', ${CONTACT_ID});
update_field('cta_primary_btn_url',   '/community', ${CONTACT_ID});
" --allow-root 2>/dev/null || echo "  ACF field seeding skipped."

# ── Create Sample CPT Entries ──

echo "Creating sample content..."

# Stat items
STAT1_ID=$(wp post create --post_type=stat_item --post_title="Open Source Projects" --post_status=publish --porcelain --allow-root)
STAT2_ID=$(wp post create --post_type=stat_item --post_title="Contributors" --post_status=publish --porcelain --allow-root)
STAT3_ID=$(wp post create --post_type=stat_item --post_title="Languages" --post_status=publish --porcelain --allow-root)

wp eval "
if (!function_exists('update_field')) { return; }
update_field('stat_number', '12+', ${STAT1_ID});
update_field('stat_label',  'Open Source Projects', ${STAT1_ID});
update_field('stat_number', '50+', ${STAT2_ID});
update_field('stat_label',  'Contributors', ${STAT2_ID});
update_field('stat_number', '6',   ${STAT3_ID});
update_field('stat_label',  'Languages', ${STAT3_ID});
" --allow-root 2>/dev/null || true

# Link stat items to Home page
wp eval "
if (!function_exists('update_field')) { return; }
update_field('stats', [${STAT1_ID}, ${STAT2_ID}, ${STAT3_ID}], ${HOME_ID});
" --allow-root 2>/dev/null || true

# Sample projects
P1_ID=$(wp post create --post_type=project --post_title="Liturgical Calendar API" \
  --post_content="<p>A comprehensive API for the liturgical calendar of the Roman Rite, providing data for feast days, liturgical seasons, and more.</p>" \
  --post_status=publish --porcelain --allow-root)

P2_ID=$(wp post create --post_type=project --post_title="Catholic Lectionary" \
  --post_content="<p>An open-source lectionary application providing daily and Sunday readings in multiple languages.</p>" \
  --post_status=publish --porcelain --allow-root)

wp eval "
if (!function_exists('update_field')) { return; }
update_field('project_status',   'active',      ${P1_ID});
update_field('project_license',  'Apache-2.0',  ${P1_ID});
update_field('project_category', 'API',         ${P1_ID});
update_field('project_repo_url', 'https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI', ${P1_ID});
update_field('project_status',   'incubating',  ${P2_ID});
update_field('project_license',  'MIT',         ${P2_ID});
update_field('project_category', 'Application', ${P2_ID});
" --allow-root 2>/dev/null || true

# Link projects to Home page
wp eval "
if (!function_exists('update_field')) { return; }
update_field('featured_projects', [${P1_ID}, ${P2_ID}], ${HOME_ID});
" --allow-root 2>/dev/null || true

# Sample team member
M1_ID=$(wp post create --post_type=team_member --post_title="John R. D'Orazio" \
  --post_content="<p>Founder and lead developer of the Catholic Digital Commons Foundation.</p>" \
  --post_status=publish --porcelain --allow-root)

wp eval "
if (!function_exists('update_field')) { return; }
update_field('member_role',       'Founder',        ${M1_ID});
update_field('member_title',      'Lead Developer', ${M1_ID});
update_field('member_github_url', 'https://github.com/JohnRDOrazio', ${M1_ID});
" --allow-root 2>/dev/null || true

# Link team member to About page
wp eval "
if (!function_exists('update_field')) { return; }
update_field('team_members',       [${M1_ID}], ${ABOUT_ID});
update_field('governance_columns', '3',         ${ABOUT_ID});
" --allow-root 2>/dev/null || true

# Sample community channels
CH1_ID=$(wp post create --post_type=community_channel --post_title="GitHub Discussions" \
  --post_status=publish --porcelain --allow-root)

wp eval "
if (!function_exists('update_field')) { return; }
update_field('channel_icon',        'github', ${CH1_ID});
update_field('channel_url',         'https://github.com/orgs/CatholicOS/discussions', ${CH1_ID});
update_field('channel_description', 'Join discussions about CDCF projects and initiatives on GitHub.', ${CH1_ID});
" --allow-root 2>/dev/null || true

CH2_ID=$(wp post create --post_type=community_channel --post_title="Catholic Coders Guild by Clairvo" \
  --post_status=publish --porcelain --allow-root)

wp eval "
if (!function_exists('update_field')) { return; }
update_field('channel_icon',        'discord', ${CH2_ID});
update_field('channel_url',         'https://discord.gg/q4vg3tCe', ${CH2_ID});
update_field('channel_description', 'A Discord server for Catholic coders to collaborate and share ideas.', ${CH2_ID});
" --allow-root 2>/dev/null || true

CH3_ID=$(wp post create --post_type=community_channel --post_title="Catholic Devs" \
  --post_status=publish --porcelain --allow-root)

wp eval "
if (!function_exists('update_field')) { return; }
update_field('channel_icon',        'slack', ${CH3_ID});
update_field('channel_url',         'https://join.slack.com/t/catholicdevs/shared_invite/zt-1tovdt4om-YNoPduN0rQub5zBsbucj2w', ${CH3_ID});
update_field('channel_description', 'A Slack workspace for Catholic developers to connect and collaborate.', ${CH3_ID});
" --allow-root 2>/dev/null || true

# Link channels to Community page
wp eval "
if (!function_exists('update_field')) { return; }
update_field('channels', [${CH1_ID}, ${CH2_ID}, ${CH3_ID}], ${COMMUNITY_ID});
update_field('members',  [${M1_ID}],  ${COMMUNITY_ID});
" --allow-root 2>/dev/null || true

# Sample blog post
wp post create --post_title="Welcome to the Catholic Digital Commons Foundation" \
  --post_content="<p>We are excited to launch the Catholic Digital Commons Foundation, a community-driven initiative to build open-source tools for the Catholic Church.</p><p>Our mission is to foster collaboration among Catholic developers, designers, translators, and digital missionaries to create software that serves the universal Church.</p>" \
  --post_status=publish --allow-root > /dev/null

# ── Clean Up Defaults ──

wp post delete 1 --force --allow-root 2>/dev/null || true
wp post delete 2 --force --allow-root 2>/dev/null || true

# ── Bulk Translation (optional) ──

if [ -z "$OPENAI_API_KEY" ]; then
  echo ""
  echo "OPENAI_API_KEY not set — skipping automatic translations."
  echo "To translate content, set OPENAI_API_KEY in .env and re-run init."
else
  echo ""
  echo "Translating content to all languages..."
  wp option update cdcf_openai_api_key "$OPENAI_API_KEY" --allow-root
  wp option update cdcf_openai_model "${OPENAI_MODEL:-gpt-4o-mini}" --allow-root
  wp eval-file /usr/local/bin/translate-all.php --allow-root
fi

# ── Fix Permissions ──
# The wp-init container runs as root, so files created during init are owned by
# root. WordPress (Apache) runs as www-data (UID 33 on Debian). The CLI image
# uses UID 82 for www-data, so we must chown by numeric ID to match Apache.
# Exclude the bind-mounted theme directory (managed on the host).
echo "Fixing file permissions..."
find /var/www/html/wp-content -path /var/www/html/wp-content/themes/cdcf-headless -prune -o -exec chown 33:33 {} +

echo ""
echo "========================================="
echo "  WordPress initialization complete!"
echo "========================================="
echo ""
echo "  Site:     ${WP_URL:-http://localhost}"
echo "  Admin:    ${WP_URL:-http://localhost}/wp-admin"
echo "  Username: ${WP_ADMIN_USER:-admin}"
echo "  Password: ${WP_ADMIN_PASSWORD:-admin}"
echo ""
