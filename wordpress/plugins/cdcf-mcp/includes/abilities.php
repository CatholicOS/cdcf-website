<?php
/**
 * CDCF MCP ability definitions + registration.
 *
 * cdcf_mcp_ability_definitions() is the single source of truth for the
 * ability list: it is consumed both here (to call wp_register_ability())
 * and by includes/server.php (to enumerate ability names for the MCP
 * server). Keeping it in one function lets the unit tests assert the set
 * without booting WordPress.
 */

if (defined('ABSPATH') === false) {
    return;
}

require_once __DIR__ . '/callbacks.php';

/**
 * Reusable JSON-schema fragment for team_member creation inputs.
 */
function cdcf_mcp_member_input_schema(bool $require_collab = false): array {
    $schema = [
        'type'       => 'object',
        'properties' => [
            'title'               => ['type' => 'string', 'description' => "The member's full name."],
            'content'             => ['type' => 'string', 'description' => 'Bio / description (HTML allowed). English source text; translations are generated automatically.'],
            'member_title'        => ['type' => 'string', 'description' => 'Honorific / professional title (e.g. "Dr.", "Rev.").'],
            'member_role'         => ['type' => 'string', 'description' => 'Role within CDCF (e.g. "AI Specialist").'],
            'member_linkedin_url' => ['type' => 'string', 'description' => 'LinkedIn profile URL.'],
            'member_github_url'   => ['type' => 'string', 'description' => 'GitHub profile URL.'],
            'featured_image_id'   => ['type' => 'integer', 'description' => 'Attachment id for the headshot.'],
        ],
        'required'   => ['title', 'content'],
    ];
    if ($require_collab) {
        $schema['properties']['collab_post_id'] = [
            'type'        => 'integer',
            'description' => 'English academic collaboration post id this liaison governs.',
        ];
        $schema['required'][] = 'collab_post_id';
    }
    return $schema;
}

/**
 * @return array<int,array<string,mixed>> Ordered ability definitions.
 */
function cdcf_mcp_ability_definitions(): array {
    return [
        [
            'name'        => 'cdcf/create-board-member',
            'label'       => 'Create Board Member',
            'description' => 'Create a board (core team) member, auto-translate the bio to all site languages, and link it to the About page board section.',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_create_board_member',
            'input_schema'=> cdcf_mcp_member_input_schema(),
        ],
        [
            'name'        => 'cdcf/create-ecclesial-council-member',
            'label'       => 'Create Ecclesial Advisory Council Member',
            'description' => 'Create an ecclesial advisory council member, auto-translate, and link to the About page ecclesial council.',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_create_ecclesial_council_member',
            'input_schema'=> cdcf_mcp_member_input_schema(),
        ],
        [
            'name'        => 'cdcf/create-technical-council-member',
            'label'       => 'Create Technical Advisory Council Member',
            'description' => 'Create a technical advisory council member, auto-translate, and link to the About page technical council.',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_create_technical_council_member',
            'input_schema'=> cdcf_mcp_member_input_schema(),
        ],
        [
            'name'        => 'cdcf/create-academic-liaison',
            'label'       => 'Create Academic Liaison',
            'description' => 'Create an academic council member (liaison) and link it to the governance field of a specific academic collaboration. Requires collab_post_id.',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_create_academic_liaison',
            'input_schema'=> cdcf_mcp_member_input_schema(true),
        ],
        [
            'name'        => 'cdcf/create-author-member',
            'label'       => 'Create Author (Team Member)',
            'description' => 'Create a council-less team member, auto-translate the bio to all site languages, but link it to NO About-page council. Use this for blog authors and other people who need a translated profile (bio, photo, role, social links) without sitting on the Board / Ecclesial / Technical council. After creating, link the member to a WordPress author in wp-admin (the user\'s "Author Profile" field) or add them as a project lead via cdcf/add-project-lead.',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_create_author_member',
            'input_schema'=> cdcf_mcp_member_input_schema(),
        ],
        [
            'name'        => 'cdcf/create-academic-collaboration',
            'label'       => 'Create Academic Collaboration',
            'description' => 'Create an academic collaboration, auto-translate, and link it to the Community page.',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_create_academic_collaboration',
            'input_schema'=> [
                'type'       => 'object',
                'properties' => [
                    'title'              => ['type' => 'string', 'description' => 'Collaboration / institution name.'],
                    'collab_description' => ['type' => 'string', 'description' => 'Description (English source).'],
                    'collab_university'  => ['type' => 'string', 'description' => 'University name.'],
                    'collab_department'  => ['type' => 'string', 'description' => 'Department (optional).'],
                    'collab_location'    => ['type' => 'string', 'description' => 'Location, e.g. "Washington D.C., USA".'],
                    'collab_website_url' => ['type' => 'string', 'description' => 'Website URL (optional).'],
                    'featured_image_id'  => ['type' => 'integer', 'description' => 'Attachment id (optional).'],
                ],
                'required'   => ['title', 'collab_description', 'collab_university'],
            ],
        ],
        [
            'name'        => 'cdcf/create-community-channel',
            'label'       => 'Create Community Channel',
            'description' => 'Create a community channel (Discord, Slack, …), auto-translate the description to all site languages, and link it to the Community page.',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_create_community_channel',
            'input_schema'=> [
                'type'       => 'object',
                'properties' => [
                    'title'               => ['type' => 'string', 'description' => 'Channel name.'],
                    'channel_description' => ['type' => 'string', 'description' => 'Description (English source; translated automatically).'],
                    'channel_url'         => ['type' => 'string', 'description' => 'URL to join the channel.'],
                    'channel_icon'        => ['type' => 'string', 'description' => 'Icon key (optional), e.g. "discord", "slack".'],
                ],
                'required'   => ['title', 'channel_description', 'channel_url'],
            ],
        ],
        [
            'name'        => 'cdcf/create-local-group',
            'label'       => 'Create Local Group',
            'description' => 'Create a local group, auto-translate the description to all site languages, and link it to the Community page.',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_create_local_group',
            'input_schema'=> [
                'type'       => 'object',
                'properties' => [
                    'title'             => ['type' => 'string', 'description' => 'Group name.'],
                    'group_description' => ['type' => 'string', 'description' => 'Description (English source; translated automatically).'],
                    'group_url'         => ['type' => 'string', 'description' => 'URL for the group.'],
                    'group_location'    => ['type' => 'string', 'description' => 'City / region name (optional).'],
                ],
                'required'   => ['title', 'group_description', 'group_url'],
            ],
        ],
        [
            'name'        => 'cdcf/update-member-bio',
            'label'       => 'Update Member Bio',
            'description' => 'Update an English team member post (title/bio/role fields). Set retranslate=true to re-queue translations.',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_update_member_bio',
            'input_schema'=> [
                'type'       => 'object',
                'properties' => [
                    'post_id'             => ['type' => 'integer', 'description' => 'English team_member post id.'],
                    'title'               => ['type' => 'string'],
                    'content'             => ['type' => 'string', 'description' => 'New bio (HTML allowed).'],
                    'member_title'        => ['type' => 'string'],
                    'member_role'         => ['type' => 'string'],
                    'member_linkedin_url' => ['type' => 'string'],
                    'member_github_url'   => ['type' => 'string'],
                    'retranslate'         => ['type' => 'boolean', 'description' => 'Re-queue translations of the updated bio.', 'default' => false],
                ],
                'required'   => ['post_id'],
            ],
        ],
        [
            'name'        => 'cdcf/delete-member',
            'label'       => 'Delete Member',
            'description' => 'Trash a team member and all its translations. Set force=true to delete permanently.',
            'capability'  => 'delete_posts',
            'callback'    => 'cdcf_mcp_cb_delete_member',
            'input_schema'=> [
                'type'       => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer', 'description' => 'Any language version of the team_member.'],
                    'force'   => ['type' => 'boolean', 'description' => 'Permanently delete instead of trashing.', 'default' => false],
                ],
                'required'   => ['post_id'],
            ],
        ],
        [
            'name'        => 'cdcf/update-member-relationship',
            'label'       => 'Update Member Relationship',
            'description' => 'Replace an ACF relationship field value (e.g. an About-page council, or a collaboration governance list).',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_update_member_relationship',
            'input_schema'=> [
                'type'       => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer', 'description' => 'The post that owns the relationship field.'],
                    'field'   => ['type' => 'string', 'description' => 'Relationship field name (e.g. technical_council, ecclesial_council, members).'],
                    'value'   => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Full replacement list of post ids.'],
                ],
                'required'   => ['post_id', 'field', 'value'],
            ],
        ],
        [
            'name'        => 'cdcf/add-project-lead',
            'label'       => 'Add Project Lead',
            'description' => 'Append one or more team members to a project\'s project_leads relationship (existing leads are preserved).',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_add_project_lead',
            'input_schema'=> [
                'type'       => 'object',
                'properties' => [
                    'project_id' => ['type' => 'integer', 'description' => 'Project post id.'],
                    'member_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'team_member post ids to add as leads.'],
                ],
                'required'   => ['project_id', 'member_ids'],
            ],
        ],
        [
            'name'        => 'cdcf/update-project-description',
            'label'       => 'Update Project Description',
            'description' => 'Update a project\'s title/description/excerpt. Set retranslate=true to re-queue translations.',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_update_project_description',
            'input_schema'=> [
                'type'       => 'object',
                'properties' => [
                    'post_id'     => ['type' => 'integer', 'description' => 'English project post id.'],
                    'title'       => ['type' => 'string'],
                    'content'     => ['type' => 'string', 'description' => 'New description (HTML allowed).'],
                    'excerpt'     => ['type' => 'string'],
                    'retranslate' => ['type' => 'boolean', 'default' => false],
                ],
                'required'   => ['post_id'],
            ],
        ],
        [
            'name'        => 'cdcf/update-project-status',
            'label'       => 'Update Project Status',
            'description' => 'Set a project\'s status (incubating / active / archived) across all its translations.',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_update_project_status',
            'input_schema'=> [
                'type'       => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer'],
                    'status'  => ['type' => 'string', 'enum' => ['incubating', 'active', 'archived']],
                ],
                'required'   => ['post_id', 'status'],
            ],
        ],
        [
            'name'        => 'cdcf/set-project-repos',
            'label'       => 'Set Project Repository / Website URLs',
            'description' => 'Set a project\'s GitHub repository URL and/or website URL across all its translations.',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_set_project_repos',
            'input_schema'=> [
                'type'       => 'object',
                'properties' => [
                    'project_id'       => ['type' => 'integer'],
                    'project_repo_url' => ['type' => 'string', 'description' => 'GitHub (or other) repository URL.'],
                    'project_url'      => ['type' => 'string', 'description' => 'Project website URL.'],
                ],
                'required'   => ['project_id'],
            ],
        ],
        [
            'name'        => 'cdcf/set-featured-image',
            'label'       => 'Set Featured Image',
            'description' => 'Set an existing media-library attachment as a post\'s featured image (member, project lead, project, page, post).',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_set_featured_image',
            'input_schema'=> [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => ['type' => 'integer'],
                    'attachment_id' => ['type' => 'integer'],
                ],
                'required'   => ['post_id', 'attachment_id'],
            ],
        ],
        // NOTE: a `cdcf/upload-media` ability (sideload a remote URL into the
        // media library) was intentionally removed — download_url() on an
        // agent-supplied URL is an SSRF vector with no safe, simple guard
        // (redirect/DNS-rebind bypasses). Editors upload via wp-admin and
        // cdcf/set-featured-image attaches existing attachments. Reintroduce
        // only with a vetted SSRF-safe fetch. See the security review in
        // docs/wordpress-mcp-evaluation.md.
        [
            'name'        => 'cdcf/list-submitted-projects',
            'label'       => 'List Submitted Projects',
            'description' => 'List project posts (defaults to including drafts/pending so newly submitted projects appear).',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_list_submitted_projects',
            'input_schema'=> [
                'type'       => 'object',
                'properties' => [
                    'limit'       => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
                    'post_status' => ['type' => 'string', 'description' => 'Filter to a single status (publish/draft/pending). Omit for all.'],
                ],
            ],
        ],
        [
            'name'        => 'cdcf/list-submitted-community-projects',
            'label'       => 'List Submitted Community Projects',
            'description' => 'List community_project posts (defaults to including drafts/pending).',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_list_submitted_community_projects',
            'input_schema'=> [
                'type'       => 'object',
                'properties' => [
                    'limit'       => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
                    'post_status' => ['type' => 'string'],
                ],
            ],
        ],
        [
            'name'        => 'cdcf/create-page',
            'label'       => 'Create Page',
            'description' => 'Create a WordPress page (draft by default). Optionally assign a page template and language.',
            'capability'  => 'edit_pages',
            'callback'    => 'cdcf_mcp_cb_create_page',
            'input_schema'=> [
                'type'       => 'object',
                'properties' => [
                    'title'    => ['type' => 'string'],
                    'content'  => ['type' => 'string'],
                    'status'   => ['type' => 'string', 'enum' => ['draft', 'pending', 'publish', 'private'], 'default' => 'draft'],
                    'template' => ['type' => 'string', 'description' => 'e.g. templates/about.php.'],
                    'language' => ['type' => 'string', 'default' => 'en'],
                ],
                'required'   => ['title'],
            ],
        ],
        [
            'name'        => 'cdcf/create-post',
            'label'       => 'Create Blog Post',
            'description' => 'Create a blog post (draft by default). Optionally set excerpt, featured image and language.',
            'capability'  => 'edit_posts',
            'callback'    => 'cdcf_mcp_cb_create_post',
            'input_schema'=> [
                'type'       => 'object',
                'properties' => [
                    'title'             => ['type' => 'string'],
                    'content'           => ['type' => 'string'],
                    'excerpt'           => ['type' => 'string'],
                    'status'            => ['type' => 'string', 'enum' => ['draft', 'pending', 'publish', 'private'], 'default' => 'draft'],
                    'featured_image_id' => ['type' => 'integer'],
                    'language'          => ['type' => 'string', 'default' => 'en'],
                ],
                'required'   => ['title'],
            ],
        ],
        [
            'name'        => 'cdcf/create-user',
            'label'       => 'Create Limited User',
            'description' => 'Provision a low-privilege WordPress user (author, contributor or subscriber only) and email them a set-password link. Cannot create editors or administrators. Requires the cdcf_create_limited_users capability — granted only to a dedicated automation account, not the editor baseline the other abilities use.',
            'capability'  => 'cdcf_create_limited_users',
            'callback'    => 'cdcf_mcp_cb_create_user',
            'input_schema'=> [
                'type'       => 'object',
                'properties' => [
                    'username'     => ['type' => 'string', 'description' => 'Login name (must be unique).'],
                    'email'        => ['type' => 'string', 'description' => 'Email address (must be unique). Receives the set-password link.'],
                    'role'         => ['type' => 'string', 'enum' => ['author', 'contributor', 'subscriber'], 'description' => 'WordPress role. Restricted to these three; editor/administrator are rejected.'],
                    'display_name' => ['type' => 'string', 'description' => 'Display name (defaults to the username).'],
                    'first_name'   => ['type' => 'string'],
                    'last_name'    => ['type' => 'string'],
                ],
                'required'   => ['username', 'email', 'role'],
            ],
        ],
    ];
}

/**
 * @return string[] All ability names, for the MCP server registration.
 */
function cdcf_mcp_ability_names(): array {
    return array_map(
        static fn(array $def): string => $def['name'],
        cdcf_mcp_ability_definitions()
    );
}

/**
 * Register the 'cdcf' ability category.
 *
 * MUST run on `wp_abilities_api_categories_init` — core gives categories
 * their own init hook, separate from abilities, and
 * wp_register_ability_category() bails (returns null via _doing_it_wrong)
 * if called on any other action. Since each ability declares
 * `category => 'cdcf'`, and the abilities registry rejects an ability whose
 * category isn't registered, getting this hook wrong silently drops every
 * cdcf ability.
 */
function cdcf_mcp_register_category(): void {
    if (function_exists('wp_register_ability_category')) {
        wp_register_ability_category('cdcf', [
            'label'       => 'CDCF',
            'description' => 'Catholic Digital Commons Foundation content-management abilities.',
        ]);
    }
}

/**
 * Register every CDCF ability with the WordPress Abilities API.
 *
 * Runs on `wp_abilities_api_init` (the only action on which
 * wp_register_ability() is accepted). The 'cdcf' category must already be
 * registered by cdcf_mcp_register_category() on the categories-init hook.
 * Each ability inherits the capability gate declared in its definition and
 * is flagged public so the MCP adapter exposes it as a tool.
 */
function cdcf_mcp_register_abilities(): void {
    if (!function_exists('wp_register_ability')) {
        return;
    }

    foreach (cdcf_mcp_ability_definitions() as $def) {
        $capability = $def['capability'];

        wp_register_ability($def['name'], [
            'label'               => $def['label'],
            'description'         => $def['description'],
            'category'            => 'cdcf',
            'input_schema'        => $def['input_schema'],
            'execute_callback'    => $def['callback'],
            'permission_callback' => static function () use ($capability): bool {
                return current_user_can($capability);
            },
            'meta'                => [
                'mcp' => ['public' => true],
            ],
        ]);
    }
}
